<?php
// ajax/agregar_producto_rapido.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

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
        // Verificar si el código ya existe
        $stmt_check = $pdo->prepare("SELECT id FROM productos WHERE cod_prod = ?");
        $stmt_check->execute([$cod_prod]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'El código de producto ya existe.']);
            exit;
        }

        // Registro rápido: Guardamos con todos los campos requeridos
        $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, stock, fecha_ult_compra, rubro, proveedor) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor]);
        
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