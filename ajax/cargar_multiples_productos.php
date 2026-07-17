<?php
header('Content-Type: application/json');
include_once '../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

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
        // Insertar producto SIN el campo stock (se maneja en tabla stocks)
        $stmt = $pdo->prepare(
            "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, fecha_ult_compra, rubro, proveedor, moneda, empresa_id)
             VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)"
        );
        // placeholders: cod_prod, descripcion, p_compra, p_venta, rubro, proveedor, moneda, empresa_id
        $stmt->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $rubro, $proveedor, $moneda, $empresa_id]);

        // Stock: capturar errores específicos de la carga a `stocks`
        try {
            $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)";
            $stmt_stock = $pdo->prepare($sql_stock);
            $stmt_stock->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);
        } catch (Exception $e) {
            $errores[] = $cod_prod . ' stock: ' . $e->getMessage();
            continue; // seguimos con el resto de productos
        }

        // DEBUG (temporal)
        // error_log('cargar_multiples_productos: cod_prod='.$cod_prod.' stock='.$stock);

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