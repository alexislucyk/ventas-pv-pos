<?php
// archivo: pages/generar_pdf_presupuesto.php

// 1. Limpieza absoluta del buffer para evitar que el PDF salga dañado o vacío
if (ob_get_contents()) ob_end_clean();

date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

// Validar ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id === 0) {
    die("ID de presupuesto no valido.");
}

// 2. Buscamos datos del presupuesto y del cliente
// Usamos array($id) porque PHP 5 a veces falla con el corchete corto [] en execute
$stmt = $pdo->prepare("SELECT p.*, c.* FROM presupuestos p 
                       JOIN clientes c ON p.id_cliente = c.id 
                       WHERE p.id = ?");
$stmt->execute(array($id));
$presu = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presu) {
    die("Error: No se encontraron datos para el presupuesto ID: " . $id);
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

// --- ENCABEZADO DE LA EMPRESA ---
$pdf->SetFont('Arial', 'B', 20); 
$pdf->Cell(0, 10, utf8_decode('Electricidad Lucyk'), 0, 1, 'L'); // Nombre grande a la izquierda

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, utf8_decode('Dirección: Av. San Martin 698 - El Nochero, Santa Fe'), 0, 1, 'L');
$pdf->Cell(0, 5, utf8_decode('Teléfono: (3491) 438555 | Email: alexislucyk@gmail.com'), 0, 1, 'L');

// Línea divisoria decorativa
$pdf->Line(10, 35, 200, 35); 
$pdf->Ln(10); // Salto de línea

// --- TÍTULO DEL DOCUMENTO (Más chico como pediste) ---
$pdf->SetFont('Arial', 'B', 12); // Bajamos de 16 a 12
$pdf->Cell(0, 10, utf8_decode('PRESUPUESTO # ' . $id), 0, 1, 'R'); // Lo moví a la derecha para que quede profesional
$pdf->Ln(5);

// Datos del Cliente (Sin cambios, pero revisa el interlineado)
//$pdf->SetFont('Arial', 'B', 10);
//$pdf->Cell(0, 6, utf8_decode('DATOS DEL CLIENTE:'), 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 7, utf8_decode('Nombre: ' . $clienteNombre), 0, 1);
$pdf->Cell(0, 7, utf8_decode('CUIT/DNI: ' . $clienteDoc), 0, 1);
$pdf->Cell(0, 7, utf8_decode('Fecha: ' . $fechaFormateada), 0, 1);
$pdf->Ln(5);

// 5. Tabla de productos
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(25, 10, 'Cod', 1, 0, 'C', true);
$pdf->Cell(85, 10, utf8_decode('Descripción'), 1, 0, 'C', true);
$pdf->Cell(15, 10, 'Cant', 1, 0, 'C', true);
$pdf->Cell(30, 10, 'Precio U.', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Subtotal', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 10);

// Buscamos los productos del detalle
// OJO: Asegurate que la tabla se llame 'presupuestos_detalle' y no 'presupuesto_detalle'
$stmtDetalle = $pdo->prepare("SELECT * FROM presupuestos_detalle WHERE id_presupuesto = ?");
$stmtDetalle->execute(array($id));

while ($row = $stmtDetalle->fetch(PDO::FETCH_ASSOC)) {
    // Verificamos nombres de columnas del detalle según tu tabla
    $cant  = $row['cantidad'];
    $prec  = $row['precio_unitario'];
    $sub   = $cant * $prec;

    $pdf->Cell(25, 8, $row['cod_prod'], 1);
    $pdf->Cell(85, 8, utf8_decode($row['descripcion']), 1);
    $pdf->Cell(15, 8, $cant, 1, 0, 'C');
    $pdf->Cell(30, 8, '$ ' . number_format($prec, 2, ',', '.'), 1, 0, 'R');
    $pdf->Cell(35, 8, '$ ' . number_format($sub, 2, ',', '.'), 1, 1, 'R');
}

// 6. Fila de Total
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(155, 10, 'TOTAL', 0, 0, 'R');
$pdf->Cell(35, 10, '$ ' . number_format($totalPresu, 2, ',', '.'), 1, 1, 'R');

// --- DESPUÉS DEL TOTAL ---

if (!empty($presu['observaciones'])) {
    $pdf->Ln(10); // Espacio después del total
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, utf8_decode('OBSERVACIONES:'), 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    // Usamos MultiCell por si el comentario es muy largo, para que haga salto de línea automático
    $pdf->MultiCell(0, 5, utf8_decode($presu['observaciones']), 1, 'L');
}

// Mensaje de validez (Opcional)
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 5, utf8_decode('Este presupuesto tiene una validez de 15 días.'), 0, 1, 'C');


// 7. Salida del PDF - 'I' fuerza la visualizacion en el navegador
$pdf->Output('I', 'presupuesto_' . $id . '.pdf');
exit;