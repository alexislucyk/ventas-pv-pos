<?php
// procesos/_test_guardar_pendiente.php
// Prueba del flujo "Guardar venta como PENDIENTE" (pages/ventas.php).
// Ejecuta los mismos SQL que el guardado real dentro de una transacción y
// hace ROLLBACK: NO persiste ningún dato.
//
// Uso: php procesos/_test_guardar_pendiente.php

$_SERVER['SCRIPT_NAME']    = '/pos_dev/procesos/_test_guardar_pendiente.php';
$_SERVER['DOCUMENT_ROOT']  = 'c:/laragon/www';

require __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/numeradores.php';

$empresa_id  = 1;
$sucursal_id = 1;
$errores = [];
$ok = [];

function check($cond, $msg) {
    global $errores, $ok;
    if ($cond) { $ok[] = $msg; } else { $errores[] = $msg; }
}

try {
    $pdo->beginTransaction();

    // --- 1. N° de documento con el numerador PROPIO de pendientes (mismo helper que ventas.php) ---
    $n_documento = venta_siguiente_n($pdo, true);
    check($n_documento >= VENTA_PENDIENTE_BASE, "Pendiente numerado con el numerador propio (N° $n_documento >= " . VENTA_PENDIENTE_BASE . ")");
    $n_normal = venta_siguiente_n($pdo, false);
    check($n_normal < VENTA_PENDIENTE_BASE, "Numerador normal NO afectado por pendientes (próximo normal=$n_normal)");
    check($n_normal === (int)$pdo->query("SELECT MAX(n_documento) FROM ventas WHERE n_documento < " . (int)VENTA_PENDIENTE_BASE)->fetchColumn() + 1, "Numerador normal = MAX(serie normal) + 1");

    // --- 2. Cabecera con estado Pendiente (mismo SQL que ventas.php) ---
    $fecha_venta = date('Y-m-d H:i:s');
    $sql_v = "INSERT INTO ventas (empresa_id, sucursal_id, id_cliente, cond_pago, n_documento, total_venta, descuento_global, tipo_descuento_global, pago_efectivo, pago_transf, fecha_venta, estado, usuario, observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql_v)->execute([$empresa_id, $sucursal_id, 0, 'CONTADO', $n_documento, 1000, 100, 'fijo', 0, 0, $fecha_venta, 'Pendiente', 'TEST_ROLLBACK', 'prueba pendiente']);
    $id_venta_actual = (int)$pdo->lastInsertId();
    check($id_venta_actual > 0, "Cabecera insertada (id=$id_venta_actual) con estado='Pendiente'");

    // --- 3. Detalle con descuento por ítem (mismo SQL que ventas.php) ---
    $sql_d = "INSERT INTO ventas_detalle (empresa_id, sucursal_id, cod_prod, descripcion, cant, p_unit, descuento_unitario, p_costo_venta, total, n_documento, fecha) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql_d)->execute([$empresa_id, $sucursal_id, 'TEST-PEND', 'Producto de prueba', 2, 500, 10, 300, 900, $n_documento, date('Y-m-d H:i:s')]);
    check(true, "Detalle insertado con descuento_unitario=10");

    // --- 4. Estado pendiente NO toca stock / caja / ctacte (igual que ventas.php) ---
    $movs = (int)$pdo->query("SELECT COUNT(*) FROM movimientos WHERE detalle LIKE '%VENTA%$n_documento%' OR detalle LIKE '%$n_documento'")->fetchColumn();
    check($movs === 0, "Sin movimiento de caja para la venta pendiente");

    // --- 5. Lectura para REANUDAR (ajax/obtener_detalle_venta.php, filtrada por empresa) ---
    $sql_cab = "SELECT v.*, CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
                 FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id
                 WHERE v.n_documento = :n_documento AND v.empresa_id = :empresa_id";
    $st = $pdo->prepare($sql_cab);
    $st->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_id]);
    $cab = $st->fetch();
    check($cab && $cab['estado'] === 'Pendiente', "Cabecera reanudable por n_documento+empresa_id");

    $sql_det = "SELECT cod_prod, descripcion, cant, p_unit, descuento_unitario, p_costo_venta, total
                FROM ventas_detalle WHERE n_documento = :n_documento AND empresa_id = :empresa_id";
    $st2 = $pdo->prepare($sql_det);
    $st2->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_id]);
    $det = $st2->fetchAll();
    check(count($det) === 1 && (float)$det[0]['descuento_unitario'] === 10.0,
        "Detalle reanudable con descuento_unitario (=" . $det[0]['descuento_unitario'] . ") y p_costo_venta (=" . $det[0]['p_costo_venta'] . ")");

    // --- 6. Borrado de detalle scoping por empresa (UPDATE de pendiente existente) ---
    $stDel = $pdo->prepare("DELETE FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?");
    $stDel->execute([$n_documento, $empresa_id]);
    check($stDel->rowCount() === 1, "DELETE de detalle afecta solo la fila de esta empresa (rowCount=" . $stDel->rowCount() . ")");

    // --- 7. Listado de pendientes (ajax/ventas_pendientes_ajax.php) ---
    $stP = $pdo->prepare("SELECT v.id FROM ventas v WHERE v.estado = 'Pendiente' AND v.empresa_id = :e");
    $stP->execute([':e' => $empresa_id]);
    check(count($stP->fetchAll()) > 0, "La venta de prueba aparece en el listado de Pendientes");

    $pdo->rollBack();
    $ok[] = "ROLLBACK OK: no se persistió ningún dato de prueba";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errores[] = "EXCEPCIÓN: " . $e->getMessage();
}

echo "=== TEST Guardar venta PENDIENTE ===\n";
foreach ($ok as $m) echo "  [OK]   $m\n";
foreach ($errores as $m) echo "  [FAIL] $m\n";
echo empty($errores) ? "\nRESULTADO: PASO (" . count($ok) . " checks)\n" : "\nRESULTADO: FALLÓ (" . count($errores) . " errores)\n";
exit(empty($errores) ? 0 : 1);
