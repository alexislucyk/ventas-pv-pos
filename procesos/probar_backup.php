<?php
// Script de prueba para verificar el funcionamiento del sistema de backup
require_once dirname(__FILE__) . '/../config/db_config.php';

echo "=========================================\n";
echo "PRUEBA DEL SISTEMA DE BACKUP\n";
echo "=========================================\n\n";

// 1. Verificar configuración
echo "1. Verificando configuración...\n";
$stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'backup_%'");
$configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (empty($configs)) {
    echo "❌ No se encontraron configuraciones de backup\n";
    exit(1);
}

echo "✅ Configuraciones encontradas:\n";
foreach ($configs as $clave => $valor) {
    echo "   - $clave: " . ($valor ?: '(vacío)') . "\n";
}

// 2. Verificar módulo
echo "\n2. Verificando módulo...\n";
$stmt = $pdo->query("SELECT * FROM modulos WHERE archivo = 'pages/backup.php'");
$modulo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($modulo) {
    echo "✅ Módulo encontrado: {$modulo['nombre']} (ID: {$modulo['id']})\n";
} else {
    echo "❌ Módulo no encontrado\n";
}

// 3. Verificar directorio de backups
echo "\n3. Verificando directorio de backups...\n";
$ruta_backup = !empty($configs['backup_ruta']) ? $configs['backup_ruta'] : PATH_BASE . 'backups/';

if (!is_dir($ruta_backup)) {
    echo "⚠️  Directorio no existe, creando: $ruta_backup\n";
    if (mkdir($ruta_backup, 0755, true)) {
        echo "✅ Directorio creado exitosamente\n";
    } else {
        echo "❌ No se pudo crear el directorio\n";
        exit(1);
    }
} else {
    echo "✅ Directorio existe: $ruta_backup\n";
}

if (!is_writable($ruta_backup)) {
    echo "❌ Directorio no tiene permisos de escritura\n";
    exit(1);
} else {
    echo "✅ Directorio tiene permisos de escritura\n";
}

// 4. Ejecutar backup de prueba
echo "\n4. Ejecutando backup de prueba...\n";

// Habilitar backup temporalmente
$stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('backup_habilitado', '1') 
                       ON DUPLICATE KEY UPDATE valor = '1'");
$stmt->execute();

// Capturar output del backup
ob_start();
include __DIR__ . '/backup_database.php';
$output = ob_get_clean();

// Restaurar configuración original
if (isset($configs['backup_habilitado']) && $configs['backup_habilitado'] === '0') {
    $stmt = $pdo->prepare("UPDATE configuracion SET valor = '0' WHERE clave = 'backup_habilitado'");
    $stmt->execute();
}

echo $output;

// 5. Verificar archivo creado
echo "\n5. Verificando archivo de backup...\n";
$archivos = glob($ruta_backup . "backup_{$db_name}_*.sql");

if (empty($archivos)) {
    echo "❌ No se encontraron archivos de backup\n";
    exit(1);
}

// Ordenar por fecha (más reciente primero)
usort($archivos, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$ultimo_backup = $archivos[0];
echo "✅ Backup más reciente: " . basename($ultimo_backup) . "\n";
echo "   - Tamaño: " . formatBytes(filesize($ultimo_backup)) . "\n";
echo "   - Fecha: " . date('Y-m-d H:i:s', filemtime($ultimo_backup)) . "\n";

// 6. Verificar contenido del backup
echo "\n6. Verificando contenido del backup...\n";
$contenido = file_get_contents($ultimo_backup);

$checks = [
    'CREATE TABLE' => substr_count($contenido, 'CREATE TABLE'),
    'INSERT INTO' => substr_count($contenido, 'INSERT INTO'),
    'DROP TABLE' => substr_count($contenido, 'DROP TABLE')
];

echo "✅ Contenido del backup:\n";
foreach ($checks as $tipo => $cantidad) {
    echo "   - $tipo: $cantidad\n";
}

// 7. Resumen final
echo "\n=========================================\n";
echo "✅ PRUEBA COMPLETADA EXITOSAMENTE\n";
echo "=========================================\n";
echo "\nEl sistema de backup está funcionando correctamente.\n";
echo "Puede acceder a la interfaz web en: " . URL_BASE . "pages/backup.php\n";

function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), $precision) . ' ' . $sizes[$i];
}
?>