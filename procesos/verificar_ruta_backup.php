<?php
// Verificar en qué ruta se está guardando el backup
require_once dirname(__FILE__) . '/../config/db_config.php';

echo "Configuración de rutas:\n";
echo "PATH_BASE: " . PATH_BASE . "\n";
echo "URL_BASE: " . URL_BASE . "\n";
echo "db_name: " . $db_name . "\n\n";

// Buscar backups en todas las ubicaciones posibles
$rutas = [
    'pos_dev' => 'c:\\laragon\\www\\pos_dev\\backups\\',
    'pos_prod' => 'c:\\laragon\\www\\pos_prod\\backups\\',
    'PATH_BASE' => PATH_BASE . 'backups/'
];

foreach ($rutas as $nombre => $ruta) {
    echo "Buscando en $nombre: $ruta\n";
    if (is_dir($ruta)) {
        $archivos = glob($ruta . '*.sql');
        echo "  ✅ Directorio existe\n";
        echo "  📊 Archivos .sql: " . count($archivos) . "\n";
        if (count($archivos) > 0) {
            usort($archivos, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            echo "  📄 Último: " . basename($archivos[0]) . "\n";
        }
    } else {
        echo "  ❌ Directorio no existe\n";
    }
    echo "\n";
}
?>