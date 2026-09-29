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

    echo "== 9) Smoke test de las consultas de pagos/imputaciones ==\n";
    $cliente_con_facturas = (int)$pdo->query("SELECT id_cliente FROM ctacte
                                              WHERE empresa_id = $empresa_id AND debe > haber AND es_interes = 0
                                              GROUP BY id_cliente LIMIT 1")->fetchColumn();
    check('hay un cliente con facturas impagas', true, $cliente_con_facturas > 0);

    // Misma consulta que usa ajax/obtener_facturas_ctacte_ajax.php (facturas imputables)
    $sql_facturas = "SELECT c.id, c.saldo_disponible FROM (
                        SELECT c.id,
                               (c.debe - c.haber
                                 - COALESCE((SELECT SUM(i.importe_aplicado) FROM ctacte_pagos_imputaciones i
                                     WHERE i.movimiento_deudor_id = c.id AND i.empresa_id = c.empresa_id AND i.id_cliente = c.id_cliente), 0)
                                 - COALESCE((SELECT SUM(a.importe_aplicado) FROM ctacte_creditos_a_favor_aplicaciones a
                                     WHERE a.movimiento_deudor_id = c.id AND a.empresa_id = c.empresa_id AND a.id_cliente = c.id_cliente), 0)
                               ) AS saldo_disponible
                        FROM ctacte c
                        WHERE c.id_cliente = $cliente_con_facturas AND c.empresa_id = $empresa_id
                          AND c.debe > c.haber AND DATE(c.fecha) <= CURDATE()
                          AND " . condicionSqlNoEsInteresCc($pdo, 'c') . "
                     ) c
                     HAVING saldo_disponible > 0.005";
    $facturas_pagables = $pdo->query($sql_facturas)->fetchAll(PDO::FETCH_ASSOC);
    check('la consulta de facturas pagables se ejecuta', true, is_array($facturas_pagables));

    // Bloqueo + saldo disponible de una factura (rutas de registrarPagoCuentaCorrienteV2)
    if ($facturas_pagables) {
        $id_factura = (int)$facturas_pagables[0]['id'];
        $saldo = saldoDisponibleMovimientoCc($pdo, $id_factura, $empresa_id, $cliente_con_facturas);
        check('saldo disponible de la factura > 0', true, $saldo > 0);
    }
    $credito_aplicado = reconciliarSaldosAFavorCc($pdo, $empresa_id, $cliente_con_facturas, $hoy, 'test_intereses');
    check('la reconciliacion de saldos a favor se ejecuta', true, is_float($credito_aplicado));

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
