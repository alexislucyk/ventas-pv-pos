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
                    ':aplicar_automatico' => $aplicar_automatico,
                    ':frecuencia' => $frecuencia,
                    ':activo' => $activo,
                    ':empresa_id' => $empresa_id
                ]);
            } else {
                // Insertar
                $sql_insert = "
                    INSERT INTO configuracion_intereses 
                    (empresa_id, tasa_mensual, dias_gracia, aplicar_automatico, frecuencia, activo)
                    VALUES
                    (:empresa_id, :tasa_mensual, :dias_gracia, :aplicar_automatico, :frecuencia, :activo)
                ";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    ':empresa_id' => $empresa_id,
                    ':tasa_mensual' => $tasa_mensual,
                    ':dias_gracia' => $dias_gracia,
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
    <style>
        .content { 
            padding: 20px 30px; 
            min-height: 100vh;
        }
        
        .content h1 { font-size: 1.6rem; margin-bottom: 20px; padding-bottom: 8px; }
        .card { 
            padding: 20px; 
            margin-bottom: 20px; 
            background: #2c2c2c; 
            border-radius: 8px;
        }
        .card h2, .card h3 { font-size: 1.1rem; margin-bottom: 15px; }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: #00bcd4;
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .form-group label small {
            color: #888;
            font-weight: normal;
            font-size: 0.8rem;
        }
        
        .form-group input[type="number"],
        .form-group select {
            width: 100%;
            padding: 10px;
            background: #1f1f1f;
            border: 1px solid #444;
            border-radius: 4px;
            color: #fff;
            font-size: 0.95rem;
        }
        
        .form-group input[type="number"]:focus,
        .form-group select:focus {
            outline: none;
            border-color: #00bcd4;
        }
        
        .form-group .help-text {
            color: #888;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            color: #fff;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #00bcd4;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.95rem;
        }
        
        .btn-primary:hover {
            background: #00acc1;
        }
        
        .btn-secondary {
            background: #666;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background: #555;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        
        .alert-success {
            background: #2ecc71;
            color: white;
        }
        
        .alert-error {
            background: #e74c3c;
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #1f1f1f;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #00bcd4;
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #888;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        
        .stat-card .value {
            color: #fff;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .info-box {
            background: #1f1f1f;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            margin-bottom: 20px;
        }
        
        .info-box h4 {
            margin: 0 0 10px 0;
            color: #3498db;
        }
        
        .info-box p {
            margin: 5px 0;
            color: #bbb;
            font-size: 0.9rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>⚙️ Configuración de Intereses por Mora</h1>
            <a href="cuentas_corrientes.php" class="btn-secondary">
                ← Volver a Cuentas Corrientes
            </a>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- Estadísticas del Mes -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><i class="fas fa-percentage"></i> Intereses Generados (Mes)</h3>
                <div class="value"><?php echo $stats['total_intereses_generados']; ?></div>
            </div>
        <div class="stat-card">
            <h3><i class="fas fa-dollar-sign"></i> Monto Total (Mes)</h3>
            <div class="value">$ <?php echo number_format($stats['monto_total_intereses'] ?? 0, 2, ',', '.'); ?></div>
        </div>
        <div class="stat-card">
            <h3><i class="fas fa-chart-line"></i> Promedio por Interés</h3>
            <div class="value">$ <?php echo number_format($stats['promedio_interes'] ?? 0, 2, ',', '.'); ?></div>
        </div>
            <div class="stat-card">
                <h3><i class="fas fa-users"></i> Clientes Afectados</h3>
                <div class="value"><?php echo $stats['clientes_afectados']; ?></div>
            </div>
        </div>

        <!-- Formulario de Configuración -->
        <div class="card">
            <h2><i class="fas fa-cog"></i> Parámetros de Configuración</h2>
            
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="tasa_mensual">
                            Tasa de Interés Mensual (%)
                            <small>Porcentaje mensual a aplicar</small>
                        </label>
                        <input type="number" 
                               id="tasa_mensual" 
                               name="tasa_mensual" 
                               step="0.01" 
                               min="0" 
                               max="100"
                               value="<?php echo htmlspecialchars($config['tasa_mensual']); ?>"
                               required>
                        <p class="help-text">Ej: 3.00 = 3% mensual</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="dias_gracia">
                            Días de Gracia
                            <small>Días extras antes de aplicar intereses</small>
                        </label>
                        <input type="number" 
                               id="dias_gracia" 
                               name="dias_gracia" 
                               min="0" 
                               max="365"
                               value="<?php echo htmlspecialchars($config['dias_gracia']); ?>"
                               required>
                        <p class="help-text">Ej: 5 días de gracia después del vencimiento</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="frecuencia">
                            Frecuencia de Cálculo
                        </label>
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
                        <p class="help-text">Con qué frecuencia se recalcularán los intereses</p>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" 
                                   id="aplicar_automatico" 
                                   name="aplicar_automatico"
                                   <?php echo $config['aplicar_automatico'] ? 'checked' : ''; ?>>
                            <label for="aplicar_automatico">
                                <strong>Aplicar Automáticamente</strong>
                                <p class="help-text" style="margin-top: 3px;">
                                    Si está activado, los intereses se aplicarán automáticamente según la frecuencia configurada
                                </p>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" 
                                   id="activo" 
                                   name="activo"
                                   <?php echo $config['activo'] ? 'checked' : ''; ?>>
                            <label for="activo">
                                <strong>Sistema Activo</strong>
                                <p class="help-text" style="margin-top: 3px;">
                                    Desactivar para pausar temporalmente el cálculo de intereses
                                </p>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="guardar_configuracion" class="btn-primary">
                        <i class="fas fa-save"></i> Guardar Configuración
                    </button>
                    <a href="cuentas_corrientes.php" class="btn-secondary">
                        Cancelar
                    </a>
                </div>
            </form>
        </div>

        <!-- Información Adicional -->
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información sobre la Configuración</h4>
            <p><strong>Tasa de Interés Mensual:</strong> Porcentaje que se aplicará sobre el saldo deudor por cada mes de mora.</p>
            <p><strong>Días de Gracia:</strong> Período de gracia después del vencimiento antes de comenzar a calcular intereses.</p>
            <p><strong>Frecuencia de Cálculo:</strong> Cada cuánto tiempo se recalcularán los intereses pendientes.</p>
            <p><strong>Aplicar Automáticamente:</strong> Si está activado, el sistema aplicará intereses automáticamente sin intervención manual.</p>
        </div>

        <div class="info-box" style="border-left-color: #f39c12;">
            <h4><i class="fas fa-calculator"></i> Ejemplo de Cálculo</h4>
            <p>Saldo: $10,000 | Tasa: 3% mensual | Días de mora: 45 días | Días de gracia: 0</p>
            <p style="color: #f39c12; font-size: 1.1rem; font-weight: bold;">
                Interés = 10,000 × (3.00 / 30 / 100) × 45 = <strong>$ 450.00</strong>
            </p>
        </div>
    </div>
</body>
</html>