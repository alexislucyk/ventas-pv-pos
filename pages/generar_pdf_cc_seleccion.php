<?php
// pages/generar_pdf_cc_seleccion.php
// Genera un PDF con los movimientos de cuenta corriente seleccionados desde cuentas_corrientes_detalle.php

while (ob_get_level()) { ob_end_clean(); }
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

/**
 * Convierte strings de UTF-8 a ISO-8859-1 para compatibilidad con FPDF
 */
function to_iso($text) {
    if ($text === null) return '';
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

function formato_monto($valor) {
    return '$ ' . number_format((float)$valor, 2, ',', '.');
}

/**
 * Estima cuántas líneas ocupará un texto dentro de un ancho de celda dado.
 * Replica el criterio de corte de MultiCell/Write de FPDF: el ancho máximo
 * útil de una línea es $ancho_celda menos 2*margen_celda (cMargin=1).
 */
function contar_lineas_texto($pdf, $texto, $ancho_celda) {
    if ($ancho_celda <= 0 || $texto === '' || $texto === null) return 1;
    $texto = str_replace("\r", '', (string)$texto);
    $wmax = max(4, $ancho_celda - 2); // cMargin de FPDF = 1
    $palabras = preg_split('/\s+/', trim($texto));
    $lineas = 1;
    $ancho_linea = 0;
    $ancho_espacio = $pdf->GetStringWidth(' ');
    foreach ($palabras as $palabra) {
        $ancho_pal = $pdf->GetStringWidth($palabra);
        if ($ancho_pal > $wmax) {
            // Palabra más ancha que la celda: ocupa varias líneas completas
            $lineas += floor($ancho_pal / $wmax);
            $ancho_linea = $ancho_pal - floor($ancho_pal / $wmax) * $wmax;
            continue;
        }
        $ancho_extra = $ancho_pal + $ancho_espacio;
        if ($ancho_linea + $ancho_extra > $wmax && $ancho_linea > 0) {
            $lineas++;
            $ancho_linea = $ancho_extra;
        } else {
            $ancho_linea += $ancho_extra;
        }
    }
    return max(1, $lineas);
}

// Extender FPDF para manejar el pie de página de forma automática
class PDF extends FPDF {
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, mb_convert_encoding('Documento no válido como factura', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Cell(0, 4, mb_convert_encoding('Gracias por su confianza', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        $this->SetX(5);
        $this->Cell(0, 4, mb_convert_encoding('Pág. ' . $this->PageNo() . ' / {nb}', 'ISO-8859-1', 'UTF-8'), 0, 1, 'R');
    }
}

// --- RECOLECTAR IDs SELECCIONADOS ---
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_values(array_map('intval', $_POST['ids']));
} elseif (isset($_GET['ids']) && is_array($_GET['ids'])) {
    $ids = array_values(array_map('intval', $_GET['ids']));
}

$ids = array_filter($ids, function ($v) { return $v > 0; });
$ids = array_values(array_unique($ids));

if (empty($ids)) {
    die("❌ No se seleccionaron movimientos válidos.");
}

try {
    // --- DATOS DE LA EMPRESA ---
    $stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
    $stmt_emp->execute([$empresa_id]);
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    // --- DATOS DEL CLIENTE (del primer movimiento) ---
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql_cliente = "SELECT DISTINCT c.id, c.nombre, c.apellido, c.cuit, c.telefono
                    FROM ctacte m
                    INNER JOIN clientes c ON m.id_cliente = c.id AND c.empresa_id = ?
                    WHERE m.id IN ($placeholders) AND m.empresa_id = ?
                    LIMIT 1";
    $stmt_cliente = $pdo->prepare($sql_cliente);
    $stmt_cliente->execute([$empresa_id, ...$ids, $empresa_id]);
    $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

    // --- MOVIMIENTOS ---
    $sql_mov = "SELECT id, movimiento, n_documento, debe, haber, fecha, usuario
                FROM ctacte
                WHERE id IN ($placeholders) AND empresa_id = ?
                ORDER BY fecha ASC, id ASC";
    $stmt_mov = $pdo->prepare($sql_mov);
    $stmt_mov->execute([...$ids, $empresa_id]);
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);

    if (empty($movimientos)) {
        die('❌ No se encontraron movimientos para los IDs seleccionados.');
    }

    // --- CACHE DE DETALLES DE VENTA (FACTURA) ---
    $cache_detalle_venta = [];
    foreach ($movimientos as $mov) {
        $mov_upper = strtoupper($mov['movimiento']);
        if (strpos($mov_upper, 'FACTURA') !== false && !empty($mov['n_documento']) && $mov['n_documento'] != '0') {
            $n_doc = (int)$mov['n_documento'];
            if (!isset($cache_detalle_venta[$n_doc])) {
                $stmt_det = $pdo->prepare("SELECT cod_prod, descripcion, cant, p_unit, total
                                           FROM ventas_detalle
                                           WHERE n_documento = ? AND empresa_id = ?
                                           ORDER BY id ASC");
                $stmt_det->execute([$n_doc, $empresa_id]);
                $cache_detalle_venta[$n_doc] = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    // --- CONFIGURACIÓN A4 VERTICAL (Portrait) ---
    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(8, 8, 8);
    $ancho_total = 194; // 210mm - 16mm margen

    // --- ENCABEZADO ---
    $alto_enc = 30;
    $pdf->Rect(8, 8, $ancho_total, $alto_enc);
    $pdf->Line(8 + ($ancho_total / 2), 8, 8 + ($ancho_total / 2), 8 + $alto_enc);

    // LADO IZQUIERDO: Datos Empresa
    $pdf->SetXY(10, 10);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->MultiCell(($ancho_total / 2) - 12, 6, to_iso(strtoupper($emp['nombre_fantasia'] ?? '')), 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(10);
    $pdf->Cell(($ancho_total / 2) - 12, 4, to_iso(($emp['direccion'] ?? '') . ' - ' . ($emp['localidad'] ?? '')), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(($ancho_total / 2) - 12, 4, 'CUIT: ' . to_iso($emp['cuit'] ?? 'S/D'), 0, 1, 'L');

    // LADO DERECHO: Título y fecha
    $pdf->SetXY(8 + ($ancho_total / 2) + 5, 10);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(($ancho_total / 2) - 10, 6, to_iso('DETALLE DE CUENTA CORRIENTE'), 0, 1, 'R');
    $pdf->SetX(8 + ($ancho_total / 2) + 5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 10, 5, 'Fecha de emision: ' . date("d/m/Y H:i"), 0, 1, 'R');

    // --- DATOS DEL CLIENTE ---
    $pdf->SetY(42);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($ancho_total, 6, to_iso('  DATOS DEL CLIENTE'), 1, 1, 'L', true);

    $cliente_nombre = ($cliente && trim(($cliente['apellido'] ?? '') . ' ' . ($cliente['nombre'] ?? '')) !== '')
        ? trim(($cliente['apellido'] ?? '') . ($cliente['nombre'] ? ', ' . $cliente['nombre'] : ''))
        : 'CONSUMIDOR FINAL';
    $cliente_cuit  = $cliente ? ($cliente['cuit'] ?? 'S/D') : 'S/D';
    $cliente_tel   = $cliente ? ($cliente['telefono'] ?? 'S/D') : 'S/D';
    $cliente_id    = $cliente ? $cliente['id'] : 'S/D';

    $pdf->Rect(8, 48, $ancho_total, 16);
    $pdf->SetY(49);
    $pdf->SetX(10);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 5, to_iso('Cliente:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 40, 5, to_iso($cliente_nombre), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(28, 5, to_iso('CUIT/DNI:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, to_iso($cliente_cuit), 0, 1, 'L');

    $pdf->SetX(10);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 5, to_iso('Telefono:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 40, 5, to_iso($cliente_tel), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(28, 5, 'Nro. Cliente:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, '#' . $cliente_id, 0, 1, 'L');

    $pdf->Ln(8);

    // --- TABLA DE MOVIMIENTOS ---
    $pdf->SetFillColor(60, 60, 60);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);

    $w_fecha = 20;
    $w_nro = 16;
    $w_monto = 38;
    $w_detalle = $ancho_total - $w_fecha - $w_nro - $w_monto;

    $pdf->Cell($w_fecha, 7, to_iso('FECHA'), 1, 0, 'C', true);
    $pdf->Cell($w_nro, 7, to_iso('NRO. DOC.'), 1, 0, 'C', true);
    $pdf->Cell($w_detalle, 7, to_iso('DETALLE DE LA COMPRA / MOVIMIENTO'), 1, 0, 'C', true);
    $pdf->Cell($w_monto, 7, to_iso('TOTAL'), 1, 1, 'C', true);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);

    $total_general = 0;
    $y_inicio = $pdf->GetY();

    foreach ($movimientos as $mov) {
        $mov_upper = strtoupper($mov['movimiento']);
        $es_venta = strpos($mov_upper, 'FACTURA') !== false;
        $es_dev  = (strpos($mov_upper, 'ANULACION') !== false || strpos($mov_upper, 'DEVOLUCION') !== false);

        $fecha_render = date('d/m/Y', strtotime($mov['fecha']));
        $n_doc_display = ($mov['n_documento'] && $mov['n_documento'] != '0') ? $mov['n_documento'] : $mov['id'];
        $monto = (float)$mov['debe'] + (float)$mov['haber'];
        $total_general += $monto;

        // --- Construir textos del detalle ---
        $ancho_texto = $w_detalle - 4;  // ancho util dentro de la celda de detalle

        $bloque_detalle = to_iso($mov['movimiento']);
        $lineas_extra = [];

        if ($es_venta && !empty($cache_detalle_venta[(int)$mov['n_documento']])) {
            $items = $cache_detalle_venta[(int)$mov['n_documento']];
            foreach ($items as $it) {
                $linea = '   - ' . $it['descripcion']
                       . ' | x' . number_format($it['cant'], 2, ',', '.')
                       . ' | P. Unit: ' . formato_monto($it['p_unit'])
                       . ' | Subtotal: ' . formato_monto($it['total']);
                $lineas_extra[] = to_iso($linea);
            }
        }

        // --- Calcular alto real de la fila según líneas de cada texto ---
        $pdf->SetFont('Arial', 'B', 8);
        $n_lineas_cab = contar_lineas_texto($pdf, $bloque_detalle, $ancho_texto);
        $pdf->SetFont('Arial', '', 7.5);
        $n_lineas_items = 0;
        foreach ($lineas_extra as $li) {
            $n_lineas_items += contar_lineas_texto($pdf, $li, $ancho_texto);
        }

        $alto_textos = ($n_lineas_cab * 4) + ($n_lineas_items * 4.5);
        $alto_celda = max(7, $alto_textos + 4); // margen de seguridad 4mm

        // --- Corte de página si el bloque no entra ---
        $limite_inferior = 270; // A4 vertical: 297mm - margen inferior
        if ($pdf->GetY() + $alto_celda > $limite_inferior) {
            $pdf->AddPage();
            $pdf->SetFillColor(60, 60, 60);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell($w_fecha, 7, to_iso('FECHA'), 1, 0, 'C', true);
            $pdf->Cell($w_nro, 7, to_iso('NRO. DOC.'), 1, 0, 'C', true);
            $pdf->Cell($w_detalle, 7, to_iso('DETALLE DE LA COMPRA / MOVIMIENTO'), 1, 0, 'C', true);
            $pdf->Cell($w_monto, 7, to_iso('TOTAL'), 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);
        }

        $pos_y = $pdf->GetY();

        // Coordenadas explícitas de las columnas (base = margen izquierdo 8)
        $x_fecha = 8;
        $x_nro   = $x_fecha + $w_fecha;
        $x_det   = $x_nro + $w_nro;
        $x_monto = $x_det + $w_detalle;

        // --- Dibujar PRIMERO el texto del detalle para medir el alto real ---
        $pdf->SetXY($x_det + 2, $pos_y + 1);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->MultiCell($ancho_texto, 4, $bloque_detalle, 0, 'L');

        if (!empty($lineas_extra)) {
            $pdf->SetX($x_det + 2);
            $pdf->SetFont('Arial', '', 7.5);
            foreach ($lineas_extra as $linea_item) {
                $pdf->MultiCell($ancho_texto, 4.5, $linea_item, 0, 'L');
                $pdf->SetX($x_det + 2);
            }
        }

        // Alto real de la fila: lo que ocupó el texto (mínimo el estimado)
        $y_fin = max($pos_y + $alto_celda, $pdf->GetY() + 1);
        $alto_real = $y_fin - $pos_y;

        // --- Dibujar la cuadrícula con el alto real (sobre el texto) ---
        $pdf->SetDrawColor(0, 0, 0);

        // Borde externo de la fila completa
        $pdf->Rect($x_fecha, $pos_y, $w_fecha + $w_nro + $w_detalle + $w_monto, $alto_real);

        // Separadores verticales entre columnas
        $pdf->Line($x_nro, $pos_y, $x_nro, $y_fin);
        $pdf->Line($x_det, $pos_y, $x_det, $y_fin);
        $pdf->Line($x_monto, $pos_y, $x_monto, $y_fin);

        // --- Textos de Fecha, Nro Doc y Monto, centrados verticalmente ---
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x_fecha, $pos_y);
        $pdf->Cell($w_fecha, $alto_real, to_iso($fecha_render), 0, 0, 'C');
        $pdf->SetXY($x_nro, $pos_y);
        $pdf->Cell($w_nro, $alto_real, to_iso($n_doc_display), 0, 0, 'C');
        $pdf->SetXY($x_monto, $pos_y);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w_monto, $alto_real, formato_monto($monto), 0, 1, 'R');

        $pdf->SetY($y_fin);
    }

    // --- TOTAL GENERAL ---
    $pdf->Ln(3);
    $pdf->SetFillColor(60, 60, 60);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($ancho_total - $w_monto, 9, to_iso('TOTAL SELECCIONADO'), 1, 0, 'R', true);
    $pdf->Cell($w_monto, 9, to_iso(formato_monto($total_general)), 1, 1, 'R', true);

    $pdf->SetTextColor(0, 0, 0);

    // Salida del PDF
    $dest = isset($_GET['download']) ? 'D' : 'I';
    $pdf->Output($dest, 'Comprobante_CC_' . (isset($cliente) ? $cliente['id'] : 'cliente') . '.pdf');

} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}