<?php
// Ejecutar migración 32 - Módulo de Actualizaciones (GitHub)
require_once dirname(__FILE__) . '/../config/db_config.php';

try {
    echo "Ejecutando migración 32: Módulo de Actualizaciones\n";
    echo "==================================================\n\n";

    $sql_file = dirname(__FILE__) . '/../migrations/32_actualizaciones.sql';
    $sql = file_get_contents($sql_file);

    if (!$sql) {
        throw new Exception("No se pudo leer el archivo de migración");
    }

    $pdo->exec($sql);

    echo "✅ Migración ejecutada exitosamente\n\n";

    // Verificar módulo creado
    $stmt = $pdo->query("SELECT * FROM modulos WHERE archivo = 'pages/actualizaciones.php'");
    $modulo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($modulo) {
        echo "✅ Módulo de actualizaciones creado:\n";
        echo "  - ID: {$modulo['id']}\n";
        echo "  - Nombre: {$modulo['nombre']}\n";
        echo "  - Archivo: {$modulo['archivo']}\n";
        echo "  (El acceso se controla por rol 'developer' en la sesión)\n";
    } else {
        echo "⚠️  Módulo de actualizaciones no encontrado\n";
    }

    // Verificar configuración del repo
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'actualizar_%'");
    $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "\nConfiguración de actualizaciones:\n";
    foreach ($config as $clave => $valor) {
        echo "  - $clave: $valor\n";
    }

    echo "\n=========================================\n";
    echo "Migración completada exitosamente\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}