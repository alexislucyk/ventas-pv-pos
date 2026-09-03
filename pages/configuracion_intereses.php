<?php
// pages/configuracion_intereses.php
// Configuración de intereses por mora por empresa

include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario_id = $_SESSION['user_id'] ?? null;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Verificar permisos (solo administradores pueden configurar)
if (!tiene_permiso('configuracion_ver')) {
    die('❌ No tiene permisos para acceder a esta página.');
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar guardado de configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_configuracion'])) {
    $tasa_mensual = floatval($_POST['tasa_mensual'] ?? 3.00);
    $dias_gracia = intval($_POST['dias_gracia'] ?? 0);
    $plazo_fiado_dias = intval($_POST['plazo_fiado_dias'] ?? 30);
    $aplicar_automatico = isset($_POST['aplicar_automatico']) ? 1 : 0;
    $frecuencia = $_POST['frecuencia'] ?? 'DIARIA';
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones
    if ($tasa_mensual < 0 || $tasa_mensual > 100) {
        $mensaje = 'La tasa mensual debe estar entre 0 y 100%';
        $tipo_mensaje = 'error';
    } elseif ($dias_gracia < 0 || $dias_gracia > 365) {
        $mensaje = 'Los días de gracia deben estar entre 0 y 365';
        $tipo_mensaje = 'error';
    } elseif ($plazo_fiado_dias < 1 || $plazo_fiado_dias > 365) {
        $mensaje = 'El plazo de fiado debe estar entre 1 y 365 días';
        $tipo_mensaje = 'error';
    } else {
        try {
            // Verificar si existe configuración
            $sql_check = "SELECT id FROM configuracion_intereses WHERE empresa_id = :empresa_id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':empresa_id' => $empresa_id]);
            $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existe) {
                // Actualizar
                $sql_update = "
                    UPDATE configuracion_intereses 
                    SET tasa_mensual = :tasa_mensual,
                        dias_gracia = :dias_gracia,
                        plazo_fiado_dias = :plazo_fiado_dias,
                        aplicar_automatico = :aplicar_automatico,
                        frecuencia = :frecuencia,
                        activo = :activo,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE empresa_id = :empresa_id
                ";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    ':tasa_mensual' => $tasa_mensual,
                    ':dias_gracia' => $dias_gracia,
                    ':plazo_fiado_dias' => $plazo_fiado_dias,
                    ':aplicar_automatico' => $aplicar_automatico,
                    ':frecuencia' => $frecuencia,
                    ':activo' => $activo,
                    ':empresa_id' => $empresa_id
                ]);
            } else {
                // Insertar
                $sql_insert = "
                    INSERT INTO configuracion_intereses 
                    (empresa_id, tasa_mensual, dias_gracia, plazo_fiado_dias, aplicar_automatico, frecuencia, activo)
                    VALUES
                    (:empresa_id, :tasa_mensual, :dias_gracia, :plazo_fiado_dias, :aplicar_automatico, :frecuencia, :activo)
                ";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    ':empresa_id' => $empresa_id,
                    ':tasa_mensual' => $tasa_mensual,
                    ':dias_gracia' => $dias_gracia,
                    ':plazo_fiado_dias' => $plazo_fiado_dias,
                    ':aplicar_automatico' => $aplicar_automatico,
                    ':frecuencia' => $frecuencia,
                    ':activo' => $activo
                ]);
            }
            
            $mensaje = '✅ Configuración guardada exitosamente';
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            $mensaje = '❌ Error al guardar: ' . $e->getMessage();
            $tipo_mensaje = 'error';
            error_log("Error en configuracion_intereses.php: " . $e->getMessage());
        }
    }
}

// Obtener configuración actual
try {
    $sql_config = "SELECT * FROM configuracion_intereses WHERE empresa_id = :empresa_id LIMIT 1";
    $stmt_config = $pdo->prepare($sql_config);
    $stmt_config->execute([':empresa_id' => $empresa_id]);
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Valores por defecto
        $config = [
            'tasa_mensual' => 3.00,
            'dias_gracia' => 0,
            'plazo_fiado_dias' => 30,
            'aplicar_automatico' => 0,
            'frecuencia' => 'DIARIA',
            'activo' => 1
        ];
    }
} catch (Exception $e) {
    $mensaje = '❌ Error al cargar configuración: ' . $e->getMessage();
    $tipo_mensaje = 'error';
    $config = [
        'tasa_mensual' => 3.00,
        'dias_gracia' => 0,
        'plazo_fiado_dias' => 30,
        'aplicar_automatico' => 0,
        'frecuencia' => 'DIARIA',
        'activo' => 1
    ];
}

// Obtener estadísticas
try {
    require '../funciones/funciones_intereses.php';
    $stats = obtenerEstadisticasIntereses($pdo, $empresa_id);
} catch (Exception $e) {
    $stats = [
        'total_intereses_generados' => 0,
        'monto_total_intereses' => 0,
        'promedio_interes' => 0,
        'clientes_afectados' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración de Intereses | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>"> 
    <link rel="stylesheet" href="<?php echo url('css/pages/configuracion.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content ci-content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>

        <div class="ci-container">

        <!-- ===== Encabezado ===== -->
        <div class="ci-header">
            <div class="ci-header-title">
                <div class="ci-header-icon"><i class="fas fa-percent"></i></div>
                <div>
                    <h1>Intereses por Mora</h1>
                    <p class="ci-header-sub">Configuración de recargos automáticos para cuentas corrientes</p>
                </div>
                <span class="ci-badge <?php echo $config['activo'] ? 'ci-badge-on' : 'ci-badge-off'; ?>">
                    <i class="fas fa-<?php echo $config['activo'] ? 'play' : 'pause'; ?>"></i>
                    <?php echo $config['activo'] ? 'Sistema activo' : 'Sistema pausado'; ?>
                </span>
            </div>
            <a href="cuentas_corrientes.php" class="ci-btn-back">
                <i class="fas fa-arrow-left"></i> Volver a Cuentas Corrientes
            </a>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- ===== Estadísticas del mes ===== -->
        <div class="ci-stats">
            <div class="ci-stat">
                <div class="ci-stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="ci-stat-data">
                    <span class="ci-stat-label">Intereses generados (mes)</span>
                    <span class="ci-stat-value"><?php echo number_format($stats['total_intereses_generados'], 0, ',', '.'); ?></span>
                </div>
            </div>
            <div class="ci-stat">
                <div class="ci-stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="ci-stat-data">
                    <span class="ci-stat-label">Monto total (mes)</span>
                    <span class="ci-stat-value">$ <?php echo number_format($stats['monto_total_intereses'] ?? 0, 2, ',', '.'); ?></span>
                </div>
            </div>
            <div class="ci-stat">
                <div class="ci-stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="ci-stat-data">
                    <span class="ci-stat-label">Promedio por interés</span>
                    <span class="ci-stat-value">$ <?php echo number_format($stats['promedio_interes'] ?? 0, 2, ',', '.'); ?></span>
                </div>
            </div>
            <div class="ci-stat">
                <div class="ci-stat-icon"><i class="fas fa-users"></i></div>
                <div class="ci-stat-data">
                    <span class="ci-stat-label">Clientes afectados</span>
                    <span class="ci-stat-value"><?php echo number_format($stats['clientes_afectados'], 0, ',', '.'); ?></span>
                </div>
            </div>
        </div>

        <!-- ===== Formulario de configuración ===== -->
        <form method="POST" action="" class="ci-card">
            <div class="ci-form-grid">
                <div class="ci-field">
                    <label for="tasa_mensual">Tasa de interés mensual</label>
                    <div class="ci-input-wrap">
                        <input type="number"
                               id="tasa_mensual"
                               name="tasa_mensual"
                               step="0.01"
                               min="0"
                               max="100"
                               value="<?php echo htmlspecialchars($config['tasa_mensual']); ?>"
                               required>
                        <span class="ci-suffix">%</span>
                    </div>
                    <p class="ci-help">Porcentaje aplicado sobre el saldo moroso cada mes. Ej: 3.00 = 3% mensual</p>
                </div>

                <div class="ci-field">
                    <label for="dias_gracia">Días de gracia</label>
                    <div class="ci-input-wrap">
                        <input type="number"
                               id="dias_gracia"
                               name="dias_gracia"
                               min="0"
                               max="365"
                               value="<?php echo htmlspecialchars($config['dias_gracia']); ?>"
                               required>
                        <span class="ci-suffix">días</span>
                    </div>
                    <p class="ci-help">Tiempo extra después del vencimiento antes de calcular intereses</p>
                </div>

                <div class="ci-field">
                    <label for="plazo_fiado_dias">Plazo de vencimiento (fiado)</label>
                    <div class="ci-input-wrap">
                        <input type="number"
                               id="plazo_fiado_dias"
                               name="plazo_fiado_dias"
                               min="1"
                               max="365"
                               value="<?php echo htmlspecialchars($config['plazo_fiado_dias'] ?? 30); ?>"
                               required>
                        <span class="ci-suffix">días</span>
                    </div>
                    <p class="ci-help">Las facturas al fiado vencen N días después de la venta. Desde esa fecha se calcula la mora</p>
                </div>

                <div class="ci-field">
                    <label for="frecuencia">Frecuencia de cálculo</label>
                    <div class="ci-input-wrap">
                        <select id="frecuencia" name="frecuencia">
                            <option value="DIARIA" <?php echo $config['frecuencia'] === 'DIARIA' ? 'selected' : ''; ?>>
                                Diaria
                            </option>
                            <option value="SEMANAL" <?php echo $config['frecuencia'] === 'SEMANAL' ? 'selected' : ''; ?>>
                                Semanal
                            </option>
                            <option value="MENSUAL" <?php echo $config['frecuencia'] === 'MENSUAL' ? 'selected' : ''; ?>>
                                Mensual
                            </option>
                        </select>
                    </div>
                    <p class="ci-help">Cada cuánto se recalculan los intereses pendientes</p>
                </div>
            </div>

            <div class="ci-toggles">
                <label class="ci-switch-row" for="aplicar_automatico">
                    <div class="ci-switch-text">
                        <strong><i class="fas fa-robot"></i> Aplicar automáticamente</strong>
                        <span>Los intereses se aplican solos según la frecuencia configurada</span>
                    </div>
                    <input type="checkbox"
                           id="aplicar_automatico"
                           name="aplicar_automatico"
                           <?php echo $config['aplicar_automatico'] ? 'checked' : ''; ?>>
                    <span class="ci-switch"></span>
                </label>

                <label class="ci-switch-row" for="activo">
                    <div class="ci-switch-text">
                        <strong><i class="fas fa-power-off"></i> Sistema activo</strong>
                        <span>Desactívalo para pausar temporalmente el cálculo de intereses</span>
                    </div>
                    <input type="checkbox"
                           id="activo"
                           name="activo"
                           <?php echo $config['activo'] ? 'checked' : ''; ?>>
                    <span class="ci-switch"></span>
                </label>
            </div>

            <div class="ci-actions">
                <button type="submit" name="guardar_configuracion" class="ci-btn-save">
                    <i class="fas fa-save"></i> Guardar configuración
                </button>
                <a href="cuentas_corrientes.php" class="ci-btn-cancel">Cancelar</a>
            </div>
        </form>

        <!-- ===== Info + Ejemplo de cálculo ===== -->
        <div class="ci-bottom">
            <div class="ci-card">
                <h3><i class="fas fa-circle-info"></i> Cómo funciona</h3>
                <ul class="ci-info-list">
                    <li><i class="fas fa-percentage"></i><div><strong>Tasa mensual:</strong> porcentaje que se aplica sobre el saldo deudor por cada mes de mora.</div></li>
                    <li><i class="fas fa-calendar-check"></i><div><strong>Días de gracia:</strong> período sin recargos después del vencimiento.</div></li>
                    <li><i class="fas fa-receipt"></i><div><strong>Plazo de fiado:</strong> define la fecha de vencimiento de las ventas en cuenta corriente.</div></li>
                    <li><i class="fas fa-rotate"></i><div><strong>Frecuencia:</strong> cada cuánto se recalculan los intereses pendientes.</div></li>
                    <li><i class="fas fa-robot"></i><div><strong>Aplicación automática:</strong> el sistema aplica los intereses sin intervención manual.</div></li>
                </ul>
            </div>

            <div class="ci-card">
                <h3><i class="fas fa-calculator"></i> Ejemplo de cálculo</h3>
                <div class="ci-example-grid">
                    <div class="ci-field">
                        <label for="ejemplo_saldo">Saldo adeudado</label>
                        <div class="ci-input-wrap">
                            <input type="number" id="ejemplo_saldo" value="10000" min="0" step="100">
                            <span class="ci-suffix">$</span>
                        </div>
                    </div>
                    <div class="ci-field">
                        <label for="ejemplo_dias">Días de mora</label>
                        <div class="ci-input-wrap">
                            <input type="number" id="ejemplo_dias" value="45" min="0" step="1">
                            <span class="ci-suffix">días</span>
                        </div>
                    </div>
                </div>
                <div class="ci-example-result">
                    <div class="ci-example-row"><span>Tasa mensual</span><span id="ejemplo_tasa"><?php echo htmlspecialchars($config['tasa_mensual']); ?>%</span></div>
                    <div class="ci-example-row"><span>Días de gracia aplicados</span><span id="ejemplo_gracia"><?php echo htmlspecialchars($config['dias_gracia']); ?> días</span></div>
                    <div class="ci-example-row ci-example-total">
                        <span><i class="fas fa-arrow-trend-up"></i> Interés a aplicar</span>
                        <span id="ejemplo_interes">$ 0,00</span>
                    </div>
                </div>
                <p class="ci-help">Fórmula: Interés = Saldo × (Tasa / 30 / 100) × días de mora efectivos</p>
            </div>
        </div>
        </div><!-- /ci-container -->
    </div>

    <script>
    // Resaltar link activo del sidebar para esta página
    document.addEventListener('DOMContentLoaded', function() {
        const links = document.querySelectorAll('.sidebar-menu-container a');
        links.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href').includes('configuracion_intereses')) {
                link.classList.add('active');
            }
        });
        calcularEjemplo();
    });

    // Calculadora de ejemplo en vivo (usa la tasa y días de gracia del formulario)
    function calcularEjemplo() {
        const saldo = parseFloat(document.getElementById('ejemplo_saldo').value) || 0;
        const dias = parseFloat(document.getElementById('ejemplo_dias').value) || 0;
        const tasa = parseFloat(document.getElementById('tasa_mensual').value) || 0;
        const gracia = parseInt(document.getElementById('dias_gracia').value) || 0;

        const diasEfectivos = Math.max(0, dias - gracia);
        const interes = saldo * (tasa / 30 / 100) * diasEfectivos;

        document.getElementById('ejemplo_tasa').textContent = tasa.toFixed(2).replace('.', ',') + '%';
        document.getElementById('ejemplo_gracia').textContent = gracia + ' días';
        document.getElementById('ejemplo_interes').textContent =
            '$ ' + interes.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    ['ejemplo_saldo', 'ejemplo_dias', 'tasa_mensual', 'dias_gracia'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', calcularEjemplo);
    });
    </script>
</body>
</html>