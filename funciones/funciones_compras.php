<?php
// funciones/funciones_compras.php
// Utilidades del modulo de Compras (anulacion de compras rapidas).

if (!function_exists('anularCompraRapida')) {
    /**
     * Anula una compra rapida (es_sin_detalle = 1) y revierte sus efectos.
     *
     * Revierte dentro de una transaccion:
     *   - Los asientos de ctacte_proveedores vinculados (factura y/o pago contado)
     *   - El EGRESO de caja, mediante un asiento INGRESO inverso (solo CONTADO)
     *   - Marca compras.estado = 'Anulada' (no se borra la fila: auditoria)
     *
     * Restricciones:
     *   - Solo compras rapidas (sin detalle): las compras con detalle afectan
     *     stock y compras_detalle y requieren otro proceso de anulacion.
     *   - No puede tener pagos imputados posteriores en ctacte.proveedores.
     *
     * @param PDO    $pdo             Conexion activa (usa transaccion propia)
     * @param int    $id_compra       ID de la compra
     * @param int    $empresa_id      Empresa en sesion
     * @param string $usuario_nombre  Usuario que ejecuta (para el asiento de caja)
     * @return array{success:bool, mensaje:string}
     */
    function anularCompraRapida(PDO $pdo, int $id_compra, int $empresa_id, string $usuario_nombre): array
    {
        try {
            $pdo->beginTransaction();

            // 1. Datos de la compra (FOR UPDATE: evita doble anulacion concurrente)
            $stmt = $pdo->prepare("SELECT id, cod_proveedor, cond_pago, documento, n_documento,
                                          total_compra, estado, es_sin_detalle
                                   FROM compras
                                   WHERE id = ? AND empresa_id = ?
                                   FOR UPDATE");
            $stmt->execute([$id_compra, $empresa_id]);
            $compra = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$compra) {
                $pdo->rollBack();
                return ['success' => false, 'mensaje' => 'La compra no existe o no pertenece a esta empresa.'];
            }

            if ((int)$compra['es_sin_detalle'] !== 1) {
                $pdo->rollBack();
                return ['success' => false, 'mensaje' => 'Solo se pueden anular compras rapidas (sin detalle). '
                    . 'Esta compra tiene detalle de productos y afecta stock; para anularla use el proceso de anulaciones.'];
            }

            if ($compra['estado'] === 'Anulada') {
                $pdo->rollBack();
                return ['success' => false, 'mensaje' => 'La compra ya fue anulada anteriormente.'];
            }

            // 2. No permitir anular si tiene pagos imputados reales en la ctacte.
            //    (el unico debe legitimo de una compra rapida CONTADO es su 'PAGO CONTADO')
            $stmt_pag = $pdo->prepare("SELECT COUNT(*) FROM ctacte_proveedores
                                       WHERE compra_id = ? AND empresa_id = ?
                                         AND debe > 0 AND movimiento NOT LIKE 'PAGO CONTADO%'");
            $stmt_pag->execute([$id_compra, $empresa_id]);
            $pagos_imputados = (int)$stmt_pag->fetchColumn();

            if ($pagos_imputados > 0) {
                $pdo->rollBack();
                return ['success' => false, 'mensaje' => 'La compra tiene '
                    . $pagos_imputados . ' pago(s) imputado(s) en la cuenta corriente del proveedor. '
                    . 'Revertir los pagos antes de anular la compra.'];
            }

            $es_contado = (strtoupper(trim((string)$compra['cond_pago'])) !== 'CRÉDITO');
            $tipo_doc   = $compra['documento'];
            $n_doc      = $compra['n_documento'];
            $total      = (float)$compra['total_compra'];
            $fecha_hoy  = date('Y-m-d H:i:s');

            // 3. Revertir los asientos de ctacte.proveedores de esta compra
            $stmt_del = $pdo->prepare("DELETE FROM ctacte_proveedores
                                       WHERE compra_id = ? AND empresa_id = ?");
            $stmt_del->execute([$id_compra, $empresa_id]);
            $asientos_eliminados = $stmt_del->rowCount();

            // 4. Si fue CONTADO, reingresar el dinero a caja (asiento inverso del EGRESO original).
            //    Patron del proyecto: nunca se borran movimientos; se asienta la reversa.
            //    movimientos.fecha es DATE (solo fecha): se normaliza para no truncar y
            //    respetar el modo estricto de MySQL.
            $ingreso_caja = false;
            if ($es_contado && $total > 0) {
                $fecha_caja = substr((string)$fecha_hoy, 0, 10);
                $stmt_mov = $pdo->prepare("INSERT INTO movimientos
                        (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id)
                        VALUES ('INGRESO', ?, 'EFECTIVO', ?, ?, ?, 0, ?, ?)");
                $stmt_mov->execute([
                    $total,
                    "ANULACIÓN COMPRA S/DETALLE: $tipo_doc $n_doc (Compra #$id_compra)",
                    $fecha_caja,
                    $usuario_nombre,
                    $empresa_id,
                    (int)($_SESSION['sucursal_id'] ?? 1)
                ]);
                $ingreso_caja = true;
            }

            // 5. Marcar la compra como anulada
            $stmt_upd = $pdo->prepare("UPDATE compras SET estado = 'Anulada'
                                       WHERE id = ? AND empresa_id = ? AND estado = 'Activa'");
            $stmt_upd->execute([$id_compra, $empresa_id]);

            if ($stmt_upd->rowCount() === 0) {
                throw new Exception('No se pudo marcar la compra como anulada.');
            }

            $pdo->commit();

            $mensaje = "Compra $tipo_doc $n_doc anulada.";
            if ($asientos_eliminados > 0) {
                $mensaje .= " Se revertieron $asientos_eliminados asiento(s) de la cuenta corriente del proveedor.";
            }
            if ($ingreso_caja) {
                $mensaje .= " Se registró un ingreso de $" . number_format($total, 2, ',', '.')
                    . " en caja (reversa del pago contado).";
            }

            error_log("compras: usuario '$usuario_nombre' anuló la compra #$id_compra "
                . "($tipo_doc $n_doc) de la empresa #$empresa_id - asientos: $asientos_eliminados"
                . ($ingreso_caja ? " - ingreso caja: $total" : ""));

            return ['success' => true, 'mensaje' => $mensaje];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error al anular compra rapida #$id_compra: " . $e->getMessage());
            return ['success' => false, 'mensaje' => 'Error al anular la compra: ' . $e->getMessage()];
        }
    }
}
?>

