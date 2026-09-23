<?php
/**
 * Test temporal del ciclo de cierre de caja con fondo de vuelto.
 * Se ejecuta en una transacción que SIEMPRE se revierte: no deja datos.
 */
$_SERVER['SCRIPT_NAME'] = '/pos_dev/procesos/_test_ciclo_cierre_fondo.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/laragon/www';

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../funciones/funciones_caja.php';

$empresa_id = 1;
$sucursal_id = 1;
$usuario = 'TEST-CLI';

$ok = 0;
$fail = 0;

function check($titulo, $esperado, $obtenido) {
    global $ok, $fail;
    $igual = (is_float($esperado) || is_float($obtenido))
        ? abs((float)$esperado - (float)$obtenido) < 0.005
        : ($esperado === $obtenido);
    if ($igual) {
        $ok++;
        echo "  [OK]    $titulo = " . var_export($obtenido, true) . "\n";
    } else {
        $fail++;
        echo "  [FALLA] $titulo -> esperado " . var_export($esperado, true) . " / obtenido " . var_export($obtenido, true) . "\n";
    }
}

function mov($pdo, $empresa_id, $sucursal_id, $tipo, $monto, $metodo, $efectivo, $transferencia, $detalle) {
    $sql = "INSERT INTO movimientos (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, es_fondo_inicial, monto_efectivo, monto_transferencia)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'TEST-CLI', 0, 0, ?, ?)";
    $pdo->prepare($sql)->execute([$empresa_id, $sucursal_id, $tipo, $monto, $metodo, $detalle, $efectivo, $transferencia]);
}

$pdo->beginTransaction();
try {
    // ---- Preparar un escenario limpio (se revierte al final) ----
    $pdo->exec("UPDATE estado_caja SET estado = 'CERRADA', fecha_cierre = NOW()
                WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id AND estado = 'ABIERTA'");
    $pdo->exec("UPDATE movimientos SET cerrado = 1
                WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id AND cerrado = 0");

    $hoy = date('Y-m-d');
    $fecha_desde = $hoy . ' 00:00:00';
    $fecha_hasta = date('Y-m-d H:i:s');

    echo "\n== CAJA 1: apertura con saldo 0 + movimientos del día ==\n";
    $r1 = abrir_caja($pdo, $empresa_id, $sucursal_id, 0, $usuario);
    check('apertura 1', true, $r1['success']);

    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 100.50, 'EFECTIVO', 100.50, 0, 'TEST venta efectivo');
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 200.25, 'EFECTIVO', 200.25, 0, 'TEST venta efectivo');
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 1191.04, 'EFECTIVO', 1191.04, 0, 'TEST venta centavos');
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 500.00, 'TRANSFERENCIA', 0, 500.00, 'TEST venta transferencia');
    // Venta MIXTA: 1.000 total (400 efectivo + 600 transferencia)
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 1000.00, 'MIXTO', 400.00, 600.00, 'TEST venta mixta');
    mov($pdo, $empresa_id, $sucursal_id, 'EGRESO', 50.10, 'EFECTIVO', 0, 0, 'TEST gasto efectivo');
    // Egreso por transferencia: NO debe descontarse del efectivo
    mov($pdo, $empresa_id, $sucursal_id, 'EGRESO', 300.00, 'TRANSFERENCIA', 0, 0, 'TEST pago por transferencia');

    // Efectivo esperado = 100,50 + 200,25 + 1.191,04 + 400 (mixta) - 50,10 = 1.841,69
    $esperado_caja1 = 1841.69;
    $saldo_real_caja1 = 1841.69;
    $fondo = 800.00;

    $res1 = cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo, $fecha_desde, $fecha_hasta, $saldo_real_caja1, 'TEST cierre 1');
    if (empty($res1['success'])) {
        echo "  [MENSAJE] " . ($res1['mensaje'] ?? 'sin mensaje') . "\n";
    }
    check('cierre 1 ok', true, $res1['success']);
    check('ingresos_efectivo caja 1 (centavos incluidos)', 1891.79, $res1['ingresos_efectivo']);
    check('egresos_efectivo caja 1 (sólo caja)', 50.10, $res1['egresos_efectivo']);
    check('egresos_no_efectivo caja 1 (no toca caja)', 300.00, $res1['egresos_no_efectivo']);
    check('saldo_esperado caja 1', $esperado_caja1, $res1['saldo_esperado']);
    check('diferencia caja 1', 0.00, $res1['diferencia']);

    $stmt = $pdo->prepare("SELECT * FROM cierres_caja WHERE id = ?");
    $stmt->execute([$res1['cierre_id']]);
    $c1 = $stmt->fetch(PDO::FETCH_ASSOC);
    check('cierres_caja.saldo_esperado_efectivo', $esperado_caja1, (float)$c1['saldo_esperado_efectivo']);
    check('cierres_caja.saldo_real_efectivo', $saldo_real_caja1, (float)$c1['saldo_real_efectivo']);
    check('cierres_caja.diferencia = real - esperado', 0.00, (float)$c1['diferencia']);
    check('cierres_caja.fondo_reservado_vuelto', $fondo, (float)$c1['fondo_reservado_vuelto']);

    // La caja 1 abrió con fondo 0: NO debe crearse movimiento de fondo inicial
    // (sólo se inserta si saldo_inicial > 0).
    $fondo_count = (int)$pdo->query("SELECT COUNT(*) FROM movimientos
                              WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id
                                AND es_fondo_inicial = 1")->fetchColumn();
    check('caja 1 (fondo 0) NO crea movimiento de fondo inicial', 0, $fondo_count);

    echo "\n== Fondo sugerido para la próxima apertura ==\n";
    $fila_fondo = $pdo->query("SELECT fondo_reservado_vuelto FROM cierres_caja
                               WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id
                                 AND usuario NOT LIKE 'Sistema (Cierre Histórico)%'
                               ORDER BY fecha_cierre DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('sugerencia de fondo = último cierre', $fondo, (float)$fila_fondo['fondo_reservado_vuelto']);

    echo "\n== CAJA 2: apertura con el fondo de la caja anterior (\$800) ==\n";
    $r2 = abrir_caja($pdo, $empresa_id, $sucursal_id, $fondo, $usuario);
    check('apertura 2', true, $r2['success']);

    $estado2 = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
    check('estado_caja.saldo_inicial caja 2', $fondo, (float)$estado2['saldo_inicial']);

    $fondo_mov2 = $pdo->query("SELECT monto, es_fondo_inicial, cerrado FROM movimientos
                               WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id
                               ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('movimiento FONDO INICIAL caja 2 marcado', 1, (int)$fondo_mov2['es_fondo_inicial']);
    check('monto movimiento FONDO INICIAL caja 2', $fondo, (float)$fondo_mov2['monto']);

    // Movimientos de la caja 2 (los de la caja 1 ya quedaron cerrados)
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 500.00, 'EFECTIVO', 500.00, 0, 'TEST venta efectivo caja 2');
    mov($pdo, $empresa_id, $sucursal_id, 'INGRESO', 300.00, 'MIXTO', 100.00, 200.00, 'TEST venta mixta caja 2');

    // Esperado = 800 (fondo, NO se duplica) + 500 + 100 = 1.400
    $esperado_caja2 = 1400.00;
    $res2 = cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, 0, $fecha_desde, $fecha_hasta, $esperado_caja2, 'TEST cierre 2');
    check('cierre 2 ok', true, $res2['success']);
    check('ingresos_efectivo caja 2 (sólo lo de la caja 2)', 600.00, $res2['ingresos_efectivo']);
    check('saldo_inicial caja 2 (fondo anterior, una sola vez)', $fondo, $res2['saldo_inicial']);
    check('saldo_esperado caja 2 SIN duplicar el fondo', $esperado_caja2, $res2['saldo_esperado']);
    check('diferencia caja 2 (el fondo NO genera diferencia)', 0.00, $res2['diferencia']);

    $st2 = $pdo->prepare("SELECT * FROM cierres_caja WHERE id = ?");
    $st2->execute([$res2['cierre_id']]);
    $c2 = $st2->fetch(PDO::FETCH_ASSOC);
    check('cierres_caja.diferencia caja 2 guardada', 0.00, (float)$c2['diferencia']);
    check('cierres_caja.egresos caja 2', 0.00, (float)$c2['egresos']);

    // La caja 2 abrió con el fondo de $800: su movimiento de fondo inicial debe
    // cerrarse junto con la sesión (si quedara abierto se filtraría a la caja siguiente).
    $fondo_c2 = $pdo->query("SELECT monto, cerrado FROM movimientos
                              WHERE empresa_id = $empresa_id AND sucursal_id = $sucursal_id
                                AND es_fondo_inicial = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('movimiento de fondo inicial de caja 2 cerrado con la sesión', 1, (int)($fondo_c2['cerrado'] ?? 0));
    check('monto del fondo inicial de caja 2', $fondo, (float)($fondo_c2['monto'] ?? -1));

    echo "\n== Resultado: $ok OK / $fail FALLAS ==\n";
} catch (Exception $e) {
    echo "\n[EXCEPCIÓN] " . $e->getMessage() . "\n";
    $fail++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "(transacción revertida: la base quedó sin cambios)\n";
    }
}
