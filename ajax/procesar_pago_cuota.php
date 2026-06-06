<?php
include '../pages/infosesion.php';
header('Content-Type: application/json');

// VALIDACIÓN DE PERMISOS
if (!tiene_permiso('pages/cobro_cuotas.php')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para procesar cobros.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_cuota = (int)$data['id_cuota'];
$id_venta = (int)$data['id_venta'];
$monto_pago = (float)$data['monto'];
$descuento = (float)$data['descuento'];
$metodo = $data['metodo'] ?? 'EFECTIVO';
$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';
$id_pago_generado = 0;

try {
    $pdo->beginTransaction();

    // 1. Obtener datos actuales de la cuota
    $stmt = $pdo->prepare("SELECT monto_original, monto_pagado, nro_cuota FROM cuotas_seguimiento WHERE id = ?");
    $stmt->execute([$id_cuota]);
    $cuota = $stmt->fetch();

    if (!$cuota) throw new Exception("Cuota no encontrada");

    // El impacto real en la deuda es el efectivo + el descuento otorgado
    $nuevo_pagado = $cuota['monto_pagado'] + $monto_pago + $descuento;
    
    // Comparamos el nuevo acumulado contra el valor original de la cuota
    $estado = ($nuevo_pagado >= $cuota['monto_original']) ? 'Pagada' : 'Parcial';

    // 2. Actualizar cuota
    $stmt_upd = $pdo->prepare("UPDATE cuotas_seguimiento SET monto_pagado = ?, estado = ? WHERE id = ?");
    $stmt_upd->execute([$nuevo_pagado, $estado, $id_cuota]);

    // 3. Obtener N° Documento de la venta para el detalle del movimiento
    $stmt_v = $pdo->prepare("SELECT n_documento FROM ventas WHERE id = ?");
    $stmt_v->execute([$id_venta]);
    $n_doc = $stmt_v->fetchColumn();

    // 4. Registrar Pago e Historial (Si hubo dinero o descuento)
    if ($monto_pago > 0 || $descuento > 0) {
        // A. Registrar el pago específico en el historial de la cuota
        $stmt_cp = $pdo->prepare("INSERT INTO cuotas_pagos (id_cuota, monto, descuento, metodo_pago, usuario) VALUES (?, ?, ?, ?, ?)");
        $stmt_cp->execute([$id_cuota, $monto_pago, $descuento, $metodo, $usuario]);
        $id_pago_generado = $pdo->lastInsertId();

        $detalle = "COBRO CUOTA {$cuota['nro_cuota']} - VENTA N° $n_doc";
        if ($descuento > 0) $detalle .= " (Desc. Interés: $$descuento)";

        // Solo registramos entrada de dinero en CAJA si el monto cobrado es mayor a 0
        if ($monto_pago > 0) {
            $sql_mov = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) 
                        VALUES ('INGRESO', ?, ?, ?, NOW(), ?, 0)";
            $pdo->prepare($sql_mov)->execute([$monto_pago, $metodo, $detalle, $usuario]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'id_pago' => $id_pago_generado]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}