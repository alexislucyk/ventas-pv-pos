<?php
header('Content-Type: application/json');
session_start();

// Verifica sesión (seguridad)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso no autorizado.']);
    exit();
}

require '../config/db_config.php'; // Ajusta esta ruta si es necesario (asumo que está dos niveles arriba)

// 1. Obtener y Validar Datos POST
$id_proveedor = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT);
$monto_pago   = filter_input(INPUT_POST, 'monto_pago', FILTER_VALIDATE_FLOAT);
$tipo_pago    = filter_input(INPUT_POST, 'tipo_pago', FILTER_SANITIZE_STRING);
$ref_pago     = filter_input(INPUT_POST, 'ref_pago', FILTER_SANITIZE_STRING);
$fecha_pago   = date('Y-m-d H:i:s');

if (!$id_proveedor || $monto_pago <= 0) {
    echo json_encode(['exito' => false, 'mensaje' => 'Datos de pago inválidos o faltantes.']);
    exit();
}

// 2. Ejecutar Transacción (opcional, pero buena práctica si hubiera más operaciones)
try {
    $pdo->beginTransaction();

    // 3. Registrar el Pago en ctacte_proveedores
    // Un PAGO se registra en la columna DEBE, ya que reduce la deuda de la empresa (HABER)
    $sql = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha) 
            VALUES (:id_prov, :mov, :monto, 0, :ref_doc, :fecha)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_prov' => $id_proveedor,
        ':mov'     => 'PAGO (' . $tipo_pago . ')', // Ej: PAGO (Efectivo)
        ':monto'   => $monto_pago,
        ':ref_doc' => empty($ref_pago) ? 'PAGO AUTOMÁTICO' : $ref_pago,
        ':fecha'   => $fecha_pago
    ]);

    // 4. Confirmar la Transacción
    $pdo->commit();

    echo json_encode([
        'exito' => true, 
        'mensaje' => "✅ Pago de $" . number_format($monto_pago, 2, ',', '.') . " registrado con éxito."
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    error_log("Error al registrar pago a proveedor: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => '❌ Error de base de datos al registrar el pago.']);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    error_log("Error general al registrar pago a proveedor: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => '❌ Ocurrió un error inesperado.']);
}

?>