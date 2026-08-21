<?php
// ajax/obtener_transferencias_caja.php
// Listado JSON de los movimientos de la caja abierta con transferencia
// (solo lectura). Devuelve tres grupos:
//   - pendientes     (transferencia_validada = 0)
//   - validadas      (transferencia_validada = 1)
//   - no_realizadas  (transferencia_validada = 2)
include '../pages/infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

header('Content-Type: application/json; charset=utf-8');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
if (!$estado || $estado['estado'] !== 'ABIERTA') {
    echo json_encode(['success' => false, 'message' => 'La caja no está abierta.']);
    exit();
}

$fecha_apertura_db = $estado['fecha_apertura'] ?? date('Y-m-d H:i:s');
$apertura = date('Y-m-d', strtotime($fecha_apertura_db)) . ' 00:00:00';

$sql = "SELECT id, tipo, metodo_pago, detalle, monto, monto_efectivo, monto_transferencia,
               fecha, usuario,
               transferencia_validada, transferencia_validada_usuario,
               transferencia_validada_fecha, transferencia_comprobante,
               transferencia_no_realizada_accion, transferencia_observacion
        FROM movimientos
        WHERE cerrado = 0
          AND fecha >= ?
          AND empresa_id = ?
          AND sucursal_id = ?
          AND tipo = 'INGRESO'
          AND (metodo_pago IN ('TRANSFERENCIA','MIXTO') OR monto_transferencia > 0)
        ORDER BY id DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute([$apertura, $empresa_id, $sucursal_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendientes = [];
$validadas = [];
$noRealizadas = [];

// Mapas para resolver cliente y venta en lote (evita N+1)
$idsCliente = [];  // ids de cliente (directos o vía venta)
$docsVenta  = [];  // n_documento a resolver contra ventas
$docsPagoCC = [];  // n_recibo de "PAGO RECIBIDO - CLIENTE #X (Recibo Y)"

foreach ($rows as $r) {
    $detalle = trim($r['detalle']);
    $r['cliente'] = null;
    $r['n_documento'] = null;
    $r['venta_id'] = null;
    $r['venta_cond_pago'] = null;
    $r['es_venta'] = false;
    $r['es_pago_ctacte'] = false;
    $r['acciones'] = ['PENDIENTE'];
    $r['transferencia_monto'] = ((float)$r['monto_transferencia'] > 0) ? (float)$r['monto_transferencia'] : (float)$r['monto'];

    if (preg_match('/CLIENTE\s*#?\s*(\d+)/i', $detalle, $m)) {
        // Formato: "PAGO RECIBIDO - CLIENTE #134 (Recibo 553)"
        $idCli = (int)$m[1];
        $r['cliente_id'] = $idCli;
        $idsCliente[$idCli] = $idCli;

        if (preg_match('/Recibo\s+(\d+)/i', $detalle, $rm)) {
            $r['es_pago_ctacte'] = true;
            $r['acciones'] = ['REVERSAR', 'PENDIENTE'];
            $r['recibo'] = (int)$rm[1];
            $docsPagoCC[(int)$rm[1]] = (int)$rm[1];
        }
    } elseif (preg_match('/N\s*[°º]?\s*(\d+)/u', $detalle, $m)) {
        // Formato: "VENTA CONTADO N° 2193" / "ENTREGA/PAGO - VENTA N° 123 (CTA. CTE.)"
        $nroDoc = (int)$m[1];
        $r['n_documento'] = $nroDoc;
        $docsVenta[$nroDoc] = $nroDoc;
    }

    $r['monto'] = (float)$r['monto'];
    $r['monto_efectivo'] = (float)($r['monto_efectivo'] ?? 0);
    $r['monto_transferencia'] = (float)($r['monto_transferencia'] ?? 0);

    if ((int)$r['transferencia_validada'] === 1) {
        $validadas[] = $r;
    } elseif ((int)$r['transferencia_validada'] === 2) {
        $noRealizadas[] = $r;
    } else {
        $pendientes[] = $r;
    }
}

// Resolver n_documento -> venta (id, id_cliente, cond_pago, estado)
$mapaVentas = [];
if ($docsVenta) {
    $in = implode(',', array_map('intval', array_keys($docsVenta)));
    $sqlv = "SELECT n_documento, id, id_cliente, cond_pago, estado
             FROM ventas
             WHERE empresa_id = ? AND sucursal_id = ? AND n_documento IN ($in)";
    $stmtv = $pdo->prepare($sqlv);
    $stmtv->execute([$empresa_id, $sucursal_id]);
    foreach ($stmtv->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $mapaVentas[(int)$v['n_documento']] = $v;
        $idsCliente[(int)$v['id_cliente']] = (int)$v['id_cliente'];
    }
}

// Verificar pagos de cta.cte. registrados para el n_recibo (para poder reversar)
$mapaPagoCC = [];
if ($docsPagoCC) {
    $in = implode(',', array_map('intval', array_keys($docsPagoCC)));
    $sqlp = "SELECT n_documento, id, id_cliente, haber
             FROM ctacte
             WHERE empresa_id = ? AND movimiento = 'Pago Cta.Cte.' AND n_documento IN ($in)";
    $stmtp = $pdo->prepare($sqlp);
    $stmtp->execute([$empresa_id]);
    foreach ($stmtp->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $mapaPagoCC[(int)$p['n_documento']] = $p;
        $idsCliente[(int)$p['id_cliente']] = (int)$p['id_cliente'];
    }
}

// Nombre real de cada cliente
$mapaNombres = [];
if ($idsCliente) {
    $in = implode(',', array_map('intval', array_keys($idsCliente)));
    $sqlc = "SELECT id, CONCAT(TRIM(apellido), ' ', TRIM(nombre)) AS nombre_cliente
             FROM clientes
             WHERE id IN ($in)";
    foreach ($pdo->query($sqlc)->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $mapaNombres[(int)$c['id']] = $c['nombre_cliente'];
    }
}

// Asignar cliente y venta a cada movimiento
foreach ($pendientes as &$r) { resolver_datos_transferencia($r, $mapaVentas, $mapaPagoCC, $mapaNombres); }
unset($r);
foreach ($validadas as &$r) { resolver_datos_transferencia($r, $mapaVentas, $mapaPagoCC, $mapaNombres); }
unset($r);
foreach ($noRealizadas as &$r) { resolver_datos_transferencia($r, $mapaVentas, $mapaPagoCC, $mapaNombres); }
unset($r);

// Las transferencias de ventas de contado (totales o parciales) marcadas como NO realizadas
// ya fueron resueltas (se neutralizó el ingreso y se anuló/pasó a en espera la venta),
// por lo que se quitan del listado de validación.
$noRealizadasFiltradas = [];
foreach ($noRealizadas as $r) {
    if ($r['es_venta'] && strtoupper((string)$r['venta_cond_pago']) === 'CONTADO') {
        continue;
    }
    $noRealizadasFiltradas[] = $r;
}
$noRealizadas = $noRealizadasFiltradas;

echo json_encode([
    'success' => true,
    'pendientes' => $pendientes,
    'validadas' => $validadas,
    'no_realizadas' => $noRealizadas
]);
exit();

function resolver_datos_transferencia(&$r, $mapaVentas, $mapaPagoCC, $mapaNombres) {
    if (!empty($r['es_pago_ctacte']) && !empty($r['recibo']) && isset($mapaPagoCC[$r['recibo']])) {
        $p = $mapaPagoCC[$r['recibo']];
        $r['pago_cc_id'] = (int)$p['id'];
        $r['pago_cc_haber'] = (float)$p['haber'];
        if (!empty($p['id_cliente']) && isset($mapaNombres[(int)$p['id_cliente']])) {
            $r['cliente'] = $mapaNombres[(int)$p['id_cliente']];
        }
    } elseif (!empty($r['n_documento']) && isset($mapaVentas[$r['n_documento']])) {
        $v = $mapaVentas[$r['n_documento']];
        $r['es_venta'] = true;
        $r['venta_id'] = (int)$v['id'];
        $r['venta_cond_pago'] = $v['cond_pago'];
        if ((int)$r['transferencia_validada'] === 0) {
            $r['acciones'] = (strtoupper((string)$v['cond_pago']) === 'CONTADO')
                ? ['ANULAR', 'CTACTE', 'PENDIENTE']
                : ['PENDIENTE'];
        }
        if (!empty($v['id_cliente']) && isset($mapaNombres[(int)$v['id_cliente']])) {
            $r['cliente'] = $mapaNombres[(int)$v['id_cliente']];
        }
    } elseif (!empty($r['cliente_id']) && isset($mapaNombres[$r['cliente_id']])) {
        $r['cliente'] = $mapaNombres[$r['cliente_id']];
    }

    unset($r['cliente_id']);
    unset($r['n_documento']);
    unset($r['recibo']);
}