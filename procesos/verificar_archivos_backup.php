<?php
// Script de verificación de backups
require_once dirname(__FILE__) . '/../config/db_config.php';

echo "=== VERIFICACIÓN DE BACKUPS ===\n\n";

// Obtener configuración
$stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('backup_habilitado', 'backup_ruta', 'backup_cantidad')");
$config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$backup_ruta = isset($config['backup_ruta']) ? $config['backup_ruta'] : '';
$ruta_backup = !empty($backup_ruta) ? $backup_ruta : PATH_BASE . 'backups/';

echo "📋 Configuración:\n";
echo "  - Ruta configurada: " . ($backup_ruta ?: '(vacía, usa predeterminada)') . "\n";
echo "  - Ruta a usar: $ruta_backup\n";
echo "  - DB_NAME: $db_name\n";
echo "  - PATH_BASE: " . PATH_BASE . "\n";
echo "  - URL_BASE: " . URL_BASE . "\n\n";

// Convertir ruta URL a ruta de filesystem
if (strpos($ruta_backup, URL_BASE) === 0) {
    $ruta_backup_absoluta = PATH_BASE . substr($ruta_backup, strlen(URL_BASE));
} elseif (strpos($ruta_backup, '/') === 0 && strpos($ruta_backup, '/' . $db_name) !== false) {
    $ruta_backup_absoluta = str_replace('/', '\\', $_SERVER['DOCUMENT_ROOT'] . $ruta_backup);
} elseif (strpos($ruta_backup, '/') === 0 || strpos($ruta_backup, '\\') === 0) {
    $ruta_backup_absoluta = $_SERVER['DOCUMENT_ROOT'] . $ruta_backup;
} else {
    $ruta_backup_absoluta = $ruta_backup;
}

$ruta_backup_glob = str_replace('/', '\\', $ruta_backup_absoluta);
$ruta_backup_glob = rtrim($ruta_backup_glob, '\\') . '\\';

echo "📁 Rutas convertidas:\n";
echo "  - Ruta absoluta: $ruta_backup_absoluta\n";
echo "  - Ruta glob: $ruta_backup_glob\n\n";

// Verificar si el directorio existe
if (!is_dir($ruta_backup_absoluta)) {
    echo "❌ ERROR: El directorio NO existe: $ruta_backup_absoluta\n";
    
    // Intentar crear el directorio
    if (mkdir($ruta_backup_absoluta, 0755, true)) {
        echo "✅ Directorio creado exitosamente\n";
    } else {
        echo "❌ No se pudo crear el directorio\n";
    }
} else {
    echo "✅ Directorio existe: $ruta_backup_absoluta\n";
    
    // Verificar permisos
    if (is_writable($ruta_backup_absoluta)) {
        echo "✅ Directorio tiene permisos de escritura\n";
    } else {
        echo "❌ ERROR: Directorio NO tiene permisos de escritura\n";
    }
}

echo "\n🔍 Buscando archivos de backup...\n";
$patron = $ruta_backup_glob . "backup_{$db_name}_*.sql";
echo "  - Patrón de búsqueda: $patron\n";

$archivos = glob($patron);

if (empty($archivos)) {
    echo "⚠️  No se encontraron archivos con el patrón principal\n";
    
    // Intentar con ruta alternativa
    if (strpos($ruta_backup, '/') === 0) {
        $ruta_alt = str_replace('/', '\\', $ruta_backup);
        $patron_alt = $ruta_alt . "backup_{$db_name}_*.sql";
        echo "  - Intentando con ruta alternativa: $patron_alt\n";
        $archivos = glob($patron_alt);
    }
}

if (empty($archivos)) {
    echo "❌ No se encontraron backups en ninguna ruta\n";
} else {
    echo "✅ Se encontraron " . count($archivos) . " backup(s):\n\n";
    
    usort($archivos, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    foreach ($archivos as $i => $archivo) {
        echo ($i + 1) . ". " . basename($archivo) . "\n";
        echo "   - Ruta completa: $archivo\n";
        echo "   - Tamaño: " . formatBytes(filesize($archivo)) . "\n";
        echo "   - Fecha: " . date('Y-m-d H:i:s', filemtime($archivo)) . "\n";
        echo "   - Existe: " . (file_exists($archivo) ? 'Sí' : 'NO') . "\n";
        echo "   - Legible: " . (is_readable($archivo) ? 'Sí' : 'NO') . "\n\n";
    }
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), $precision) . ' ' . $sizes[$i];
}

echo "\n=== FIN DE VERIFICACIÓN ===\n";
?>