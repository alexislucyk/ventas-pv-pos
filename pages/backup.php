<?php
include 'infosesion.php';
require '../config/db_config.php';

// Solo 'developer' o usuarios con permiso específico pueden entrar
if (!tiene_permiso('pages/backup.php')) {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ejecutar backup manual
    if (isset($_POST['ejecutar_backup'])) {
        try {
            // Habilitar temporalmente el backup
            $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('backup_habilitado', '1') 
                                   ON DUPLICATE KEY UPDATE valor = '1'");
            $stmt->execute();
            
            // Ejecutar script de backup
            ob_start();
            include PATH_BASE . 'procesos/backup_database.php';
            $output = ob_get_clean();
            
            // Restaurar configuración original
            $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'backup_habilitado'");
            $stmt->execute();
            $estado_original = $stmt->fetchColumn();
            
            if ($estado_original === false || $estado_original === '0') {
                $stmt = $pdo->prepare("UPDATE configuracion SET valor = '0' WHERE clave = 'backup_habilitado'");
                $stmt->execute();
            }
            
            $mensaje = "✅ Backup ejecutado correctamente.";
            $tipo_mensaje = "success";
        } catch (Exception $e) {
            $mensaje = "❌ Error al ejecutar backup: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
    
    // Guardar configuración de backup
    if (isset($_POST['guardar_config_backup'])) {
        try {
            $pdo->beginTransaction();
            
            $habilitado = isset($_POST['backup_habilitado']) ? '1' : '0';
            $frecuencia = $_POST['backup_frecuencia'] ?? 'diario';
            $ruta = trim($_POST['backup_ruta'] ?? '');
            $cantidad = (int)($_POST['backup_cantidad'] ?? 7);
            
            // Validar cantidad
            if ($cantidad < 1) $cantidad = 1;
            if ($cantidad > 50) $cantidad = 50;
            
            $configs = [
                'backup_habilitado' => $habilitado,
                'backup_frecuencia' => $frecuencia,
                'backup_ruta' => $ruta,
                'backup_cantidad' => (string)$cantidad
            ];
            
            $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            
            foreach ($configs as $clave => $valor) {
                $stmt->execute([$clave, $valor]);
            }
            
            $pdo->commit();
            $mensaje = "✅ Configuración de backup actualizada correctamente.";
            $tipo_mensaje = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje = "❌ Error al guardar configuración: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
    
    // Eliminar backup
    if (isset($_POST['eliminar_backup'])) {
        $archivo = $_POST['eliminar_backup'] ?? '';
        $ruta_backup = $_POST['ruta_backup'] ?? '';
        
        if (!empty($archivo) && !empty($ruta_backup)) {
            $ruta_completa = rtrim($ruta_backup, '/') . '/' . basename($archivo);
            if (file_exists($ruta_completa)) {
                if (unlink($ruta_completa)) {
                    $mensaje = "✅ Backup eliminado correctamente.";
                    $tipo_mensaje = "success";
                } else {
                    $mensaje = "❌ Error al eliminar el backup.";
                    $tipo_mensaje = "error";
                }
            }
        }
    }
}

// Obtener configuración actual
$stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('backup_habilitado', 'backup_frecuencia', 'backup_ruta', 'backup_cantidad')");
$config_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$backup_habilitado = isset($config_raw['backup_habilitado']) ? $config_raw['backup_habilitado'] : '0';
$backup_frecuencia = isset($config_raw['backup_frecuencia']) ? $config_raw['backup_frecuencia'] : 'diario';
$backup_ruta = isset($config_raw['backup_ruta']) ? $config_raw['backup_ruta'] : '';
$backup_cantidad = isset($config_raw['backup_cantidad']) ? (int)$config_raw['backup_cantidad'] : 7;

// Obtener ruta de backups (para listar)
$ruta_backup = !empty($backup_ruta) ? $backup_ruta : PATH_BASE . 'backups/';

// Convertir ruta URL a ruta de filesystem
if (strpos($ruta_backup, URL_BASE) === 0) {
    // Ruta empieza con URL_BASE, convertir a PATH_BASE
    $ruta_backup_absoluta = PATH_BASE . substr($ruta_backup, strlen(URL_BASE));
} elseif (strpos($ruta_backup, '/') === 0 || strpos($ruta_backup, '\\') === 0) {
    // Ruta relativa desde DOCUMENT_ROOT (ej: /pos_prod/backups/ o \pos_prod\backups\)
    // Convertir a ruta de Windows
    $ruta_normalizada = str_replace('/', '\\', $ruta_backup);
    $ruta_backup_absoluta = $_SERVER['DOCUMENT_ROOT'] . $ruta_normalizada;
} else {
    // Ya es una ruta absoluta
    $ruta_backup_absoluta = $ruta_backup;
}

// Normalizar ruta para Windows
$ruta_backup_glob = str_replace('/', '\\', $ruta_backup_absoluta);
$ruta_backup_glob = rtrim($ruta_backup_glob, '\\') . '\\';

// Debug: mostrar información de ruta
echo "<!-- DEBUG: db_name=$db_name, ruta_backup=$ruta_backup, ruta_absoluta=$ruta_backup_absoluta, ruta_glob=$ruta_backup_glob -->";

// Obtener lista de backups
$backups = [];

// Intentar buscar en la ruta absoluta primero
if (is_dir($ruta_backup_absoluta)) {
    $archivos = glob($ruta_backup_glob . "backup_{$db_name}_*.sql");
    
    // Si no encuentra, intentar con la ruta URL directamente (porque puede ser una ruta del sistema)
    if (empty($archivos) && strpos($ruta_backup, '/') === 0) {
        $ruta_alt = str_replace('/', '\\', $ruta_backup);
        $archivos = glob($ruta_alt . "backup_{$db_name}_*.sql");
    }
} else {
    // Si el directorio no existe, intentar con la ruta URL directamente
    if (strpos($ruta_backup, '/') === 0) {
        $ruta_alt = str_replace('/', '\\', $ruta_backup);
        if (is_dir($ruta_alt)) {
            $archivos = glob($ruta_alt . "backup_{$db_name}_*.sql");
        }
    }
}

// Debug: mostrar archivos encontrados
echo "<!-- DEBUG: Archivos encontrados: " . count($archivos) . " -->";

if (!empty($archivos)) {
    usort($archivos, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    foreach ($archivos as $archivo) {
        $backups[] = [
            'nombre' => basename($archivo),
            'ruta' => $archivo,
            'fecha' => date('Y-m-d H:i:s', filemtime($archivo)),
            'tamano' => formatBytes(filesize($archivo))
        ];
    }
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), $precision) . ' ' . $sizes[$i];
}

// Obtener información del último backup
$ultimo_backup_file = PATH_BASE . 'cache/ultimo_backup.txt';
$ultimo_backup = file_exists($ultimo_backup_file) ? date('Y-m-d H:i:s', (int)file_get_contents($ultimo_backup_file)) : 'Nunca';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Backup de Base de Datos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .backup-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .backup-section {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 25px;
        }
        .backup-section h3 {
            color: #00bcd4;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 1px solid #2a2a2a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .backup-section h3 i {
            font-size: 1.2em;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            color: #aaa;
            font-weight: 600;
            font-size: 0.85em;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #444;
            background: #252525;
            color: #f0f0f0;
            border-radius: 8px;
            font-size: 0.95em;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: #00bcd4;
            box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.15);
        }
        .form-group .helper-text {
            font-size: 0.78em;
            color: #666;
            margin-top: 6px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .checkbox-group label {
            color: #aaa;
            font-weight: 600;
            cursor: pointer;
            margin: 0;
        }
        .btn {
            padding: 12px 30px;
            font-weight: bold;
            font-size: 0.95rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: #fff;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #00bcd4, #0097a7);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.3);
        }
        .btn-success {
            background: linear-gradient(135deg, #4caf50, #388e3c);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.3);
        }
        .btn-danger {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.3);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #252525;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-card i {
            font-size: 2rem;
            color: #00bcd4;
            margin-bottom: 10px;
        }
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #f0f0f0;
            margin: 5px 0;
        }
        .stat-card .stat-label {
            font-size: 0.85em;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .backups-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .backups-table th,
        .backups-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #2a2a2a;
        }
        .backups-table th {
            color: #00bcd4;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85em;
            letter-spacing: 0.5px;
        }
        .backups-table td {
            color: #ccc;
        }
        .backups-table tr:hover {
            background: #252525;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid #4caf50;
            color: #4caf50;
        }
        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid #f44336;
            color: #f44336;
        }
        .actions-cell {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85em;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div class="backup-container">
            <h1><i class="fas fa-database"></i> Backup de Base de Datos</h1>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                    <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>
            
            <!-- Estadísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?php echo $ultimo_backup === 'Nunca' ? 'N/A' : date('d/m/Y', strtotime($ultimo_backup)); ?></div>
                    <div class="stat-label">Último Backup</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-hdd"></i>
                    <div class="stat-value"><?php echo count($backups); ?></div>
                    <div class="stat-label">Backups Totales</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-hdd"></i>
                    <div class="stat-value"><?php echo $backup_cantidad; ?></div>
                    <div class="stat-label">A Mantener</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-sync-alt"></i>
                    <div class="stat-value"><?php echo ucfirst($backup_frecuencia); ?></div>
                    <div class="stat-label">Frecuencia</div>
                </div>
            </div>
            
            <!-- Configuración -->
            <div class="backup-section">
                <h3><i class="fas fa-cog"></i> Configuración</h3>
                <form method="POST">
                    <div class="checkbox-group">
                        <input type="checkbox" id="backup_habilitado" name="backup_habilitado" 
                               <?php echo $backup_habilitado === '1' ? 'checked' : ''; ?>>
                        <label for="backup_habilitado">Habilitar backup automático</label>
                    </div>
                    
                    <div class="form-group">
                        <label>Frecuencia</label>
                        <select name="backup_frecuencia">
                            <option value="diario" <?php echo $backup_frecuencia === 'diario' ? 'selected' : ''; ?>>Diario</option>
                            <option value="semanal" <?php echo $backup_frecuencia === 'semanal' ? 'selected' : ''; ?>>Semanal</option>
                            <option value="mensual" <?php echo $backup_frecuencia === 'mensual' ? 'selected' : ''; ?>>Mensual</option>
                        </select>
                        <p class="helper-text">Cada cuánto tiempo se realizará un backup automático.</p>
                    </div>
                    
                    <div class="form-group">
                        <label>Ruta de Almacenamiento</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="backup_ruta" name="backup_ruta" value="<?php echo htmlspecialchars($backup_ruta); ?>" 
                                   placeholder="Dejar vacío para usar carpeta predeterminada (backups/)" 
                                   style="flex: 1;">
                            <button type="button" onclick="abrirExploradorArchivos()" class="btn btn-primary">
                                <i class="fas fa-folder-open"></i> Examinar
                            </button>
                        </div>
                        <p class="helper-text">Ruta completa donde se guardarán los backups. Haga clic en "Examinar" para navegar.</p>
                    </div>
                    
                    <div class="form-group">
                        <label>Cantidad de Backups a Mantener</label>
                        <input type="number" name="backup_cantidad" value="<?php echo $backup_cantidad; ?>" min="1" max="50">
                        <p class="helper-text">Número máximo de backups a conservar. Los más antiguos se eliminarán automáticamente.</p>
                    </div>
                    
                    <button type="submit" name="guardar_config_backup" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Configuración
                    </button>
                </form>
            </div>
            
            <!-- Backup Manual -->
            <div class="backup-section">
                <h3><i class="fas fa-download"></i> Backup Manual</h3>
                <p style="color: #aaa; margin-bottom: 20px;">
                    Ejecuta un backup inmediato de la base de datos. El archivo se guardará en la ruta configurada.
                </p>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="ejecutar_backup" class="btn btn-success" 
                            onclick="return confirm('¿Desea ejecutar un backup manual ahora?')">
                        <i class="fas fa-play"></i> Ejecutar Backup Ahora
                    </button>
                </form>
            </div>
            
            <!-- Lista de Backups -->
            <div class="backup-section">
                <h3><i class="fas fa-list"></i> Backups Disponibles</h3>
                
                <?php if (count($backups) > 0): ?>
                    <table class="backups-table">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Fecha</th>
                                <th>Tamaño</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($backup['nombre']); ?></td>
                                    <td><?php echo $backup['fecha']; ?></td>
                                    <td><?php echo $backup['tamano']; ?></td>
                                    <td class="actions-cell">
                                        <a href="?descargar=<?php echo urlencode($backup['nombre']); ?>&ruta=<?php echo urlencode($ruta_backup); ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-download"></i> Descargar
                                        </a>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('¿Desea eliminar este backup?')">
                                            <input type="hidden" name="ruta_backup" value="<?php echo htmlspecialchars($ruta_backup); ?>">
                                            <button type="submit" name="eliminar_backup" value="<?php echo htmlspecialchars($backup['nombre']); ?>" 
                                                    class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i> Eliminar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No hay backups disponibles</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
    // Manejar descarga de backup
    if (isset($_GET['descargar']) && isset($_GET['ruta'])) {
        $archivo = basename($_GET['descargar']);
        $ruta = rtrim($_GET['ruta'], '/') . '/' . $archivo;
        
        if (file_exists($ruta)) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $archivo . '"');
            header('Content-Length: ' . filesize($ruta));
            readfile($ruta);
            exit();
        }
    }
    ?>
    
    <!-- Modal del Explorador de Archivos -->
    <div id="modalExplorador" style="display: none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100vh; background-color: rgba(0,0,0,0.7); overflow-y: auto;">
        <div style="background: #1e1e1e; border: 1px solid #333; border-radius: 12px; max-width: 800px; margin: 50px auto; padding: 25px; box-shadow: 0 20px 60px rgba(0,0,0,0.5);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #333;">
                <h2 style="color: #00bcd4; margin: 0; font-size: 1.3rem;">
                    <i class="fas fa-folder-open"></i> Seleccionar Carpeta de Backup
                </h2>
                <button onclick="cerrarExplorador()" style="background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; padding: 0; width: 35px; height: 35px; border-radius: 50%; transition: all 0.3s;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Navegación -->
            <div style="background: #252525; padding: 12px; border-radius: 8px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <button type="button" onclick="navegarAtras()" id="btnAtras" style="background: #00bcd4; border: none; color: #fff; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-arrow-up"></i> Subir
                </button>
                <div style="flex: 1; background: #1e1e1e; padding: 8px 12px; border-radius: 6px; color: #00bcd4; font-family: monospace; font-size: 0.9em; overflow-x: auto;" id="rutaActual">
                    C:\
                </div>
                <button type="button" onclick="mostrarFormularioNuevoDirectorio()" class="btn btn-success" style="padding: 8px 15px; font-size: 0.9em;">
                    <i class="fas fa-folder-plus"></i> Nuevo
                </button>
            </div>
            
            <!-- Formulario para crear nuevo directorio (oculto por defecto) -->
            <div id="formularioNuevoDirectorio" style="display: none; background: #252525; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #00bcd4;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" id="nombreNuevoDirectorio" placeholder="Nombre del nuevo directorio" 
                           style="flex: 1; padding: 10px 14px; border: 1px solid #444; background: #1e1e1e; color: #f0f0f0; border-radius: 6px; font-size: 0.95em; outline: none;"
                           onkeypress="if(event.key==='Enter') crearNuevoDirectorio()">
                    <button type="button" onclick="crearNuevoDirectorio()" class="btn btn-success">
                        <i class="fas fa-check"></i> Crear
                    </button>
                    <button type="button" onclick="ocultarFormularioNuevoDirectorio()" class="btn btn-danger">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </div>
            
            <!-- Lista de carpetas -->
            <div id="listaCarpetas" style="background: #252525; border: 1px solid #333; border-radius: 8px; padding: 10px; min-height: 300px; max-height: 400px; overflow-y: auto;">
                <div style="text-align: center; padding: 40px; color: #666;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px;"></i>
                    <p>Cargando directorio...</p>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div style="display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #333;">
                <button type="button" onclick="seleccionarCarpetaActual()" class="btn btn-success" style="flex: 1;">
                    <i class="fas fa-check"></i> Seleccionar Esta Carpeta
                </button>
                <button type="button" onclick="cerrarExplorador()" class="btn btn-danger" style="flex: 1;">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </div>
    </div>

    <script>
    let directorioActual = 'C:\\';
    let carpetaSeleccionada = null;

    function abrirExploradorArchivos() {
        const modal = document.getElementById('modalExplorador');
        modal.style.display = 'block';
        
        // Si hay una ruta en el input, usarla como punto de partida
        const rutaInput = document.getElementById('backup_ruta').value;
        if (rutaInput && rutaInput.trim() !== '') {
            directorioActual = rutaInput.trim();
        } else {
            directorioActual = 'C:\\';
        }
        
        console.log('Abriendo explorador en:', directorioActual);
        cargarDirectorio(directorioActual);
    }

    function cerrarExplorador() {
        document.getElementById('modalExplorador').style.display = 'none';
    }

    function cargarDirectorio(ruta) {
        directorioActual = ruta;
        document.getElementById('rutaActual').textContent = ruta;
        
        const lista = document.getElementById('listaCarpetas');
        lista.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px;"></i><p>Cargando...</p></div>';
        
        console.log('Cargando directorio:', ruta);
        
        const url = '<?php echo URL_BASE; ?>ajax/explorador_archivos_backup.php?dir=' + encodeURIComponent(ruta);
        console.log('Intentando cargar:', url);
        
        fetch(url)
            .then(response => {
                console.log('Respuesta recibida:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status + ' - ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Datos recibidos:', data);
                
                // Verificar si hay error o si no hay elementos
                if (data.error) {
                    lista.innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336;">' +
                        '<i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i>' +
                        '<p style="font-weight: bold; margin-bottom: 10px;">Error al cargar directorio</p>' +
                        '<p style="font-size: 0.9em;">' + data.error + '</p>' +
                        '<p style="font-size: 0.8em; color: #888; margin-top: 10px;">Ruta: ' + ruta + '</p>' +
                        '<button onclick="navegarAtras()" class="btn btn-primary" style="margin-top: 15px;">' +
                        '<i class="fas fa-arrow-up"></i> Volver Atrás</button></div>';
                    return;
                }
                
                if (data.elementos.length === 0) {
                    lista.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">' +
                        '<i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>' +
                        '<p>Carpeta vacía</p>' +
                        (data.puede_subir ? '<button onclick="navegarAtras()" class="btn btn-primary" style="margin-top: 15px;"><i class="fas fa-arrow-up"></i> Volver Atrás</button>' : '') +
                        '</div>';
                    return;
                }
                
                let html = '';
                data.elementos.forEach(elemento => {
                    const icono = elemento.es_directorio ? 'fa-folder' : 'fa-file';
                    const color = elemento.es_directorio ? '#00bcd4' : '#888';
                    
                    html += '<div onclick="' + (elemento.es_directorio ? 'entrarCarpeta(\'' + elemento.ruta + '\')' : '') + '" ' +
                            'style="display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 6px; cursor: ' + (elemento.es_directorio ? 'pointer' : 'default') + '; transition: all 0.2s; margin-bottom: 5px;" ' +
                            'onmouseover="this.style.background=\'rgba(0,188,212,0.1)\'" ' +
                            'onmouseout="this.style.background=\'transparent\'">' +
                            '<i class="fas ' + icono + '" style="font-size: 1.5rem; color: ' + color + '; width: 30px; text-align: center;"></i>' +
                            '<div style="flex: 1;">' +
                                '<div style="color: #f0f0f0; font-weight: 500;">' + elemento.nombre + '</div>' +
                                '<div style="color: #666; font-size: 0.85em;">' + (elemento.es_directorio ? 'Carpeta' : formatearTamano(elemento.tamano)) + '</div>' +
                            '</div>' +
                            (elemento.es_directorio ? '<i class="fas fa-chevron-right" style="color: #666;"></i>' : '') +
                        '</div>';
                });
                
                lista.innerHTML = html;
            })
            .catch(error => {
                console.error('Error completo:', error);
                lista.innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336;">' +
                    '<i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>' +
                    '<p style="font-weight: bold; margin-bottom: 10px;">Error al cargar directorio</p>' +
                    '<p style="font-size: 0.9em;">' + error.message + '</p>' +
                    '<p style="font-size: 0.8em; color: #888; margin-top: 10px;">Ruta: ' + ruta + '</p>' +
                    '<button onclick="navegarAtras()" class="btn btn-primary" style="margin-top: 15px;">' +
                    '<i class="fas fa-arrow-up"></i> Volver Atrás</button></div>';
            });
    }

    function navegarAtras() {
        if (directorioActual.length > 3) {
            const padre = directorioActual.substring(0, directorioActual.lastIndexOf('\\', directorioActual.length - 2) + 1);
            cargarDirectorio(padre);
        }
    }

    function entrarCarpeta(ruta) {
        cargarDirectorio(ruta);
    }

    function seleccionarCarpetaActual() {
        document.getElementById('backup_ruta').value = directorioActual;
        cerrarExplorador();
    }

    function is_dir(ruta) {
        // Validación simple de ruta
        return ruta && ruta.length > 2;
    }

    function formatearTamano(bytes) {
        if (bytes === null || bytes === undefined) return '-';
        if (bytes == 0) return '0 B';
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
    }

    // Cerrar modal con tecla Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cerrarExplorador();
        }
    });

    // Cerrar modal al hacer clic fuera
    window.onclick = function(event) {
        const modal = document.getElementById('modalExplorador');
        if (event.target === modal) {
            cerrarExplorador();
        }
    }
    
    // Funciones para crear nuevo directorio
    function mostrarFormularioNuevoDirectorio() {
        document.getElementById('formularioNuevoDirectorio').style.display = 'block';
        document.getElementById('nombreNuevoDirectorio').focus();
    }
    
    function ocultarFormularioNuevoDirectorio() {
        document.getElementById('formularioNuevoDirectorio').style.display = 'none';
        document.getElementById('nombreNuevoDirectorio').value = '';
    }
    
    function crearNuevoDirectorio() {
        const nombre = document.getElementById('nombreNuevoDirectorio').value.trim();
        
        if (!nombre) {
            alert('Ingrese un nombre para el directorio');
            return;
        }
        
        const url = '<?php echo URL_BASE; ?>ajax/explorador_archivos_backup.php?dir=' + encodeURIComponent(directorioActual) + '&crear_directorio=1&nombre=' + encodeURIComponent(nombre);
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                } else if (data.success) {
                    alert('✅ ' + data.mensaje);
                    ocultarFormularioNuevoDirectorio();
                    cargarDirectorio(directorioActual);
                }
            })
            .catch(error => {
                alert('Error al crear directorio: ' + error.message);
            });
    }
    </script>
</body>
</html>
