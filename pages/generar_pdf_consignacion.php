<?php
// archivo: pages/generar_pdf_consignacion.php
// Genera PDF Profesional del Reporte de Consignación (50/50)

while (ob_get_level()) { ob_end_clean(); }
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require_once '../config/db_config.php';
include 'infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

function to_iso($text) {
    if ($text === null) return '';
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

class PDF extends FPDF {
    function Header() {
        // Línea superior decorativa
        $this->SetDrawColor(0, 188, 212);
        $this->SetLineWidth(0.8);
        $this->Line(10, 8, 200, 8);
        $this->SetLineWidth(0.3);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 4, to_iso('Reporte de Consignación - Documento Interno'), 0, 1, 'C');
        $this->Cell(0, 4, to_iso('Pág. ' . $this->PageNo() . ' / {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    // Helper: celda con fondo y borde redondeado visual (rect + cell)
    function InfoCell($x, $y, $w, $h, $label, $value, $color_label, $color_value, $bg_color) {
        $this->SetFillColor($bg_color[0], $bg_color[1], $bg_color[2]);
        $this->Rect($x, $y, $w, $h, 'F');
        
        // Borde delgado
        $this->SetDrawColor(200, 200, 200);
        $this->Rect($x, $y, $w, $h, 'D');
        
        // Línea superior de acento
        $this->SetFillColor($color_label[0], $color_label[1], $color_label[2]);
        $this->Rect($x, $y, $w, 2.5, 'F');
        
        $this->SetXY($x, $y + 3.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor($color_label[0], $color_label[1], $color_label[2]);
        $this->Cell($w, 4, to_iso($label), 0, 1, 'C');
        
        $this->SetX($x);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor($color_value[0], $color_value[1], $color_value[2]);
        $this->Cell($w, 8, '$ ' . number_format($value, 2, ',', '.'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// Parámetros
$prov_sel = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$desde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
$hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

if (!$prov_sel) {
    die('Debe seleccionar un proveedor.');
}

// --- Consultar datos de la empresa ---
$stmt_emp = $pdo->query("SELECT * FROM datos_empresa WHERE id = 1 LIMIT 1");
$emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);
$nombre_empresa = $emp['nombre_fantasia'] ?? ($emp['razon_social'] ?? 'Mi Empresa');
$direccion_empresa = $emp['direccion'] ?? '';
$localidad_empresa = $emp['localidad'] ?? '';


if ($direccion_empresa && $localidad_empresa) {
    $direccion_empresa .= ' - ' . $localidad_empresa;
} elseif ($localidad_empresa) {
    $direccion_empresa = $localidad_empresa;
}

// --- Consultar resultados ---
$resultados = [];
$totales = ['venta' => 0, 'costo' => 0, 'ganancia' => 0];

try {
    $sql = "SELECT 
                vd.cod_prod, 
                vd.descripcion, 
                SUM(vd.cant) as total_cant,
                vd.p_unit as precio_venta,
                COALESCE(p.p_compra, 0) as costo_unitario,
                SUM(vd.total) as subtotal_venta,
                SUM(COALESCE(p.p_compra, 0) * vd.cant) as subtotal_costo,
                SUM(vd.total - (COALESCE(p.p_compra, 0) * vd.cant)) as ganancia_total
            FROM ventas_detalle vd
            JOIN ventas v ON vd.n_documento = v.n_documento AND v.empresa_id = :empresa_id1
            JOIN productos p ON vd.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci AND p.empresa_id = :empresa_id2
            WHERE v.estado = 'Finalizada' 
              AND TRIM(p.proveedor) = :proveedor
              AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta
            GROUP BY vd.cod_prod, vd.descripcion, vd.p_unit, p.p_compra
            ORDER BY vd.descripcion ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id, ':proveedor' => $prov_sel, ':desde' => $desde, ':hasta' => $hasta]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados as $r) {
        $totales['venta'] += $r['subtotal_venta'];
        $totales['costo'] += $r['subtotal_costo'];
        $totales['ganancia'] += $r['ganancia_total'];
    }
} catch (Exception $e) {
    die('Error en consulta: ' . $e->getMessage());
}

// --- CREAR PDF ---
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25);
$pdf->SetMargins(10, 10, 10);
$ancho = 190;
$margen = 10;

// ============================================================
// ENCABEZADO PROFESIONAL
// ============================================================

// Nombre de la empresa (izquierda) + Título del documento (derecha)
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 7, to_iso($nombre_empresa), 0, 1, 'L');

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(100, 100, 100);
if ($direccion_empresa) $pdf->Cell(0, 4, to_iso($direccion_empresa), 0, 1, 'L');
if ($cuit_empresa) $pdf->Cell(0, 4, to_iso('CUIT: ' . $cuit_empresa), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);

// Título del documento a la derecha (lo ponemos después bajando Y y usando X absoluta)
$pdf->SetXY(100, 15);
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(0, 188, 212);
$pdf->Cell(100, 7, to_iso('LIQUIDACIÓN DE CONSIGNACIÓN'), 0, 1, 'R');
$pdf->SetX(100);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(100, 5, to_iso('50% - 50%'), 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

// Línea separadora
$pdf->SetDrawColor(0, 188, 212);
$pdf->SetLineWidth(0.5);
$y_linea = $pdf->GetY() + 3;
$pdf->Line(10, $y_linea, 200, $y_linea);
$pdf->SetLineWidth(0.2);
$pdf->Ln(6);

// ============================================================
// DATOS DEL REPORTE (proveedor + período)
// ============================================================
$pdf->SetFont('Arial', '', 9);
$pdf->SetFillColor(245, 245, 245);
$pdf->SetDrawColor(220, 220, 220);

$alto_info = 6;
$pdf->Cell(50, $alto_info, to_iso('Proveedor:'), 1, 0, 'L', true);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, $alto_info, to_iso($prov_sel), 1, 0, 'L', false);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, $alto_info, to_iso('Período:'), 1, 0, 'L', true);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, $alto_info, to_iso(date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))), 1, 1, 'L', false);

$pdf->Ln(6);

// ============================================================
// TARJETAS DE RESUMEN (3 columnas)
// ============================================================
$total_proveedor = $totales['costo'] + ($totales['ganancia'] / 2);
$utilidad_negocio = $totales['ganancia'] / 2;

$ancho_card = 58;
$gap_card = 8;
$y_cards = $pdf->GetY();

// Card 1 - Ventas Totales
$x1 = $margen;
$pdf->InfoCell($x1, $y_cards, $ancho_card, 20, 
    'VENTAS TOTALES', $totales['venta'],
    [0, 188, 212], [0, 188, 212], [240, 248, 250]);

// Card 2 - Costo de Mercadería
$x2 = $x1 + $ancho_card + $gap_card;
$pdf->InfoCell($x2, $y_cards, $ancho_card, 20,
    'COSTO DE MERCADERÍA', $totales['costo'],
    [231, 76, 60], [231, 76, 60], [253, 237, 236]);

// Card 3 - Ganancia Líquida
$x3 = $x2 + $ancho_card + $gap_card;
$pdf->InfoCell($x3, $y_cards, $ancho_card, 20,
    'GANANCIA LÍQUIDA', $totales['ganancia'],
    [46, 204, 113], [46, 204, 113], [234, 250, 241]);

$pdf->SetY($y_cards + 24);
$pdf->Ln(4);

// ============================================================
// REPARTO 50/50 - Tabla profesional
// ============================================================
$pdf->SetDrawColor(200, 200, 200);
$pdf->SetFillColor(248, 248, 248);

// Título de sección
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 6, to_iso('DISTRIBUCIÓN DE GANANCIAS'), 0, 1, 'L');
$pdf->Ln(2);

// Tabla de reparto
$reparto_cols = [63, 63, 64];
$reparto_headers = ['Concepto', 'Monto', 'Detalle'];

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(50, 50, 50);
$pdf->SetTextColor(255, 255, 255);

foreach ($reparto_headers as $i => $h) {
    $align = ($i == 0) ? 'L' : 'R';
    $pdf->Cell($reparto_cols[$i], 7, to_iso($h), 1, 0, $align, true);
}
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);

// Filas del reparto
$pdf->SetFont('Arial', '', 9);
$pdf->SetFillColor(253, 237, 236);
$pdf->Cell($reparto_cols[0], 7, to_iso('Costo de Mercadería'), 1, 0, 'L', false);
$pdf->Cell($reparto_cols[1], 7, '$ ' . number_format($totales['costo'], 2, ',', '.'), 1, 0, 'R', false);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell($reparto_cols[2], 7, to_iso('Reembolso al proveedor por costo de productos'), 1, 1, 'R', false);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell($reparto_cols[0], 7, to_iso('50% Ganancia - Proveedor'), 1, 0, 'L', false);
$pdf->Cell($reparto_cols[1], 7, '$ ' . number_format($utilidad_negocio, 2, ',', '.'), 1, 0, 'R', false);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell($reparto_cols[2], 7, to_iso('Mitad de la ganancia generada'), 1, 1, 'R', false);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 9);
$pdf->SetFillColor(234, 250, 241);
$pdf->Cell($reparto_cols[0], 7, to_iso('50% Ganancia - Comercio'), 1, 0, 'L', true);
$pdf->Cell($reparto_cols[1], 7, '$ ' . number_format($utilidad_negocio, 2, ',', '.'), 1, 0, 'R', true);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell($reparto_cols[2], 7, to_iso('Utilidad neta para el negocio'), 1, 1, 'R', true);
$pdf->SetTextColor(0, 0, 0);

// Fila total a pagar al proveedor
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(40, 40, 40);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($reparto_cols[0], 9, to_iso('TOTAL A PAGAR AL PROVEEDOR'), 1, 0, 'L', true);
$pdf->Cell($reparto_cols[1], 9, '$ ' . number_format($total_proveedor, 2, ',', '.'), 1, 0, 'R', true);
$pdf->Cell($reparto_cols[2], 9, to_iso('Costo + 50% Ganancia'), 1, 1, 'R', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->Ln(6);

// ============================================================
// TABLA DE DETALLE DE PRODUCTOS
// ============================================================
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 6, to_iso('DETALLE DE PRODUCTOS VENDIDOS'), 0, 1, 'L');
$pdf->Ln(2);

$cols = [68, 16, 20, 20, 22, 22, 22];
$headers = ['Producto', 'Cant.', 'Vta. Unit.', 'Cos. Unit.', 'Vta. Total', 'Mi 50%', 'Prov. 50%'];

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(50, 50, 50);
$pdf->SetTextColor(255, 255, 255);
$x_start = 10;
foreach ($headers as $i => $h) {
    $align = ($i == 0) ? 'L' : 'R';
    $pdf->Cell($cols[$i], 7, to_iso($h), 1, 0, $align, true);
}
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);

// Filas
$pdf->SetFont('Arial', '', 7);
$fill = false;
foreach ($resultados as $r) {
    $ganancia_mitad = $r['ganancia_total'] / 2;
    
    if ($fill) {
        $pdf->SetFillColor(245, 245, 245);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    
    $desc = mb_substr($r['descripcion'], 0, 55);
    $pdf->Cell($cols[0], 6, to_iso($desc), 1, 0, 'L', $fill);
    $pdf->Cell($cols[1], 6, number_format($r['total_cant'], 2), 1, 0, 'R', $fill);
    $pdf->Cell($cols[2], 6, '$' . number_format($r['precio_venta'], 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell($cols[3], 6, '$' . number_format($r['costo_unitario'], 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell($cols[4], 6, '$' . number_format($r['subtotal_venta'], 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell($cols[5], 6, '$' . number_format($ganancia_mitad, 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell($cols[6], 6, '$' . number_format($ganancia_mitad, 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Ln();
    $fill = !$fill;
}

// Fila de totales
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(60, 60, 60);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cols[0], 7, to_iso('TOTALES'), 1, 0, 'L', true);
$pdf->Cell($cols[1], 7, '', 1, 0, 'R', true);
$pdf->Cell($cols[2], 7, '', 1, 0, 'R', true);
$pdf->Cell($cols[3], 7, '', 1, 0, 'R', true);
$pdf->Cell($cols[4], 7, '$' . number_format($totales['venta'], 2, ',', '.'), 1, 0, 'R', true);
$pdf->Cell($cols[5], 7, '$' . number_format($utilidad_negocio, 2, ',', '.'), 1, 0, 'R', true);
$pdf->Cell($cols[6], 7, '$' . number_format($utilidad_negocio, 2, ',', '.'), 1, 0, 'R', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(10);

// ============================================================
// FECHA DE GENERACIÓN
// ============================================================
$pdf->Ln(5);

// Fecha de generación
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 4, to_iso('Documento generado el ' . date('d/m/Y \a \l\a\s H:i:s')), 0, 1, 'C');
$pdf->Cell(0, 4, to_iso('Sistema de Gestión - Mi Negocio'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// --- SALIDA ---
$nombre_archivo = 'consignacion_' . str_replace(' ', '_', $prov_sel) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre_archivo);