<?php
// pages/caja_dashboard.php
include 'infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Obtener estado de caja
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
$caja_abierta = $estado && $estado['estado'] === 'ABIERTA';

// Si la caja está cerrada, redirigir
if (!$caja_abierta) {
    header("Location: " . url('abrir-caja'));
    exit();
}

$hoy = date('Y-m-d');

try {
    // Modelo por sesión: la caja puede estar abierta varios días. Se muestran
    // todos los movimientos sin cerrar desde la apertura de la sesión actual.
    // movimientos.fecha es DATE (sin hora), por lo que el filtro arranca a las
    // 00:00 del día de apertura (consistente con el cierre de caja).
    $fecha_apertura_db = $estado['fecha_apertura'] ?? date('Y-m-d H:i:s');
    $apertura = date('Y-m-d', strtotime($fecha_apertura_db)) . ' 00:00:00';

    // USAR cerrado = 0 en lugar de DATE(fecha) = ?
    $sql_resumen = "SELECT 
                        SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) as efectivo,
                        SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as transferencia,
                        SUM(CASE WHEN metodo_pago = 'MIXTO' THEN monto ELSE 0 END) as mixto
                    FROM movimientos 
                    WHERE tipo = 'INGRESO' 
                      AND cerrado = 0 
                      AND fecha >= ?
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    $stmt = $pdo->prepare($sql_resumen);
    $stmt->execute([$apertura, $empresa_id, $sucursal_id]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['mixto'] = (float)($resumen['mixto'] ?? 0);

    $sql_egresos = "SELECT SUM(monto) as total_egresos 
                    FROM movimientos 
                    WHERE tipo = 'EGRESO' 
                      AND cerrado = 0 
                      AND fecha >= ?
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    $stmt_eg = $pdo->prepare($sql_egresos);
    $stmt_eg->execute([$apertura, $empresa_id, $sucursal_id]);
    $egresos = $stmt_eg->fetchColumn() ?: 0;

    $sql_movs = "SELECT tipo, metodo_pago, detalle, monto, fecha, usuario 
                 FROM movimientos 
                 WHERE cerrado = 0 
                   AND fecha >= ?
                   AND empresa_id = ? 
                   AND sucursal_id = ?
                 ORDER BY id DESC LIMIT 10";
    $stmt_m = $pdo->prepare($sql_movs);
    $stmt_m->execute([$apertura, $empresa_id, $sucursal_id]);
    $lista_movimientos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // Cantidad de transferencias pendientes de validar (para badge y modal)
    $sql_transf_pend = "SELECT COUNT(*) FROM movimientos
                        WHERE cerrado = 0
                          AND fecha >= ?
                          AND empresa_id = ?
                          AND sucursal_id = ?
                          AND tipo = 'INGRESO'
                          AND (metodo_pago IN ('TRANSFERENCIA','MIXTO') OR monto_transferencia > 0)
                          AND transferencia_validada = 0";
    $stmt_tp = $pdo->prepare($sql_transf_pend);
    $stmt_tp->execute([$apertura, $empresa_id, $sucursal_id]);
    $cant_transf_pendientes = (int)$stmt_tp->fetchColumn();

    // Incluir saldo inicial en el cálculo del total de caja física
    $saldo_inicial = (float)($estado['saldo_inicial'] ?? 0);
    $total_caja_fisica = $saldo_inicial + ($resumen['efectivo'] + $resumen['mixto']) - $egresos;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Utilidad para formatear montos
function fmt_moneda($monto) {
    return '$ ' . number_format((float)$monto, 2, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja del Día | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg: #141414;
            --panel: #1e1e1e;
            --panel-2: #181818;
            --border: #2e2e2e;
            --border-soft: #333;
            --accent: #00bcd4;
            --accent-soft: rgba(0, 188, 212, 0.12);
            --success: #2ecc71;
            --success-soft: rgba(46, 204, 113, 0.14);
            --warn: #f1c40f;
            --warn-soft: rgba(241, 196, 15, 0.14);
            --danger: #e74c3c;
            --danger-soft: rgba(231, 76, 60, 0.14);
            --text: #e8e8e8;
            --muted: #9aa0a6;
            --muted-2: #666;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', 'Roboto', Helvetica, Arial, sans-serif;
            margin: 0;
            overflow-x: hidden;
        }

        .content { padding: 70px 30px 30px; }
        .dash-container { max-width: 1200px; margin: 0 auto; }

        /* ===== CABECERA DE PÁGINA ===== */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .page-title .icon {
            background: var(--success-soft);
            color: var(--success);
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .page-title .icon.cerrada { background: var(--danger-soft); color: var(--danger); }
        .page-title h1 { margin: 0; font-size: 1.5rem; color: var(--accent); border: none; padding: 0; }
        .page-title .sub { color: var(--muted); font-size: 0.85rem; margin-top: 3px; }
        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 18px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.88rem;
            transition: all 0.2s;
        }
        .btn-action.primary {
            background: linear-gradient(135deg, #00bcd4, #0091a7);
            color: #00131a;
            box-shadow: 0 4px 14px rgba(0, 188, 212, 0.25);
        }
        .btn-action.primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 188, 212, 0.4); }
        .btn-action.secondary {
            background: var(--panel);
            border: 1px solid var(--border-soft);
            color: var(--muted);
        }
        .btn-action.secondary:hover { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }

        /* ===== ALERTAS ===== */
        .alert-box {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.88rem;
            line-height: 1.5;
        }
        .alert-box i { margin-top: 2px; font-size: 1.05rem; }
        .alert-success { background: var(--success-soft); border: 1px solid rgba(46,204,113,0.35); color: #7ee2a8; }
        .alert-success strong { color: var(--success); }
        .alert-warn { background: var(--warn-soft); border: 1px solid rgba(241,196,15,0.35); color: #f1c40f; }
        .alert-info { background: var(--accent-soft); border: 1px solid rgba(0,188,212,0.35); color: #7fdbe9; }

        /* ===== PANELES ===== */
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 22px;
            overflow: hidden;
        }
        .panel-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--panel-2);
        }
        .panel-head .ph-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .panel-head h2 { margin: 0; font-size: 1rem; color: var(--text); }
        .panel-head .ph-desc { color: var(--muted-2); font-size: 0.78rem; margin-top: 2px; }
        .panel-body { padding: 20px; }

        .ph-icon.cyan { background: var(--accent-soft); color: var(--accent); }
        .ph-icon.green { background: var(--success-soft); color: var(--success); }
        .ph-icon.amber { background: var(--warn-soft); color: var(--warn); }
        .ph-icon.purple { background: rgba(155, 89, 182, 0.14); color: #9b59b6; }
        .ph-icon.blue { background: rgba(52, 152, 219, 0.14); color: #3498db; }
        .ph-icon.red { background: var(--danger-soft); color: var(--danger); }

        /* ===== GRID DE MÉTRICAS ===== */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .metric {
            background: #232323;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 20px;
            border-left: 5px solid var(--accent);
        }
        .metric.green { border-left-color: var(--success); }
        .metric.green .m-label i { color: var(--success); }
        .metric.blue { border-left-color: #3498db; }
        .metric.blue .m-label i { color: #3498db; }
        .metric.red { border-left-color: var(--danger); }
        .metric.red .m-label i { color: var(--danger); }
        .metric.amber { border-left-color: var(--warn); }
        .metric.amber .m-label i { color: var(--warn); }
        .metric .m-label {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 9px;
        }
        .metric .m-label i { color: var(--accent); }
        .metric .m-value { font-size: 1.5rem; font-weight: 700; color: var(--text); }
        .metric .m-value.green { color: var(--success); }
        .metric .m-value.blue { color: #3498db; }
        .metric .m-value.red { color: var(--danger); }
        .metric .m-note { color: var(--muted-2); font-size: 0.74rem; margin-top: 7px; }

        /* ===== DEV TOOLS ===== */
        .dev-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .dev-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        .dev-link.search { background: rgba(52, 152, 219, 0.12); border: 1px solid rgba(52, 152, 219, 0.4); color: #7fb8e8; }
        .dev-link.search:hover { background: rgba(52, 152, 219, 0.2); }
        .dev-link.danger { background: var(--danger-soft); border: 1px solid rgba(231, 76, 60, 0.4); color: #f09086; }
        .dev-link.danger:hover { background: rgba(231, 76, 60, 0.22); }
        .dev-note { color: var(--muted-2); font-size: 0.75rem; font-style: italic; }

        /* ===== TABLA DE MOVIMIENTOS ===== */
        .mov-table { width: 100%; border-collapse: separate; border-spacing: 0 6px; margin-top: 6px; }
        .mov-table thead th {
            color: var(--accent);
            text-transform: uppercase;
            font-size: 0.72em;
            letter-spacing: 1px;
            padding: 9px 12px;
            text-align: left;
            border: none;
            background: transparent;
            white-space: nowrap;
        }
        .mov-table tbody tr { background: #232323; }
        .mov-table tbody tr:hover { background: #2a2a2a; }
        .mov-table td { padding: 12px; border: none; font-size: 0.85rem; }
        .mov-table td:first-child { border-radius: 8px 0 0 8px; }
        .mov-table td:last-child { border-radius: 0 8px 8px 0; }

        .badge-tipo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .badge-tipo.ingreso { background: var(--success-soft); color: var(--success); border: 1px solid rgba(46,204,113,0.35); }
        .badge-tipo.egreso { background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(231,76,60,0.35); }

        .monto-mov { font-weight: 700; white-space: nowrap; }
        .monto-mov.pos { color: var(--success); }
        .monto-mov.neg { color: var(--danger); }
        .usuario-cell { color: var(--muted); font-size: 0.8rem; white-space: nowrap; }
        .metodo-tag {
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            background: #2e2e2e;
            color: var(--muted);
            border: 1px solid var(--border-soft);
            white-space: nowrap;
        }

        /* ===== VALIDACIÓN DE TRANSFERENCIAS ===== */
        .btn-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--danger);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1;
        }
        .m-transferencias .m-note.warn { color: var(--warn); }
        .m-transferencias .m-note.ok { color: var(--success); }

        .transferencia-list h4 {
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 14px 0 8px;
        }
        .transferencia-list h4:first-child { margin-top: 0; }
        .transferencia-list .mov-table { border-spacing: 0 2px; margin-top: 0; }
        .transferencia-list .mov-table thead th,
        .transferencia-list .mov-table td { padding: 5px 9px; font-size: 0.78rem; }
        .transferencia-list .usuario-cell { font-size: 0.73rem; }
        .transferencia-list .cliente-cell { color: var(--text); font-size: 0.78rem; white-space: nowrap; }
        .comp-input {
            background: #141414;
            border: 1px solid var(--border-soft);
            border-radius: 6px;
            color: var(--text);
            padding: 4px 6px;
            font-size: 0.75rem;
            width: 130px;
        }
        .comp-input:focus { outline: none; border-color: var(--accent); }
        .btn-validar {
            background: rgba(46, 204, 113, 0.14);
            border: 1px solid rgba(46, 204, 113, 0.4);
            color: #7ee2a8;
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-validar:hover { background: rgba(46, 204, 113, 0.25); }
        .btn-norealizada {
            background: rgba(231, 76, 60, 0.14);
            border: 1px solid rgba(231, 76, 60, 0.4);
            color: #f09086;
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            margin-left: 6px;
        }
        .btn-norealizada:hover { background: rgba(231, 76, 60, 0.25); }
        .pill-resolucion {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .pill-resolucion.anulada { background: var(--danger-soft); color: #f09086; border: 1px solid rgba(231,76,60,0.4); }
        .pill-resolucion.ctacte { background: var(--warn-soft); color: #f1c40f; border: 1px solid rgba(241,196,15,0.4); }
        .pill-resolucion.pendiente { background: var(--accent-soft); color: #7fdbe9; border: 1px solid rgba(0,188,212,0.4); }
        .pill-resolucion.reversada { background: rgba(155,89,182,0.14); color: #b79cdb; border: 1px solid rgba(155,89,182,0.4); }
        .obs-cell { color: var(--muted); font-size: 0.75rem; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .obs-cell:hover { white-space: normal; overflow: visible; }
        .btn-desvalidar {
            background: transparent;
            border: 1px solid var(--border-soft);
            color: var(--muted);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.72rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-desvalidar:hover { color: var(--warn); border-color: var(--warn); }
        .transf-total {
            font-weight: 700;
            color: var(--accent);
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted-2);
        }
        .empty-state i { font-size: 2.2rem; opacity: 0.3; display: block; margin-bottom: 12px; }

        /* Responsive */
        @media (max-width: 640px) {
            .content { padding: 18px; }
            .mov-table { font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <?php include 'topbar.php'; ?>

        <div class="dash-container">

        <!-- Cabecera -->
        <div class="page-head">
            <div class="page-title">
                <div class="icon <?php echo $caja_abierta ? '' : 'cerrada'; ?>">
                    <i class="fas <?php echo $caja_abierta ? 'fa-cash-register' : 'fa-lock'; ?>"></i>
                </div>
                <div>
                    <h1>Estado de Caja</h1>
                    <div class="sub">Caja abierta en sesión, con todos los movimientos sin cerrar</div>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn-action secondary" onclick="abrirModalTransferencias()">
                    <i class="fas fa-arrow-right-arrow-left"></i> Validar Transferencias
                    <?php if ($cant_transf_pendientes > 0): ?>
                        <span class="btn-badge"><?php echo $cant_transf_pendientes; ?></span>
                    <?php endif; ?>
                </button>
                <a href="<?php echo route_file('pages/movimiento_manual.php'); ?>" class="btn-action secondary">
                    <i class="fas fa-plus-circle"></i> Nuevo Movimiento
                </a>
                <a href="<?php echo route_file('pages/cierre_caja.php'); ?>" class="btn-action primary">
                    <i class="fas fa-lock"></i> Cerrar Caja
                </a>
            </div>
        </div>

        <!-- Estado de la caja -->
        <div class="alert-box alert-success">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>Caja Abierta</strong> -
                Apertura: <strong><?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?></strong> -
                Usuario: <strong><?php echo htmlspecialchars($estado['usuario_apertura']); ?></strong>
            </div>
        </div>

        <!-- Botones de acceso para Developer (Cierres Históricos) -->
        <?php if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer'): ?>
        <div class="panel">
            <div class="panel-head">
                <div class="ph-icon amber"><i class="fas fa-tools"></i></div>
                <div>
                    <h2>Dev Tools</h2>
                    <div class="ph-desc">Herramientas de desarrollo para cierres históricos</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="dev-links">
                    <a href="<?php echo route_file('pages/verificar_cajas_historicas.php'); ?>" class="dev-link search" title="Verificar cajas abiertas anteriores al 05/08/2026 (SOLO LECTURA)">
                        <i class="fas fa-search"></i> Verificar Cajas Históricas
                    </a>
                    <a href="<?php echo route_file('pages/cerrar_cajas_historicas.php'); ?>" class="dev-link danger" title="Cerrar todas las cajas abiertas anteriores al 05/08/2026 (MODIFICA BD)">
                        <i class="fas fa-exclamation-triangle"></i> Cerrar Cajas Históricas
                    </a>
                </div>
                <p class="dev-note" style="margin-top: 12px;">* Solo para desarrollo - Ejecutar una sola vez</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resumen del período -->
        <div class="metrics-grid">
            <div class="metric green">
                <div class="m-label"><i class="fas fa-money-bill-wave"></i> Efectivo en Caja</div>
                <div class="m-value green"><?php echo fmt_moneda($total_caja_fisica); ?></div>
                <div class="m-note">Incluye saldo inicial de apertura</div>
            </div>
            <div class="metric blue m-transferencias">
                <div class="m-label"><i class="fas fa-arrow-right-arrow-left"></i> Transferencias</div>
                <div class="m-value blue"><?php echo fmt_moneda($resumen['transferencia']); ?></div>
                <?php if ($cant_transf_pendientes > 0): ?>
                    <div class="m-note warn"><i class="fas fa-exclamation-triangle"></i> <?php echo $cant_transf_pendientes; ?> pendiente(s) de validar</div>
                <?php else: ?>
                    <div class="m-note ok">Todas las transferencias validadas</div>
                <?php endif; ?>
            </div>
            <div class="metric red">
                <div class="m-label"><i class="fas fa-arrow-up-from-bracket"></i> Egresos / Gastos</div>
                <div class="m-value red"><?php echo fmt_moneda($egresos); ?></div>
            </div>
        </div>

        <!-- Últimos movimientos -->
        <div class="panel">
            <div class="panel-head">
                <div class="ph-icon cyan"><i class="fas fa-list-ul"></i></div>
                <div>
                    <h2>Últimos Movimientos</h2>
                    <div class="ph-desc">Movimientos de la caja abierta desde su apertura (<?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?>). Los movimientos de cierres anteriores no se muestran aquí.</div>
                </div>
            </div>
            <div class="panel-body">
                <?php if (empty($lista_movimientos)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    Sin movimientos en el período actual
                </div>
                <?php else: ?>
                <table class="mov-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Responsable</th>
                            <th>Tipo</th>
                            <th>Método</th>
                            <th>Detalle</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_movimientos as $m): $es_ingreso = ($m['tipo'] == 'INGRESO'); ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($m['fecha'])); ?></td>
                            <td class="usuario-cell"><i class="fas fa-user-circle" style="margin-right: 5px;"></i><?php echo htmlspecialchars($m['usuario']); ?></td>
                            <td>
                                <span class="badge-tipo <?php echo $es_ingreso ? 'ingreso' : 'egreso'; ?>">
                                    <i class="fas <?php echo $es_ingreso ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
                                    <?php echo $m['tipo']; ?>
                                </span>
                            </td>
                            <td><span class="metodo-tag"><?php echo $m['metodo_pago'] ?: '-'; ?></span></td>
                            <td><?php echo htmlspecialchars($m['detalle']); ?></td>
                            <td>
                                <span class="monto-mov <?php echo $es_ingreso ? 'pos' : 'neg'; ?>">
                                    <?php echo ($es_ingreso ? '+' : '-') . ' ' . number_format($m['monto'], 2, ',', '.'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>

    <!-- Modal: Validación de Transferencias -->
    <div id="modalValidarTransferencias" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 950px; border-top: 4px solid #3498db; background: #1e1e1e;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; color:#3498db;"><i class="fas fa-arrow-right-arrow-left"></i> Validación de Transferencias</h3>
                <button type="button" onclick="cerrarModalTransferencias()" style="background:transparent;border:none;color:#9aa0a6;font-size:1.6rem;line-height:1;cursor:pointer;" title="Cerrar">&times;</button>
            </div>
            <div class="alert-box alert-info" style="margin-bottom:14px;">
                <i class="fas fa-info-circle"></i>
                <div>Marque como <strong>validada</strong> cada transferencia que ya fue acreditada en el banco. Si la transferencia <strong>no llegó</strong>, márquela como <strong>No realizada</strong> y resuelva qué hacer con la venta.</div>
            </div>
            <div id="transferenciasResumen" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;"></div>
            <div id="transferenciasBody" class="transferencia-list" style="max-height:60vh; overflow-y:auto;"></div>
            <div style="text-align:right; margin-top:14px;">
                <button type="button" class="btn-action secondary" onclick="recargarTransferencias()" style="margin-right:8px;">
                    <i class="fas fa-sync-alt"></i> Refrescar
                </button>
                <button type="button" class="btn-action primary" onclick="cerrarModalTransferencias()">
                    <i class="fas fa-check"></i> Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Resolución de transferencia NO realizada -->
    <div id="modalNoRealizada" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 520px; border-top: 4px solid #e74c3c; background: #1e1e1e;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; color:#f09086;"><i class="fas fa-times-circle"></i> Transferencia No Realizada</h3>
                <button type="button" onclick="cerrarModalNoRealizada()" style="background:transparent;border:none;color:#9aa0a6;font-size:1.6rem;line-height:1;cursor:pointer;" title="Cerrar">&times;</button>
            </div>
            <label style="color:var(--muted); font-size:0.8rem; display:block; margin-bottom:6px;">¿Qué hacer con la venta asociada?</label>
            <div id="confNoRealizadaOpciones" style="margin-bottom:14px;"></div>
            <label style="color:var(--muted); font-size:0.8rem; display:block; margin-bottom:6px;">Motivo / observación</label>
            <textarea id="confNoRealizadaObservacion" class="comp-input" rows="2" maxlength="500" placeholder="Ej: el cliente nunca hizo la transferencia, se le dio la mercadería igual..." style="width:100%; min-height:60px; resize:vertical;"></textarea>
            <div style="text-align:right; margin-top:16px;">
                <button type="button" class="btn-action secondary" onclick="cerrarModalNoRealizada()" style="margin-right:8px;"><i class="fas fa-times"></i> Cancelar</button>
                <button type="button" class="btn-validar" id="btnConfirmarNoRealizada" onclick="confirmarNoRealizada()"><i class="fas fa-check"></i> Confirmar</button>
            </div>
        </div>
    </div>

    <script>
    const URL_AJAX_BASE = '<?php echo URL_BASE; ?>ajax/';

    function abrirModalTransferencias() {
        document.getElementById('modalValidarTransferencias').style.display = 'block';
        cargarTransferencias();
    }
    function cerrarModalTransferencias() {
        document.getElementById('modalValidarTransferencias').style.display = 'none';
    }
    function recargarTransferencias() {
        cargarTransferencias();
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function fmtMoneda(n) {
        return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function montoTransf(r) {
        return (r.monto_transferencia > 0) ? r.monto_transferencia : r.monto;
    }

    function renderTransferencias(data) {
        const body = document.getElementById('transferenciasBody');
        const resumen = document.getElementById('transferenciasResumen');
        const pend = data.pendientes || [];
        const val = data.validadas || [];
        const nr = data.no_realizadas || [];

        let totalPend = 0;
        let totalVal = 0;
        pend.forEach(function(r){ totalPend += montoTransf(r); });
        val.forEach(function(r){ totalVal += montoTransf(r); });

        resumen.innerHTML =
            '<span class="metodo-tag" style="border-color:rgba(231,76,60,0.6); color:#f09086;">Pendientes: ' + pend.length + '</span>' +
            '<span class="metodo-tag" style="border-color:rgba(46,204,113,0.6); color:#7ee2a8;">Validadas: ' + val.length + '</span>' +
            '<span class="metodo-tag" style="border-color:rgba(155,89,182,0.6); color:#b79cdb;">No realizadas: ' + nr.length + '</span>' +
            '<span class="metodo-tag" style="border-color:rgba(0,188,212,0.6); color:#7fdbe9;">Total pendiente: $ ' + fmtMoneda(totalPend) + '</span>';

        if (pend.length === 0 && val.length === 0 && nr.length === 0) {
            body.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i>No hay transferencias en el período de la caja abierta.</div>';
            return;
        }

        let html = '';

        if (pend.length > 0) {
            html += '<h4 style="color:#f09086;">Pendientes de validación</h4>';
            html += '<table class="mov-table"><thead><tr>' +
                    '<th>Fecha</th><th>Cliente</th><th>Detalle</th><th>Monto transf.</th><th>Referencia</th><th>Acciones</th>' +
                    '</tr></thead><tbody>';
            for (const r of pend) {
                html += '<tr>' +
                    '<td>' + escHtml(r.fecha) + '</td>' +
                    '<td class="cliente-cell">' + escHtml(r.cliente || '-') + '</td>' +
                    '<td>' + escHtml(r.detalle) + '</td>' +
                    '<td class="monto-mov pos">$ ' + fmtMoneda(montoTransf(r)) + '</td>' +
                    '<td><input type="text" id="comp_' + r.id + '" class="comp-input" maxlength="100" placeholder="Referencia opcional"></td>' +
                    '<td>' +
                        '<button type="button" class="btn-validar" onclick="validarTransferencia(' + r.id + ')"><i class="fas fa-check"></i> Validar</button>' +
                        '<button type="button" class="btn-norealizada" onclick="abrirModalNoRealizada(' + r.id + ', ' + JSON.stringify(r.acciones || ['PENDIENTE']).replace(/"/g,"'") + ')"><i class="fas fa-times-circle"></i> No realizada</button>' +
                    '</td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div class="alert-box alert-success" style="margin-bottom:10px;"><i class="fas fa-check-circle"></i><div>No hay transferencias pendientes. Todas fueron resueltas.</div></div>';
        }

        if (nr.length > 0) {
            html += '<h4 style="color:#b79cdb;">Transferencias marcadas como NO realizadas</h4>';
            html += '<table class="mov-table"><thead><tr>' +
                    '<th>Fecha</th><th>Cliente</th><th>Detalle</th><th>Monto</th><th>Resolución</th><th>Observación</th><th></th>' +
                    '</tr></thead><tbody>';
            for (const r of nr) {
                const acc = r.transferencia_no_realizada_accion || 'PENDIENTE';
                html += '<tr>' +
                    '<td>' + escHtml(r.fecha) + '</td>' +
                    '<td class="cliente-cell">' + escHtml(r.cliente || '-') + '</td>' +
                    '<td>' + escHtml(r.detalle) + '</td>' +
                    '<td class="monto-mov neg">$ ' + fmtMoneda(montoTransf(r)) + '</td>' +
                    '<td><span class="pill-resolucion ' + escHtml((ACCION_DISPLAY[acc] === 'Anulada' ? 'anulada' : (acc === 'CTACTE' ? 'ctacte' : (acc === 'REVERSAR' ? 'reversada' : 'pendiente')))) + '">' + escHtml(ACCION_DISPLAY[acc] || acc) + '</span></td>' +
                    '<td class="obs-cell" title="' + escHtml(r.transferencia_observacion || '') + '">' + escHtml(r.transferencia_observacion || '-') + '</td>' +
                    '<td><button type="button" class="btn-desvalidar" onclick="desvalidarTransferencia(' + r.id + ')" title="Volver a pendiente"><i class="fas fa-undo"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        }

        if (val.length > 0) {
            html += '<h4 style="color:#7ee2a8;">Transferencias validadas</h4>';
            html += '<table class="mov-table"><thead><tr>' +
                    '<th>Fecha</th><th>Cliente</th><th>Detalle</th><th>Monto transf.</th><th>Comprobante</th><th>Validada por</th><th></th>' +
                    '</tr></thead><tbody>';
            for (const r of val) {
                html += '<tr>' +
                    '<td>' + escHtml(r.fecha) + '</td>' +
                    '<td class="cliente-cell">' + escHtml(r.cliente || '-') + '</td>' +
                    '<td>' + escHtml(r.detalle) + '</td>' +
                    '<td class="monto-mov pos">$ ' + fmtMoneda(montoTransf(r)) + '</td>' +
                    '<td>' + escHtml(r.transferencia_comprobante || '-') + '</td>' +
                    '<td class="usuario-cell">' + escHtml(r.transferencia_validada_usuario || '-') +
                        (r.transferencia_validada_fecha ? '<br><small>' + escHtml(r.transferencia_validada_fecha) + '</small>' : '') + '</td>' +
                    '<td><button type="button" class="btn-desvalidar" onclick="desvalidarTransferencia(' + r.id + ')" title="Marcar como pendiente"><i class="fas fa-undo"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        }

        body.innerHTML = html;
    }

    async function cargarTransferencias() {
        const body = document.getElementById('transferenciasBody');
        body.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Cargando transferencias...</div>';
        try {
            const res = await fetch(URL_AJAX_BASE + 'obtener_transferencias_caja.php', { cache: 'no-store' });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Error al cargar las transferencias.');
            renderTransferencias(data);
        } catch (e) {
            body.innerHTML = '<div class="alert-box alert-warn"><i class="fas fa-exclamation-triangle"></i><div>' + escHtml(e.message) + '</div></div>';
        }
    }

    async function validarTransferencia(id) {
        const input = document.getElementById('comp_' + id);
        const comp = input ? input.value.trim() : '';
        confirmarAccion(
            'Confirmar Validación',
            '¿Confirmar que esta transferencia fue acreditada?',
            'VALIDAR',
            'btn-success',
            async function() {
                const fd = new FormData();
                fd.append('id', id);
                fd.append('accion', 'validar');
                fd.append('comprobante', comp);
                await enviarValidacion(fd);
            }
        );
    }

    async function desvalidarTransferencia(id) {
        confirmarAccion(
            'Deshacer Validación',
            '¿Marcar esta transferencia como pendiente?',
            'DESHACER',
            'btn-success',
            async function() {
                const fd = new FormData();
                fd.append('id', id);
                fd.append('accion', 'desvalidar');
                await enviarValidacion(fd);
            }
        );
    }

    async function enviarValidacion(fd) {
        try {
            const res = await fetch(URL_AJAX_BASE + 'validar_transferencia.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Error al guardar.');
            cargarTransferencias();
        } catch (e) {
            mostrarMensaje('Error', e.message || 'Error al guardar.', 'error');
        }
    }

    // ---------- Modal "No realizada" ----------
    const ACCION_LABELS = {
        ANULAR: 'Anular la venta (reintegra stock)',
        CTACTE: 'Pasar a Cuenta Corriente (deuda del cliente)',
        PENDIENTE: 'Pasar la venta a "en espera" (reintegra stock y se retoma desde ventas)',
        REVERSAR: 'Reversar pago (anula recibo y quita el ingreso)'
    };
    const ACCION_DISPLAY = {
        ANULAR: 'Anulada',
        CTACTE: 'Cta. Cte.',
        PENDIENTE: 'Pendiente',
        REVERSAR: 'Reversada'
    };

    let noRealizadaId = 0;

    function abrirModalNoRealizada(id, acciones) {
        noRealizadaId = id;
        const opts = acciones && acciones.length ? acciones : ['PENDIENTE'];
        const cont = document.getElementById('confNoRealizadaOpciones');
        cont.innerHTML = opts.map(function(a, i) {
            return '<label style="display:block; margin-bottom:8px; padding:8px 10px; border:1px solid var(--border-soft); border-radius:8px; cursor:pointer; font-size:0.85rem;">' +
                '<input type="radio" name="nrAccion" value="' + a + '"' + (i === 0 ? ' checked' : '') + ' style="margin-right:8px;">' +
                escHtml(ACCION_LABELS[a] || a) + '</label>';
        }).join('');
        const obs = document.getElementById('confNoRealizadaObservacion');
        obs.value = '';
        document.getElementById('modalNoRealizada').style.display = 'block';
    }

    function cerrarModalNoRealizada() {
        document.getElementById('modalNoRealizada').style.display = 'none';
    }

    function radioNrSeleccionada() {
        const r = document.querySelector('input[name="nrAccion"]:checked');
        return r ? r.value : '';
    }

    async function confirmarNoRealizada() {
        const sub = radioNrSeleccionada();
        if (!sub) { mostrarMensaje('Atención', 'Seleccione qué hacer con la venta.', 'error'); return; }
        const obs = document.getElementById('confNoRealizadaObservacion').value.trim();
        confirmarAccion(
            'Transferencia No Realizada',
            '¿Confirmar que la transferencia NO fue realizada y aplicar: ' + (ACCION_LABELS[sub] || sub) + '?',
            'CONFIRMAR',
            'btn-danger',
            async function() {
                const fd = new FormData();
                fd.append('id', noRealizadaId);
                fd.append('accion', 'no_realizada');
                fd.append('subaccion', sub);
                fd.append('observacion', obs);
                cerrarModalNoRealizada();
                await enviarValidacion(fd);
            }
        );
    }
    </script>
</body>
</html>