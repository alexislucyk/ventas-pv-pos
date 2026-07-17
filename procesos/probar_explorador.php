<?php
// Script de prueba para el explorador de archivos
echo "Probando Explorador de Archivos para Backup\n";
echo "==========================================\n\n";

// Simular sesión de usuario
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_rol'] = 'developer';

// Simular llamada al explorador
$_GET['dir'] = 'C:\\';
$_GET['accion'] = 'listar';

// Capturar output (suprimiendo warnings)
ob_start();
@include __DIR__ . '/../ajax/explorador_archivos_backup.php';
$output = ob_get_clean();

$data = json_decode($output, true);

if ($data && !isset($data['error'])) {
    echo "✅ Explorador funcionando correctamente\n\n";
    echo "Directorio actual: {$data['directorio_actual']}\n";
    echo "Puede subir: " . ($data['puede_subir'] ? 'Sí' : 'No') . "\n";
    echo "Cantidad de directorios: {$data['cantidad_directorios']}\n";
    echo "Cantidad de archivos: {$data['cantidad_archivos']}\n\n";
    
    echo "Primeros 10 elementos:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-30s %-15s %-20s\n", "Nombre", "Tipo", "Fecha Modificación");
    echo str_repeat("-", 80) . "\n";
    
    $elementos = array_slice($data['elementos'], 0, 10);
    foreach ($elementos as $elemento) {
        $tipo = $elemento['es_directorio'] ? '[DIR]' : '[FILE]';
        printf("%-30s %-15s %-20s\n", 
            substr($elemento['nombre'], 0, 28), 
            $tipo, 
            $elemento['fecha_modificacion']
        );
    }
    
    echo "\n✅ Prueba del explorador completada exitosamente\n";
} else {
    echo "❌ Error en el explorador:\n";
    echo $output . "\n";
    exit(1);
}
?>