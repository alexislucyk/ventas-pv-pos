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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Caja | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --accent: #00bcd4; --success: #2ecc71; --warning: #f1c40f; --danger: #e74c3c; }
        
        /* Dashboard Cards */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #1e1e1e; border: 1px solid #333; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 15px; }
        .stat-icon { background: rgba(0, 188, 212, 0.1); color: var(--accent); padding: 15px; border-radius: 10px; font-size: 1.5em; }
        .stat-info h3 { margin: 0; font-size: 0.9em; color: #888; text-transform: uppercase; }
        .stat-info p { margin: 5px 0 0; font-size: 1.6em; font-weight: bold; color: #fff; }

        /* Contenedores */
        .reporte-container { background: #1e1e1e; border-radius: 12px; border: 1px solid #333; padding: 20px; margin-bottom: 30px; }
        
        /* Grid de cierre */
        .cierre-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 30px; }
        
        /* Billetes */
        .billetes-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .billete-input { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            background: #252525; 
            padding: 12px 15px; 
            border-radius: 8px; 
            border: 1px solid #333;
            transition: all 0.3s;
        }
        .billete-input:hover { border-color: var(--accent); transform: translateX(3px); }
        .billete-input label { color: #ccc; font-weight: 500; }
        .billete-input input { 
            width: 90px; 
            text-align: center; 
            background: #1e1e1e; 
            border: 1px solid #444; 
            color: #fff; 
            padding: 6px;
            border-radius: 6px;
        }
        .billete-input input:focus { border-color: var(--accent); outline: none; }

        /* Totales */
        .total-real-box { 
            font-size: 1.8rem; 
            color: #ffc107; 
            text-align: center; 
            margin-top: 20px; 
            padding: 20px; 
            background: linear-gradient(135deg, #2c3e50 0%, #1a1a1a 100%);
            border: 2px dashed #ffc107; 
            border-radius: 12px;
            font-weight: bold;
        }
        
        .diferencia-box { 
            text-align: center; 
            font-weight: bold; 
            padding: 15px; 
            margin-top: 15px; 
            border-radius: 8px;
            font-size: 1.1em;
            transition: all 0.3s;
        }
        .bg-ok { background: rgba(46, 204, 113, 0.1); color: var(--success); border: 1px solid var(--success); }
        .bg-error { background: rgba(231, 76, 60, 0.1); color: var(--danger); border: 1px solid var(--danger); }

        /* Info lines */
        .info-line { 
            display: flex; 
            justify-content: space-between; 
            padding: 12px 0; 
            border-bottom: 1px solid #333;
            color: #ccc;
        }
        .info-line:last-child { border-bottom: none; }
        .info-line strong { color: #fff; }

        /* Formularios */
        .input-field { 
            background: #252525; 
            border: 1px solid #444; 
            color: #fff; 
            padding: 10px 15px; 
            border-radius: 8px;
            width: 100%;
            transition: all 0.3s;
        }
        .input-field:focus { border-color: var(--accent); outline: none; }
        
        textarea.input-field { resize: vertical; min-height: 80px; }

        /* Botones */
        .btn-primary { 
            background: var(--accent); 
            color: #fff; 
            border: none; 
            padding: 14px 28px; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: bold; 
            font-size: 1em;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .btn-primary:hover { 
            background: #00acc1; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }
        .btn-block { width: 100%; }

        /* Métodos de pago grid */
        .metodos-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 15px; 
            margin-top: 15px; 
        }
        .metodo-card { 
            background: #252525; 
            padding: 15px; 
            border-radius: 8px; 
            border-left: 3px solid; 
            transition: all 0.3s;
        }
        .metodo-card:hover { transform: translateX(5px); }
        .metodo-card .label { color: #aaa; font-size: 0.85rem; margin-bottom: 5px; }
        .metodo-card .valor { font-size: 1.3rem; font-weight: bold; }
        .metodo-efectivo { border-left-color: #27ae60; }
        .metodo-efectivo .valor { color: #27ae60; }
        .metodo-transferencia { border-left-color: #3498db; }
        .metodo-transferencia .valor { color: #3498db; }
        .metodo-cheque { border-left-color: #f39c12; }
        .metodo-cheque .valor { color: #f39c12; }
        .metodo-tarjeta { border-left-color: #9b59b6; }
        .metodo-tarjeta .valor { color: #9b59b6; }
        .metodo-otros { border-left-color: #95a5a6; }
        .metodo-otros .valor { color: #95a5a6; }
        .metodo-egresos { border-left-color: #e74c3c; }
        .metodo-egresos .valor { color: #e74c3c; }

        .total-ingresos-box {
            margin-top: 15px;
            padding: 15px;
            background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%);
            border-radius: 8px;
            text-align: center;
        }
        .total-ingresos-box strong { color: #aaa; }
        .total-ingresos-box .monto { 
            font-size: 1.5rem; 
            color: #fff; 
            margin-left: 10px; 
            font-weight: bold;
        }

        /* Período info */
        .periodo-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .periodo-card {
            background: #252525;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid var(--accent);
        }
        .periodo-card .label { color: #aaa; font-size: 0.85rem; margin-bottom: 5px; }
        .periodo-card .valor { font-size: 1.2rem; font-weight: bold; }
        .periodo-apertura .valor { color: #27ae60; }
        .periodo-cierre .valor { color: #e74c3c; }

        .duracion-box {
            margin-top: 15px;
            padding: 12px;
            background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%);
            border-radius: 8px;
            text-align: center;
        }
        .duracion-box i { color: var(--warning); margin-right: 8px; }
        .duracion-box strong { color: #fff; }
        .duracion-box .tiempo { font-size: 1.2rem; color: #ffc107; margin-left: 8px; font-weight: bold; }

        /* Fondo mañana card */
        .fondo-card {
            background: #252525;
            border: 1px solid #17a2b8;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .fondo-card h3 { 
            color: var(--accent); 
            margin-top: 0; 
            margin-bottom: 12px;
            font-size: 1.1em;
        }
        .fondo-card label { 
            display: block; 
            color: #ccc; 
            margin-bottom: 8px; 
        }
        .fondo-card .help-text {
            color: #888;
            font-size: 0.85em;
            margin-top: 8px;
            font-style: italic;
        }

        /* Alertas */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert i { font-size: 1.2em; }
        .alert-warning {
            background: rgba(241, 196, 15, 0.1);
            border-left: 4px solid #ff8c00;
            color: #ffc107;
        }
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border-left: 4px solid #27ae60;
            color: #2ecc71;
        }
        .alert-info {
            background: rgba(0, 188, 212, 0.1);
            border-left: 4px solid var(--accent);
            color: var(--accent);
        }

        /* Títulos */
        h1 { 
            color: var(--accent); 
            margin-bottom: 25px; 
            font-size: 1.8em;
        }
        h2 { 
            font-size: 1.2em; 
            margin-bottom: 20px; 
            color: #fff;
        }
        h3 { 
            color: #fff; 
            margin-top: 0; 
            margin-bottom: 15px;
            font-size: 1.1em;
        }

        .card-header-icon {
            color: var(--accent);
            margin-right: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .cierre-grid { grid-template-columns: 1fr; }
            .billetes-grid { grid-template-columns: 1fr; }
            .metodos-grid { grid-template-columns: 1fr; }
            .periodo-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <h1><i class="fas fa-cash-register"></i> Cierre de Caja</h1>
        
        <?php if (isset($advertencia_dia_anterior) && $advertencia_dia_anterior): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>ADVERTENCIA:</strong> Existen <?php echo $mov_pendientes_previos; ?> movimiento(s) sin cerrar de días anteriores<?php if ($ultimo_dia_pendiente): ?> (último con pendientes: <?php echo date('d/m/Y', strtotime($ultimo_dia_pendiente)); ?>)<?php endif; ?>.
                <br>Se recomienda cerrar los días en orden cronológico.
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Información del Período Automático -->
        <div class="reporte-container" style="background: linear-gradient(135deg, #1e3a5f 0%, #2c3e50 100%); border-left: 4px solid #27ae60;">
            <h3><i class="fas fa-clock card-header-icon"></i> Período de Cierre Automático</h3>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <strong>El cierre se realizará automáticamente desde la apertura de caja hasta el momento actual.</strong>
            </div>
            <div class="periodo-info">
                <div class="periodo-card periodo-apertura">
                    <div class="label">Fecha/Hora Apertura:</div>
                    <div class="valor"><?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?></div>
                </div>
                <div class="periodo-card periodo-cierre">
                    <div class="label">Fecha/Hora Cierre:</div>
                    <div class="valor"><?php echo date('d/m/Y H:i'); ?></div>
                </div>
            </div>
            <?php
            $duracion_minutos = round((strtotime($fecha_hasta) - strtotime($fecha_apertura_sesion)) / 60);
            $horas = floor($duracion_minutos / 60);
            $minutos = $duracion_minutos % 60;
            ?>
            <div class="duracion-box">
                <i class="fas fa-hourglass-half"></i>
                <strong>Duración del período:</strong>
                <span class="tiempo"><?php echo $horas; ?>h <?php echo $minutos; ?>min</span>
            </div>
        </div>
        
        <!-- Resumen completo de métodos de pago -->
        <div class="reporte-container" style="background: linear-gradient(135deg, #1e3a5f 0%, #2c3e50 100%); border-left: 4px solid #3498db;">
            <h3><i class="fas fa-chart-bar card-header-icon"></i> Resumen del Período por Método de Pago</h3>
            <?php
            // Calcular totales por método de pago
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
            ?>
            <div class="metodos-grid">
                <div class="metodo-card metodo-efectivo">
                    <div class="label">Efectivo</div>
                    <div class="valor">$<?php echo number_format($metodos['efectivo'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="metodo-card metodo-transferencia">
                    <div class="label">Transferencias</div>
                    <div class="valor">$<?php echo number_format($metodos['transferencia'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="metodo-card metodo-cheque">
                    <div class="label">Cheques</div>
                    <div class="valor">$<?php echo number_format($metodos['cheques'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="metodo-card metodo-tarjeta">
                    <div class="label">Tarjetas</div>
                    <div class="valor">$<?php echo number_format($metodos['tarjetas'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="metodo-card metodo-otros">
                    <div class="label">Otros</div>
                    <div class="valor">$<?php echo number_format($metodos['otros'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="metodo-card metodo-egresos">
                    <div class="label">Egresos</div>
                    <div class="valor">$<?php echo number_format($metodos['egresos'] ?? 0, 2, ',', '.'); ?></div>
                </div>
            </div>
            <div class="total-ingresos-box">
                <strong>Total Ingresos:</strong>
                <span class="monto">$<?php echo number_format($total_ingresos, 2, ',', '.'); ?></span>
            </div>
        </div>
        
        <!-- Mostrar información de apertura -->
        <div class="reporte-container" style="background: linear-gradient(135deg, #004a54 0%, #00838f 100%); border-left: 4px solid #00bcd4;">
            <h3><i class="fas fa-info-circle card-header-icon"></i> Información de Apertura</h3>
            <div class="info-line">
                <span>Saldo Inicial:</span>
                <strong>$ <?php echo number_format($estado['saldo_inicial'] ?? 0, 2, ',', '.'); ?></strong>
            </div>
            <div class="info-line">
                <span>Fecha Apertura:</span>
                <strong><?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?></strong>
            </div>
            <div class="info-line">
                <span>Usuario Apertura:</span>
                <strong><?php echo htmlspecialchars($estado['usuario_apertura']); ?></strong>
            </div>
        </div>
        
        <form action="<?php echo url('pages/procesar_cierre.php'); ?>" method="POST">
            <!-- Campos ocultos para el rango de fechas -->
            <input type="hidden" name="fecha_desde" value="<?php echo $fecha_desde; ?>">
            <input type="hidden" name="fecha_hasta" value="<?php echo $fecha_hasta; ?>">
            
            <div class="cierre-grid">
                <div class="reporte-container">
                    <h3><i class="fas fa-money-bill-wave card-header-icon"></i> Conteo de Billetes</h3>
                    <div class="billetes-grid">
                        <?php 
                        $denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];
                        foreach ($denominaciones as $valor): ?>
                        <div class="billete-input">
                            <label>$<?php echo number_format($valor, 0, '', '.'); ?></label>
                            <input type="number" class="cant-billete input-field" data-valor="<?php echo $valor; ?>" name="b_<?php echo $valor; ?>" value="" min="0">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="total-real-box">
                        TOTAL REAL: $<span id="total_real_display">0,00</span>
                    </div>
                    <input type="hidden" name="saldo_real_efectivo" id="saldo_real_input" value="0">
                </div>

                <div class="reporte-container">
                    <h3><i class="fas fa-calculator card-header-icon"></i> Resumen del Sistema</h3>
                    <?php if ($ingresos_efectivo == 0 && $ingresos_transf == 0 && $egresos == 0): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>No hay movimientos pendientes de cierre.</div>
                        </div>
                    <?php endif; ?>
                    <div class="info-line">
                        <span>Ingresos Efectivo:</span>
                        <strong>$ <?php echo number_format($ingresos_efectivo, 2, ',', '.'); ?></strong>
                    </div>
                    <div class="info-line">
                        <span>(-) Egresos/Gastos:</span>
                        <strong style="color: #ff6b6b;">$ <?php echo number_format($egresos, 2, ',', '.'); ?></strong>
                    </div>
                    <hr style="border-color: #333; margin: 15px 0;">
                    <div class="info-line" style="font-size: 1.2rem;">
                        <span>Saldo Esperado:</span>
                        <strong id="saldo_esperado_val" data-valor="<?php echo $saldo_esperado; ?>" style="color: var(--accent);">
                            $ <?php echo number_format($saldo_esperado, 2, ',', '.'); ?>
                        </strong>
                    </div>

                    <div id="box_diferencia" class="diferencia-box">
                        Diferencia: $ <span id="dif_val">0,00</span>
                    </div>

                    <hr style="border-color: #333; margin: 15px 0;">
                    <label style="display: block; color: #ccc; margin-bottom: 8px;">Observaciones (Opcional)</label>
                    <textarea name="observaciones" class="input-field" rows="4" placeholder="Ej: Faltó cobrar una entrega de cta cte..."></textarea>
                    
                    <div class="fondo-card">
                        <h3><i class="fas fa-piggy-bank"></i> Fondo Reservado (Vuelto)</h3>
                        <label>¿Cuánto dinero se reserva en caja como fondo de vuelto?</label>
                        <input type="number" name="fondo_vuelto" class="input-field" step="0.01" value="0" placeholder="Ej: 5000">
                        <p class="help-text">Este monto se registrará como fondo reservado y se sugerirá como saldo inicial en la próxima apertura de caja.</p>
                    </div>
                    
                    <button type="submit" class="btn-primary btn-block" style="margin-top: 20px;">
                        <i class="fas fa-check-circle"></i> Confirmar y Cerrar Caja
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        const inputs = document.querySelectorAll('.cant-billete');
        const displayTotalReal = document.getElementById('total_real_display');
        const inputHiddenReal = document.getElementById('saldo_real_input');
        const displayDif = document.getElementById('dif_val');
        const boxDif = document.getElementById('box_diferencia');
        const esperado = parseFloat(document.getElementById('saldo_esperado_val').dataset.valor);

        inputs.forEach(input => {
            input.addEventListener('input', calcularTotales);
        });

        function calcularTotales() {
            let totalReal = 0;
            inputs.forEach(input => {
                const valor = parseFloat(input.dataset.valor);
                const cant = parseInt(input.value) || 0;
                totalReal += (valor * cant);
            });

            displayTotalReal.innerText = totalReal.toLocaleString('es-AR', { minimumFractionDigits: 2 });
            inputHiddenReal.value = totalReal;

            let diferencia = totalReal - esperado;
            displayDif.innerText = diferencia.toLocaleString('es-AR', { minimumFractionDigits: 2 });

            if (diferencia === 0) {
                boxDif.className = 'diferencia-box bg-ok';
                boxDif.innerHTML = '<i class="fas fa-check-circle"></i> CAJA OK';
            } else if (diferencia > 0) {
                boxDif.className = 'diferencia-box bg-ok';
                boxDif.innerHTML = '<i class="fas fa-arrow-up"></i> SOBRANTE: $ ' + diferencia.toLocaleString('es-AR', { minimumFractionDigits: 2 });
            } else {
                boxDif.className = 'diferencia-box bg-error';
                boxDif.innerHTML = '<i class="fas fa-arrow-down"></i> FALTANTE: $ ' + Math.abs(diferencia).toLocaleString('es-AR', { minimumFractionDigits: 2 });
            }
        }
    </script>
</body>
</html>