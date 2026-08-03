<?php
// pages/generar_pdf_lista_precios.php
// Genera un listado de precios en PDF con opciones de filtro

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

function to_iso($text) {
    if ($text === null) return '';
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

// Obtener datos de la empresa
$stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
$stmt_emp->execute([$empresa_id]);
$emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);
$nombre_empresa = !empty($emp['nombre_fantasia']) ? $emp['nombre_fantasia'] : 'Mi Negocio';

// --- PARÁMETROS DE FILTRO ---
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todo';
$valor = isset($_GET['valor']) ? trim($_GET['valor']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- CONSULTAR PRODUCTOS SEGÚN FILTRO ---
$productos = [];
$titulo_filtro = '';

// Helper para construir la consulta base
function ejecutarConsulta($pdo, $where_conditions, $params, $sucursal_id) {
    if ($sucursal_id == 0) {
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_venta, p.moneda, p.rubro, p.proveedor,
                       COALESCE((SELECT SUM(s2.stock_actual) FROM stocks s2 WHERE s2.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci), 0) AS stock 
                FROM productos p 
                WHERE " . implode(' AND ', $where_conditions) . "
                ORDER BY p.descripcion
                LIMIT 500";
    } else {
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_venta, p.moneda, p.rubro, p.proveedor, COALESCE(s.stock_actual, 0) AS stock 
                FROM productos p 
                LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.sucursal_id = :sucursal_id
                WHERE " . implode(' AND ', $where_conditions) . "
                ORDER BY p.descripcion
                LIMIT 500";
        $params[':sucursal_id'] = $sucursal_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

switch ($tipo) {
    case 'todo':
        // Todos los productos
        $productos = ejecutarConsulta($pdo, ['p.empresa_id = :empresa_id'], [':empresa_id' => $empresa_id], $sucursal_id);
        $titulo_filtro = 'Todos los productos';
        break;

    case 'rubro':
        // Por rubro
        if (!empty($valor)) {
            $productos = ejecutarConsulta(
                $pdo,
                ['p.empresa_id = :empresa_id', 'p.rubro = :rubro'],
                [':empresa_id' => $empresa_id, ':rubro' => $valor],
                $sucursal_id
            );
            $titulo_filtro = 'Rubro: ' . $valor;
        }
        break;

    case 'proveedor':
        // Por proveedor
        if (!empty($valor)) {
            $productos = ejecutarConsulta(
                $pdo,
                ['p.empresa_id = :empresa_id', 'TRIM(p.proveedor) = :proveedor'],
                [':empresa_id' => $empresa_id, ':proveedor' => $valor],
                $sucursal_id
            );
            $titulo_filtro = 'Proveedor: ' . $valor;
        }
        break;

    case 'busqueda':
    default:
        // Por búsqueda de texto
        if (!empty($q)) {
            $param_busqueda = '%' . $q . '%';
            $productos = ejecutarConsulta(
                $pdo,
                ['p.empresa_id = :empresa_id', '(p.cod_prod LIKE :q OR p.descripcion LIKE :q2)'],
                [':empresa_id' => $empresa_id, ':q' => $param_busqueda, ':q2' => $param_busqueda],
                $sucursal_id
            );
            $titulo_filtro = 'Búsqueda: "' . $q . '"';
        }
        break;
}

// Procesar conversión de dólar si aplica
$cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
$dolar_operativo = null;
if (file_exists($cache_path)) {
    $cache = json_decode(file_get_contents($cache_path), true);
    if (is_array($cache) && isset($cache['venta']) && is_numeric($cache['venta'])) {
        $dolar_operativo = (float)$cache['venta'] * 1.02;
    }
}

foreach ($productos as &$p) {
    if (($p['moneda'] ?? '') === 'dolar' && $dolar_operativo && $dolar_operativo > 0) {
        $p['p_venta_pesos'] = (float)$p['p_venta'] * $dolar_operativo;
    } else {
        $p['p_venta_pesos'] = null;
    }
}
unset($p);

// --- CREAR PDF ---
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetMargins(10, 10, 10);
$ancho_total = 190;

// --- ENCABEZADO ---
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 8, to_iso($nombre_empresa), 0, 1, 'C');

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, to_iso('LISTADO DE PRECIOS'), 0, 1, 'C');

$pdf->SetFont('Arial', '', 7);
$pdf->Cell(0, 4, to_iso('Fecha: ' . date('d/m/Y H:i')), 0, 1, 'C');

if (!empty($titulo_filtro)) {
    $pdf->Cell(0, 4, to_iso($titulo_filtro), 0, 1, 'C');
}

$pdf->Cell(0, 4, to_iso('Cantidad de productos: ' . count($productos)), 0, 1, 'C');

$pdf->Ln(2);

// Línea divisoria
$pdf->SetDrawColor(0, 188, 212);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);

// --- TABLA DE PRODUCTOS ---
if (count($productos) === 0) {
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 20, to_iso('No se encontraron productos con el criterio seleccionado.'), 0, 1, 'C');
} else {
    // Encabezados de tabla (solo Descripción y Precio Venta)
    $pdf->SetFillColor(0, 188, 212);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    
    $col_desc = 130;
    $col_precio = 50;
    $x_start = 10;
    
    $pdf->SetX($x_start);
    $pdf->Cell($col_desc, 10, to_iso('Descripción'), 1, 0, 'C', true);
    $pdf->Cell($col_precio, 10, to_iso('Precio Venta'), 1, 1, 'C', true);
    
    // Filas de datos
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $fill = false;
    
    foreach ($productos as $prod) {
        // Determinar precio a mostrar
        $precio_mostrar = (float)$prod['p_venta'];
        $moneda_simbolo = '$';
        if (!empty($prod['p_venta_pesos'])) {
            $precio_mostrar = (float)$prod['p_venta_pesos'];
        } elseif (($prod['moneda'] ?? '') === 'dolar') {
            $moneda_simbolo = 'U$S';
        }
        
        $precio_txt = $moneda_simbolo . ' ' . number_format($precio_mostrar, 2, ',', '.');
        
        $y_antes = $pdf->GetY();
        
        // Verificar si necesitamos nueva página
        if ($y_antes > 250) {
            $pdf->AddPage();
            $pdf->SetFillColor(0, 188, 212);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetX($x_start);
            $pdf->Cell($col_desc, 10, to_iso('Descripción'), 1, 0, 'C', true);
            $pdf->Cell($col_precio, 10, to_iso('Precio Venta'), 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 10);
            $y_antes = $pdf->GetY();
        }
        
        $alto_fila = 8;
        
        if ($fill) {
            $pdf->SetFillColor(245, 245, 245);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        
        $pdf->SetX($x_start);
        $pdf->Cell($col_desc, $alto_fila, to_iso($prod['descripcion']), 1, 0, 'L', $fill);
        $pdf->Cell($col_precio, $alto_fila, to_iso($precio_txt), 1, 1, 'R', $fill);
        
        $fill = !$fill;
    }
}

// --- PIE DE PÁGINA ---
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, to_iso('Documento generado el ' . date('d/m/Y H:i')), 0, 1, 'C');
$pdf->Cell(0, 5, to_iso('Sistema de Gestión POS'), 0, 1, 'C');

// Salida
$dest = isset($_GET['download']) ? 'D' : 'I';
$nombre_archivo = 'lista_precios_' . date('Ymd_His') . '.pdf';
$pdf->Output($dest, $nombre_archivo);