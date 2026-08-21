<?php
// ajax/validar_transferencia.php
// Acciones sobre las transferencias de la caja abierta:
//   accion=validar      → marca como validada (acreditada en el banco)
//   accion=desvalidar   → vuelve a dejarla pendiente
//   accion=no_realizada → marca como NO realizada y aplica una resolución
//                         (subaccion): ANULAR, CTACTE, PENDIENTE, REVERSAR
include '../pages/infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';
$id = (int)($_POST['id'] ?? 0);
$accion = (string)($_POST['accion'] ?? 'validar');
$comprobante = trim((string)($_POST['comprobante'] ?? ''));
$subaccion = strtoupper(trim((string)($_POST['subaccion'] ?? '')));
$observacion = trim((string)($_POST['observacion'] ?? ''));

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Movimiento inválido.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, tipo, metodo_pago, monto, detalle, usuario,
                                  monto_efectivo, monto_transferencia, transferencia_validada
                           FROM movimientos
                           WHERE id = ? AND empresa_id = ? AND sucursal_id = ?");
    $stmt->execute([$id, $empresa_id, $sucursal_id]);
    $mov = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mov) {
        echo json_encode(['success' => false, 'message' => 'El movimiento no existe o pertenece a otra sucursal/empresa.']);
        exit();
    }

    if (!in_array($mov['metodo_pago'], ['TRANSFERENCIA', 'MIXTO'], true) && (float)$mov['monto_transferencia'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'El movimiento seleccionado no es una transferencia.']);
        exit();
    }

    // ---------------- VALIDAR ----------------
    if ($accion === 'validar') {
        if (mb_strlen($comprobante) > 100) {
            echo json_encode(['success' => false, 'message' => 'La referencia es demasiado larga (máx. 100 caracteres).']);
            exit();
        }
        $pdo->prepare("UPDATE movimientos
                       SET transferencia_validada = 1,
                           transferencia_validada_usuario = ?,
                           transferencia_validada_fecha = NOW(),
                           transferencia_comprobante = ?,
                           transferencia_no_realizada_accion = NULL,
                           transferencia_observacion = NULL
                       WHERE id = ?")
            ->execute([$usuario, $comprobante !== '' ? $comprobante : null, $id]);
        echo json_encode(['success' => true, 'message' => 'Transferencia validada correctamente.']);
        exit();
    }

    // ---------------- DESVALIDAR ----------------
    if ($accion === 'desvalidar') {
        $pdo->prepare("UPDATE movimientos
                       SET transferencia_validada = 0,
                           transferencia_validada_usuario = NULL,
                           transferencia_validada_fecha = NULL,
                           transferencia_comprobante = NULL,
                           transferencia_no_realizada_accion = NULL,
                           transferencia_observacion = NULL
                       WHERE id = ?")
            ->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Transferencia marcada como pendiente.']);
        exit();
    }

    // ---------------- NO REALIZADA ----------------
    if ($accion === 'no_realizada') {
        if (!in_array($subaccion, ['ANULAR', 'CTACTE', 'PENDIENTE', 'REVERSAR'], true)) {
            echo json_encode(['success' => false, 'message' => 'Resolución inválida.']);
            exit();
        }

        $pdo->beginTransaction();

        // Determinar el tipo de movimiento (venta o pago cta.cte.)
        $detalle = trim((string)$mov['detalle']);
        $nroDoc = 0;
        $esPagoCC = false;

        if (preg_match('/CLIENTE\s*#?\s*(\d+)/i', $detalle, $m)) {
            $idCliente = (int)$m[1];
            if (preg_match('/Recibo\s+(\d+)/i', $detalle, $rm)) {
                $esPagoCC = true;
                $nroDoc = (int)$rm[1];
            }
        } elseif (preg_match('/N\s*[°º]?\s*(\d+)/u', $detalle, $m)) {
            $nroDoc = (int)$m[1];
        }

        $ahora = date('Y-m-d H:i:s');
        $montoTransf = ((float)$mov['monto_transferencia'] > 0) ? (float)$mov['monto_transferencia'] : (float)$mov['monto'];
        $montoEfectivo = (float)$mov['monto_efectivo'];

        // ---- REVERSAR (pagos de cta.cte. recibidos por transferencia) ----
        if ($subaccion === 'REVERSAR') {
            if (!$esPagoCC || $nroDoc <= 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Reversar solo aplica a pagos recibidos de cuenta corriente.']);
                exit();
            }
            // Eliminar el haber de cta.cte. registrado
            $stmtDelCC = $pdo->prepare("DELETE FROM ctacte
                                        WHERE empresa_id = ? AND id_cliente = ? AND n_documento = ?
                                          AND movimiento = 'Pago Cta.Cte.' AND haber = ?");
            $stmtDelCC->execute([$empresa_id, $idCliente, $nroDoc, $montoTransf]);
            // Eliminar el movimiento de ingreso (el dinero nunca llegó)
            $pdo->prepare("DELETE FROM movimientos WHERE id = ?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Pago reversado: se quitaron el recibo y el ingreso de caja.']);
            exit();
        }

        // ---- Resoluciones asociadas a una VENTA ----
        $venta = null;
        if (!$esPagoCC && $nroDoc > 0) {
            $stmtV = $pdo->prepare("SELECT id, id_cliente, cond_pago, total_venta,
                                           pago_efectivo, pago_transf, n_documento, estado, observaciones
                                    FROM ventas
                                    WHERE n_documento = ? AND empresa_id = ? AND sucursal_id = ?");
            $stmtV->execute([$nroDoc, $empresa_id, $sucursal_id]);
            $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
        }

        // ---- ANULAR (venta CONTADO por transferencia no realizada) ----
        if ($subaccion === 'ANULAR') {
            if (!$venta || strtoupper((string)$venta['cond_pago']) !== 'CONTADO') {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Anular solo aplica a ventas de contado asociadas a la transferencia.']);
                exit();
            }
            if (strtoupper((string)$venta['estado']) === 'ANULADA') {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'La venta N° ' . $nroDoc . ' ya está anulada.']);
                exit();
            }

            // Reintegrar stock
            $stmtDet = $pdo->prepare("SELECT cod_prod, descripcion, cant, p_unit, total
                                      FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?");
            $stmtDet->execute([$nroDoc, $empresa_id]);
            $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            $stmtStock = $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual + ?
                                        WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?");
            $devolucionDetalles = [];
            foreach ($items as $it) {
                $stmtStock->execute([$it['cant'], $empresa_id, $sucursal_id, $it['cod_prod']]);
                $devolucionDetalles[] = $it;
            }

            $pdo->prepare("UPDATE ventas SET estado = 'Anulada' WHERE id = ?")
                ->execute([(int)$venta['id']]);

            // Registro histórico de devolución (sin reintegro de efectivo si no hubo)
            $montoEfectivoVenta = (float)$venta['pago_efectivo'];
            $opN = (int)$pdo->query("SELECT MAX(op_n) FROM devoluciones")->fetchColumn() + 1;
            $motivo = 'ANULADA POR TRANSFERENCIA NO REALIZADA. ' . $observacion;
            $pdo->prepare("INSERT INTO devoluciones (empresa_id, op_n, n_documento_venta, id_cliente, total_reintegrado, motivo, fecha, usuario, cond_pago)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$empresa_id, $opN, $nroDoc, (int)$venta['id_cliente'], $montoEfectivoVenta, $motivo, $ahora, $usuario, $venta['cond_pago']]);
            $idDev = (int)$pdo->lastInsertId();

            $stmtDevDet = $pdo->prepare("INSERT INTO devoluciones_detalle (empresa_id, id_devolucion, cod_prod, descripcion, cantidad, p_unit, subtotal)
                                         VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($devolucionDetalles as $it) {
                $stmtDevDet->execute([$empresa_id, $idDev, $it['cod_prod'], $it['descripcion'], $it['cant'], $it['p_unit'], $it['total']]);
            }

            // Si se había cobrado efectivo, se reintegra con un EGRESO
            if ($montoEfectivoVenta > 0) {
                $pdo->prepare("INSERT INTO movimientos (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado)
                               VALUES (?, ?, 'EGRESO', ?, 'EFECTIVO', ?, NOW(), ?, 0)")
                    ->execute([$empresa_id, $sucursal_id, $montoEfectivoVenta,
                               "REINTEGRO VENTA ANULADA N° $nroDoc - TRANSFERENCIA NO REALIZADA", $usuario]);
            }

            // Neutralizar el ingreso de la transferencia (nunca llegó) y guardar estado
            ajustar_movimiento_origen($pdo, $id, $mov, 'ANULADA', $observacion, $usuario, $montoEfectivoVenta);

            // Nota en la venta
            agregar_observacion_venta($pdo, $venta, "ANULADA POR TRANSFERENCIA NO REALIZADA - $ahora ($usuario): $observacion");

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Venta N° $nroDoc anulada y stock reintegrado."]);
            exit();
        }

        // ---- CTACTE (pasar a deuda la parte no cobrada de la venta) ----
        if ($subaccion === 'CTACTE') {
            if (!$venta) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No se encontró la venta asociada a la transferencia.']);
                exit();
            }
            $pdo->prepare("INSERT INTO ctacte (empresa_id, id_cliente, movimiento, n_documento, debe, haber, fecha, usuario)
                           VALUES (?, ?, 'FACTURA', ?, ?, 0, NOW(), ?)")
                ->execute([$empresa_id, (int)$venta['id_cliente'], $nroDoc, $montoTransf, $usuario]);

            // Actualizar la venta: lo que figuraba como transferencia ahora es deuda
            $nuevoPagoTransf = max(0, (float)$venta['pago_transf'] - $montoTransf);
            $pdo->prepare("UPDATE ventas SET cond_pago = 'CUENTA CORRIENTE', pago_transf = ? WHERE id = ?")
                ->execute([$nuevoPagoTransf, (int)$venta['id']]);

            // Neutralizar el ingreso de la transferencia / conservar el efectivo si es mixta
            $montoEfectivoQueda = ($mov['metodo_pago'] === 'MIXTO') ? $montoEfectivo : 0;
            ajustar_movimiento_origen($pdo, $id, $mov, 'CTACTE', $observacion, $usuario, $montoEfectivoQueda);

            agregar_observacion_venta($pdo, $venta, "PASADA A CTA. CTE. POR TRANSFERENCIA NO REALIZADA (\$" . number_format($montoTransf, 2, ',', '.') . ") - $ahora ($usuario): $observacion");

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Venta pasada a Cuenta Corriente por $' . number_format($montoTransf, 2, ',', '.') . '.']);
            exit();
        }

        // ---- PENDIENTE (la venta vuelve a "en espera" como en ventas/hacer Guardar) ----
        if ($subaccion === 'PENDIENTE') {
            if ($venta && strtoupper((string)$venta['cond_pago']) === 'CONTADO') {
                // Restaurar stock: la venta deja de estar consumida
                $stmtDet = $pdo->prepare("SELECT cod_prod, cant FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?");
                $stmtDet->execute([$nroDoc, $empresa_id]);
                $stmtStock = $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual + ? WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?");
                foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $stmtStock->execute([$it['cant'], $empresa_id, $sucursal_id, $it['cod_prod']]);
                }

                // La venta queda 'Pendiente' (mismo estado que al pulsar Guardar en ventas):
                // puede retomarse desde ventas con el botón Pendientes. Sin stock transferido,
                // por lo que solo se conserva el efectivo realmente cobrado (si fue mixta).
                $montoEfectivoQueda = ($mov['metodo_pago'] === 'MIXTO') ? $montoEfectivo : 0;
                $pdo->prepare("UPDATE ventas SET estado = 'Pendiente', pago_transf = 0, pago_efectivo = ? WHERE id = ?")
                    ->execute([$montoEfectivoQueda, (int)$venta['id']]);

                // Neutralizar el ingreso de la transferencia (el dinero nunca llegó)
                ajustar_movimiento_origen($pdo, $id, $mov, 'PENDIENTE', $observacion, $usuario, $montoEfectivoQueda);

                agregar_observacion_venta($pdo, $venta, "VENTA EN ESPERA POR TRANSFERENCIA NO REALIZADA - $ahora ($usuario): $observacion");

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "Venta N° $nroDoc pasó a 'en espera' (Pendiente) y su stock fue reintegrado."]);
                exit();
            }

            // Otros casos (cliente genérico o venta no de contado): solo se marca + comentario
            $pdo->prepare("UPDATE movimientos
                           SET transferencia_validada = 2,
                               transferencia_validada_usuario = ?,
                               transferencia_validada_fecha = NOW(),
                               transferencia_no_realizada_accion = 'PENDIENTE',
                               transferencia_observacion = ?
                           WHERE id = ?")
                ->execute([$usuario, $observacion !== '' ? $observacion : 'Transferencia no realizada', $id]);

            if ($venta) {
                agregar_observacion_venta($pdo, $venta, "TRANSFERENCIA NO REALIZADA (PENDIENTE) - $ahora ($usuario): $observacion");
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transferencia marcada como no realizada (venta pendiente).']);
            exit();
        }

        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Resolución no implementada.']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
}
exit();

/**
 * Neutraliza el ingreso de la transferencia en el movimiento origen:
 *  - Transferencia pura: monto = 0 (nunca entró dinero).
 *  - Mixta: solo queda el efectivo realmente cobrado (metodo_pago -> EFECTIVO).
 * Y guarda el estado 2 (no realizada) + resolución + observación.
 */
function ajustar_movimiento_origen($pdo, $idMov, $mov, $resolucion, $observacion, $usuario, $montoEfectivoQueda) {
    if ($mov['metodo_pago'] === 'MIXTO') {
        $pdo->prepare("UPDATE movimientos
                       SET monto = ?, monto_transferencia = 0, metodo_pago = 'EFECTIVO',
                           transferencia_validada = 2, transferencia_validada_usuario = ?,
                           transferencia_validada_fecha = NOW(),
                           transferencia_no_realizada_accion = ?, transferencia_observacion = ?
                       WHERE id = ?")
            ->execute([$montoEfectivoQueda, $usuario, $resolucion, $observacion, $idMov]);
    } else {
        $pdo->prepare("UPDATE movimientos
                       SET monto = 0, monto_transferencia = 0,
                           transferencia_validada = 2, transferencia_validada_usuario = ?,
                           transferencia_validada_fecha = NOW(),
                           transferencia_no_realizada_accion = ?, transferencia_observacion = ?
                       WHERE id = ?")
            ->execute([$usuario, $resolucion, $observacion, $idMov]);
    }
}

function agregar_observacion_venta($pdo, $venta, $texto) {
    $pdo->prepare("UPDATE ventas SET observaciones = CONCAT(COALESCE(observaciones, ''), '\n', ?) WHERE id = ?")
        ->execute([$texto, (int)$venta['id']]);
}