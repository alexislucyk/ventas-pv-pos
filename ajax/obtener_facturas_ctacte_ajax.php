<?php
header('Content-Type: application/json; charset=utf-8');
include '../pages/infosesion.php';
require_once '../funciones/funciones_pagos_ctacte.php';

require_permiso('pages/pagos_ctacte.php');

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$idCliente = isset($_GET['id_cliente'])
    ? filter_var($_GET['id_cliente'], FILTER_VALIDATE_INT)
    : filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);

if ($empresaId <= 0 || !$idCliente) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

if (!tablaImputacionesCcExiste($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falta aplicar la migración 48 de imputaciones de cuenta corriente.']);
    exit;
}
if (!tablaCreditosAFavorCcExiste($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falta aplicar la migración 49 de saldos a favor de cuenta corriente.']);
    exit;
}

try {
    reconciliarSaldosAFavorCc($pdo, $empresaId, $idCliente, date('Y-m-d'), 'consulta_pago');

    $sql = "SELECT c.id,
                   c.fecha,
                   c.fecha_vencimiento,
                   c.movimiento,
                   c.n_documento,
                   c.debe,
                   c.haber,
                   COALESCE((
                       SELECT SUM(i.importe_aplicado)
                       FROM ctacte_pagos_imputaciones i
                       WHERE i.movimiento_deudor_id = c.id
                         AND i.empresa_id = c.empresa_id
                         AND i.id_cliente = c.id_cliente
                   ), 0) AS aplicado,
                   COALESCE((
                       SELECT SUM(a.importe_aplicado)
                       FROM ctacte_creditos_a_favor_aplicaciones a
                       WHERE a.movimiento_deudor_id = c.id
                         AND a.empresa_id = c.empresa_id
                         AND a.id_cliente = c.id_cliente
                   ), 0) AS aplicado_credito,
                   (c.debe - c.haber
                       - COALESCE((SELECT SUM(i.importe_aplicado)
                       FROM ctacte_pagos_imputaciones i
                       WHERE i.movimiento_deudor_id = c.id AND i.empresa_id = c.empresa_id AND i.id_cliente = c.id_cliente), 0)
                       - COALESCE((SELECT SUM(a.importe_aplicado)
                       FROM ctacte_creditos_a_favor_aplicaciones a
                       WHERE a.movimiento_deudor_id = c.id AND a.empresa_id = c.empresa_id AND a.id_cliente = c.id_cliente), 0)
                   ) AS saldo_disponible
            FROM ctacte c
            WHERE c.id_cliente = ?
              AND c.empresa_id = ?
              AND c.debe > c.haber
              AND DATE(c.fecha) <= CURDATE()
              AND LOWER(c.movimiento) NOT LIKE 'inter%por%mora%'
            HAVING saldo_disponible > 0.005
            ORDER BY c.fecha ASC, c.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idCliente, $empresaId]);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($facturas as &$factura) {
        $factura['id'] = (int)$factura['id'];
        $factura['debe'] = (float)$factura['debe'];
        $factura['haber'] = (float)$factura['haber'];
        $factura['aplicado'] = (float)$factura['aplicado'];
        $factura['aplicado_credito'] = (float)$factura['aplicado_credito'];
        $factura['saldo_disponible'] = round((float)$factura['saldo_disponible'], 2);
    }
    unset($factura);

    $saldoContable = (float)$pdo->query(
        "SELECT COALESCE(SUM(debe-haber),0) FROM ctacte WHERE id_cliente={$idCliente} AND empresa_id={$empresaId}"
    )->fetchColumn();
    $totalPendiente = 0.0;
    foreach ($facturas as $factura) {
        $totalPendiente = round($totalPendiente + $factura['saldo_disponible'], 2);
    }

    echo json_encode([
        'success' => true,
        'facturas' => $facturas,
        'total_pendiente' => $totalPendiente,
        'saldo_contable' => round($saldoContable, 2),
        'saldo_a_favor_actual' => round(max(0, -$saldoContable), 2)
    ]);
} catch (Throwable $e) {
    error_log('Error al cargar facturas para imputar pago CC: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudieron cargar las facturas pendientes.']);
}
