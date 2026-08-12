<?php
// ajax/verificar_estado_caja.php
include '../pages/infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['abierta' => false, 'mensaje' => 'Sesión expirada']);
    exit();
}

$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);

if (!$estado) {
    echo json_encode([
        'abierta' => false, 
        'mensaje' => 'Caja no iniciada. Debe abrir la caja antes de continuar.'
    ]);
    exit();
}

if ($estado['estado'] === 'CERRADA') {
    echo json_encode([
        'abierta' => false, 
        'mensaje' => 'Caja cerrada. Debe abrir la caja para operar.'
    ]);
    exit();
}

echo json_encode([
    'abierta' => true,
    'mensaje' => 'Caja abierta',
    'datos' => $estado
]);
?>