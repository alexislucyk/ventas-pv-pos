<?php
// ajax/abrir_caja.php
include '../pages/infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

// Validar que sea una solicitud AJAX o POST
$es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$es_ajax && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Si se accede directamente, redirigir a la página de apertura
    header('Location: ../pages/abrir_caja.php');
    exit();
}

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario = $_SESSION['usuario'] ?? 'Sistema';

if (!$empresa_id) {
    echo json_encode(['success' => false, 'mensaje' => 'Sesión expirada']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'mensaje' => 'Método no permitido']);
    exit();
}

$saldo_inicial = (float)($_POST['saldo_inicial'] ?? 0);
$observaciones = trim($_POST['observaciones'] ?? '');

if ($saldo_inicial < 0) {
    echo json_encode(['success' => false, 'mensaje' => 'El saldo inicial no puede ser negativo']);
    exit();
}

$resultado = abrir_caja($pdo, $empresa_id, $sucursal_id, $saldo_inicial, $usuario);

echo json_encode($resultado);
?>