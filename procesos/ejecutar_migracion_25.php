<?php
// procesos/ejecutar_migracion_25.php
// Migración: Tabla de Proveedores Autorizados por Usuario
// Fecha: 2026-06-30

require_once '../config/db_config.php';

echo "<h1>Migración 2.5.0 - Proveedores Autorizados por Usuario</h1>\n";
echo "<pre>\n";

try {
    $pdo->beginTransaction();
    
    // ============================================
    // PASO 1: Crear tabla proveedores_autorizados_usuario
    // ============================================
    echo "1. Creando tabla proveedores_autorizados_usuario...\n";
    try {
        $sql = "CREATE TABLE IF NOT EXISTS `proveedores_autorizados_usuario` (
          `id` INT PRIMARY KEY AUTO_INCREMENT,
          `usuario_id` INT NOT NULL,
          `proveedor_nombre` VARCHAR(255) NOT NULL,
          `empresa_id` INT NOT NULL DEFAULT 1,
          `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE,
          UNIQUE KEY `unique_proveedor_usuario` (`usuario_id`, `proveedor_nombre`, `empresa_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo "   ✓ Tabla proveedores_autorizados_usuario creada\n";
    } catch (Exception $e) {
        echo "   ⚠ Tabla ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // COMMIT FINAL
    // ============================================
    $pdo->commit();
    
    echo "\n";
    echo "============================================\n";
    echo "✅ MIGRACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "============================================\n";
    echo "\n";
    echo "Próximos pasos:\n";
    echo "1. Acceder a: Proveedores Autorizados por Usuario (menú Administración)\n";
    echo "2. Seleccionar un usuario\n";
    echo "3. Autorizar los proveedores que puede consultar\n";
    echo "4. El usuario remoto podrá ver solo los proveedores autorizados\n";
    echo "\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n";
    echo "============================================\n";
    echo "❌ ERROR EN LA MIGRACIÓN\n";
    echo "============================================\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Se realizó rollback de todos los cambios.\n";
    echo "\n";
}

echo "</pre>\n";
echo "<a href='../index.php' class='btn'>Volver al Inicio</a>\n";
?>