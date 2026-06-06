<?php
include '../pages/infosesion.php';
header('Content-Type: application/json');

// VALIDACIÓN DE PERMISOS
if (!tiene_permiso('pages/cobro_cuotas.php')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para anular cobros.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_pago = (int)($data['id_pago'] ?? 0);
$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';

try {
    $pdo->beginTransaction();

    // 1. Obtener datos del pago antes de borrarlo
    $stmt = $pdo->prepare("SELECT id_cuota, monto, descuento, metodo_pago FROM cuotas_pagos WHERE id = ?");
    $stmt->execute([$id_pago]);
    $pago = $stmt->fetch();

    if (!$pago) throw new Exception("El registro de pago no existe.");

    $id_cuota = $pago['id_cuota'];
    $monto_anular = (float)$pago['monto'] + (float)($pago['descuento'] ?? 0);

    // 2. Obtener datos de la cuota para recalcular el estado
    $stmt_c = $pdo->prepare("SELECT cs.id_venta, cs.nro_cuota, cs.monto_pagado, v.n_documento 
                             FROM cuotas_seguimiento cs 
                             JOIN ventas v ON cs.id_venta = v.id 
                             WHERE cs.id = ?");
    $stmt_c->execute([$id_cuota]);
    $cuota = $stmt_c->fetch();

    if (!$cuota) throw new Exception("Error al recuperar datos de la cuota vinculada.");

    // 3. Eliminar el registro del pago parcial
    $pdo->prepare("DELETE FROM cuotas_pagos WHERE id = ?")->execute([$id_pago]);

    // 4. Actualizar el saldo de la cuota y su estado
    $nuevo_pagado = max(0, $cuota['monto_pagado'] - $monto_anular);
    $nuevo_estado = ($nuevo_pagado <= 0) ? 'Pendiente' : 'Parcial';
    
    $stmt_upd = $pdo->prepare("UPDATE cuotas_seguimiento SET monto_pagado = ?, estado = ? WHERE id = ?");
    $stmt_upd->execute([$nuevo_pagado, $nuevo_estado, $id_cuota]);

    // 5. Registrar un EGRESO en Caja para compensar el dinero que se "devuelve"
    $detalle = "ANULACIÓN PAGO PARCIAL CUOTA {$cuota['nro_cuota']} - VENTA N° {$cuota['n_documento']}";
    $sql_mov = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) 
                VALUES ('EGRESO', ?, ?, ?, NOW(), ?, 0)";
    $pdo->prepare($sql_mov)->execute([$monto_anular, $pago['metodo_pago'], $detalle, $usuario]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al anular pago cuota: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}