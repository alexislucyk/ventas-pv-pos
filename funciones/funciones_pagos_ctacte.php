<?php
/**
 * Servicios compartidos para pagos e imputaciones de cuenta corriente.
 * La relación persistida es la fuente de verdad para el saldo aplicado.
 */
/**
 * Condición SQL que deja afuera los movimientos de interés por mora (no son
 * facturas: no se imputan ni se pagan). Usa la columna ctacte.es_interes de la
 * migración 50 y cae al filtro por texto si la base todavía no está migrada.
 *
 * @param PDO    $pdo
 * @param string $alias Alias de la tabla ctacte en la consulta (ej. 'c')
 * @return string
 */
function condicionSqlNoEsInteresCc(PDO $pdo, string $alias = ''): string {
    static $tieneColumna = null;

    if ($tieneColumna === null) {
        try {
            $pdo->query('SELECT es_interes FROM ctacte LIMIT 1');
            $tieneColumna = true;
        } catch (PDOException $e) {
            $tieneColumna = false;
        }
    }

    $prefijo = $alias !== '' ? $alias . '.' : '';
    if ($tieneColumna) {
        return $prefijo . 'es_interes = 0';
    }
    return "LOWER(" . $prefijo . "movimiento) NOT LIKE 'inter%por%mora%'";
}

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
function tablaCreditosAFavorCcExiste(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM ctacte_creditos_a_favor_aplicaciones LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}

function saldoDirectoAplicadoMovimientoCc(PDO $pdo, int $id, int $empresaId, int $clienteId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(importe_aplicado),0) FROM ctacte_pagos_imputaciones WHERE movimiento_deudor_id=? AND empresa_id=? AND id_cliente=?");
    $st->execute([$id, $empresaId, $clienteId]);
    return (float)$st->fetchColumn();
}

function saldoCreditoAplicadoMovimientoCc(PDO $pdo, int $id, int $empresaId, int $clienteId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(importe_aplicado),0) FROM ctacte_creditos_a_favor_aplicaciones WHERE movimiento_deudor_id=? AND empresa_id=? AND id_cliente=?");
    $st->execute([$id, $empresaId, $clienteId]);
    return (float)$st->fetchColumn();
}

/**
 * Distribuye el excedente no imputado de pagos anteriores sobre facturas
 * posteriores. No altera debe/haber: sólo evita que una factura cubierta por
 * ese crédito vuelva a presentarse como pagable.
 */
function reconciliarSaldosAFavorCc(PDO $pdo, int $empresaId, int $clienteId, ?string $fechaCorte = null, string $origen = 'reconciliacion_automatica'): float {
    if (!tablaCreditosAFavorCcExiste($pdo)) {
        throw new RuntimeException('Falta aplicar la migración 49 de saldos a favor de cuenta corriente.');
    }
    $fechaCorte = substr($fechaCorte ?: date('Y-m-d'), 0, 10);
    $propia = !$pdo->inTransaction();
    if ($propia) { $pdo->beginTransaction(); }

    try {
        // El orden de bloqueo es siempre pagos -> facturas.
        $lockPagos = $pdo->prepare("SELECT id FROM ctacte WHERE id_cliente=? AND empresa_id=? AND movimiento='Pago Cta.Cte.' AND haber>0 AND DATE(fecha)<=? ORDER BY fecha,id FOR UPDATE");
        $lockPagos->execute([$clienteId, $empresaId, $fechaCorte]);
        $idsPagos = $lockPagos->fetchAll(PDO::FETCH_COLUMN);
        if (!$idsPagos) { if ($propia) { $pdo->commit(); } return 0.0; }

        $creditos = $pdo->prepare(
            "SELECT p.id,p.fecha,p.haber,
                    COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i WHERE i.pago_movimiento_id=p.id AND i.empresa_id=p.empresa_id AND i.id_cliente=p.id_cliente),0) directo,
                    COALESCE((SELECT SUM(a.importe_aplicado) FROM ctacte_creditos_a_favor_aplicaciones a WHERE a.credito_pago_movimiento_id=p.id AND a.empresa_id=p.empresa_id AND a.id_cliente=p.id_cliente),0) aplicado_credito
             FROM ctacte p
             WHERE p.id_cliente=? AND p.empresa_id=? AND p.movimiento='Pago Cta.Cte.' AND p.haber>0 AND DATE(p.fecha)<=?
             ORDER BY p.fecha,p.id"
        );
        $creditos->execute([$clienteId, $empresaId, $fechaCorte]);
        $filasCredito = $creditos->fetchAll(PDO::FETCH_ASSOC);

        $lockFacturas = $pdo->prepare("SELECT id FROM ctacte WHERE id_cliente=? AND empresa_id=? AND debe>haber AND DATE(fecha)<=? AND " . condicionSqlNoEsInteresCc($pdo) . " ORDER BY fecha,id FOR UPDATE");
        $lockFacturas->execute([$clienteId, $empresaId, $fechaCorte]);
        $idsFacturas = $lockFacturas->fetchAll(PDO::FETCH_COLUMN);
        if (!$idsFacturas) { if ($propia) { $pdo->commit(); } return 0.0; }

        $facturas = $pdo->prepare(
            "SELECT c.id,c.fecha,c.debe,c.haber,
                    COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i WHERE i.movimiento_deudor_id=c.id AND i.empresa_id=c.empresa_id AND i.id_cliente=c.id_cliente),0) directo,
                    COALESCE((SELECT SUM(a.importe_aplicado) FROM ctacte_creditos_a_favor_aplicaciones a WHERE a.movimiento_deudor_id=c.id AND a.empresa_id=c.empresa_id AND a.id_cliente=c.id_cliente),0) aplicado_credito
             FROM ctacte c
             WHERE c.id_cliente=? AND c.empresa_id=? AND c.debe>c.haber AND DATE(c.fecha)<=? AND " . condicionSqlNoEsInteresCc($pdo, 'c') . "
             ORDER BY c.fecha,c.id"
        );
        $facturas->execute([$clienteId, $empresaId, $fechaCorte]);
        $filasFacturas = $facturas->fetchAll(PDO::FETCH_ASSOC);
        $saldoPorFactura = [];
        foreach ($filasFacturas as $factura) {
            $saldoPorFactura[(int)$factura['id']] = round((float)$factura['debe'] - (float)$factura['haber'] - (float)$factura['directo'] - (float)$factura['aplicado_credito'], 2);
        }

        $insertar = $pdo->prepare("INSERT INTO ctacte_creditos_a_favor_aplicaciones (credito_pago_movimiento_id,movimiento_deudor_id,empresa_id,id_cliente,importe_aplicado,fecha_aplicacion,origen) VALUES (?,?,?,?,?,?,?)");
        $totalAplicado = 0.0;
        foreach ($filasCredito as $credito) {
            $disponible = round((float)$credito['haber'] - (float)$credito['directo'] - (float)$credito['aplicado_credito'], 2);
            if ($disponible <= 0.005) { continue; }
            foreach ($filasFacturas as $factura) {
                if ($disponible <= 0.005) { break; }
                if (substr((string)$factura['fecha'], 0, 10) < substr((string)$credito['fecha'], 0, 10)) { continue; }
                $saldoFactura = $saldoPorFactura[(int)$factura['id']];
                if ($saldoFactura <= 0.005) { continue; }
                $aplicar = min($disponible, $saldoFactura);
                $insertar->execute([(int)$credito['id'], (int)$factura['id'], $empresaId, $clienteId, $aplicar, $fechaCorte, $origen]);
                $saldoPorFactura[(int)$factura['id']] = round($saldoFactura - $aplicar, 2);
                $disponible = round($disponible - $aplicar, 2);
                $totalAplicado = round($totalAplicado + $aplicar, 2);
            }
        }
        if ($propia) { $pdo->commit(); }
        return $totalAplicado;
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function saldoDisponibleMovimientoCc(PDO $pdo, int $id, int $empresaId, int $clienteId): float {


    $st = $pdo->prepare("SELECT c.debe,c.haber FROM ctacte c WHERE c.id=? AND c.empresa_id=? AND c.id_cliente=? AND c.debe>0 AND " . condicionSqlNoEsInteresCc($pdo, 'c'));
    $st->execute([$id, $empresaId, $clienteId]);
    $factura = $st->fetch(PDO::FETCH_ASSOC);
    if (!$factura) throw new InvalidArgumentException('La factura indicada no pertenece al cliente o no es pagable.');
    $directo = saldoDirectoAplicadoMovimientoCc($pdo, $id, $empresaId, $clienteId);
    $credito = saldoCreditoAplicadoMovimientoCc($pdo, $id, $empresaId, $clienteId);
    return round(max(0, (float)$factura['debe'] - (float)$factura['haber'] - $directo - $credito), 2);
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
    if (!tablaCreditosAFavorCcExiste($pdo)) {
        throw new RuntimeException('Falta aplicar la migración 49 de saldos a favor de cuenta corriente.');
    }
    $permitirSaldoAFavor = !empty($datos['permitir_saldo_a_favor']);

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

        // Los créditos previos se consumen antes de validar las imputaciones nuevas.
        reconciliarSaldosAFavorCc($pdo, $empresaId, $idCliente, $fechaPago, 'antes_pago');

        // Se bloquean las facturas candidatas antes de calcular saldos para
        // impedir que dos pagos concurrentes consuman la misma imputación.
        $lock = $pdo->prepare("SELECT c.id, c.debe, c.haber,
            COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i
                WHERE i.movimiento_deudor_id=c.id AND i.empresa_id=c.empresa_id AND i.id_cliente=c.id_cliente),0) AS aplicado_directo,
            COALESCE((SELECT SUM(a.importe_aplicado) FROM ctacte_creditos_a_favor_aplicaciones a
                WHERE a.movimiento_deudor_id=c.id AND a.empresa_id=c.empresa_id AND a.id_cliente=c.id_cliente),0) AS aplicado_credito
            FROM ctacte c
            WHERE c.id_cliente=? AND c.empresa_id=? AND c.debe>c.haber
              AND c.fecha<=? AND " . condicionSqlNoEsInteresCc($pdo, 'c') . "
            ORDER BY c.fecha, c.id FOR UPDATE");
        $lock->execute([$idCliente, $empresaId, $fechaPago]);
        $disponibles = $lock->fetchAll(PDO::FETCH_ASSOC);
        $saldoPorId = [];
        foreach ($disponibles as $fila) {
            $saldo = round((float)$fila['debe'] - (float)$fila['haber'] - (float)$fila['aplicado_directo'] - (float)$fila['aplicado_credito'], 2);
            if ($saldo > 0.005) { $saldoPorId[(int)$fila['id']] = $saldo; }
        }

        $importes = normalizarImputacionesCc($imputaciones);
        if (!$importes && !$permitirSaldoAFavor) {
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
        if ($totalImputado > $montoPago + 0.005) {
            throw new InvalidArgumentException('La suma imputada a facturas no puede superar el monto del pago.');
        }
        $saldoAFavor = round($montoPago - $totalImputado, 2);
        if ($saldoAFavor > 0.005 && !$permitirSaldoAFavor) {
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
                $fechaPago, $saldoAFavor > 0.005 ? ((string)($datos['origen'] ?? 'formulario') . '_con_excedente') : (string)($datos['origen'] ?? 'formulario')
            ]);
        }
        if ($despuesInsertarPago) { $despuesInsertarPago($pagoId); }
        if ($propia) { $pdo->commit(); }
        return [
            'pago_id' => $pagoId,
            'importe_total' => $totalImputado,
            'saldo_a_favor' => max(0, $saldoAFavor),
            'cantidad' => count($importes)
        ];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

