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
    <link rel="stylesheet" href="<?php echo url('css/pages/cierre_caja.css'); ?>">
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