<?php
/**
 * Test del cálculo de intereses por mora sobre el SALDO DEUDOR de la cuenta.
 * Corre en una transacción que SIEMPRE se revierte: no deja datos.
 *
 * Uso: php procesos/_test_intereses_saldo.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}

$_SERVER['SCRIPT_NAME'] = '/pos_dev/procesos/_test_intereses_saldo.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/laragon/www';

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../funciones/funciones_intereses.php';
require_once __DIR__ . '/../funciones/funciones_pagos_ctacte.php';

$empresa_id = 1;
$hoy = date('Y-m-d');

$_SESSION['empresa_id'] = $empresa_id;
$_SESSION['usuario_nombre'] = 'TEST-CLI';

$ok = 0;
$fail = 0;

function check($titulo, $esperado, $obtenido) {
    global $ok, $fail;
    $igual = (is_float($esperado) || is_float($obtenido))
        ? abs((float)$esperado - (float)$obtenido) < 0.02
        : ($esperado === $obtenido);
    if ($igual) {
        $ok++;
        echo "  [OK]    $titulo = " . var_export($obtenido, true) . "\n";
    } else {
        $fail++;
        echo "  [FALLA] $titulo -> esperado " . var_export($esperado, true) . " / obtenido " . var_export($obtenido, true) . "\n";
    }
}

function crearClienteTest(PDO $pdo, int $empresa_id, string $sufijo): int {
    // clientes.id no es AUTO_INCREMENT: lo asigna la aplicación
    $id = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM clientes")->fetchColumn();
    $pdo->prepare("INSERT INTO clientes (id, empresa_id, apellido, nombre, direccion, cuit, telefono, estado, habilita_cta, relacion)
                   VALUES (?, ?, 'TEST', ?, '', ?, '', 'Activo', 'Si', '')")
        ->execute([$id, $empresa_id, 'INTERESES ' . $sufijo, 'TEST-' . $sufijo . '-' . mt_rand(1000, 9999)]);
    return $id;
}

/** Factura al fiado con vencimiento a N días de hoy (negativo = ya vencida). */
function facturaTest(PDO $pdo, int $empresa_id, int $cliente, float $monto, int $diasVencimiento, string $doc) {
    $venc = date('Y-m-d', strtotime("$diasVencimiento days"));
    $fecha = date('Y-m-d', strtotime("$diasVencimiento days -30 days"));
    $pdo->prepare("INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha, fecha_vencimiento, usuario, empresa_id, es_interes)
                   VALUES (?, 'FACTURA', ?, ?, 0, ?, ?, 'TEST-CLI', ?, 0)")
        ->execute([$cliente, $doc, $monto, $fecha, $venc, $empresa_id]);
}

function pagoTest(PDO $pdo, int $empresa_id, int $cliente, float $monto, int $diasAtras, string $doc) {
    $pdo->prepare("INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha, usuario, empresa_id, es_interes)
                   VALUES (?, 'Pago Cta.Cte.', ?, 0, ?, ?, 'TEST-CLI', ?, 0)")
        ->execute([$cliente, $doc, $monto, date('Y-m-d', strtotime("$diasAtras days")), $empresa_id]);
}
$pdo->beginTransaction();
try {
    // Configuración conocida para el test: 7% mensual, sin gracia, modo DIARIO
    $pdo->exec("UPDATE configuracion_intereses SET tasa_mensual = 7.00, dias_gracia = 0,
                       modo_calculo = 'DIARIO', activo = 1, fecha_vigencia = NULL
                WHERE empresa_id = $empresa_id");

    echo "== 1) Factura vencida hace 45 dias, sin pagos ==\n";
    $c1 = crearClienteTest($pdo, $empresa_id, '1');
    facturaTest($pdo, $empresa_id, $c1, 10000, -45, 'T1');
    $r = calcularInteresesCliente($c1, $pdo, $empresa_id);
    check('saldo deudor', 10000.0, $r['saldo_deudor']);
    check('dias efectivos', 45, $r['dias_efectivos']);
    check('interes (10000 × 0,07/30 × 45)', 1050.0, $r['interes_total']);
    check('fecha base = vencimiento', date('Y-m-d', strtotime('-45 days')), $r['fecha_base']);

    echo "== 2) Aplicar y recalcular: no debe duplicar la mora ==\n";
    $apl = aplicarInteresesMora($c1, $pdo, 1);
    check('aplicacion exitosa', true, (bool)$apl['success']);
    check('monto aplicado', 1050.0, $apl['monto_aplicado'] ?? 0);
    $r2 = calcularInteresesCliente($c1, $pdo, $empresa_id);
    check('interes justo despues de aplicar (corte = hoy)', 0.0, $r2['interes_total']);
    check('fecha de corte', $hoy, $r2['fecha_corte']);
    $apl2 = aplicarInteresesMora($c1, $pdo, 1);
    check('segunda aplicacion el mismo dia rechazada', false, (bool)$apl2['success']);
    $movs = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(debe),0) AS debe FROM ctacte
                         WHERE id_cliente = $c1 AND es_interes = 1")->fetch(PDO::FETCH_ASSOC);
    check('movimientos de interes creados', 1, (int)$movs['n']);
    check('total del movimiento de interes', 1050.0, (float)$movs['debe']);
    check('los intereses no generan interes (anatocismo)',
        10000.0, calcularInteresesCliente($c1, $pdo, $empresa_id)['saldo_deudor']);

    echo "== 3) Modo MENSUAL: solo meses completos ==\n";
    $pdo->exec("UPDATE configuracion_intereses SET modo_calculo = 'MENSUAL' WHERE empresa_id = $empresa_id");
    $c2 = crearClienteTest($pdo, $empresa_id, '2');
    facturaTest($pdo, $empresa_id, $c2, 10000, -45, 'T2');
    $r3 = calcularInteresesCliente($c2, $pdo, $empresa_id);
    check('45 dias => 1 mes (10000 × 7%)', 700.0, $r3['interes_total']);
    facturaTest($pdo, $empresa_id, $c2, 10000, -75, 'T3');
    $r4 = calcularInteresesCliente($c2, $pdo, $empresa_id);
    check('saldo deudor acumulado', 20000.0, $r4['saldo_deudor']);
    check('75 dias => 2 meses (20000 × 7% × 2)', 2800.0, $r4['interes_total']);
    $pdo->exec("UPDATE configuracion_intereses SET modo_calculo = 'DIARIO' WHERE empresa_id = $empresa_id");

    echo "== 4) FIFO: el pago cancela primero la factura mas vieja ==\n";
    $c3 = crearClienteTest($pdo, $empresa_id, '3');
    facturaTest($pdo, $empresa_id, $c3, 5000, -60, 'T4');
    facturaTest($pdo, $empresa_id, $c3, 3000, -10, 'T5');
    pagoTest($pdo, $empresa_id, $c3, 5000, -20, 'R1');
    $r5 = calcularInteresesCliente($c3, $pdo, $empresa_id);
    check('saldo deudor (queda la factura nueva)', 3000.0, $r5['saldo_deudor']);
    check('inicio de mora = vencimiento de la factura impaga', date('Y-m-d', strtotime('-10 days')), $r5['inicio_mora']);
    check('interes 10 dias sobre 3000', 70.0, $r5['interes_total']);

    echo "== 5) Cliente a favor y cliente dentro del plazo ==\n";
    $c4 = crearClienteTest($pdo, $empresa_id, '4');
    facturaTest($pdo, $empresa_id, $c4, 1000, -50, 'T6');
    pagoTest($pdo, $empresa_id, $c4, 2000, -5, 'R2');
    $r6 = calcularInteresesCliente($c4, $pdo, $empresa_id);
    check('motivo', 'sin_saldo_deudor', $r6['motivo']);
    check('interes', 0.0, $r6['interes_total']);

    $c5 = crearClienteTest($pdo, $empresa_id, '5');
    facturaTest($pdo, $empresa_id, $c5, 1000, 15, 'T7');
    $r7 = calcularInteresesCliente($c5, $pdo, $empresa_id);
    check('motivo', 'dentro_del_plazo', $r7['motivo']);
    check('interes', 0.0, $r7['interes_total']);

    echo "== 6) Dias de gracia ==\n";
    $pdo->exec("UPDATE configuracion_intereses SET dias_gracia = 5 WHERE empresa_id = $empresa_id");
    $c6 = crearClienteTest($pdo, $empresa_id, '6');
    facturaTest($pdo, $empresa_id, $c6, 6000, -35, 'T8');
    $r8 = calcularInteresesCliente($c6, $pdo, $empresa_id);
    check('35 dias de mora - 5 de gracia', 30, $r8['dias_efectivos']);
    check('interes 30 dias sobre 6000', 420.0, $r8['interes_total']);
    $pdo->exec("UPDATE configuracion_intereses SET dias_gracia = 0 WHERE empresa_id = $empresa_id");

    echo "== 7) Resumen de mora de la empresa (listado) ==\n";
    $resumen = obtenerResumenInteresesPendientes($pdo, $empresa_id);
    check('incluye al cliente en mora con su interes', 70.0, (float)($resumen[$c3]['interes'] ?? 0));
    check('no incluye al cliente a favor', false, isset($resumen[$c4]));
    check('no incluye al cliente dentro del plazo', false, isset($resumen[$c5]));

    echo "== 8) Fecha de vigencia opcional ==\n";
    $pdo->exec("UPDATE configuracion_intereses SET fecha_vigencia = '" . date('Y-m-d', strtotime('-7 days')) . "'
                WHERE empresa_id = $empresa_id");
    $c7 = crearClienteTest($pdo, $empresa_id, '7');
    facturaTest($pdo, $empresa_id, $c7, 9000, -45, 'T9');
    $r9 = calcularInteresesCliente($c7, $pdo, $empresa_id);
    check('la mora se cuenta desde la vigencia', 7, $r9['dias_efectivos']);
    check('interes 7 dias sobre 9000', 147.0, $r9['interes_total']);
    $pdo->exec("UPDATE configuracion_intereses SET fecha_vigencia = NULL WHERE empresa_id = $empresa_id");

    echo "== 9) El pago se aplica sobre el SALDO de la cuenta ==\n";
    $cliente_pago = (int)$pdo->query("SELECT id_cliente FROM ctacte
                                      WHERE empresa_id = $empresa_id AND debe > haber AND es_interes = 0
                                      GROUP BY id_cliente LIMIT 1")->fetchColumn();
    check('hay un cliente con deuda', true, $cliente_pago > 0);

    $saldo_antes = saldoCuentaCc($pdo, $cliente_pago, $empresa_id);
    $pago = registrarPagoCuentaCorriente($pdo, [
        'id_cliente' => $cliente_pago,
        'empresa_id' => $empresa_id,
        'monto_pago' => 1000,
        'n_recibo'   => 'TEST-PAGO-51',
        'fecha'      => $hoy,
        'usuario'    => 'TEST-CLI'
    ]);
    check('el abono baja el saldo justo el monto', round($saldo_antes - 1000, 2),
        saldoCuentaCc($pdo, $cliente_pago, $empresa_id));
    check('el pago devuelve el saldo anterior', $saldo_antes, $pago['saldo_anterior']);
    check('el pago devuelve el saldo final', saldoCuentaCc($pdo, $cliente_pago, $empresa_id), $pago['saldo_final']);
    check('el pago no crea imputaciones, solo el movimiento', 1,
        (int)$pdo->query("SELECT COUNT(*) FROM ctacte WHERE n_documento = 'TEST-PAGO-51'")->fetchColumn());

    // Pagar de mas deja saldo a favor: el interes se detiene hasta volver a deber.
    $c8 = crearClienteTest($pdo, $empresa_id, '8');
    facturaTest($pdo, $empresa_id, $c8, 4000, -20, 'T10');
    check('el cliente nuevo arranca debiendo 4000', 4000.0, saldoCuentaCc($pdo, $c8, $empresa_id));
    registrarPagoCuentaCorriente($pdo, [
        'id_cliente' => $c8,
        'empresa_id' => $empresa_id,
        'monto_pago' => 5000,
        'n_recibo'   => 'TEST-PAGO-52',
        'fecha'      => $hoy,
        'usuario'    => 'TEST-CLI'
    ]);
    check('el exceso sobre la deuda queda como saldo a favor', -1000.0, saldoCuentaCc($pdo, $c8, $empresa_id));
    check('con saldo a favor no hay interes', 'sin_saldo_deudor',
        calcularInteresesCliente($c8, $pdo, $empresa_id)['motivo']);

    // La migracion 51 elimina las tablas de metadatos que ya no leen nada.
    $tablas_viejas = 0;
    foreach (['ctacte_pagos_imputaciones', 'ctacte_creditos_a_favor_aplicaciones'] as $tabla_vieja) {
        $st_tabla = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st_tabla->execute([$tabla_vieja]);
        $tablas_viejas += (int)$st_tabla->fetchColumn();
    }
    check('las tablas de imputacion ya no existen (migracion 51)', 0, $tablas_viejas);

    echo "== 10) Los movimientos de interes son deuda pero no son pagables ==\n";
    $sql_pagables = "SELECT COUNT(*) FROM ctacte c WHERE c.id_cliente = $c1 AND c.empresa_id = $empresa_id
                       AND c.debe > c.haber AND DATE(c.fecha) <= CURDATE() AND " . condicionSqlNoEsInteresCc($pdo, 'c');
    check('facturas pagables del cliente (solo la original)', 1, (int)$pdo->query($sql_pagables)->fetchColumn());
    $sql_interes = "SELECT COUNT(*) FROM ctacte WHERE id_cliente = $c1 AND empresa_id = $empresa_id AND es_interes = 1 AND debe > 0";
    check('el interes es un movimiento de deuda', 1, (int)$pdo->query($sql_interes)->fetchColumn());
    check('el interes sigue sumando al saldo del cliente',
        11050.0, (float)$pdo->query("SELECT COALESCE(SUM(debe - haber), 0) FROM ctacte WHERE id_cliente = $c1 AND empresa_id = $empresa_id")->fetchColumn());

} catch (Throwable $e) {
    $fail++;
    echo "  [EXCEPCION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\nResultado: $ok OK / $fail FALLAS (todos los datos del test se revirtieron)\n";
exit($fail > 0 ? 1 : 0);
