<?php
// pages/cierre_caja.php
include 'infosesion.php';
// VALIDACIÓN CRÍTICA:
require_once '../config/validar_permisos.php';
require_permiso('pages/cierre_caja.php');
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Verificar que la caja esté abierta
if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
    $_SESSION['error_caja'] = 'La caja está cerrada. Debe abrirla antes de cerrar.';
    header("Location: " . url('abrir-caja'));
    exit();
}

// NOTA: Se permite cerrar la caja múltiples veces por día por el mismo usuario
// No hay validación de cierre único

// Obtener estado de caja (modelo por sesión)
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);

// PERÍODO AUTOMÁTICO: desde el inicio del día en que se abrió la caja (sesión)
// hasta el momento actual. En el modelo por sesión la caja queda abierta hasta
// que el usuario la cierra (puede abarcar varios días). Como movimientos.fecha
// es DATE (sin hora), el período arranca a las 00:00 del día de apertura para
// no dejar fuera movimientos de ese mismo día.
$fecha_apertura_sesion = $estado['fecha_apertura'] ?? date('Y-m-d H:i:s');
$fecha_desde = date('Y-m-d', strtotime($fecha_apertura_sesion)) . ' 00:00:00'; // Inicio del día de apertura
$fecha_hasta = date('Y-m-d H:i:s'); // Momento actual

// Verificar si existen movimientos sin cerrar de días anteriores (advertencia).
// Criterio consistente con pages/verificar_cajas_historicas.php: un día queda
// pendiente de cierre SOLO si tiene movimientos sin cerrar (cerrado = 0).
// Si el día anterior no tuvo actividad, no corresponde exigir un cierre.
$advertencia_dia_anterior = false;
$mov_pendientes_previos = 0;
$ultimo_dia_pendiente = null;

$sql_check_previos = "SELECT COUNT(*) as pendientes, MAX(DATE(fecha)) as ultimo_dia
                      FROM movimientos
                      WHERE cerrado = 0
                        AND empresa_id = :empresa_id
                        AND sucursal_id = :sucursal_id
                        AND DATE(fecha) < DATE(:fecha_desde)";

$stmt_previos = $pdo->prepare($sql_check_previos);
$stmt_previos->execute([
    ':empresa_id' => $empresa_id,
    ':sucursal_id' => $sucursal_id,
    ':fecha_desde' => $fecha_desde
]);
$check_previos = $stmt_previos->fetch(PDO::FETCH_ASSOC);

$mov_pendientes_previos = (int)($check_previos['pendientes'] ?? 0);
if ($mov_pendientes_previos > 0) {
    $advertencia_dia_anterior = true;
    $ultimo_dia_pendiente = $check_previos['ultimo_dia'];
}

// Calculamos los totales del período para mostrar como "Esperado"
try {
    $sql_sistema = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') THEN monto ELSE 0 END) as ingresos_efectivo,
        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as ingresos_transf,
        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
    FROM movimientos 
    WHERE cerrado = 0 
      AND empresa_id = :empresa_id 
      AND sucursal_id = :sucursal_id
      AND fecha BETWEEN :fecha_desde AND :fecha_hasta";
    
    $stmt = $pdo->prepare($sql_sistema);
    $stmt->execute([
        ':empresa_id' => $empresa_id, 
        ':sucursal_id' => $sucursal_id,
        ':fecha_desde' => $fecha_desde,
        ':fecha_hasta' => $fecha_hasta
    ]);
    $sistema = $stmt->fetch(PDO::FETCH_ASSOC);

    $ingresos_efectivo = $sistema['ingresos_efectivo'] ?: 0;
    $ingresos_transf = $sistema['ingresos_transf'] ?: 0;
    $egresos = $sistema['egresos'] ?: 0;
    
    // Saldo que DEBERÍA haber en efectivo (incluye saldo inicial)
    $saldo_inicial = (float)($estado['saldo_inicial'] ?? 0);
    $saldo_esperado = $saldo_inicial + $ingresos_efectivo - $egresos;

} catch (Exception $e) {
    die("Error al calcular totales: " . $e->getMessage());
}

// Totales del período por método de pago
$sql_metodos = "SELECT 
    SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
             THEN monto ELSE 0 END) as efectivo,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
             THEN monto ELSE 0 END) as transferencia,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'CHEQUE' 
             THEN monto ELSE 0 END) as cheques,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TARJETA' 
             THEN monto ELSE 0 END) as tarjetas,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'TARJETA', 'MIXTO') 
             THEN monto ELSE 0 END) as otros,
    SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
FROM movimientos 
WHERE cerrado = 0 
  AND empresa_id = :empresa_id 
  AND sucursal_id = :sucursal_id
  AND fecha BETWEEN :fecha_desde AND :fecha_hasta";

$stmt_metodos = $pdo->prepare($sql_metodos);
$stmt_metodos->execute([
    ':empresa_id' => $empresa_id,
    ':sucursal_id' => $sucursal_id,
    ':fecha_desde' => $fecha_desde,
    ':fecha_hasta' => $fecha_hasta
]);
$metodos = $stmt_metodos->fetch(PDO::FETCH_ASSOC);

$total_ingresos = ($metodos['efectivo'] ?? 0) + ($metodos['transferencia'] ?? 0) + 
                 ($metodos['cheques'] ?? 0) + ($metodos['tarjetas'] ?? 0) + ($metodos['otros'] ?? 0);

// Duración del período
$duracion_minutos = round((strtotime($fecha_hasta) - strtotime($fecha_apertura_sesion)) / 60);
$horas = floor($duracion_minutos / 60);
$minutos = $duracion_minutos % 60;

$denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];

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
    <title>Cierre de Caja | <?php echo $nombre_empresa_sistema; ?></title>
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

        .content {
            padding: 70px 30px 30px;
        }

        .cierre-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ===== CABECERA DE PÁGINA ===== */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .page-title .icon {
            background: var(--accent-soft);
            color: var(--accent);
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .page-title h1 { margin: 0; font-size: 1.5rem; color: var(--accent); border: none; padding: 0; }
        .page-title .sub { color: var(--muted); font-size: 0.85rem; margin-top: 3px; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 8px;
            background: var(--panel);
            border: 1px solid var(--border-soft);
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .back-link:hover { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }

        /* ===== STEPPER ===== */
        .stepper {
            display: flex;
            gap: 10px;
            margin: 20px 0 28px;
            flex-wrap: wrap;
        }
        .step {
            flex: 1;
            min-width: 180px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .step.done { border-color: var(--accent); background: linear-gradient(135deg, rgba(0,188,212,0.08), var(--panel)); }
        .step-current { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
        .step-num {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--border-soft);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .step.done .step-num { background: var(--accent); color: #00131a; }
        .step.step-current .step-num { background: var(--accent); color: #00131a; }
        .step strong { display: block; font-size: 0.85rem; color: var(--text); }
        .step small { color: var(--muted-2); font-size: 0.72rem; }

        #seccion-conteo, #seccion-confirmar { scroll-margin-top: 90px; }

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

        /* ===== GRID DE MÉTRICAS (período) ===== */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
        }
        .metric {
            background: #232323;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            border-left: 4px solid var(--accent);
        }
        .metric .m-label { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .metric .m-label i { color: var(--accent); }
        .metric .m-value { font-size: 1.15rem; font-weight: 700; color: var(--text); }
        .metric .m-value.green { color: var(--success); }
        .metric .m-value.red { color: var(--danger); }
        .metric .m-value.amber { color: var(--warn); }
        .metric.green { border-left-color: var(--success); }
        .metric.green .m-label i { color: var(--success); }
        .metric.red { border-left-color: var(--danger); }
        .metric.red .m-label i { color: var(--danger); }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .k { color: var(--muted); }
        .info-row .v { color: var(--text); font-weight: 600; }

        /* ===== MÉTODOS DE PAGO ===== */
        .metodos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }
        .metodo-card {
            background: #232323;
            border: 1px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: 10px;
            padding: 14px 15px;
            transition: transform 0.15s;
        }
        .metodo-card:hover { transform: translateY(-2px); }
        .metodo-card .label { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .metodo-card .valor { font-size: 1.25rem; font-weight: 700; }
        .metodo-card.efectivo { border-top-color: #27ae60; }
        .metodo-card.efectivo .valor { color: #2ecc71; }
        .metodo-card.transferencia { border-top-color: #3498db; }
        .metodo-card.transferencia .valor { color: #3498db; }
        .metodo-card.cheque { border-top-color: #f39c12; }
        .metodo-card.cheque .valor { color: #f39c12; }
        .metodo-card.tarjeta { border-top-color: #9b59b6; }
        .metodo-card.tarjeta .valor { color: #9b59b6; }
        .metodo-card.otros { border-top-color: #95a5a6; }
        .metodo-card.otros .valor { color: #95a5a6; }
        .metodo-card.egresos { border-top-color: #e74c3c; }
        .metodo-card.egresos .valor { color: #e74c3c; }

        .total-ingresos-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            padding: 14px 18px;
            background: linear-gradient(135deg, #0d47a1, #1565c0);
            border-radius: 10px;
            color: #fff;
            flex-wrap: wrap;
        }
        .total-ingresos-row .lbl { font-weight: 600; font-size: 0.9rem; opacity: 0.9; }
        .total-ingresos-row .monto-total { font-size: 1.6rem; font-weight: 700; }

        /* ===== ARQUEO (2 columnas) ===== */
        .arqueo-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 22px;
            align-items: start;
        }

        .billetes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .billete-input {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: #232323;
            padding: 10px 13px;
            border-radius: 9px;
            border: 1px solid var(--border);
            transition: border-color 0.2s;
        }
        .billete-input:focus-within { border-color: var(--accent); }
        .billete-input label {
            color: #ccc;
            font-weight: 600;
            font-size: 0.95rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .billete-input label i { color: var(--success); font-size: 0.9rem; }
        .billete-input input {
            width: 76px !important;
            text-align: center !important;
            background: #1a1a1a !important;
            border: 1px solid var(--border-soft) !important;
            color: var(--text) !important;
            padding: 7px 6px !important;
            margin-bottom: 0 !important;
            border-radius: 6px !important;
            font-weight: 600;
        }
        .billete-input input:focus { border-color: var(--accent) !important; outline: none; }

        /* Comparador real vs esperado */
        .compare-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .compare-box {
            padding: 14px;
            border-radius: 10px;
            text-align: center;
        }
        .compare-box .c-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .compare-box .c-value { font-size: 1.6rem; font-weight: 700; }
        .compare-box.esperado { background: var(--accent-soft); border: 1px solid rgba(0,188,212,0.4); }
        .compare-box.esperado .c-value { color: var(--accent); }
        .compare-box.real { background: var(--success-soft); border: 1px solid rgba(46,204,113,0.4); }
        .compare-box.real .c-value { color: var(--success); }

        .dif-box {
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        .dif-box .dif-icon { margin-right: 8px; }
        .dif-ok { background: var(--success-soft); border: 1px solid var(--success); color: var(--success); }
        .dif-pos { background: var(--success-soft); border: 1px solid var(--success); color: var(--success); }
        .dif-neg { background: var(--danger-soft); border: 1px solid var(--danger); color: var(--danger); }
        .dif-pend { background: #232323; border: 1px dashed var(--border-soft); color: var(--muted); }

        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type="number"] { -moz-appearance: textfield; appearance: textfield; }

        /* ===== CONFIRMACIÓN ===== */
        .confirm-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 22px;
        }
        .campo label {
            display: block;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 7px;
        }
        .campo textarea,
        .campo input {
            width: 100% !important;
            background: #232323 !important;
            border: 1px solid var(--border-soft) !important;
            color: var(--text) !important;
            padding: 11px 13px !important;
            margin-bottom: 0 !important;
            border-radius: 8px !important;
        }
        .campo textarea { resize: vertical; }
        .campo input:focus, .campo textarea:focus { border-color: var(--accent) !important; outline: none; }
        .campo .help { color: var(--muted-2); font-size: 0.75rem; margin-top: 7px; font-style: italic; }

        .fondo-box {
            background: linear-gradient(135deg, rgba(23,162,184,0.10), transparent);
            border: 1px dashed rgba(23, 162, 184, 0.5);
            border-radius: 10px;
            padding: 16px;
            margin-top: 18px;
        }
        .fondo-box .fondo-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--accent);
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 12px;
        }
        .fondo-box label { color: var(--muted); font-size: 0.82rem; font-weight: 600; margin-bottom: 7px; }
        .fondo-box .help { color: var(--muted-2); font-size: 0.75rem; margin-top: 7px; font-style: italic; }

        .btn-cierre {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            background: linear-gradient(135deg, #00bcd4, #0091a7);
            color: #00131a;
            border: none;
            padding: 16px 28px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0.5px;
            margin-top: 20px;
            transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(0, 188, 212, 0.25);
        }
        .btn-cierre:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.4);
        }
        .btn-cierre:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        /* Responsive */
        @media (max-width: 900px) {
            .arqueo-grid, .confirm-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .compare-grid { grid-template-columns: 1fr; }
            .content { padding: 18px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <?php include 'topbar.php'; ?>

        <div class="cierre-container">

        <!-- Cabecera -->
        <div class="page-head">
            <div class="page-title">
                <div class="icon"><i class="fas fa-cash-register"></i></div>
                <div>
                    <h1>Cierre de Caja</h1>
                    <div class="sub">Período automático desde la apertura hasta el momento actual</div>
                </div>
            </div>
            <a href="<?php echo url('caja-dashboard'); ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Volver a la caja
            </a>
        </div>

        <!-- Indicador de pasos -->
        <div class="stepper">
            <a class="step step-current" id="stepConteo" href="#seccion-conteo">
                <span class="step-num">1</span>
                <div><strong>Conteo de Efectivo</strong><small>Billetes en caja</small></div>
            </a>
            <a class="step" id="stepVerif" href="#seccion-conteo">
                <span class="step-num">2</span>
                <div><strong>Verificación en vivo</strong><small>Real vs. esperado</small></div>
            </a>
            <a class="step" id="stepConfirmar" href="#seccion-confirmar">
                <span class="step-num">3</span>
                <div><strong>Confirmar Cierre</strong><small>Observaciones y fondos</small></div>
            </a>
        </div>

        <?php if ($advertencia_dia_anterior): ?>
        <div class="alert-box alert-warn">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Existen <?php echo $mov_pendientes_previos; ?> movimiento(s) sin cerrar de días anteriores</strong>
                <?php if ($ultimo_dia_pendiente): ?> (último día con pendientes: <?php echo date('d/m/Y', strtotime($ultimo_dia_pendiente)); ?>)<?php endif; ?>.
                <br>Se recomienda cerrar los días en orden cronológico antes de continuar.
            </div>
        </div>
        <?php endif; ?>

        <!-- PASO 1: Período y estado -->
        <div class="panel">
            <div class="panel-head">
                <div class="ph-icon cyan"><i class="fas fa-clock"></i></div>
                <div>
                    <h2>Período y Estado de la Caja</h2>
                    <div class="ph-desc">Caja abierta por sesión, con cierre automático desde la apertura</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="metrics-grid">
                    <div class="metric green">
                        <div class="m-label"><i class="fas fa-unlock"></i> Fecha Apertura</div>
                        <div class="m-value green"><?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?></div>
                    </div>
                    <div class="metric red">
                        <div class="m-label"><i class="fas fa-lock"></i> Fecha Cierre</div>
                        <div class="m-value red"><?php echo date('d/m/Y H:i'); ?></div>
                    </div>
                    <div class="metric">
                        <div class="m-label"><i class="fas fa-hourglass-half"></i> Duración del Período</div>
                        <div class="m-value"><?php echo $horas; ?>h <?php echo $minutos; ?>min</div>
                    </div>
                    <div class="metric amber">
                        <div class="m-label"><i class="fas fa-piggy-bank"></i> Saldo Inicial</div>
                        <div class="m-value amber"><?php echo fmt_moneda($saldo_inicial); ?></div>
                    </div>
                </div>
                <div class="info-row" style="margin-top: 16px;">
                    <span class="k"><i class="fas fa-user-circle" style="color: var(--muted-2); margin-right: 6px;"></i>Usuario de Apertura</span>
                    <span class="v"><?php echo htmlspecialchars($estado['usuario_apertura']); ?></span>
                </div>
            </div>
        </div>

        <!-- PASO 2: Resumen por método de pago -->
        <div class="panel">
            <div class="panel-head">
                <div class="ph-icon blue"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <h2>Resumen del Período por Método de Pago</h2>
                    <div class="ph-desc">Ingresos y egresos registrados en el sistema sin cerrar</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="metodos-grid">
                    <div class="metodo-card efectivo">
                        <div class="label"><i class="fas fa-money-bill-wave"></i> Efectivo</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['efectivo'] ?? 0); ?></div>
                    </div>
                    <div class="metodo-card transferencia">
                        <div class="label"><i class="fas fa-arrow-right-arrow-left"></i> Transferencias</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['transferencia'] ?? 0); ?></div>
                    </div>
                    <div class="metodo-card cheque">
                        <div class="label"><i class="fas fa-money-check"></i> Cheques</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['cheques'] ?? 0); ?></div>
                    </div>
                    <div class="metodo-card tarjeta">
                        <div class="label"><i class="fas fa-credit-card"></i> Tarjetas</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['tarjetas'] ?? 0); ?></div>
                    </div>
                    <div class="metodo-card otros">
                        <div class="label"><i class="fas fa-coins"></i> Otros</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['otros'] ?? 0); ?></div>
                    </div>
                    <div class="metodo-card egresos">
                        <div class="label"><i class="fas fa-arrow-up-from-bracket"></i> Egresos</div>
                        <div class="valor"><?php echo fmt_moneda($metodos['egresos'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="total-ingresos-row">
                    <span class="lbl"><i class="fas fa-circle-check" style="margin-right: 8px;"></i>Total de Ingresos del Período</span>
                    <span class="monto-total"><?php echo fmt_moneda($total_ingresos); ?></span>
                </div>
            </div>
        </div>

        <form action="<?php echo url('pages/procesar_cierre.php'); ?>" method="POST">
            <!-- Campos ocultos para el rango de fechas -->
            <input type="hidden" name="fecha_desde" value="<?php echo $fecha_desde; ?>">
            <input type="hidden" name="fecha_hasta" value="<?php echo $fecha_hasta; ?>">

            <!-- PASO 3: Arqueo -->
            <div class="arqueo-grid" id="seccion-conteo">
                <div class="panel" style="margin-bottom: 0;">
                    <div class="panel-head">
                        <div class="ph-icon green"><i class="fas fa-money-bill-wave"></i></div>
                        <div>
                            <h2>Conteo de Billetes</h2>
                            <div class="ph-desc">Cargue las cantidades de dinero físico en caja</div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="billetes-grid">
                            <?php foreach ($denominaciones as $valor): ?>
                            <div class="billete-input">
                                <label><i class="fas fa-money-bill-1"></i> $<?php echo number_format($valor, 0, '', '.'); ?></label>
                                <input type="number" class="cant-billete input-field" data-valor="<?php echo $valor; ?>" name="b_<?php echo $valor; ?>" value="" min="0" placeholder="0">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="total-ingresos-row" style="margin-top: 16px;">
                            <span class="lbl"><i class="fas fa-calculator" style="margin-right: 8px;"></i>TOTAL REAL CONTADO</span>
                            <span class="monto-total" style="color: #ffc107;">$ <span id="total_real_display">0,00</span></span>
                        </div>
                        <input type="hidden" name="saldo_real_efectivo" id="saldo_real_input" value="0">
                    </div>
                </div>

                <div class="panel" style="margin-bottom: 0;">
                    <div class="panel-head">
                        <div class="ph-icon blue"><i class="fas fa-scale-balanced"></i></div>
                        <div>
                            <h2>Verificación del Sistema</h2>
                            <div class="ph-desc">Compare el efectivo contado contra lo esperado</div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if ($ingresos_efectivo == 0 && $ingresos_transf == 0 && $egresos == 0): ?>
                        <div class="alert-box alert-info" style="margin-bottom: 14px;">
                            <i class="fas fa-info-circle"></i>
                            <div>No hay movimientos pendientes de cierre.</div>
                        </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <span class="k">Ingresos en Efectivo</span>
                            <span class="v" style="color: var(--success);"><?php echo fmt_moneda($ingresos_efectivo); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="k">Egresos / Gastos</span>
                            <span class="v" style="color: var(--danger);">- <?php echo fmt_moneda($egresos); ?></span>
                        </div>

                        <div class="compare-grid" style="margin-top: 16px;">
                            <div class="compare-box esperado">
                                <div class="c-label">Saldo Esperado</div>
                                <div class="c-value" id="saldo_esperado_val" data-valor="<?php echo $saldo_esperado; ?>"><?php echo fmt_moneda($saldo_esperado); ?></div>
                            </div>
                            <div class="compare-box real">
                                <div class="c-label">Real Contado</div>
                                <div class="c-value" id="total_real_compare">$ 0,00</div>
                            </div>
                        </div>

                        <div id="box_diferencia" class="dif-box dif-pend">
                            <i class="fas fa-calculator dif-icon"></i> Cargue el conteo para calcular la diferencia
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 4: Confirmación -->
            <div class="panel" id="seccion-confirmar">
                <div class="panel-head">
                    <div class="ph-icon purple"><i class="fas fa-clipboard-check"></i></div>
                    <div>
                        <h2>Confirmación del Cierre</h2>
                        <div class="ph-desc">Últimos datos antes de cerrar la caja</div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="confirm-grid">
                        <div class="campo">
                            <label>Observaciones <span style="color: var(--muted-2); font-weight: normal;">(opcional)</span></label>
                            <textarea name="observaciones" rows="4" placeholder="Ej: Faltó cobrar una entrega de cta cte..."></textarea>
                        </div>
                        <div class="fondo-box">
                            <div class="fondo-title"><i class="fas fa-piggy-bank"></i> Fondo Reservado (Vuelto)</div>
                            <label>¿Cuánto dinero se reserva en caja como fondo de vuelto?</label>
                            <input type="number" name="fondo_vuelto" class="input-field" step="0.01" value="0" placeholder="Ej: 5000">
                            <p class="help">Este monto se registrará como fondo reservado y se sugerirá como saldo inicial en la próxima apertura de caja.</p>
                        </div>
                    </div>
                    <button type="submit" class="btn-cierre">
                        <i class="fas fa-lock"></i> Confirmar y Cerrar Caja
                    </button>
                </div>
            </div>
        </form>
        </div>
    </div>

    <script>
        const inputs = document.querySelectorAll('.cant-billete');
        const displayTotalReal = document.getElementById('total_real_display');
        const displayTotalCompare = document.getElementById('total_real_compare');
        const inputHiddenReal = document.getElementById('saldo_real_input');
        const displayDif = document.getElementById('box_diferencia');
        const esperado = parseFloat(document.getElementById('saldo_esperado_val').dataset.valor);

        const pasoConteo = document.getElementById('stepConteo');
        const pasoVerif = document.getElementById('stepVerif');
        const pasoConfirmar = document.getElementById('stepConfirmar');

        function setPasos(conteoRealizado) {
            if (conteoRealizado) {
                pasoConteo.className = 'step done';
                pasoVerif.className = 'step done';
                pasoConfirmar.className = 'step step-current';
            } else {
                pasoConteo.className = 'step step-current';
                pasoVerif.className = 'step';
                pasoConfirmar.className = 'step';
            }
        }

        function fmtMonto(valor) {
            return valor.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function calcularTotales() {
            let totalReal = 0;
            inputs.forEach(input => {
                const valor = parseFloat(input.dataset.valor);
                const cant = parseInt(input.value) || 0;
                totalReal += (valor * cant);
            });

            const totalRealFmt = fmtMonto(totalReal);
            displayTotalReal.innerText = totalRealFmt;
            displayTotalCompare.innerText = '$ ' + totalRealFmt;
            inputHiddenReal.value = totalReal;

            setPasos(totalReal > 0);

            let diferencia = totalReal - esperado;

            if (totalReal === 0) {
                displayDif.className = 'dif-box dif-pend';
                displayDif.innerHTML = '<i class="fas fa-calculator dif-icon"></i> Cargue el conteo para calcular la diferencia';
            } else if (diferencia === 0) {
                displayDif.className = 'dif-box dif-ok';
                displayDif.innerHTML = '<i class="fas fa-check-circle dif-icon"></i> CAJA OK - La diferencia es de $ 0,00';
            } else if (diferencia > 0) {
                displayDif.className = 'dif-box dif-pos';
                displayDif.innerHTML = '<i class="fas fa-arrow-up dif-icon"></i> SOBRANTE: $ ' + fmtMonto(diferencia);
            } else {
                displayDif.className = 'dif-box dif-neg';
                displayDif.innerHTML = '<i class="fas fa-arrow-down dif-icon"></i> FALTANTE: $ ' + fmtMonto(Math.abs(diferencia));
            }
        }

        inputs.forEach(input => {
            input.addEventListener('input', calcularTotales);
        });
    </script>
</body>
</html>