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

require_once '../funciones/funciones_intereses.php';

// ¿Esta base ya tiene aplicada la migración 50 (columnas nuevas de intereses)?
$tiene_migracion_50 = tablaEsInteresCcExiste($pdo);

$mensaje = '';
$tipo_mensaje = '';

// Procesar guardado de configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_configuracion'])) {
    // Datos del formulario
    $tasa_mensual = floatval($_POST['tasa_mensual'] ?? INTERES_CC_TASA_DEFECTO);
    $dias_gracia = intval($_POST['dias_gracia'] ?? 0);
    $plazo_fiado_dias = intval($_POST['plazo_fiado_dias'] ?? INTERES_CC_PLAZO_DEFECTO);
    $modo_calculo = strtoupper(trim($_POST['modo_calculo'] ?? 'DIARIO'));
    $fecha_vigencia = trim($_POST['fecha_vigencia'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;

    if (!in_array($modo_calculo, ['DIARIO', 'MENSUAL'], true)) {
        $modo_calculo = 'DIARIO';
    }
    if ($fecha_vigencia !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_vigencia)) {
        $fecha_vigencia = '';
    }

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
            // ¿Ya existe una fila de configuración para esta empresa?
            $stmt_check = $pdo->prepare("SELECT id FROM configuracion_intereses WHERE empresa_id = :empresa_id");
            $stmt_check->execute([':empresa_id' => $empresa_id]);
            $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);

            $datos = [
                'tasa_mensual'     => $tasa_mensual,
                'dias_gracia'      => $dias_gracia,
                'plazo_fiado_dias' => $plazo_fiado_dias,
                'activo'           => $activo,
            ];

            // Columnas de la migración 50: sólo se usan si esta base ya las tiene
            if ($tiene_migracion_50) {
                $datos['modo_calculo'] = $modo_calculo;
                $datos['fecha_vigencia'] = $fecha_vigencia !== '' ? $fecha_vigencia : null;
            }

            $params = $datos;
            $params['empresa_id'] = $empresa_id;

            if ($existe) {
                $sets = [];
                foreach (array_keys($datos) as $col) {
                    $sets[] = $col . ' = :' . $col;
                }
                $sql = "UPDATE configuracion_intereses SET " . implode(', ', $sets)
                     . ", updated_at = CURRENT_TIMESTAMP WHERE empresa_id = :empresa_id";
            } else {
                $cols = array_merge(['empresa_id'], array_keys($datos));
                $marcas = array_map(function ($col) { return ':' . $col; }, $cols);
                $sql = "INSERT INTO configuracion_intereses (" . implode(', ', $cols) . ")"
                     . " VALUES (" . implode(', ', $marcas) . ")";
            }

            $stmt_guardar = $pdo->prepare($sql);
            $stmt_guardar->execute($params);

            $mensaje = '✅ Configuración guardada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = '❌ Error al guardar: ' . $e->getMessage();
            $tipo_mensaje = 'error';
            error_log("Error en configuracion_intereses.php: " . $e->getMessage());
        }
    }
}

// Obtener configuración actual (con valores por defecto si falta la fila)
try {
    $stmt_config = $pdo->prepare("SELECT * FROM configuracion_intereses WHERE empresa_id = :empresa_id LIMIT 1");
    $stmt_config->execute([':empresa_id' => $empresa_id]);
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    if (!$mensaje) {
        $mensaje = '❌ Error al cargar configuración: ' . $e->getMessage();
        $tipo_mensaje = 'error';
    }
    $config = [];
}

$config = $config + [
    'tasa_mensual'     => INTERES_CC_TASA_DEFECTO,
    'dias_gracia'      => 0,
    'plazo_fiado_dias' => INTERES_CC_PLAZO_DEFECTO,
    'modo_calculo'     => 'DIARIO',
    'fecha_vigencia'   => null,
    'activo'           => 1,
];

// Obtener estadísticas del mes actual
try {
    $stats = obtenerEstadisticasIntereses($pdo, $empresa_id);
} catch (Exception $e) {
    $stats = [
        'total_intereses_generados' => 0,
        'monto_total_intereses'     => 0,
        'promedio_interes'          => 0,
        'clientes_afectados'        => 0,
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
                    <p class="ci-help">Se aplica sobre el saldo deudor de la cuenta. Ej: 7.00 = 7% mensual</p>
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
                    <p class="ci-help">Tiempo extra sin recargos después del vencimiento</p>
                </div>

                <div class="ci-field">
                    <label for="plazo_fiado_dias">Plazo de vencimiento (fiado)</label>
                    <div class="ci-input-wrap">
                        <input type="number"
                               id="plazo_fiado_dias"
                               name="plazo_fiado_dias"
                               min="1"
                               max="365"
                               value="<?php echo htmlspecialchars($config['plazo_fiado_dias']); ?>"
                               required>
                        <span class="ci-suffix">días</span>
                    </div>
                    <p class="ci-help">Las ventas al fiado vencen N días después de la venta</p>
                </div>

                <div class="ci-field">
                    <label for="modo_calculo">Modo de cálculo</label>
                    <div class="ci-input-wrap">
                        <select id="modo_calculo" name="modo_calculo">
                            <option value="DIARIO" <?php echo strtoupper((string)$config['modo_calculo']) === 'DIARIO' ? 'selected' : ''; ?>>
                                Diario (prorratea por día)
                            </option>
                            <option value="MENSUAL" <?php echo strtoupper((string)$config['modo_calculo']) === 'MENSUAL' ? 'selected' : ''; ?>>
                                Mensual (sólo meses completos)
                            </option>
                        </select>
                    </div>
                    <p class="ci-help">Diario: saldo × tasa/30 × días. Mensual: saldo × tasa × meses completos</p>
                </div>

                <div class="ci-field">
                    <label for="fecha_vigencia">Fecha de inicio (opcional)</label>
                    <div class="ci-input-wrap">
                        <input type="date"
                               id="fecha_vigencia"
                               name="fecha_vigencia"
                               value="<?php echo htmlspecialchars((string)($config['fecha_vigencia'] ?? '')); ?>">
                    </div>
                    <p class="ci-help">Vacío = calcula desde el vencimiento más antiguo impago (cálculo completo)</p>
                </div>
            </div>

            <div class="ci-toggles">
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
                    <li><i class="fas fa-scale-balanced"></i><div><strong>Base:</strong> el interés se calcula sobre el saldo deudor de la cuenta, no factura por factura.</div></li>
                    <li><i class="fas fa-calendar-check"></i><div><strong>Desde cuándo:</strong> desde el vencimiento de la factura impaga más antigua; los pagos cancelan primero la deuda más vieja.</div></li>
                    <li><i class="fas fa-scissors"></i><div><strong>Sin anatocismo:</strong> los intereses ya aplicados no generan nuevos intereses.</div></li>
                    <li><i class="fas fa-percentage"></i><div><strong>Tasa mensual:</strong> porcentaje sobre el saldo deudor por día o por mes de mora.</div></li>
                    <li><i class="fas fa-receipt"></i><div><strong>Plazo de fiado:</strong> define la fecha de vencimiento de las ventas en cuenta corriente.</div></li>
                    <li><i class="fas fa-rotate-left"></i><div><strong>Corte:</strong> al aplicar el interés queda marcada la fecha, así los días cobrados no se repiten nunca.</div></li>
                </ul>
            </div>

            <div class="ci-card">
                <h3><i class="fas fa-calculator"></i> Ejemplo de cálculo</h3>
                <div class="ci-example-grid">
                    <div class="ci-field">
                        <label for="ejemplo_saldo">Saldo deudor</label>
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
                    <div class="ci-example-row"><span>Tasa mensual</span><span id="ejemplo_tasa">0,00%</span></div>
                    <div class="ci-example-row"><span>Días de gracia aplicados</span><span id="ejemplo_gracia">0 días</span></div>
                    <div class="ci-example-row"><span>Días que se cobran</span><span id="ejemplo_dias_efectivos">0 días</span></div>
                    <div class="ci-example-row ci-example-total">
                        <span><i class="fas fa-arrow-trend-up"></i> Interés a aplicar</span>
                        <span id="ejemplo_interes">$ 0,00</span>
                    </div>
                </div>
                <p class="ci-help" id="ejemplo_formula">Fórmula: Saldo × (Tasa / 30 / 100) × días de mora</p>
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

    // Calculadora de ejemplo en vivo (usa la tasa, la gracia y el modo del formulario)
    function calcularEjemplo() {
        const saldo = parseFloat(document.getElementById('ejemplo_saldo').value) || 0;
        const dias = parseInt(document.getElementById('ejemplo_dias').value) || 0;
        const tasa = parseFloat(document.getElementById('tasa_mensual').value) || 0;
        const gracia = parseInt(document.getElementById('dias_gracia').value) || 0;
        const modo = document.getElementById('modo_calculo').value;

        const diasEfectivos = Math.max(0, dias - gracia);
        let interes = 0;
        let formula = '';

        if (modo === 'MENSUAL') {
            const meses = Math.floor(diasEfectivos / 30);
            interes = saldo * (tasa / 100) * meses;
            formula = 'Fórmula: Saldo × (Tasa / 100) × meses completos (' + meses + ')';
        } else {
            interes = saldo * (tasa / 30 / 100) * diasEfectivos;
            formula = 'Fórmula: Saldo × (Tasa / 30 / 100) × días de mora';
        }

        document.getElementById('ejemplo_tasa').textContent = tasa.toFixed(2).replace('.', ',') + '%';
        document.getElementById('ejemplo_gracia').textContent = gracia + ' días';
        document.getElementById('ejemplo_dias_efectivos').textContent = diasEfectivos + ' días';
        document.getElementById('ejemplo_interes').textContent =
            '$ ' + interes.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('ejemplo_formula').textContent = formula;
    }

    ['ejemplo_saldo', 'ejemplo_dias', 'tasa_mensual', 'dias_gracia', 'modo_calculo'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', calcularEjemplo);
            el.addEventListener('change', calcularEjemplo);
        }
    });
    </script>
</body>
</html>