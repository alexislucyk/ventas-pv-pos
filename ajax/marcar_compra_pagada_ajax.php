<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'mensaje' => 'Acceso no autorizado.']);
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'mensaje' => 'Método no permitido.']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$id_proveedor = filter_var($data['id_proveedor'] ?? 0, FILTER_VALIDATE_INT);
$n_documento = htmlspecialchars($data['n_documento'] ?? '');
$monto_pendiente = filter_var($data['monto_pendiente'] ?? 0, FILTER_VALIDATE_FLOAT);
$id_compra = filter_var($data['compra_id'] ?? null, FILTER_VALIDATE_INT);
$usuario_id = $_SESSION['usuario_id'] ?? 0;
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Sistema';

if (!$id_proveedor || empty($n_documento) || $monto_pendiente <= 0) {
    echo json_encode(['success' => false, 'mensaje' => 'Datos inválidos o faltantes para marcar como pagado.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Insertar un movimiento de "pago" en ctacte_proveedores para saldar la factura
    $sql_pago_ajuste = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id)
                        VALUES (?, ?, ?, 0, ?, NOW(), ?, ?)";
    $stmt_pago_ajuste = $pdo->prepare($sql_pago_ajuste);
    $stmt_pago_ajuste->execute([
        $id_proveedor,
        "AJUSTE - FACTURA $n_documento MARCADA COMO PAGADA",
        $monto_pendiente,
        $n_documento,
        $usuario_id,
        $id_compra
    ]);

    // Registrar un egreso en movimientos de caja (asumiendo que el pago se hizo "fuera de sistema" o se regulariza)
    $sql_mov_caja = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado)
                     VALUES ('EGRESO', ?, 'AJUSTE', ?, NOW(), ?, 0)";
    $stmt_mov_caja = $pdo->prepare($sql_mov_caja);
    $stmt_mov_caja->execute([
        $monto_pendiente,
        "AJUSTE - PAGO FACTURA PROVEEDOR $n_documento (MARCADO COMO PAGADO)",
        $usuario_nombre
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'mensaje' => "Factura N° $n_documento marcada como pagada con éxito."]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error al marcar compra como pagada: " . $e->getMessage());
    echo json_encode(['success' => false, 'mensaje' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>