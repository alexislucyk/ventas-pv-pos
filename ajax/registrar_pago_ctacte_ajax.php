<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';
header('Content-Type: application/json');

// VALIDACIÓN DE PERMISOS
if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] !== 'developer' && !tiene_permiso('pages/pagos_ctacte.php'))) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para registrar pagos.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$id_cliente = filter_var($data['id_cliente'] ?? null, FILTER_VALIDATE_INT);
$monto_pago = filter_var($data['monto_pago'] ?? null, FILTER_VALIDATE_FLOAT);
$n_recibo_raw = trim($data['n_recibo'] ?? '');
$condicion_pago = $data['condicion_pago'] ?? 'Efectivo'; // Aunque no se usa en ctacte, se guarda para consistencia
$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';

// Lógica para evitar el '0' en n_documento:
// Si viene vacío, buscamos el último n_documento de un pago para seguir la correlatividad
if ($n_recibo_raw === '') {
    $stmt_max = $pdo->query("SELECT MAX(n_documento) FROM ctacte WHERE movimiento = 'Pago Cta.Cte.'");
    $ultimo_n = (int)$stmt_max->fetchColumn();
    $n_recibo = ($ultimo_n > 0) ? $ultimo_n + 1 : 1;
} else {
    $n_recibo = $n_recibo_raw;
}

$movimiento = "Pago Cta.Cte.";
$fecha_movimiento = date('Y-m-d H:i:s');
$cero = 0; // El campo 'debe' es 0 para un pago

// Validaciones básicas
if (!$id_cliente || $monto_pago <= 0) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos (cliente o monto).']);
    exit();
}

try {
    $pdo->beginTransaction();

    $sql = "
        INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha, usuario)
        VALUES (:id_cliente, :movimiento, :n_doc, :debe, :haber, :fecha, :usuario)
    ";
    
    $stmt = $pdo->prepare($sql);
    
    $stmt->bindValue(':id_cliente', $id_cliente, PDO::PARAM_INT);
    $stmt->bindValue(':movimiento', $movimiento, PDO::PARAM_STR);
    $stmt->bindValue(':n_doc', $n_recibo, PDO::PARAM_STR);
    $stmt->bindValue(':debe', $cero, PDO::PARAM_INT);
    $stmt->bindValue(':haber', $monto_pago, PDO::PARAM_STR);
    $stmt->bindParam(':fecha', $fecha_movimiento, PDO::PARAM_STR);
    $stmt->bindParam(':usuario', $usuario, PDO::PARAM_STR);
    
    $stmt->execute();

    $id_movimiento_generado = $pdo->lastInsertId();

    // Registrar en tabla de movimientos de caja si es efectivo o transferencia
    if ($condicion_pago === 'Efectivo' || $condicion_pago === 'Transferencia') {
        $sql_mov_caja = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) 
                         VALUES ('INGRESO', ?, ?, ?, ?, ?, 0)";
        $pdo->prepare($sql_mov_caja)->execute([
            $monto_pago,
            $condicion_pago,
            "PAGO CTA. CTE. CLIENTE #$id_cliente (Recibo $n_recibo)",
            $fecha_movimiento,
            $usuario
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'id_movimiento' => $id_movimiento_generado]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al registrar pago CC (AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error general al registrar pago CC (AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ocurrió un error inesperado: ' . $e->getMessage()]);
}
?>