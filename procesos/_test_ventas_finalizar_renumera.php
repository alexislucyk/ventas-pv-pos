<?php
// procesos/_test_ventas_finalizar_renumera.php
// Prueba C: al FINALIZAR una venta pendiente numerada con el numerador PROPIO
// de pendientes (>= VENTA_PENDIENTE_BASE), la venta debe adoptar el numerador
// NORMAL de ventas (< VENTA_PENDIENTE_BASE) y re-enlazar su detalle al nuevo
// número. Al final limpia todos los datos creados (venta, detalle, stock y
// movimiento de caja).
//
// Uso: php procesos/_test_ventas_finalizar_renumera.php

$_SERVER['SCRIPT_NAME']    = '/pos_dev/pages/ventas.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['DOCUMENT_ROOT']  = 'c:/laragon/www';

session_start();
$_SESSION['usuario_id']     = 1;
$_SESSION['usuario_nombre'] = 'TEST_FINAL';
$_SESSION['empresa_id']     = 1;
$_SESSION['sucursal_id']    = 1;
$_SESSION['usuario_rol']    = 'developer';

require __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/numeradores.php';

$prod = $pdo->query("SELECT s.cod_prod, s.stock_actual
                     FROM stocks s
                     WHERE s.empresa_id = 1 AND s.sucursal_id = 1 AND s.stock_actual > 5
                     ORDER BY s.stock_actual DESC LIMIT 1")->fetch();
if (!$prod) { echo "[FAIL] No hay producto con stock > 5 para la prueba\n"; exit(1); }
$codProd    = $prod['cod_prod'];
$stockAntes = (float)$prod['stock_actual'];

// --- Fase 1: crear una PENDIENTE real con el numerador propio (commit) ---
$maxNormalAntes = (int)$pdo->query("SELECT COALESCE(MAX(n_documento), 0) FROM ventas WHERE n_documento < " . (int)VENTA_PENDIENTE_BASE)->fetchColumn();

$nDocPendiente = venta_siguiente_n($pdo, true);
if ($nDocPendiente < VENTA_PENDIENTE_BASE) {
    echo "[FAIL] El numerador de pendientes devolvió $nDocPendiente (esperado >= " . VENTA_PENDIENTE_BASE . ")\n";
    exit(1);
}

$pdo->beginTransaction();
// Fecha antigua de referencia: al finalizar, debe cambiar a HOY
$fechaPendienteAntigua = '2020-01-01 08:30:00';
$pdo->prepare("INSERT INTO ventas (empresa_id, sucursal_id, id_cliente, cond_pago, n_documento, total_venta, descuento_global, tipo_descuento_global, pago_efectivo, pago_transf, fecha_venta, estado, usuario, observaciones)
               VALUES (1, 1, 0, 'CONTADO', ?, 100, 0, 'fijo', 0, 0, ?, 'Pendiente', 'TEST_FINAL', 'prueba renumerar')")
    ->execute([$nDocPendiente, $fechaPendienteAntigua]);
$idVenta = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ventas_detalle (empresa_id, sucursal_id, cod_prod, descripcion, cant, p_unit, descuento_unitario, p_costo_venta, total, n_documento, fecha)
               VALUES (1, 1, ?, 'Producto prueba renumerar', 1, 100, 0, 50, 100, ?, NOW())")
    ->execute([$codProd, $nDocPendiente]);
$pdo->commit();


// --- Fase 2: POST real de Finalizar con esa pendiente ---
$_POST = [
    'venta_action'       => 'Finalizar',
    'id_venta_existente' => (string)$idVenta,
    'id_cliente_hidden'  => '0',
    'cond_pago'          => 'CONTADO',
    'desc_global_tipo'   => 'fijo',
    'desc_global_valor'  => '0',
    'pago_efectivo'      => '0',
    'pago_transf'        => '0',
    'observaciones'      => 'prueba renumerar',
    'detalle_productos'  => json_encode([
        ['cod_prod' => $codProd, 'descripcion' => 'Producto prueba renumerar', 'cant' => 1,
         'p_unit' => 100, 'p_costo_venta' => 50, 'desc' => 0, 'total' => 100],
    ]),
];


register_shutdown_function(function () use ($idVenta, $nDocPendiente, $maxNormalAntes, $codProd, $stockAntes, $fechaPendienteAntigua) {
    global $pdo;
    $html = ob_get_clean();

    $errores = [];
    $chk = function ($cond, $msg) use (&$errores) { if (!$cond) $errores[] = $msg; };

    $st = $pdo->prepare("SELECT id, n_documento, estado, fecha_venta FROM ventas WHERE id = ?");
    $st->execute([$idVenta]);
    $venta = $st->fetch();

    $chk(!empty($venta), "La venta de prueba sigue existiendo");
    if ($venta) {
        $nFinal = (int)$venta['n_documento'];

        // 1. Renumeración al numerador normal
        $chk($venta['estado'] === 'Finalizada', "estado='Finalizada' (obtenido '" . $venta['estado'] . "')");
        $chk($nFinal < VENTA_PENDIENTE_BASE, "Adoptó el numerador NORMAL (N° $nFinal < " . VENTA_PENDIENTE_BASE . ")");
        $chk($nFinal === $maxNormalAntes + 1, "Numerador normal correlativo (N° $nFinal = $maxNormalAntes + 1)");
        $chk($nFinal !== $nDocPendiente, "Ya no usa el número de pendientes (antes N° $nDocPendiente)");

        // 1b. Al cerrarse, la fecha es la del DÍA DE HOY (no la de cuando quedó pendiente)
        $fechaVenta = $venta['fecha_venta'];
        $chk(date('Y-m-d', strtotime($fechaVenta)) === date('Y-m-d'), "fecha_venta es la del día de cierre (hoy, obtuvo '$fechaVenta')");
        $chk($fechaVenta !== $fechaPendienteAntigua, "Dejó de tener la fecha en que quedó pendiente ($fechaPendienteAntigua)");

        // 2. Detalle re-enlazado al nuevo número y sin filas con el viejo
        $stD = $pdo->prepare("SELECT COUNT(*) FROM ventas_detalle WHERE n_documento = ? AND empresa_id = 1");
        $stD->execute([$nFinal]);
        $chk((int)$stD->fetchColumn() === 1, "Detalle re-enlazado al N° definitivo (filas=1)");
        $stD->execute([$nDocPendiente]);
        $chk((int)$stD->fetchColumn() === 0, "Sin detalle con el N° de pendientes antiguo");

        // 3. Efectos de finalizar aplicados bajo el N° nuevo
        $stS = $pdo->prepare("SELECT stock_actual FROM stocks WHERE empresa_id = 1 AND sucursal_id = 1 AND cod_prod = ?");
        $stS->execute([$codProd]);
        $stockDesp = (float)$stS->fetchColumn();
        $chk($stockDesp == $stockAntes - 1, "Stock descontado al finalizar ($stockAntes -> $stockDesp)");

        $stM = $pdo->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario = 'TEST_FINAL' AND detalle LIKE ?");
        $stM->execute(["%N° $nFinal%"]);
        $chk((int)$stM->fetchColumn() === 1, "Movimiento de caja registrado con el N° definitivo");

        // 4. Mensaje de sesión con el N° definitivo
        $msj = $_SESSION['status_msj'] ?? '';
        $chk(strpos($msj, "N° $nFinal") !== false, "Mensaje de sesión referencia el N° definitivo (msj='$msj')");

        // --- Limpieza: caja, stock, detalle y venta ---
        $pdo->prepare("DELETE FROM movimientos WHERE usuario = 'TEST_FINAL'")->execute();
        $pdo->prepare("UPDATE stocks SET stock_actual = ? WHERE empresa_id = 1 AND sucursal_id = 1 AND cod_prod = ?")
            ->execute([$stockAntes, $codProd]);
        $pdo->prepare("DELETE FROM ventas_detalle WHERE n_documento IN (?, ?) AND empresa_id = 1")
            ->execute([$nFinal, $nDocPendiente]);
        $pdo->prepare("DELETE FROM ventas WHERE id = ?")->execute([$idVenta]);
        $chk(true, "Datos de prueba eliminados (venta id=$idVenta: N° $nDocPendiente -> $nFinal)");
    }

    $chk(stripos((string)$html, 'Fatal error') === false, "Sin errores fatales de PHP");

    echo "=== TEST C: finalizar pendiente adopta numerador normal ===\n";
    if (empty($errores)) { echo "RESULTADO: PASO\n"; return; }
    foreach ($errores as $m) echo "  [FAIL] $m\n";
    echo "RESULTADO: FALLÓ (" . count($errores) . ")\n";
});

chdir(__DIR__ . '/../pages');
ob_start();
include 'ventas.php';

