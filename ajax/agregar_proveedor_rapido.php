<?php
// ajax/agregar_proveedor_rapido.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod_prov = trim($_POST['cod_prov'] ?? '');
    $razon = trim($_POST['razon'] ?? '');
    $cuit = trim($_POST['cuit'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');

    if (empty($cod_prov) || empty($razon)) {
        echo json_encode(['success' => false, 'error' => 'Código y Razón Social son obligatorios.']);
        exit;
    }

    try {
        // Verificar si el código ya existe
        $stmt_check = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE cod_prov = ?");
        $stmt_check->execute([$cod_prov]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'El código de proveedor ya existe.']);
            exit;
        }

        $sql = "INSERT INTO proveedores (cod_prov, razon, cuit, telefono) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cod_prov, $razon, $cuit, $telefono]);
        
        echo json_encode([
            'success' => true, 
            'id_proveedor' => $cod_prov, 
            'nombre' => $razon,
            'cuit' => $cuit
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}