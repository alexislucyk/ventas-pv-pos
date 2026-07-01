<?php
// cambiar_empresa.php - Cambiar empresa activa en sesion
require_once '../config/db_config.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$empresa_id = $_POST['empresa_id'] ?? null;

if (!$empresa_id || !is_numeric($empresa_id)) {
    echo json_encode(['success' => false, 'error' => 'Empresa invalida']);
    exit;
}

// Verificar que el usuario pertenece a esa empresa
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND empresa_id = ?");
$stmt->execute([$_SESSION['usuario_id'], $empresa_id]);

if ($stmt->fetch()) {
    $_SESSION['empresa_id'] = $empresa_id;
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No tiene acceso a esa empresa']);
}