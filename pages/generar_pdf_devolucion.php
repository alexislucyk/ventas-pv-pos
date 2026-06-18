<?php
// archivo: pages/generar_pdf_devolucion.php
session_start();
while (ob_get_level()) { ob_end_clean(); }
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

function to_iso($text) {
    if ($text === null) return '';
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

class PDF extends FPDF {
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, mb_convert_encoding("Comprobante de devolución de mercadería", 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
    }
}

$id_req = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo_req = isset($_GET['tipo']) ? $_GET['tipo'] : ''; 

if ($id_req > 0 && !empty($tipo_req)) {
    // Recuperar datos históricos de la DB
    try {
        $id_cliente_val = 0;
        // Consulta la tabla 'devoluciones' directamente usando op_n y cond_pago
        $stmt = $pdo->prepare("SELECT * FROM devoluciones WHERE op_n = ? AND cond_pago = ?");
        $stmt->execute([$id_req, $tipo_req]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) die("Registro no encontrado.");

        $id_cliente_val = $row['id_cliente'];
        $n_doc_ref = $row['n_documento_venta'];
        
        // Cargar los items desde la nueva tabla de detalle
        $stmt_items = $pdo->prepare("SELECT cod_prod, descripcion as `desc`, cantidad as cant, p_unit, subtotal as total FROM devoluciones_detalle WHERE id_devolucion = ?");
        $stmt_items->execute([$row['id']]); // Usamos el 'id' de la tabla 'devoluciones'
        $items_db = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $data = [
            'op_n' => $row['op_n'],
            'n_documento' => $row['n_documento_venta'],
            'id_cliente' => $row['id_cliente'],
            'items' => $items_db,
            'total_reintegrado' => $row['total_reintegrado'],
            'motivo' => $row['motivo'],
            'fecha' => $row['fecha']
        ];

    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
} elseif (isset($_SESSION['last_op_data'])) {
    $data = $_SESSION['last_op_data'];
    $data['fecha'] = date("Y-m-d H:i:s");
} else {
    die("No hay datos de operación.");
}

$n_doc = $data['n_documento'];
$op_n = $data['op_n'];
$items = $data['items']; // Array: [ ['desc' => '', 'cant' => X, 'p_unit' => X, 'total' => X], ... ]
$total_reintegrado = $data['total_reintegrado'];
$motivo = $data['motivo'];
$cliente_id = $data['id_cliente'];
$fecha_op = $data['fecha'];

try {
    // Consultar datos del cliente
    $stmt_c = $pdo->prepare("SELECT nombre, apellido, cuit FROM clientes WHERE id = ?");
    $stmt_c->execute([$cliente_id]);
    $cliente = $stmt_c->fetch(PDO::FETCH_ASSOC);
    $nom_cliente = $cliente ? (trim($cliente['apellido']).", ".trim($cliente['nombre'])) : "CONSUMIDOR FINAL";

    // Consulta de datos de la empresa
    $stmt_emp = $pdo->query("SELECT * FROM datos_empresa WHERE id = 1 LIMIT 1");
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    // --- CONFIGURACIÓN A5 ---
    $pdf = new PDF('P', 'mm', 'A5');
    $pdf->AddPage();
    $pdf->SetMargins(5, 5, 5);
    $ancho_total = 138;

    // --- ENCABEZADO ---
    $pdf->Rect(5, 5, $ancho_total, 32); 
    $pdf->Line(5 + ($ancho_total/2), 5, 5 + ($ancho_total/2), 37);

    // CUADRO DE LA "R" (De Devolución/Remito)
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY(5 + ($ancho_total/2) - 6, 5);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(12, 12, 'R', 1, 0, 'C', true);
    
    $pdf->SetXY(5 + ($ancho_total/2) - 15, 17);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->Cell(30, 3, to_iso("DOC. NO VÁLIDO"), 0, 0, 'C');

    // LADO IZQUIERDO: Empresa
    $pdf->SetXY(7, 8);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->MultiCell(($ancho_total/2) - 8, 5, to_iso(strtoupper($emp['nombre_fantasia'])), 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['direccion']), 0, 1, 'L');

    // LADO DERECHO: Datos Comprobante
    $pdf->SetXY(5 + ($ancho_total/2) + 5, 8);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(($ancho_total/2) - 10, 6, to_iso("COMPROBANTE DEVOLUCIÓN"), 0, 1, 'R');
    
    $pdf->SetX(5 + ($ancho_total/2) + 5);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(($ancho_total/2) - 10, 6, "OP" . chr(176) . " " . str_pad($op_n, 6, "0", STR_PAD_LEFT), 0, 1, 'R');
    
    $pdf->SetX(5 + ($ancho_total/2) + 5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(($ancho_total/2) - 10, 6, "Fecha: " . date("d/m/Y H:i", strtotime($fecha_op)), 0, 1, 'R');

    // --- DATOS DEL CLIENTE Y VENTA ORIGINAL ---
    $pdf->SetY(42);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($ancho_total, 6, to_iso("  DETALLE DE LA OPERACIÓN"), 1, 1, 'L', true);
    
    $pdf->Rect(5, 48, $ancho_total, 18);
    $pdf->SetY(49);
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "Cliente:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(60, 5, to_iso($nom_cliente), 0, 0, 'L');
    
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(20, 5, "Ref. Venta:", 0, 0, 'R');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, ($n_doc ? "#".$n_doc : "S/D"), 0, 1, 'L');
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(12, 5, "Motivo:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($motivo), 0, 1, 'L');

    $pdf->Ln(5);

    // --- TABLA DE ITEMS DEVUELTOS ---
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(15, 7, "CANT", 1, 0, 'C', true);
    $pdf->Cell(78, 7, to_iso("DESCRIPCIÓN DE PRODUCTO"), 1, 0, 'C', true);
    $pdf->Cell(22, 7, "P. UNIT", 1, 0, 'C', true);
    $pdf->Cell(23, 7, "SUBTOTAL", 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);

    if (empty($items)) {
        $pdf->Cell($ancho_total, 8, to_iso(" (Consulte el motivo para el detalle general de la operación) "), 1, 1, 'C');
    }

    foreach ($items as $item) {
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->SetXY($x + 15, $y);
        $pdf->MultiCell(78, 4, " " . to_iso($item['desc']), 0, 'L');
        $y_final = $pdf->GetY();
        $alto_fila = max(7, $y_final - $y + 1);

        $pdf->SetXY($x, $y);
        $pdf->Cell(15, $alto_fila, number_format($item['cant'], 2), 1, 0, 'C');
        $pdf->Cell(78, $alto_fila, '', 1, 0, 'L');
        $pdf->Cell(22, $alto_fila, "$ " . number_format($item['p_unit'], 2), 1, 0, 'R');
        $pdf->Cell(23, $alto_fila, "$ " . number_format($item['total'], 2), 1, 1, 'R');
        $pdf->SetY($y + $alto_fila);
    }

    // --- TOTAL ---
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(115, 8, "TOTAL REINTEGRADO: ", 0, 0, 'R');
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(23, 8, "$ " . number_format($total_reintegrado, 2, ',', '.'), 1, 1, 'R', true);

    $pdf->Output('I', 'Devolucion_OP' . $op_n . '.pdf');
} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}