<?php
// pages/reparar_cierres_fondo_inicial.php
//
// Herramienta de desarrollo: corrige los cierres de caja que quedaron con el
// FONDO INICIAL contado DOS VECES (bug corregido en funciones_caja.php 2.2.0).
//
// - GET  : detección en modo solo lectura (no modifica nada).
// - POST : con confirmación + token CSRF aplica la reparación.
//
// La reparación vuelve a detectar los cierres del lado del servidor (no confía
// en datos del cliente) y es idempotente: re-ejecutarla no vuelve a modificar
// filas ya corregidas.
include 'infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

// Solo el rol developer puede ejecutar esta herramienta
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'developer') {
    header('Location: ' . route('caja.dashboard'));
    exit();
}

$usuario = $_SESSION['usuario_nombre'] ?? $_SESSION['usuario'] ?? 'Sistema';

$errores = [];
$log = [];
$proceso_completado = false;
$resultado_reparacion = null;
$afectados = [];
$inicio_ejecucion = microtime(true);

// ---------------------------------------------------------------
// Detección (siempre, en modo lectura)
// ---------------------------------------------------------------
try {
    $afectados = detectar_cierres_fondo_inicial_duplicado($pdo);
} catch (Exception $e) {
    $errores[] = 'Error al detectar cierres afectados: ' . $e->getMessage();
}

$total_fondo_duplicado = 0;
$empresas_afectadas = [];
foreach ($afectados as $a) {
    $total_fondo_duplicado += (float)$a['saldo_inicial'];
    $empresas_afectadas[(int)$a['empresa_id']] = true;
}
$cant_empresas_afectadas = count($empresas_afectadas);

// ---------------------------------------------------------------
// Reparación (solo por POST confirmado y con token válido)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_reparacion']) && empty($errores)) {
    $csrf_ok = function_exists('csrf_verify') ? csrf_verify() : true;
    
    if (!$csrf_ok) {
        $errores[] = 'Token de seguridad inválido. Recargue la página e intente nuevamente.';
    } else {
        try {
            // Se re-detecta en el servidor: nunca se confía en el listado del cliente
            $a_reparar = detectar_cierres_fondo_inicial_duplicado($pdo);
            
            $log[] = '==========================================';
            $log[] = 'REPARACIÓN DE CIERRES CON FONDO INICIAL DUPLICADO';
            $log[] = '==========================================';
            $log[] = 'Fecha/Hora: ' . date('Y-m-d H:i:s');
            $log[] = 'Usuario: ' . $usuario;
            $log[] = 'Cierres afectados detectados: ' . count($a_reparar);
            $log[] = '==========================================';
            $log[] = '';
            
            $resultado_reparacion = reparar_cierres_fondo_inicial_duplicado($pdo, $a_reparar, $usuario);
            
            if (!$resultado_reparacion['success']) {
                $errores[] = $resultado_reparacion['mensaje'];
            } else {
                foreach ($resultado_reparacion['detalle'] as $d) {
                    $log[] = sprintf(
                        '✓ Cierre #%d: ingresos efectivo %s -> %s | esperado nuevo %s | diferencia nueva %s',
                        $d['cierre_id'],
                        number_format($d['ingresos_efectivo'], 2, ',', '.'),
                        number_format($d['ingresos_efectivo_nuevo'], 2, ',', '.'),
                        number_format($d['saldo_esperado_nuevo'], 2, ',', '.'),
                        number_format($d['diferencia_nueva'], 2, ',', '.')
                    );
                }
                if ($resultado_reparacion['reparados'] === 0) {
                    $log[] = 'Sin cambios: no había cierres con la firma del bug (re-ejecución inofensiva).';
                }
                $proceso_completado = true;
                // Recalcular el estado final para la vista
                $afectados = detectar_cierres_fondo_inicial_duplicado($pdo);
            }
        } catch (Exception $e) {
            $errores[] = 'Error general: ' . $e->getMessage();
            $log[] = '✗ ERROR CRÍTICO: ' . $e->getMessage();
        }
    }
}

$fin_ejecucion = microtime(true);
$tiempo_total = round($fin_ejecucion - $inicio_ejecucion, 2);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reparar Cierres con Fondo Duplicado | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/cierre_caja.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1><i class="fas fa-screwdriver-wrench"></i> Reparar Cierres con Fondo Duplicado</h1>
            <a href="<?php echo route_file('pages/caja_dashboard.php'); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver al Dashboard
            </a>
        </div>

        <div class="info-box">
            <strong><i class="fas fa-info-circle"></i> ¿Qué corrige esta herramienta?</strong><br>
            Al abrir la caja, el saldo inicial se registra en <code>estado_caja.saldo_inicial</code> y además como
            movimiento <code>FONDO INICIAL (APERTURA)</code>. En los cierres hechos con la versión anterior del
            sistema, ese movimiento también se sumaba a <code>ingresos_efectivo</code>, por lo que el fondo reservado
            dejado de la caja anterior quedaba contado <strong>dos veces</strong>.<br>
            Esta herramienta quita ese monto del ingreso, recalcula el <code>saldo_esperado_efectivo</code> y la
            <code>diferencia</code>, y registra cada cambio en <code>cierres_caja_audit</code>.
            El <strong>saldo real contado no se modifica</strong>. Los cierres históricos masivos
            (<code>Sistema (Cierre Histórico)</code>) se excluyen porque usan otra fórmula.
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Cierres Afectados</div>
                <div class="value" style="color: <?php echo count($afectados) > 0 ? '#ffc107' : '#28a745'; ?>;">
                    <?php echo count($afectados); ?>
                </div>
            </div>
            <div class="stat-box">
                <div class="label">Fondo Duplicado Detectado</div>
                <div class="value">$<?php echo number_format($total_fondo_duplicado, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Empresas Afectadas</div>
                <div class="value"><?php echo $cant_empresas_afectadas; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Errores</div>
                <div class="value" style="color: <?php echo count($errores) > 0 ? '#dc3545' : '#28a745'; ?>;">
                    <?php echo count($errores); ?>
                </div>
            </div>
        </div>

        <?php if (!empty($errores)): ?>
        <div class="error-box">
            <strong><i class="fas fa-exclamation-circle"></i> ERRORES ENCONTRADOS:</strong><br>
            <?php foreach ($errores as $error): ?>
                - <?php echo htmlspecialchars($error); ?><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (count($afectados) === 0): ?>
        <div class="success-box">
            <strong><i class="fas fa-check-circle"></i> Todo en orden!</strong><br>
            No se encontraron cierres de caja con el fondo inicial duplicado.
            <?php if ($proceso_completado): ?>
                <br>La reparación se aplicó correctamente en esta ejecución.
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-container">
            <h3 style="margin-top: 0; color: #00bcd4;">
                <i class="fas fa-list"></i> Cierres a Corregir (<?php echo count($afectados); ?>)
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cierre</th>
                        <th>Empresa / Sucursal</th>
                        <th>Período</th>
                        <th>Usuario</th>
                        <th>Saldo Inicial</th>
                        <th>Ing. Efectivo (actual)</th>
                        <th>Ing. Efectivo (corregido)</th>
                        <th>Esperado (actual)</th>
                        <th>Esperado (corregido)</th>
                        <th>Diferencia (corregida)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($afectados as $index => $a): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><strong>#<?php echo (int)$a['id']; ?></strong></td>
                        <td><?php echo (int)$a['empresa_id']; ?> / <?php echo (int)$a['sucursal_id']; ?></td>
                        <td>
                            <?php echo date('d/m/Y', strtotime($a['fecha_desde'])); ?>
                            <?php if (date('Y-m-d', strtotime($a['fecha_desde'])) !== date('Y-m-d', strtotime($a['fecha_hasta']))): ?>
                                al <?php echo date('d/m/Y', strtotime($a['fecha_hasta'])); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($a['usuario'] ?? ''); ?></td>
                        <td>$<?php echo number_format((float)$a['saldo_inicial'], 2, ',', '.'); ?></td>
                        <td style="color: #f09086;">$<?php echo number_format((float)$a['ingresos_efectivo'], 2, ',', '.'); ?></td>
                        <td style="color: #7fd68a;"><strong>$<?php echo number_format((float)$a['ingresos_efectivo_corregido'], 2, ',', '.'); ?></strong></td>
                        <td style="color: #f09086;">$<?php echo number_format((float)$a['saldo_esperado_efectivo'], 2, ',', '.'); ?></td>
                        <td style="color: #7fd68a;"><strong>$<?php echo number_format((float)$a['saldo_esperado_corregido'], 2, ',', '.'); ?></strong></td>
                        <td><strong>$<?php echo number_format((float)$a['diferencia_corregida'], 2, ',', '.'); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (count($afectados) > 0): ?>
        <div class="warning-box" style="margin-top: 20px;">
            <strong><i class="fas fa-exclamation-triangle"></i> Modo Ejecución:</strong><br>
            Al confirmar se <strong>MODIFICA la base de datos</strong>: se corrigen los
            <?php echo count($afectados); ?> cierre(s) listados por un total de
            $<?php echo number_format($total_fondo_duplicado, 2, ',', '.'); ?> de fondo duplicado.<br>
            Se recomienda hacer un respaldo (backup) antes de continuar.
        </div>

        <div class="confirm-box">
            <h3 style="color: #ffc107; margin-top: 0;">
                <i class="fas fa-exclamation-triangle"></i> ¿Está seguro de continuar?
            </h3>
            <p style="color: #fff; margin-bottom: 20px;">
                Se recalcularán los importes indicados y cada cambio quedará auditado en
                <code>cierres_caja_audit</code>. La operación es idempotente: se puede volver a
                ejecutar sin riesgo.
            </p>
            <form method="POST" style="display: inline;">
                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                <input type="hidden" name="confirmar_reparacion" value="1">
                <button type="submit" class="btn btn-danger" style="padding: 12px 25px; font-size: 1rem;">
                    <i class="fas fa-check"></i> Sí, Reparar Cierres
                </button>
            </form>
            <a href="<?php echo route_file('pages/caja_dashboard.php'); ?>" class="btn btn-secondary" style="padding: 12px 25px; font-size: 1rem; margin-left: 10px;">
                <i class="fas fa-times"></i> Cancelar
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($log)): ?>
        <div class="table-container">
            <h3 style="margin-top: 0; color: #00bcd4;">
                <i class="fas fa-terminal"></i> Log de Ejecución
            </h3>
            <div class="log-container">
                <?php foreach ($log as $linea): ?>
                    <?php
                    $clase = '';
                    if (strpos($linea, '✓') !== false) $clase = 'success';
                    elseif (strpos($linea, '✗') !== false) $clase = 'error';
                    elseif (strpos($linea, '===') !== false) $clase = 'info';
                    ?>
                    <span class="<?php echo $clase; ?>"><?php echo htmlspecialchars($linea); ?></span>
                <?php endforeach; ?>
                <span class="">Tiempo de ejecución: <?php echo $tiempo_total; ?>s</span>
            </div>
        </div>

        <div class="info-box" style="margin-top: 20px;">
            <strong><i class="fas fa-clipboard-check"></i> Resultado:</strong><br>
            <?php echo htmlspecialchars($resultado_reparacion['mensaje'] ?? ''); ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>



