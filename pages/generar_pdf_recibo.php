<?php
// archivo: pages/generar_pdf_recibo.php

while (ob_get_level()) { ob_end_clean(); }
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
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

/**
 * Convierte un grupo de hasta 3 dígitos a letras.
 * Esta es una función auxiliar interna.
 */
function _convertirGrupoTresDigitos($n) {
    $unidades = array('', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE');
    $decenas = array('', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA');
    $centenas = array('', 'CIEN', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS');

    $res = "";
    if ($n >= 100) {
        if ($n == 100) $res .= 'CIEN ';
        else $res .= $centenas[floor($n / 100)] . ' ';
        $n %= 100;
    }
    if ($n >= 20) {
        $decena = floor($n / 10);
        $unidad = $n % 10;
        if ($decena == 2 && $unidad > 0) $res .= 'VEINTI' . $unidades[$unidad];
        else $res .= $decenas[$decena] . ($unidad > 0 ? ' Y ' . $unidades[$unidad] : '');
    } else if ($n > 0) {
        $res .= $unidades[$n];
    }
    return trim($res);
}

/**
 * Convierte un monto numérico a su representación en letras
 */
function numeroALetras($numero) {
    $numero = round($numero, 2);
    $enteros = floor($numero);
    $centavos = round(($numero - $enteros) * 100);

    if ($enteros == 0) {
        $res_enteros = 'CERO';
    } else {
        $res_enteros = "";
        $copia_enteros = $enteros; // Usamos copia para no alterar el original
        
        if ($copia_enteros >= 1000000) {
            $millones = floor($copia_enteros / 1000000);
            $res_enteros .= ($millones == 1 ? 'UN MILLON ' : _convertirGrupoTresDigitos($millones) . ' MILLONES ');
            $copia_enteros %= 1000000;
        }
        if ($copia_enteros >= 1000) {
            $miles = floor($copia_enteros / 1000);
            $res_enteros .= ($miles == 1 ? 'MIL ' : _convertirGrupoTresDigitos($miles) . ' MIL ');
            $copia_enteros %= 1000;
        }
        if ($copia_enteros > 0) {
            $res_enteros .= _convertirGrupoTresDigitos($copia_enteros);
        }
    }

    $texto_final = trim($res_enteros);

    // Solo agregamos centavos si el número no es redondo
    if ($centavos > 0) {
        $texto_final .= " CON " . _convertirGrupoTresDigitos($centavos) . ($centavos == 1 ? " CENTAVO" : " CENTAVOS");
    }

    return $texto_final;
}

// Extender FPDF para manejar el pie de página de forma automática
class PDF extends FPDF {
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, mb_convert_encoding("Documento no válido como factura", 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Cell(0, 4, mb_convert_encoding("Gracias por su confianza", 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        $this->SetX(5); 
        $this->Cell(0, 4, mb_convert_encoding("Pág. " . $this->PageNo() . " / {nb}", 'ISO-8859-1', 'UTF-8'), 0, 1, 'R');
    }
}

$id_mov = isset($_GET['id_mov']) ? intval($_GET['id_mov']) : 0;
if ($id_mov === 0) die("ID de movimiento no valido.");

try {
    // Consulta del movimiento y datos del cliente
    $stmt = $pdo->prepare("SELECT m.*, c.nombre, c.apellido, c.cuit, c.direccion as dir_cliente 
                           FROM ctacte m 
                           LEFT JOIN clientes c ON m.id_cliente = c.id AND c.empresa_id = :empresa_id_cliente
                           WHERE m.id = :id_mov AND m.empresa_id = :empresa_id_movimiento");
    $stmt->execute([':empresa_id_cliente' => $empresa_id, ':empresa_id_movimiento' => $empresa_id, ':id_mov' => $id_mov]);
    $mov = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mov) die("Movimiento no encontrado.");

    // Consulta de datos de la empresa
    $stmt_emp = $pdo->query("SELECT * FROM datos_empresa WHERE id = 1 LIMIT 1");
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    // --- CONFIGURACIÓN A5 ---
    $pdf = new PDF('P', 'mm', 'A5');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(5, 5, 5);
    $ancho_total = 138; // Ancho efectivo (148mm - 10mm margen)

    // --- ENCABEZADO: RECUADRO Y LÍNEA DIVISORIA ---
    $alto_enc = 32;
    $pdf->Rect(5, 5, $ancho_total, $alto_enc); 
    $pdf->Line(5 + ($ancho_total/2), 5, 5 + ($ancho_total/2), 5 + $alto_enc);

    // CUADRO DE LA "X" (Igual que en ventas)
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY(5 + ($ancho_total/2) - 6, 5);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(12, 12, 'X', 1, 0, 'C', true);
    
    $pdf->SetXY(5 + ($ancho_total/2) - 15, 17);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->Cell(30, 3, to_iso("DOC. NO VÁLIDO"), 0, 0, 'C');

    // LADO IZQUIERDO: Datos Empresa
    $pdf->SetXY(7, 8);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->MultiCell(($ancho_total/2) - 8, 5, to_iso(strtoupper($emp['nombre_fantasia'])), 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['direccion']), 0, 1, 'L');
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['localidad']), 0, 1, 'L');

    // LADO DERECHO: Datos Comprobante
    $pdf->SetXY(5 + ($ancho_total/2) + 5, 8);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(($ancho_total/2) - 10, 6, to_iso("RECIBO DE PAGO"), 0, 1, 'R');
    
    $pdf->SetX(5 + ($ancho_total/2) + 5);
    $pdf->SetFont('Arial', 'B', 10);
    $n_recibo = ($mov['n_documento'] && $mov['n_documento'] != "0") ? $mov['n_documento'] : $mov['id'];
    $pdf->Cell(($ancho_total/2) - 10, 6, "N" . chr(176) . " " . str_pad($n_recibo, 8, "0", STR_PAD_LEFT), 0, 1, 'R');
    
    $pdf->SetX(5 + ($ancho_total/2) + 5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total/2) - 10, 6, "Fecha: " . date("d/m/Y", strtotime($mov['fecha'])), 0, 1, 'R');

    // --- SECCIÓN: DATOS DEL CLIENTE ---
    $pdf->SetY(42);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($ancho_total, 6, to_iso("  DATOS DEL CLIENTE"), 1, 1, 'L', true);
    
    $pdf->Rect(5, 48, $ancho_total, 18);
    $pdf->SetY(49);
    
    // Lógica robusta para construir el nombre del cliente
    $ape = trim($mov['apellido'] ?? '');
    $nom = trim($mov['nombre'] ?? '');
    $cliente = ($ape !== '' || $nom !== '') ? trim($ape . ($nom !== '' ? ($ape !== '' ? ', ' : '') . $nom : '')) : "CONSUMIDOR FINAL";
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "Cliente:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($cliente), 0, 1, 'L');
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "CUIT/DNI:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, ($mov['cuit'] ?: "S/D"), 0, 1, 'L');
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "Domicilio:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($mov['dir_cliente'] ?: "ZONA URBANA"), 0, 1, 'L');

    $pdf->Ln(10);

    // --- CUERPO DEL RECIBO ---
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell($ancho_total, 8, to_iso("DETALLE DEL MOVIMIENTO"), 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 11);
    $pdf->Rect(5, $pdf->GetY(), $ancho_total, 40);
    $pdf->SetY($pdf->GetY() + 10);
    $pdf->SetX(10);
    $pdf->MultiCell($ancho_total - 20, 8, to_iso("Recibimos del Sr./Sra. " . $cliente . " la suma de pesos:"), 0, 'L');
    
    // Renglón de abajo: Monto en letras formateado correctamente
    $pdf->SetX(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->MultiCell($ancho_total - 20, 6, to_iso("" . numeroALetras($mov['haber'])), 0, 'L');

    $pdf->Ln(2);
    $pdf->SetX(10);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, to_iso("Concepto: " . $mov['movimiento']), 0, 1, 'L');

    // --- SECCIÓN DE TOTALES ---
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(100, 10, to_iso("MONTO TOTAL RECIBIDO: "), 0, 0, 'R');
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(38, 10, "$ " . number_format((float)($mov['haber'] ?? 0), 2, ',', '.'), 1, 1, 'R', true);

    // Salida del PDF
    $dest = isset($_GET['download']) ? 'D' : 'I';
    $pdf->Output($dest, 'Recibo_Pago_' . $n_recibo . '.pdf');

} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}