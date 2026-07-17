<?php
// ajax/agregar_producto_rapido.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod_prod = trim($_POST['cod_prod'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $p_compra = (float)str_replace(',', '.', $_POST['p_compra'] ?? '0');
    $p_venta = (float)str_replace(',', '.', $_POST['p_venta'] ?? '0');
    $stock = (float)str_replace(',', '.', $_POST['stock'] ?? '0');
    $fecha_ult_compra = $_POST['fecha_ult_compra'] ?? date('Y-m-d');
    $rubro = trim($_POST['rubro'] ?? 'VARIOS');
    $proveedor = trim($_POST['proveedor'] ?? 'GENERAL');

    if (empty($cod_prod) || empty($descripcion) || $p_venta <= 0) {
        echo json_encode(['success' => false, 'error' => 'Código, descripción y precio son obligatorios.']);
        exit;
    }

    try {
        $stmt_check = $pdo->prepare("SELECT id FROM productos WHERE cod_prod = ? AND empresa_id = ?");
        $stmt_check->execute([$cod_prod, $empresa_id]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'El código de producto ya existe para esta empresa.']);
            exit;
        }

        // Insertar producto incluyendo stock (para evitar desincronía si la BD tiene productos.stock NOT NULL)
        $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, stock, fecha_ult_compra, rubro, proveedor, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor, $empresa_id]);

        $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                      ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)";
        $stmt_stock = $pdo->prepare($sql_stock);
        $stmt_stock->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);

        // Verificación: corroborar que exista stock y devolverlo
        $stmt_get = $pdo->prepare("SELECT stock_actual FROM stocks WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ? LIMIT 1");
        $stmt_get->execute([$empresa_id, $sucursal_id, $cod_prod]);
        $stock_db = $stmt_get->fetchColumn();
        if ($stock_db === false) {
            throw new Exception('No se pudo verificar stock en tabla stocks para cod_prod=' . $cod_prod);
        }

        // Verificación simple: si no se actualizó nada, es porque no existe la fila esperada en stocks
        echo json_encode([
            'success' => true, 
            'producto' => [
                'cod_prod' => $cod_prod, 
                'descripcion' => $descripcion, 
                'p_compra' => $p_compra, 
                'p_venta' => $p_venta, 
                'stock' => $stock
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
}