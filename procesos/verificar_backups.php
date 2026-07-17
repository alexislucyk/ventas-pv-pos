<?php
// Verificar backups generados
$ruta = 'c:\\laragon\\www\\pos_dev\\backups\\';
$archivos = glob($ruta . '*.sql');

echo "Backups encontrados en: $ruta\n";
echo str_repeat("=", 80) . "\n\n";

if (empty($archivos)) {
    echo "No se encontraron archivos de backup.\n";
    exit(0);
}

// Ordenar por fecha (más reciente primero)
usort($archivos, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

echo "Total de backups: " . count($archivos) . "\n\n";

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);
    $tamano = filesize($archivo);
    $fecha = date('Y-m-d H:i:s', filemtime($archivo));
    
    echo "📄 $nombre\n";
    echo "   Tamaño: " . number_format($tamano / 1024 / 1024, 2) . " MB\n";
    echo "   Fecha: $fecha\n\n";
}
?>