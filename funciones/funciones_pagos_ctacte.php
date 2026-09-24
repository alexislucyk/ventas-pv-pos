<?php
/**
 * Servicios compartidos para pagos e imputaciones de cuenta corriente.
 * La relación persistida es la fuente de verdad para el saldo aplicado.
 */
function tablaImputacionesCcExiste(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM ctacte_pagos_imputaciones LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}
function normalizarImputacionesCc($imputaciones): array {
    if (is_string($imputaciones)) { $tmp = json_decode($imputaciones, true); $imputaciones = is_array($tmp) ? $tmp : []; }
    if (!is_array($imputaciones)) return [];
    $out = [];
    foreach ($imputaciones as $id => $monto) {
        $id = (int) $id; $monto = round((float) $monto, 2);
        if ($id > 0 && $monto > 0) $out[$id] = $monto;
    }
    return $out;
}
function saldoDisponibleMovimientoCc(PDO $pdo, int $id, int $empresaId, int $clienteId): float {
    $st = $pdo->prepare("SELECT c.debe,c.haber,COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i WHERE i.movimiento_deudor_id=c.id AND i.empresa_id=c.empresa_id AND i.id_cliente=c.id_cliente),0) aplicado FROM ctacte c WHERE c.id=? AND c.empresa_id=? AND c.id_cliente=? AND c.debe>0 AND LOWER(c.movimiento) NOT LIKE 'inter%por%mora%'");
    $st->execute([$id, $empresaId, $clienteId]); $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) throw new InvalidArgumentException('La factura indicada no pertenece al cliente o no es pagable.');
    // La factura descuenta su propio haber y las imputaciones persistidas.
    // El pago genérico vive en otra fila de ctacte, por lo que no se duplica.
    return round(max(0, (float) $f['debe'] - (float) $f['haber'] - (float) $f['aplicado']), 2);
}
function guardarImputacionesCc(PDO $pdo, int $pagoId, int $clienteId, int $empresaId, string $fechaPago, array $imputaciones, string $origen = 'formulario'): array {
    $total = 0.0;
    $st = $pdo->prepare("INSERT INTO ctacte_pagos_imputaciones (pago_movimiento_id,movimiento_deudor_id,empresa_id,id_cliente,importe_aplicado,fecha_imputacion,origen) VALUES (?,?,?,?,?,?,?)");
    foreach ($imputaciones as $id => $monto) {
        $monto = round((float) $monto, 2);
        if ($monto > saldoDisponibleMovimientoCc($pdo, (int) $id, $empresaId, $clienteId) + 0.005) throw new InvalidArgumentException('La imputación supera el saldo disponible de la factura #'.(int) $id.'.');
        $st->execute([$pagoId, (int) $id, $empresaId, $clienteId, $monto, substr($fechaPago, 0, 10), $origen]);
        $total = round($total + $monto, 2);
    }
    return ['importe_total' => $total, 'cantidad' => count($imputaciones)];
}
function registrarPagoCuentaCorriente(PDO $pdo,array $datos,array $imputaciones=[],?callable $despuesInsertarPago=null): array {
    return registrarPagoCuentaCorrienteV2($pdo, $datos, $imputaciones, $despuesInsertarPago);
}


function calcularInteresesClienteImputado(int $idCliente, PDO $pdo, int $empresaId, ?string $fechaCalculo = null): array {
    $fechaCalculo = $fechaCalculo ?: date('Y-m-d');
    $config = obtenerConfiguracionIntereses($pdo, $empresaId);
    if (!$config || !$config['activo']) {
        return ['interes_total' => 0, 'detalle' => [], 'config' => $config];
    }

    $st = $pdo->prepare(
        "SELECT c.id,c.fecha,c.fecha_vencimiento,c.debe,c.haber,c.movimiento,c.n_documento
         FROM ctacte c
         WHERE c.id_cliente=? AND c.empresa_id=?
           AND c.debe>c.haber
           AND c.fecha_vencimiento IS NOT NULL
           AND c.fecha_vencimiento<?
           AND LOWER(c.movimiento) NOT LIKE 'inter%por%mora%'
         ORDER BY c.fecha_vencimiento,c.id"
    );
    $st->execute([$idCliente, $empresaId, $fechaCalculo]);

    $stI = $pdo->prepare(
        "SELECT i.importe_aplicado,i.fecha_imputacion
         FROM ctacte_pagos_imputaciones i
         WHERE i.movimiento_deudor_id=? AND i.empresa_id=? AND i.id_cliente=?
           AND i.fecha_imputacion<=?
         ORDER BY i.fecha_imputacion,i.id"
    );

    $total = 0.0;
    $detalle = [];
    $gracia = max(0, (int)$config['dias_gracia']);
    $tasa = (float)$config['tasa_mensual'];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $factura) {
        $saldo = round((float)$factura['debe'] - (float)$factura['haber'], 2);
        $stI->execute([(int)$factura['id'], $empresaId, $idCliente, $fechaCalculo]);
        $pagos = $stI->fetchAll(PDO::FETCH_ASSOC);

        $inicio = $factura['fecha_vencimiento'];
        $interes = 0.0;
        $graciaAplicada = false;
        foreach ($pagos as $pago) {
            $aplicado = min($saldo, (float)$pago['importe_aplicado']);
            if ($aplicado <= 0.005) {
                continue;
            }
            $corte = min($fechaCalculo, $pago['fecha_imputacion']);
            $dias = max(0, (int)floor((strtotime($corte) - strtotime($inicio)) / 86400));
            $interes += calcularInteresMora($saldo, $graciaAplicada ? $dias : $dias - $gracia, $tasa);
            $graciaAplicada = true;
            $saldo = round($saldo - $aplicado, 2);
            $inicio = $pago['fecha_imputacion'];
        }

        // Una factura totalmente imputada no genera interés pendiente. Para una
        // factura parcial, solo se calcula el saldo que sigue vigente al corte.
        if ($saldo <= 0.005) {
            continue;
        }
        $dias = max(0, (int)floor((strtotime($fechaCalculo) - strtotime($inicio)) / 86400));
        $interes += calcularInteresMora($saldo, $graciaAplicada ? $dias : $dias - $gracia, $tasa);

        if ($interes > 0) {
            $total = round($total + $interes, 2);
            $detalle[] = [
                'id_movimiento' => (int)$factura['id'],
                'n_documento' => $factura['n_documento'],
                'movimiento' => $factura['movimiento'],
                'fecha' => $factura['fecha'],
                'fecha_vencimiento' => $factura['fecha_vencimiento'],
                'saldo_pendiente' => $saldo,
                'dias_mora' => $graciaAplicada ? $dias : max(0, $dias - $gracia),
                'tasa_aplicada' => $tasa,
                'interes_calculado' => round($interes, 2),
            ];
        }
    }

    return ['interes_total' => round($total, 2), 'detalle' => $detalle, 'config' => $config];
}
/**
 * Registra un abono y guarda sus imputaciones. La función es transaccional y
 * bloquea los movimientos涉及的 para impedir sobreasignaciones concurrentes.
 *
 * @param array $datos id_cliente, n_recibo, monto_pago, fecha, usuario, empresa_id, origen
 * @param array $imputaciones [id_movimiento => importe]
 */
function registrarPagoCuentaCorrienteV2(PDO $pdo, array $datos, array $imputaciones = [], ?callable $despuesInsertarPago = null): array {
    if (!tablaImputacionesCcExiste($pdo)) {
        throw new RuntimeException('Falta aplicar la migración 48 de imputaciones de cuenta corriente.');
    }

    $propia = !$pdo->inTransaction();
    if ($propia) { $pdo->beginTransaction(); }
    try {
        $idCliente = (int)($datos['id_cliente'] ?? 0);
        $empresaId = (int)($datos['empresa_id'] ?? 0);
        $montoPago = round((float)($datos['monto_pago'] ?? 0), 2);
        $fechaPago = substr((string)($datos['fecha'] ?? date('Y-m-d')), 0, 10);
        if ($idCliente <= 0 || $empresaId <= 0 || $montoPago <= 0) {
            throw new InvalidArgumentException('Cliente, empresa y monto del pago son obligatorios.');
        }

        // Se bloquean las facturas candidatas antes de calcular saldos para
        // impedir que dos pagos concurrentes consuman la misma imputación.
        $lock = $pdo->prepare("SELECT c.id, c.debe, c.haber,
            COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i
                WHERE i.movimiento_deudor_id=c.id AND i.empresa_id=c.empresa_id AND i.id_cliente=c.id_cliente),0) AS aplicado
            FROM ctacte c
            WHERE c.id_cliente=? AND c.empresa_id=? AND c.debe>c.haber
              AND c.fecha<=? AND LOWER(c.movimiento) NOT LIKE 'inter%por%mora%'
            ORDER BY c.fecha, c.id FOR UPDATE");
        $lock->execute([$idCliente, $empresaId, $fechaPago]);
        $disponibles = $lock->fetchAll(PDO::FETCH_ASSOC);
        $saldoPorId = [];
        foreach ($disponibles as $fila) {
            $saldo = round((float)$fila['debe'] - (float)$fila['haber'] - (float)$fila['aplicado'], 2);
            if ($saldo > 0.005) { $saldoPorId[(int)$fila['id']] = $saldo; }
        }

        $importes = normalizarImputacionesCc($imputaciones);
    if (!$importes) {
        throw new InvalidArgumentException('Debe indicar las facturas a las que se imputará el pago.');
    }

        $totalImputado = 0.0;
        foreach ($importes as $id => $monto) {
            $monto = round((float)$monto, 2);
            if (!array_key_exists((int)$id, $saldoPorId)) {
                throw new InvalidArgumentException('La factura #' . (int)$id . ' no existe o no estaba disponible a la fecha del pago.');
            }
            if ($monto > $saldoPorId[(int)$id] + 0.005) {
                throw new InvalidArgumentException('La imputación de la factura #' . (int)$id . ' supera su saldo disponible.');
            }
            $totalImputado = round($totalImputado + $monto, 2);
        }
        if (abs($totalImputado - $montoPago) > 0.005) {
            throw new InvalidArgumentException('El pago debe estar asignado completamente a facturas. Imputado: $' . number_format($totalImputado, 2, ',', '.') . '; recibido: $' . number_format($montoPago, 2, ',', '.') . '.');
        }

        $st = $pdo->prepare("INSERT INTO ctacte
            (id_cliente, movimiento, n_documento, debe, haber, fecha, usuario, empresa_id)
            VALUES (?, 'Pago Cta.Cte.', ?, 0, ?, ?, ?, ?)");
        $st->execute([
            $idCliente, (string)$datos['n_recibo'], $montoPago, $fechaPago,
            (string)$datos['usuario'], $empresaId
        ]);
        $pagoId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO ctacte_pagos_imputaciones
            (pago_movimiento_id, movimiento_deudor_id, empresa_id, id_cliente, importe_aplicado, fecha_imputacion, origen)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($importes as $id => $monto) {
            $ins->execute([
                $pagoId, (int)$id, $empresaId, $idCliente, round((float)$monto, 2),
                $fechaPago, (string)($datos['origen'] ?? 'formulario')
            ]);
        }
        if ($despuesInsertarPago) { $despuesInsertarPago($pagoId); }
        if ($propia) { $pdo->commit(); }
        return ['pago_id' => $pagoId, 'importe_total' => $totalImputado, 'cantidad' => count($importes)];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

