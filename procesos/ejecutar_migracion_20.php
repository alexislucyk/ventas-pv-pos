<?php
// Ejecutar migración 20 - Sistema de Backup
require_once dirname(__FILE__) . '/../config/db_config.php';

try {
    echo "Ejecutando migración 20: Sistema de Backup\n";
    echo "=========================================\n\n";
    
    // Leer archivo SQL
    $sql_file = dirname(__FILE__) . '/../migrations/20_backup_system.sql';
    $sql = file_get_contents($sql_file);
    
    if (!$sql) {
        throw new Exception("No se pudo leer el archivo de migración");
    }
    
    // Ejecutar consultas
    $pdo->exec($sql);
    
    echo "✅ Migración ejecutada exitosamente\n\n";
    
    // Verificar que se crearon las configuraciones
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'backup_%'");
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "Configuraciones de backup creadas:\n";
    foreach ($configs as $clave => $valor) {
        echo "  - $clave: $valor\n";
    }
    
    // Verificar que se creó el módulo
    $stmt = $pdo->query("SELECT * FROM modulos WHERE archivo = 'pages/backup.php'");
    $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($modulo) {
        echo "\n✅ Módulo de backup creado:\n";
        echo "  - ID: {$modulo['id']}\n";
        echo "  - Nombre: {$modulo['nombre']}\n";
        echo "  - Archivo: {$modulo['archivo']}\n";
    } else {
        echo "\n⚠️  Módulo de backup no encontrado\n";
    }
    
    echo "\n=========================================\n";
    echo "Migración completada exitosamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>