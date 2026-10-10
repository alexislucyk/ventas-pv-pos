<?php
// procesos/_test_ventas_post_pendiente.php
// Prueba B: POST real de "Guardar (Pendiente)" contra pages/ventas.php.
// Verifica que la venta quede en estado 'Pendiente', que NO se descuente stock,
// que NO haya ingreso en caja / ctacte / cuotas, que NO se ofrezca ticket y
// que el mensaje de sesión diga PENDIENTE. Al final limpia los datos creados.
//
// Uso: php procesos/_test_ventas_post_pendiente.php

$_SERVER['SCRIPT_NAME']    = '/pos_dev/pages/ventas.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['DOCUMENT_ROOT']  = 'c:/laragon/www';

session_start();
$_SESSION['usuario_id']     = 1;
$_SESSION['usuario_nombre'] = 'TEST_PENDIENTE';
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

$_POST = [
    'venta_action'       => 'Pendiente',
    'id_venta_existente' => '',
    'id_cliente_hidden'  => '0',
    'cond_pago'          => 'CONTADO',
    'desc_global_tipo'   => 'fijo',
    'desc_global_valor'  => '0',
    'pago_efectivo'      => '0',
    'pago_transf'        => '0',
    'observaciones'      => 'prueba pendiente',
    'detalle_productos'  => json_encode([
        ['cod_prod' => $codProd, 'descripcion' => 'Producto prueba pendiente', 'cant' => 1,
         'p_unit' => 100, 'p_costo_venta' => 50, 'desc' => 10, 'total' => 90],
    ]),
];

register_shutdown_function(function () use ($codProd, $stockAntes) {
    global $pdo;
    $html = ob_get_clean();

    $errores = [];
    $chk = function ($cond, $msg) use (&$errores) { if (!$cond) $errores[] = $msg; };

    // 1. Mensaje de sesión y ausencia de modal de ticket
    $msj = $_SESSION['status_msj'] ?? '';
    $chk(strpos($msj, 'PENDIENTE') !== false, "Mensaje de sesión indica PENDIENTE (msj='$msj')");
    $chk(!isset($_SESSION['ticket_a_imprimir_doc']), "NO se pide imprimir ticket para una venta pendiente");
    $chk(!isset($_SESSION['ticket_a_imprimir_id']), "Sin id de ticket para pendiente");

    // 2. Estado en base de datos + alcance del guardado
    $v = $pdo->query("SELECT id, n_documento, estado FROM ventas WHERE usuario = 'TEST_PENDIENTE' ORDER BY id DESC LIMIT 1")->fetch();
    $chk(!empty($v), "La venta fue creada");
    if ($v) {
        $nDoc = (int)$v['n_documento'];
        $chk($v['estado'] === 'Pendiente', "estado='Pendiente' (obtenido '" . $v['estado'] . "')");
        $chk($nDoc >= VENTA_PENDIENTE_BASE, "Usa el numerador PROPIO de pendientes (N° $nDoc >= " . VENTA_PENDIENTE_BASE . ")");

        $stDet = $pdo->prepare("SELECT COUNT(*) FROM ventas_detalle WHERE n_documento = ? AND empresa_id = 1");
        $stDet->execute([$nDoc]);
        $det = (int)$stDet->fetchColumn();
        $chk($det === 1, "Detalle insertado (filas=$det)");

        $movs = (int)$pdo->query("SELECT COUNT(*) FROM movimientos WHERE detalle LIKE '%$nDoc'")->fetchColumn();
        $chk($movs === 0, "Sin ingreso en caja (movimientos=$movs)");

        $cc = (int)$pdo->query("SELECT COUNT(*) FROM ctacte WHERE n_documento = $nDoc")->fetchColumn();
        $chk($cc === 0, "Sin asiento en cuenta corriente (ctacte=$cc)");

        $cuo = (int)$pdo->query("SELECT COUNT(*) FROM cuotas_seguimiento WHERE id_venta = " . (int)$v['id'])->fetchColumn();
        $chk($cuo === 0, "Sin plan de cuotas (cuotas=$cuo)");

        // 3. Stock sin descontar
        $stStock = $pdo->prepare("SELECT stock_actual FROM stocks WHERE empresa_id = 1 AND sucursal_id = 1 AND cod_prod = ?");
        $stStock->execute([$codProd]);
        $stockDesp = (float)$stStock->fetchColumn();
        $chk($stockDesp == $stockAntes, "Stock sin descontar ($stockAntes -> $stockDesp)");

        // 4. Limpieza de los datos de prueba
        $pdo->prepare("DELETE FROM ventas_detalle WHERE n_documento = ? AND empresa_id = 1")->execute([$nDoc]);
        $pdo->prepare("DELETE FROM ventas WHERE id = ?")->execute([$v['id']]);
        $chk(true, "Datos de prueba eliminados (venta N° $nDoc)");
    }

    $chk(stripos((string)$html, 'Fatal error') === false, "Sin errores fatales de PHP");

    echo "=== TEST B: guardar venta PENDIENTE (end-to-end) ===\n";
    if (empty($errores)) { echo "RESULTADO: PASO\n"; return; }
    foreach ($errores as $m) echo "  [FAIL] $m\n";
    echo "RESULTADO: FALLÓ (" . count($errores) . ")\n";
});

chdir(__DIR__ . '/../pages');
ob_start();
include 'ventas.php';
