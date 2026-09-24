<?php
/**
 * Migrador idempotente de imputaciones de pagos de cuenta corriente.
 * Uso: php core/migrar_imputaciones_ctacte.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/db_config.php';

function ejecutarMigracionImputaciones(PDO $pdo): array {
    $sql = file_get_contents(__DIR__ . '/../migrations/48_imputaciones_pagos_ctacte.sql');
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer la migración 48.');
    }
    $pdo->exec($sql);

    // Backfill reproducible: los metadatos históricos se reconstruyen, sin tocar
    // los movimientos contables. Se conservan únicamente imputaciones creadas
    // por la aplicación para pagos nuevos.
    // Solo se reconstruye el backfill FIFO. Las asignaciones de aplicación y
    // la reconciliación explícita 548→2099 se conservan en reejecuciones.
    $pdo->exec("DELETE FROM ctacte_pagos_imputaciones WHERE origen = 'historico_fifo'");

    // Los pagos ya capturados por formularios/AJAX/transferencias se conservan.
    // Solo se reprocesan pagos históricos que aún no tienen imputación.
    $existentes = $pdo->query(
        "SELECT pago_movimiento_id, movimiento_deudor_id, SUM(importe_aplicado) AS aplicado
         FROM ctacte_pagos_imputaciones
         WHERE origen <> 'historico_fifo'
         GROUP BY pago_movimiento_id, movimiento_deudor_id"
    )->fetchAll();
    $pagosConImputacion = [];
    $aplicadoPorDeudor = [];
    foreach ($existentes as $fila) {
        $pagoId = (int)$fila['pago_movimiento_id'];
        $deudorId = (int)$fila['movimiento_deudor_id'];
        $pagosConImputacion[$pagoId] = true;
        $aplicadoPorDeudor[$deudorId] = round((float)($aplicadoPorDeudor[$deudorId] ?? 0) + (float)$fila['aplicado'], 2);
    }

    $deudores = $pdo->query(
        "SELECT id,empresa_id,id_cliente,n_documento,debe,haber,fecha
         FROM ctacte
         WHERE debe>0 AND LOWER(movimiento) NOT LIKE 'inter%por%mora%'
         ORDER BY empresa_id,id_cliente,fecha,id"
    )->fetchAll();
    $pagos = $pdo->query(
        "SELECT id,empresa_id,id_cliente,n_documento,haber,fecha
         FROM ctacte
         WHERE movimiento='Pago Cta.Cte.' AND haber>0
         ORDER BY empresa_id,id_cliente,fecha,id"
    )->fetchAll();

    $deudoresPorCuenta = [];
    foreach ($deudores as $fila) {
        $cuenta = (int)$fila['empresa_id'] . ':' . (int)$fila['id_cliente'];
        $deudoresPorCuenta[$cuenta][] = $fila;
    }
    $pagosPorCuenta = [];
    foreach ($pagos as $fila) {
        $cuenta = (int)$fila['empresa_id'] . ':' . (int)$fila['id_cliente'];
        $pagosPorCuenta[$cuenta][] = $fila;
    }

    $insert = $pdo->prepare(
        "INSERT INTO ctacte_pagos_imputaciones
         (pago_movimiento_id,movimiento_deudor_id,empresa_id,id_cliente,importe_aplicado,fecha_imputacion,origen)
         VALUES (?,?,?,?,?,?,?)"
    );
    $insertados = 0;
    $pagosProcesados = 0;
    $reconciliacion = 'no aplica';

    $pdo->beginTransaction();
    try {
        foreach ($pagosPorCuenta as $cuenta => $listaPagos) {
            list($empresaId, $clienteId) = array_map('intval', explode(':', $cuenta));
            $disponibles = [];
            foreach ($deudoresPorCuenta[$cuenta] ?? [] as $fila) {
                $disponibles[(int)$fila['id']] = [
                    'fila' => $fila,
                    'saldo' => round((float)$fila['debe'] - (float)$fila['haber'] - (float)($aplicadoPorDeudor[(int)$fila['id']] ?? 0), 2)
                ];
            }

            foreach ($listaPagos as $pago) {
                if (isset($pagosConImputacion[(int)$pago['id']])) {
                    continue;
                }
                $restante = round((float)$pago['haber'], 2);
                $aplicaciones = [];

                // Reconciliación explícita y validada solicitada: recibo 548 a factura 2099.
                if ($empresaId === 1 && $clienteId === 118 && (string)$pago['n_documento'] === '548') {
                    $factura = null;
                    foreach ($disponibles as $candidata) {
                        if ((string)$candidata['fila']['n_documento'] === '2099') {
                            $factura = $candidata;
                            break;
                        }
                    }
                    $saldoFactura = $factura ? (float)$factura['fila']['debe'] - (float)$factura['fila']['haber'] : 0;
                    if ($restante !== 16208.0 || $saldoFactura !== 16208.0) {
                        throw new RuntimeException('Reconciliación 548→2099 no coincide con los importes esperados.');
                    }
                    $aplicaciones[(int)$factura['fila']['id']] = $restante;
                    $reconciliacion = 'recibo 548 -> factura 2099';
                } else {
                    foreach ($disponibles as $id => &$factura) {
                        if ($restante <= 0.005 || $factura['fila']['fecha'] > $pago['fecha']) {
                            continue;
                        }
                        $aplicar = min($restante, (float)$factura['saldo']);
                        if ($aplicar <= 0.005) {
                            continue;
                        }
                        $aplicaciones[$id] = round($aplicar, 2);
                        $restante = round($restante - $aplicar, 2);
                    }
                    unset($factura);
                }

                foreach ($aplicaciones as $idDeudor => $importe) {
                    if (!isset($disponibles[$idDeudor])) {
                        throw new RuntimeException('El reconciliador encontró un deudor inexistente.');
                    }
                    $disponibles[$idDeudor]['saldo'] = round((float)$disponibles[$idDeudor]['saldo'] - $importe, 2);
                    $insert->execute([
                        (int)$pago['id'], (int)$idDeudor, $empresaId, $clienteId, $importe,
                        substr((string)$pago['fecha'], 0, 10), $reconciliacion !== 'no aplica' && (string)$pago['n_documento'] === '548' ? 'reconciliacion_2099' : 'historico_fifo'
                    ]);
                    $insertados++;
                }
                $pagosProcesados++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ya_migrada' => false, 'insertadas' => $insertados, 'pagos' => $pagosProcesados, 'reconciliacion' => $reconciliacion];
}

try {
    echo json_encode(ejecutarMigracionImputaciones($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
