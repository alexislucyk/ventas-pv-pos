<?php
// ajax/anular_compra_ajax.php
// Anula una compra rapida (sin detalle) y revierte sus efectos.
// Recibe JSON: { id_compra: int }
// Respuesta JSON: { success, mensaje }

include '../pages/infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_compras.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'mensaje' => 'Método no permitido']);
    exit;
}

// Permiso de la pagina desde la que se ejecuta la anulacion
require_permiso('pages/historial_compras.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Falta empresa_id en sesión.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Cuerpo JSON inválido.']);
    exit;
}

$id_compra = filter_var($data['id_compra'] ?? null, FILTER_VALIDATE_INT);

if (!$id_compra) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'ID de compra no válido.']);
    exit;
}

$resultado = anularCompraRapida($pdo, (int)$id_compra, (int)$empresa_id, $_SESSION['usuario_nombre'] ?? 'Sistema');
echo json_encode($resultado);
?>
