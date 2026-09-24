<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';
require_once '../funciones/funciones_pagos_ctacte.php';
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
    $resultado = registrarPagoCuentaCorriente($pdo, [
        'id_cliente' => (int)$id_cliente,
        'empresa_id' => (int)($_SESSION['empresa_id'] ?? 0),
        'monto_pago' => (float)$monto_pago,
        'n_recibo' => (string)$n_recibo,
        'fecha' => $fecha_movimiento,
        'usuario' => $usuario,
        'origen' => 'ajax'
    ], $data['imputaciones'] ?? [], function($pagoId) use ($pdo, $monto_pago, $condicion_pago, $n_recibo, $id_cliente, $fecha_movimiento, $usuario) {
        if ($condicion_pago === 'Efectivo' || $condicion_pago === 'Transferencia') {
            $pdo->prepare("INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id)
                           VALUES ('INGRESO', ?, ?, ?, ?, ?, 0, ?, ?)")
                ->execute([$monto_pago, $condicion_pago, "PAGO CTA. CTE. CLIENTE #$id_cliente (Recibo $n_recibo)", $fecha_movimiento, $usuario, (int)($_SESSION['empresa_id'] ?? 0), (int)($_SESSION['sucursal_id'] ?? 1)]);
        }
    });
    echo json_encode(['success' => true, 'id_movimiento' => $resultado['pago_id'], 'imputaciones' => $resultado]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al registrar pago CC (AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>