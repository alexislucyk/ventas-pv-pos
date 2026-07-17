<?php
// procesos/backup_database.php - Script de backup automático de base de datos
// Este script puede ejecutarse manualmente o mediante cron/tarea programada

// Incluir configuración
require_once dirname(__FILE__) . '/../config/db_config.php';

// Iniciar sesión si es necesario
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTA: No incluir infosesion.php porque envía headers HTTP
// Solo necesitamos la sesión y la configuración de BD

// Verificar que se ejecuta desde línea de comandos o con parámetro de seguridad
// También permitir ejecución desde la aplicación web (cuando hay sesión activa)
$es_cli = php_sapi_name() === 'cli';
$tiene_key = isset($_GET['key']) && $_GET['key'] === 'backup_security_key_2024';

// Verificar sesión (más permisivo para ejecución desde la app)
$tiene_sesion = isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);

if (!$es_cli && !$tiene_key && !$tiene_sesion) {
    // Si no hay acceso, mostrar mensaje y salir sin enviar headers
    echo "❌ Acceso denegado. Se requiere sesión activa o clave de seguridad.\n";
    exit(1);
}

// C) ENDURECER BACKUP: si se ejecuta vía web (no CLI, sin key), exigir permiso granular
if (!$es_cli && !$tiene_key) {
    include_once dirname(__FILE__) . '/../pages/infosesion.php';
    if (!tiene_permiso('pages/backup.php')) {
        echo "❌ Acceso denegado. No tiene permiso para generar backups.\n";
        exit(1);
    }
}

// NUNCA enviar headers - los backups solo se guardan en archivo
// Si se necesita descargar, se hace desde la interfaz web

// Obtener configuración de backup
function obtener_config_backup($pdo) {
    try {
        $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('backup_habilitado', 'backup_frecuencia', 'backup_ruta', 'backup_cantidad')");
        $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        return [
            'habilitado' => isset($configs['backup_habilitado']) ? $configs['backup_habilitado'] : '0',
            'frecuencia' => isset($configs['backup_frecuencia']) ? $configs['backup_frecuencia'] : 'diario',
            'ruta'       => isset($configs['backup_ruta']) ? $configs['backup_ruta'] : '',
            'cantidad'   => isset($configs['backup_cantidad']) ? (int)$configs['backup_cantidad'] : 7
        ];
    } catch (Exception $e) {
        return null;
    }
}

// Verificar si el backup está habilitado
$config = obtener_config_backup($pdo);
if (!$config || $config['habilitado'] !== '1') {
    if (php_sapi_name() !== 'cli') {
        die("Backup deshabilitado en configuración.");
    } else {
        echo "⚠️  Backup deshabilitado en configuración\n";
        exit(0); // Salir silenciosamente en CLI
    }
}

// Verificar si debe ejecutarse según la frecuencia
function debe_ejecutar_backup($frecuencia) {
    $ultimo_backup_file = PATH_BASE . 'cache/ultimo_backup.txt';
    
    // Si no existe archivo de control, ejecutar
    if (!file_exists($ultimo_backup_file)) {
        echo "📋 No hay registro de backup anterior, ejecutando...\n";
        return true;
    }
    
    $ultima_fecha = (int)file_get_contents($ultimo_backup_file);
    $ahora = time();
    $diferencia = $ahora - $ultima_fecha;
    
    echo "📊 Último backup hace: " . round($diferencia / 3600, 1) . " horas\n";
    echo "📊 Frecuencia configurada: $frecuencia\n";
    
    switch ($frecuencia) {
        case 'diario':
            $resultado = $diferencia >= 86400; // 24 horas
            echo ($resultado ? "✅" : "⏳") . " Backup diario: " . ($resultado ? "Necesario" : "No necesario") . "\n";
            return $resultado;
        case 'semanal':
            $resultado = $diferencia >= 604800; // 7 días
            echo ($resultado ? "✅" : "⏳") . " Backup semanal: " . ($resultado ? "Necesario" : "No necesario") . "\n";
            return $resultado;
        case 'mensual':
            $resultado = $diferencia >= 2592000; // 30 días
            echo ($resultado ? "✅" : "⏳") . " Backup mensual: " . ($resultado ? "Necesario" : "No necesario") . "\n";
            return $resultado;
        default:
            echo "✅ Frecuencia desconocida, ejecutando...\n";
            return true;
    }
}

// Si no debe ejecutarse, salir
if (!debe_ejecutar_backup($config['frecuencia'])) {
    if (php_sapi_name() !== 'cli') {
        die("Backup no necesario según frecuencia configurada.");
    } else {
        exit(0);
    }
}

// Validar ruta de backup
$ruta_backup = $config['ruta'];
if (empty($ruta_backup)) {
    $ruta_backup = PATH_BASE . 'backups/';
}

// Normalizar ruta (convertir / a \ en Windows)
$ruta_backup = str_replace('/', '\\', $ruta_backup);
$ruta_backup = rtrim($ruta_backup, '\\') . '\\';

echo "📁 Ruta de backup: $ruta_backup\n";

// Crear directorio si no existe
if (!is_dir($ruta_backup)) {
    echo "📁 Directorio no existe, creando...\n";
    if (!mkdir($ruta_backup, 0755, true)) {
        die("❌ Error: No se pudo crear el directorio de backups: $ruta_backup");
    }
    echo "✅ Directorio creado\n";
}

// Verificar que la ruta sea escribible
if (!is_writable($ruta_backup)) {
    die("❌ Error: El directorio de backups no tiene permisos de escritura: $ruta_backup");
}

echo "✅ Directorio accesible y escribible\n";

// Nombre del archivo de backup
$fecha_actual = date('Y-m-d_H-i-s');
$nombre_archivo = "backup_{$db_name}_{$fecha_actual}.sql";
$ruta_completa = $ruta_backup . $nombre_archivo;

// Función para escapar comillas en strings
function escapar_string($str) {
    return str_replace("'", "''", $str);
}

// Iniciar output de SQL
$output = "-- ========================================\n";
$output .= "-- Backup de Base de Datos: {$db_name}\n";
$output .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
$output .= "-- Generado por: Sistema POS\n";
$output .= "-- ========================================\n\n";
$output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$output .= "SET time_zone = '+00:00';\n\n";
$output .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
$output .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
$output .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
$output .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

try {
    // Obtener todas las tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tablas as $tabla) {
        // Obtener estructura de la tabla
        $stmt = $pdo->query("SHOW CREATE TABLE `$tabla`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $create_table = $row['Create Table'];
        
        $output .= "-- ========================================\n";
        $output .= "-- Estructura de tabla: `$tabla`\n";
        $output .= "-- ========================================\n\n";
        $output .= "DROP TABLE IF EXISTS `$tabla`;\n";
        $output .= $create_table . ";\n\n";
        
        // Obtener datos de la tabla
        $stmt = $pdo->query("SELECT * FROM `$tabla`");
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($filas) > 0) {
            $output .= "-- ========================================\n";
            $output .= "-- Datos de tabla: `$tabla`\n";
            $output .= "-- ========================================\n\n";
            
            foreach ($filas as $fila) {
                $valores = array_map(function($valor) {
                    if (is_null($valor)) {
                        return 'NULL';
                    }
                    return "'" . escapar_string($valor) . "'";
                }, $fila);
                
                $columnas = implode('`, `', array_keys($fila));
                $output .= "INSERT INTO `$tabla` (`$columnas`) VALUES (" . implode(', ', $valores) . ");\n";
            }
            $output .= "\n";
        }
    }
    
    $output .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
    $output .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
    $output .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
    
    // Guardar archivo
    if (file_put_contents($ruta_completa, $output)) {
        // Actualizar fecha de último backup
        file_put_contents(PATH_BASE . 'cache/ultimo_backup.txt', time());
        
        // Limpiar backups antiguos
        limpiar_backups_antiguos($ruta_backup, $config['cantidad']);
        
        // Log de éxito
        $mensaje = "✅ Backup exitoso: {$nombre_archivo}";
        error_log("BACKUP: " . $mensaje);
        
        if (php_sapi_name() !== 'cli') {
            echo $output;
        } else {
            echo $mensaje . "\n";
        }
    } else {
        throw new Exception("No se pudo escribir el archivo de backup");
    }
    
} catch (Exception $e) {
    $error = "❌ Error en backup: " . $e->getMessage();
    error_log("BACKUP ERROR: " . $error);
    
    if (php_sapi_name() !== 'cli') {
        die($error);
    } else {
        echo $error . "\n";
    }
}

// Función para limpiar backups antiguos
function limpiar_backups_antiguos($ruta, $cantidad_a_mantener) {
    $archivos = glob($ruta . "backup_{$GLOBALS['db_name']}_*.sql");
    
    if (count($archivos) > $cantidad_a_mantener) {
        // Ordenar por fecha de modificación (más antiguo primero)
        usort($archivos, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Eliminar archivos más antiguos
        $cantidad_eliminar = count($archivos) - $cantidad_a_mantener;
        for ($i = 0; $i < $cantidad_eliminar; $i++) {
            if (file_exists($archivos[$i])) {
                unlink($archivos[$i]);
                error_log("BACKUP: Eliminado backup antiguo: " . basename($archivos[$i]));
            }
        }
    }
}
?>