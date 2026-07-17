<?php
// cambiar_empresa.php - Cambiar empresa activa en sesion
require_once '../config/db_config.php';
// session_start() ya está activo en db_config.php

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$empresa_id = $_POST['empresa_id'] ?? null;

if (!$empresa_id || !is_numeric($empresa_id)) {
    echo json_encode(['success' => false, 'error' => 'Empresa invalida']);
    exit;
}

// Verificar que el usuario existe (sin restricción de empresa para desarrollo)
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);

if ($stmt->fetch()) {
    // Guardar nueva empresa en sesión
    $_SESSION['empresa_id'] = (int)$empresa_id;
    
    // Forzar escritura de sesión
    session_write_close();
    
    // Retornar el ID de empresa guardado para verificación
    echo json_encode(['success' => true, 'empresa_id' => (int)$empresa_id, 'message' => 'Empresa cambiada correctamente']);
} else {
    echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
}
