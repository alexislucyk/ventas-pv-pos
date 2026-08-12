<?php
// archivo: pages/generar_pdf_presupuesto.php

if (ob_get_contents()) ob_end_clean();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

function to_iso($text) {
    return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id === 0) {
    die("ID de presupuesto no valido.");
}

$stmt = $pdo->prepare("SELECT p.*, c.* FROM presupuestos p 
                       JOIN clientes c ON p.id_cliente = c.id AND c.empresa_id = ?
                       WHERE p.id = ? AND p.empresa_id = ?");
$stmt->execute([$empresa_id, $id, $empresa_id]);
$presu = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presu) {
    die("Error: No se encontraron datos para el presupuesto ID: " . $id);
}

// 2.1. Buscamos datos de la empresa y sucursal principal de forma dinámica
try {
    $empresa_id = $_SESSION['empresa_id'] ?? 1;
    $stmt_emp = $pdo->prepare("SELECT nombre_fantasia, cuit, direccion, localidad, telefono FROM empresas WHERE id = ? LIMIT 1");
    $stmt_emp->execute([$empresa_id]);
    $emp_d = $stmt_emp->fetch(PDO::FETCH_ASSOC);
    
    // Buscamos el email en la sucursal principal (ya que no está en empresas)
    $stmt_suc = $pdo->query("SELECT email FROM sucursales WHERE es_principal = 1 LIMIT 1");
    $suc_d = $stmt_suc->fetch(PDO::FETCH_ASSOC);
    
    $nombreEmpresa = !empty($emp_d['nombre_fantasia']) ? $emp_d['nombre_fantasia'] : 'Mi Negocio';
    
    // Construimos la dirección completa usando los campos de empresas
    $dirEmpresa    = !empty($emp_d['direccion']) ? $emp_d['direccion'] . (!empty($emp_d['localidad']) ? ' - ' . $emp_d['localidad'] : '') : 'Dirección no configurada';
    
    $telEmpresa    = !empty($emp_d['telefono']) ? $emp_d['telefono'] : '';
    $emailEmpresa  = !empty($suc_d['email']) ? $suc_d['email'] : '';
} catch (Exception $e) {
    $nombreEmpresa = 'Mi Negocio';
    $dirEmpresa = 'Error al cargar dirección';
}

// 3. Mapeo de datos (Usando los nombres exactos de tu base de datos)
$clienteNombre = $presu['apellido'] . " " . $presu['nombre'];
$clienteDoc    = $presu['cuit']; 
$fechaPresu    = $presu['fecha_presupuesto'];
$totalPresu    = $presu['total_presupuesto'];
$fechaFormateada = date("d/m/Y", strtotime($fechaPresu));

// 4. Iniciamos el PDF
$pdf = new FPDF();
$pdf->AddPage();

// --- ENCABEZADO DE LA EMPRESA DINÁMICO ---
$pdf->SetFont('Arial', 'B', 20); 
$pdf->Cell(0, 10, to_iso($nombreEmpresa), 0, 1, 'L'); 

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, to_iso('Dirección: ' . $dirEmpresa), 0, 1, 'L');
$pdf->Cell(0, 5, to_iso('Teléfono: ' . $telEmpresa . ($emailEmpresa ? ' | Email: ' . $emailEmpresa : '')), 0, 1, 'L');

// Línea divisoria decorativa
$pdf->Line(10, 35, 200, 35); 
$pdf->Ln(10); // Salto de línea

// 3. Mapeo de datos (Usando los nombres exactos de tu base de datos)
$clienteNombre = $presu['apellido'] . " " . $presu['nombre'];
$clienteDoc    = $presu['cuit']; 
$fechaPresu    = $presu['fecha_presupuesto'];
$totalPresu    = $presu['total_presupuesto'];

// --- TÍTULO DEL DOCUMENTO (Más chico como pediste) ---
$pdf->SetFont('Arial', 'B', 12); // Bajamos de 16 a 12
$pdf->Cell(0, 10, to_iso('PRESUPUESTO # ' . $id), 0, 1, 'R'); // Lo moví a la derecha para que quede profesional
$pdf->Ln(5);

// Datos del Cliente (Sin cambios, pero revisa el interlineado)
//$pdf->SetFont('Arial', 'B', 10);
//$pdf->Cell(0, 6, utf8_decode('DATOS DEL CLIENTE:'), 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 7, to_iso('Nombre: ' . $clienteNombre), 0, 1);
$pdf->Cell(0, 7, to_iso('Domicilio: ' . (!empty($presu['direccion']) ? $presu['direccion'] : '-')), 0, 1);
$pdf->Cell(0, 7, to_iso('CUIT/DNI: ' . $clienteDoc), 0, 1);
$pdf->Cell(0, 7, to_iso('Fecha: ' . $fechaFormateada), 0, 1);
$pdf->Ln(5);

// 5. Tabla de productos
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(110, 10, to_iso('Descripción'), 1, 0, 'C', true);
$pdf->Cell(15, 10, 'Cant', 1, 0, 'C', true);
$pdf->Cell(30, 10, 'Precio U.', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Subtotal', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 10);

// Buscamos los productos del detalle
// OJO: Asegurate que la tabla se llame 'presupuestos_detalle' y no 'presupuesto_detalle'
$stmtDetalle = $pdo->prepare("SELECT * FROM presupuestos_detalle WHERE id_presupuesto = ? AND empresa_id = ?");
$stmtDetalle->execute(array($id, $empresa_id));

while ($row = $stmtDetalle->fetch(PDO::FETCH_ASSOC)) {
    // Verificamos nombres de columnas del detalle según tu tabla
    $cant  = $row['cantidad'];
    $prec  = $row['precio_unitario'];
    $desc_porc = isset($row['descuento_unitario']) ? $row['descuento_unitario'] : 0;
    $sub_bruto = $cant * $prec;
    $monto_desc = $sub_bruto * ($desc_porc / 100);
    $sub_neto = $sub_bruto - $monto_desc;

    $pdf->Cell(110, 8, to_iso($row['descripcion']), 1);
    $pdf->Cell(15, 8, $cant, 1, 0, 'C');
    $pdf->Cell(30, 8, '$ ' . number_format((float)($prec ?? 0), 2, ',', '.'), 1, 0, 'R');
    $pdf->Cell(35, 8, '$ ' . number_format((float)($sub_neto ?? 0), 2, ',', '.'), 1, 1, 'R');
    
    if ($desc_porc > 0) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(110, 5, '', 0);
        $pdf->Cell(80, 5, to_iso("Descuento aplicado: " . (float)$desc_porc . "% (-$" . number_format($monto_desc, 2, ',', '.') . ")"), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
    }
}

// 6. Fila de Total
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(155, 10, 'TOTAL', 0, 0, 'R');
$pdf->Cell(35, 10, '$ ' . number_format((float)($totalPresu ?? 0), 2, ',', '.'), 1, 1, 'R');

// --- DESPUÉS DEL TOTAL ---

if (!empty($presu['observaciones'])) {
    $pdf->Ln(10); // Espacio después del total
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, to_iso('OBSERVACIONES:'), 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    // Usamos MultiCell por si el comentario es muy largo, para que haga salto de línea automático
    $pdf->MultiCell(0, 5, to_iso($presu['observaciones']), 1, 'L');
}

/* // Mensaje de validez (Opcional)
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 5, to_iso('Este presupuesto tiene una validez de 15 días.'), 0, 1, 'C'); */


// 7. Salida del PDF - 'I' fuerza la visualizacion en el navegador
$pdf->Output('I', 'presupuesto_' . $id . '.pdf');
exit;