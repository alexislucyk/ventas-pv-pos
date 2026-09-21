<?php
// pages/generar_pdf_ctacte.php
// Estado de cuenta corriente completo de un cliente en PDF.
// Columnas: FECHA | MOVIMIENTO (texto + nro., "Venta" en vez de "Factura") | DEBE | HABER | SALDO (acumulado).
// Se invoca desde cuentas_corrientes_detalle.php: generar_pdf_ctacte.php?id_cliente=112[&download=1]

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

$id_cliente = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
if ($id_cliente <= 0) {
    die('❌ Error: ID de cliente no válido.');
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
 * Replica el criterio de corte de MultiCell de FPDF: el ancho útil de una
 * línea es el ancho de la celda menos 2mm (cMargin de FPDF = 1).
 */
function ctacte_lineas_texto($pdf, $texto, $ancho_celda) {
    if ($ancho_celda <= 0 || $texto === '' || $texto === null) return 1;
    $texto = str_replace("\r", '', (string)$texto);
    $wmax = max(4, $ancho_celda - 2);
    $palabras = preg_split('/\s+/', trim($texto));
    $lineas = 1;
    $ancho_linea = 0;
    $ancho_espacio = $pdf->GetStringWidth(' ');
    foreach ($palabras as $palabra) {
        $ancho_pal = $pdf->GetStringWidth($palabra);
        if ($ancho_pal > $wmax) {
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
        $this->SetX(8);
        $this->Cell(0, 4, mb_convert_encoding('Estado de cuenta corriente emitido por el sistema', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
        $this->Cell(0, 4, mb_convert_encoding('Pág. ' . $this->PageNo() . ' / {nb}', 'ISO-8859-1', 'UTF-8'), 0, 0, 'R');
    }
}

/**
 * Dibuja la fila de títulos de la tabla de movimientos.
 * Se usa al inicio del listado y cada vez que se agrega una página nueva.
 */
function ctacte_cabecera_tabla($pdf, $w) {
    $pdf->SetFillColor(60, 60, 60);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($w['fecha'], 7, to_iso('FECHA'), 1, 0, 'C', true);
    $pdf->Cell($w['mov'],   7, to_iso('MOVIMIENTO'), 1, 0, 'C', true);
    $pdf->Cell($w['debe'],  7, to_iso('DEBE'), 1, 0, 'C', true);
    $pdf->Cell($w['haber'], 7, to_iso('HABER'), 1, 0, 'C', true);
    $pdf->Cell($w['saldo'], 7, to_iso('SALDO'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);
}

// ============================================================
//  DATOS DE EMPRESA, CLIENTE Y MOVIMIENTOS
// ============================================================
try {
    // --- Empresa emisora ---
    $stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
    $stmt_emp->execute([$empresa_id]);
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC) ?: [];

    // --- Cliente titular de la cuenta corriente ---
    $stmt_cli = $pdo->prepare("SELECT id, nombre, apellido, cuit, telefono, direccion
                               FROM clientes
                               WHERE id = ? AND empresa_id = ?");
    $stmt_cli->execute([$id_cliente, $empresa_id]);
    $cliente = $stmt_cli->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        die('❌ Error: Cliente no encontrado.');
    }

    // --- Movimientos (mismo criterio de orden que la pantalla) ---
    $stmt_mov = $pdo->prepare("SELECT id, movimiento, n_documento, debe, haber, fecha
                               FROM ctacte
                               WHERE id_cliente = ? AND empresa_id = ?
                               ORDER BY fecha ASC, id ASC");
    $stmt_mov->execute([$id_cliente, $empresa_id]);
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);

    if (empty($movimientos)) {
        die('❌ El cliente no tiene movimientos registrados en la cuenta corriente.');
    }

    // --- Configuración A4 vertical ---
    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(false); // el corte de página lo controla la tabla
    $pdf->SetMargins(8, 8, 8);
    $pdf->AddPage();

    $ancho_total = 194;                 // 210mm - 2 * 8mm de margen
    $x0 = 8;                            // margen izquierdo
    $w = [
        'fecha' => 20,
        'mov'   => 90,
        'debe'  => 29,
        'haber' => 29,
        'saldo' => 26,
    ];                                  // 20+90+29+29+26 = 194

    // --- ENCABEZADO (empresa | título) ---
    $alto_enc = 30;
    $pdf->Rect($x0, 8, $ancho_total, $alto_enc);
    $pdf->Line($x0 + ($ancho_total / 2), 8, $x0 + ($ancho_total / 2), 8 + $alto_enc);

    $pdf->SetXY($x0 + 2, 10);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->MultiCell(($ancho_total / 2) - 12, 6, to_iso(strtoupper($emp['nombre_fantasia'] ?? '')), 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX($x0 + 2);
    $pdf->Cell(($ancho_total / 2) - 12, 4, to_iso(trim(($emp['direccion'] ?? '') . ' - ' . ($emp['localidad'] ?? ''), ' -')), 0, 1, 'L');
    $pdf->SetX($x0 + 2);
    $pdf->Cell(($ancho_total / 2) - 12, 4, 'CUIT: ' . to_iso($emp['cuit'] ?? 'S/D'), 0, 1, 'L');

    $pdf->SetXY($x0 + ($ancho_total / 2) + 5, 10);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(($ancho_total / 2) - 10, 6, to_iso('ESTADO DE CUENTA CORRIENTE'), 0, 1, 'R');
    $pdf->SetX($x0 + ($ancho_total / 2) + 5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 10, 5, 'Fecha de emision: ' . date('d/m/Y H:i'), 0, 1, 'R');
    $pdf->SetX($x0 + ($ancho_total / 2) + 5);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(($ancho_total / 2) - 10, 5, 'Movimientos: ' . count($movimientos), 0, 1, 'R');

    // --- DATOS DEL CLIENTE ---
    $cliente_nombre = trim(($cliente['apellido'] ?? '') . (($cliente['nombre'] ?? '') !== '' ? ', ' . $cliente['nombre'] : ''));

    $pdf->SetY(42);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($ancho_total, 6, to_iso('  DATOS DEL CLIENTE'), 1, 1, 'L', true);

    $pdf->Rect($x0, 48, $ancho_total, 20);
    $pdf->SetY(49);
    $pdf->SetX($x0 + 2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 5, to_iso('Cliente:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 30, 5, to_iso($cliente_nombre !== '' ? $cliente_nombre : 'CONSUMIDOR FINAL'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(28, 5, to_iso('CUIT/DNI:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, to_iso($cliente['cuit'] ?? 'S/D'), 0, 1, 'L');

    $pdf->SetX($x0 + 2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 5, to_iso('Telefono:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total / 2) - 30, 5, to_iso($cliente['telefono'] ?? 'S/D'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(28, 5, to_iso('Nro. Cliente:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, '#' . $cliente['id'], 0, 1, 'L');

    $pdf->SetX($x0 + 2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 5, to_iso('Domicilio:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($ancho_total - 40, 5, to_iso(($cliente['direccion'] ?? '') !== '' ? $cliente['direccion'] : 'S/D'), 0, 1, 'L');

    $pdf->SetY(72);

    // --- TABLA DE MOVIMIENTOS ---
    ctacte_cabecera_tabla($pdf, $w);

    $limite_inferior = 250;     // A4 (297mm) menos pie de página y márgenes
    $saldo_acumulado = 0;
    $total_debe = 0;
    $total_haber = 0;

    foreach ($movimientos as $mov) {
        $debe  = (float)$mov['debe'];
        $haber = (float)$mov['haber'];
        $saldo_acumulado += $debe - $haber;
        $total_debe  += $debe;
        $total_haber += $haber;

        // Nro. de movimiento: usa el nro. de documento y, si no lo tiene, el id
        $nro_mov = ($mov['n_documento'] && $mov['n_documento'] != '0') ? $mov['n_documento'] : $mov['id'];

        // Saldo con el mismo signo que muestra la pantalla (negativo = el cliente adeuda)
        $saldo_mostrar = $saldo_acumulado;
        if ($saldo_acumulado > 0) {
            $saldo_mostrar = -$saldo_acumulado;
            $pdf->SetTextColor(200, 0, 0);
        } elseif ($saldo_acumulado < 0) {
            $saldo_mostrar = abs($saldo_acumulado);
            $pdf->SetTextColor(0, 130, 60);
        } else {
            $pdf->SetTextColor(90, 90, 90);
        }

        // Columna unica MOVIMIENTO: texto y nro. de movimiento unificados en
        // una sola columna, y "Venta" en lugar de "Factura" (no son facturas
        // de ARCA). Solo se reemplaza la palabra completa, sin tocar textos
        // como "facturacion" o "factuar" dentro de los motivos.
        $texto_mov = trim((string)preg_replace('/\bFACTURA\b/i', 'Venta', (string)$mov['movimiento']));
        if ($texto_mov === '') {
            $texto_mov = 'Movimiento';
        }
        if (!preg_match('/\b' . preg_quote((string)$nro_mov, '/') . '\b/', $texto_mov)) {
            $texto_mov .= ' N° ' . $nro_mov;
        }

        // Alto de la fila según las líneas que ocupe el texto del movimiento
        $lineas = ctacte_lineas_texto($pdf, to_iso($texto_mov), $w['mov'] - 4);
        $alto_fila = max(6.5, ($lineas * 4.2) + 1.6);

        // Salto de página: se repite la cabecera de la tabla
        if (($pdf->GetY() + $alto_fila) > $limite_inferior) {
            $pdf->AddPage();
            $pdf->SetY(10);
            ctacte_cabecera_tabla($pdf, $w);
        }

        $y = $pdf->GetY();

        // Columna FECHA
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($x0, $y);
        $pdf->Cell($w['fecha'], $alto_fila, date('d/m/Y', strtotime($mov['fecha'])), 0, 0, 'C');

        // Columna MOVIMIENTO (con corte de línea automático)
        $pdf->SetXY($x0 + $w['fecha'] + 2, $y + 1.2);
        $pdf->MultiCell($w['mov'] - 4, 4.2, to_iso($texto_mov), 0, 'L');

        $x_debe  = $x0 + $w['fecha'] + $w['mov'];
        $x_haber = $x_debe + $w['debe'];
        $x_saldo = $x_haber + $w['haber'];

        // Columna DEBE
        $pdf->SetXY($x_debe, $y);
        $pdf->Cell($w['debe'], $alto_fila, $debe > 0 ? formato_monto($debe) : '-', 0, 0, 'R');

        // Columna HABER
        $pdf->SetXY($x_haber, $y);
        $pdf->Cell($w['haber'], $alto_fila, $haber > 0 ? formato_monto($haber) : '-', 0, 0, 'R');

        // Columna SALDO (acumulado)
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetXY($x_saldo, $y);
        $pdf->Cell($w['saldo'], $alto_fila, $saldo_acumulado != 0 ? formato_monto($saldo_mostrar) : '-', 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // Grilla de la fila (se dibuja sobre los textos ya escritos)
        $pdf->Rect($x0, $y, $ancho_total, $alto_fila);
        $x_linea = $x0;
        foreach (['fecha', 'mov', 'debe', 'haber'] as $col) {
            $x_linea += $w[$col];
            $pdf->Line($x_linea, $y, $x_linea, $y + $alto_fila);
        }

        $pdf->SetY($y + $alto_fila);
    }

    // --- TOTALES ---
    if (($pdf->GetY() + 30) > $limite_inferior) {
        $pdf->AddPage();
        $pdf->SetY(10);
    }


    // --- SALDO FINAL ---
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 10.5);
    if ($saldo_acumulado > 0) {
        $etiqueta_saldo = 'SALDO FINAL (DEUDOR)';
        $pdf->SetTextColor(200, 0, 0);
    } elseif ($saldo_acumulado < 0) {
        $etiqueta_saldo = 'SALDO FINAL (A FAVOR)';
        $pdf->SetTextColor(0, 130, 60);
    } else {
        $etiqueta_saldo = 'SALDO FINAL (AL DIA)';
        $pdf->SetTextColor(90, 90, 90);
    }
    $pdf->Cell($ancho_total - $w['saldo'], 9, to_iso($etiqueta_saldo), 'LRB', 0, 'R', true);
    $pdf->Cell($w['saldo'], 9, formato_monto(abs($saldo_acumulado)), 1, 1, 'R', true);

    // --- LEYENDA ---
    $pdf->Ln(2);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetFont('Arial', 'I', 7);
    // $pdf->MultiCell($ancho_total, 3.5, to_iso('Columna SALDO: negativo indica deuda del cliente y positivo saldo a favor. El saldo final se informa en valor absoluto junto a su condicion.'), 0, 'L');

    // --- SALIDA DEL PDF ---
    $dest = isset($_GET['download']) ? 'D' : 'I';
    $pdf->Output($dest, 'Estado_Cuenta_Corriente_Cliente_' . $cliente['id'] . '.pdf');

} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}
