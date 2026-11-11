<?php
// eliminar_venta_pendiente.php
// Elimina una venta pendiente (de la lista de espera) SIN afectar el stock.
// Solo se permite eliminar ventas con estado 'Pendiente'.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Debe iniciar sesión.']);
    exit();
}

require '../config/db_config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Conexión a BD no disponible.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$n_documento = $input['n_documento'] ?? null;

if (!$n_documento) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Número de documento requerido.']);
    exit();
}

try {
    // Solo eliminar si está en estado Pendiente y pertenece a la empresa
    $pdo->beginTransaction();

    // 1. Borrar detalle de la venta
    $stmtDet = $pdo->prepare(
        "DELETE FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?"
    );
    $stmtDet->execute([$n_documento, $empresa_id]);

    // 2. Borrar la venta solo si está Pendiente
    $stmtVen = $pdo->prepare(
        "DELETE FROM ventas WHERE n_documento = ? AND empresa_id = ? AND estado = 'Pendiente'"
    );
    $stmtVen->execute([$n_documento, $empresa_id]);

    if ($stmtVen->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Venta pendiente no encontrada o ya no está en espera.']);
        exit();
    }

    // 3. Limpiar tablas relacionadas si existen (financiación, cuotas, etc.)
    $pdo->prepare("DELETE FROM ventas_financiacion WHERE id_venta IN (SELECT id FROM ventas WHERE n_documento = ? AND empresa_id = ?)")->execute([$n_documento, $empresa_id]);
    $pdo->prepare("DELETE FROM cuotas_seguimiento WHERE id_venta IN (SELECT id FROM ventas WHERE n_documento = ? AND empresa_id = ?)")->execute([$n_documento, $empresa_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Venta eliminada de la lista de espera.']);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error al eliminar venta pendiente: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno al eliminar la venta.']);
}