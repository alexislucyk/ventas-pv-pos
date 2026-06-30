<?php
header('Content-Type: application/json');
include_once '../pages/infosesion.php';

$input = json_decode(file_get_contents('php://input'), true);
$proveedor = $input['proveedor'] ?? '';
$rubro = $input['rubro'] ?? '';
$moneda = $input['moneda'] ?? 'pesos';
$productos = $input['productos'] ?? [];

if (empty($proveedor) || empty($rubro)) {
    echo json_encode(['success' => false, 'error' => 'Faltan datos obligatorios']);
    exit;
}

$insertados = 0;
$errores = [];

foreach ($productos as $prod) {
    $cod_prod = trim($prod['cod'] ?? '');
    $descripcion = trim($prod['desc'] ?? '');
    $p_compra = (float)str_replace(',', '.', $prod['compra'] ?? $prod['venta']);
    $p_venta = (float)str_replace(',', '.', $prod['venta'] ?? $prod['compra']);
    $stock = (float)str_replace(',', '.', $prod['stock'] ?? 0);
    
    if (empty($cod_prod) || empty($descripcion)) continue;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, stock, fecha_ult_compra, rubro, proveedor, moneda) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)");
        $stmt->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $stock, $rubro, $proveedor, $moneda]);
        $insertados++;
    } catch (Exception $e) {
        $errores[] = $cod_prod . ': ' . $e->getMessage();
    }
}

if ($insertados > 0) {
    $msg = "Se insertaron $insertados productos correctamente";
    if (!empty($errores)) $msg .= ". Errores: " . implode(', ', $errores);
    echo json_encode(['success' => true, 'message' => $msg]);
} else {
    echo json_encode(['success' => false, 'error' => 'No se insertó ningún producto']);
}