<?php
// procesos/_test_ventas_repost_error.php
// Prueba A: POST de "Guardar (Pendiente)" con un producto inexistente.
// Verifica que el formulario se repuebla con los datos enviados (para que el
// reintento actualice la misma pendiente en vez de duplicarla) y que el JSON
// del carrito se emite escapado. No persiste datos (rollback del backend).
//
// Uso: php procesos/_test_ventas_repost_error.php

$_SERVER['SCRIPT_NAME']   = '/pos_dev/pages/ventas.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['DOCUMENT_ROOT'] = 'c:/laragon/www';

session_start();
$_SESSION['usuario_id']   = 1;
$_SESSION['usuario_nombre'] = 'TEST_RENDER';
$_SESSION['empresa_id']   = 1;
$_SESSION['sucursal_id']  = 1;
$_SESSION['usuario_rol']  = 'developer';

$_POST = [
    'venta_action'       => 'Pendiente',
    'id_venta_existente' => '12345',          // pendiente existente simulada
    'id_cliente_hidden'  => '7',
    'cond_pago'          => 'CUENTA CORRIENTE',
    'desc_global_tipo'   => 'porcentaje',
    'desc_global_valor'  => '15',
    'pago_efectivo'      => '500',
    'pago_transf'        => '250',
    'observaciones'      => '<b>obs</b> "con comillas"',
    'detalle_productos'  => json_encode([
        ['cod_prod' => 'NO-EXISTE-XYZ', 'descripcion' => 'x', 'cant' => 1,
         'p_unit' => 10, 'p_costo_venta' => 5, 'total' => 10],
    ]),
];

chdir(__DIR__ . '/../pages');
ob_start();
include 'ventas.php';
$html = ob_get_clean();

$errores = [];
function chk($cond, $msg) { global $errores; if (!$cond) $errores[] = $msg; }

chk(strpos($html, 'Producto no encontrado: NO-EXISTE-XYZ') !== false, "Se muestra el error del backend (❌)");
chk(strpos($html, 'id="id_venta_existente" value="12345"') !== false, "id_venta_existente repoblado (no se duplica la pendiente)");
chk(strpos($html, 'id="id_cliente_hidden" value="7"') !== false, "id_cliente_hidden repoblado");
chk(strpos($html, 'value="CUENTA CORRIENTE" selected') !== false, "cond_pago repoblado (selected)");
chk(strpos($html, 'value="porcentaje" selected') !== false, "desc_global_tipo repoblado (selected)");
chk(strpos($html, 'id="desc_global_valor"') !== false && strpos($html, 'value="15"') !== false, "desc_global_valor repoblado");
chk(strpos($html, 'id="pago_efectivo"') !== false && strpos($html, 'value="500"') !== false, "pago_efectivo repoblado");
chk(strpos($html, 'id="pago_transf"') !== false && strpos($html, 'value="250"') !== false, "pago_transf repoblado");
chk(strpos($html, '&lt;b&gt;obs&lt;/b&gt;') !== false, "observaciones repobladas y escapadas (HTML seguro)");
chk(strpos($html, '.filter(function(i) { return i && i.cod_prod; })') !== false, "Carrito restaurado desde JSON escapado");
chk(strpos($html, 'id="btnNuevaVenta"') !== false, "Botón Nueva presente");
chk(strpos($html, 'id="modalTicket"') === false, "No se ofrece ticket en un guardado fallido");
chk(stripos($html, 'Fatal error') === false && stripos($html, 'Uncaught') === false, "Sin errores fatales de PHP");

// Verificar que no quedó ninguna venta creada por este test
require __DIR__ . '/../config/db_config.php';
$creadas = (int)$pdo->query("SELECT COUNT(*) FROM ventas WHERE usuario = 'TEST_RENDER'")->fetchColumn();
chk($creadas === 0, "No se creó ninguna venta (rollback), ventas TEST_RENDER=$creadas");

echo "=== TEST A: repost tras error de guardado pendiente ===\n";
if (empty($errores)) {
    echo "RESULTADO: PASO\n";
    exit(0);
}
foreach ($errores as $m) echo "  [FAIL] $m\n";
echo "RESULTADO: FALLÓ (" . count($errores) . ")\n";
exit(1);
