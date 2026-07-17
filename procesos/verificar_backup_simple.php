<?php
// Verificar backups generados - versión simple
$ruta = 'c:\\laragon\\www\\pos_prod\\backups\\';

echo "Verificando backups en: $ruta\n";

if (!is_dir($ruta)) {
    echo "❌ El directorio no existe\n";
    exit(1);
}

echo "✅ Directorio existe\n";

$archivos = glob($ruta . '*.sql');
echo "📊 Archivos .sql encontrados: " . count($archivos) . "\n\n";

if (count($archivos) > 0) {
    echo "Últimos 3 backups:\n";
    usort($archivos, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $ultimos = array_slice($archivos, 0, 3);
    foreach ($ultimos as $archivo) {
        echo "  - " . basename($archivo) . " (" . date('Y-m-d H:i:s', filemtime($archivo)) . ")\n";
    }
}
?>