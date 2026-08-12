<?php
// procesos/ejecutar_migracion_21.php
// Migración: Sistema de Estados de Caja v2.1.0
// Fecha: 08/03/2026

require_once '../config/db_config.php';

echo "<h1>Migración 2.1.0 - Sistema de Estados de Caja</h1>\n";
echo "<pre>\n";

try {
    $pdo->beginTransaction();
    
    // ============================================
    // PASO 1: Agregar campo es_fondo_inicial a movimientos
    // ============================================
    echo "1. Agregando campo es_fondo_inicial a tabla movimientos...\n";
    try {
        $sql = "ALTER TABLE `movimientos` 
                ADD COLUMN `es_fondo_inicial` TINYINT(1) DEFAULT 0 
                COMMENT '1=Es fondo inicial de caja' 
                AFTER `cerrado`";
        $pdo->exec($sql);
        echo "   ✓ Campo es_fondo_inicial agregado\n";
    } catch (Exception $e) {
        echo "   ⚠ Campo es_fondo_inicial ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 2: Agregar campos a cierres_caja
    // ============================================
    echo "\n2. Agregando campos a tabla cierres_caja...\n";
    
    // 2.1 tipo_cierre
    try {
        $sql = "ALTER TABLE `cierres_caja` 
                ADD COLUMN `tipo_cierre` ENUM('DIARIO','PARCIAL') DEFAULT 'DIARIO' 
                COMMENT 'Tipo de cierre realizado' 
                AFTER `fondo_reservado_vuelto`";
        $pdo->exec($sql);
        echo "   ✓ Campo tipo_cierre agregado\n";
    } catch (Exception $e) {
        echo "   ⚠ Campo tipo_cierre ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // 2.2 numero_cierre
    try {
        $sql = "ALTER TABLE `cierres_caja` 
                ADD COLUMN `numero_cierre` INT DEFAULT NULL 
                COMMENT 'Número secuencial de cierre por sucursal' 
                AFTER `tipo_cierre`";
        $pdo->exec($sql);
        echo "   ✓ Campo numero_cierre agregado\n";
    } catch (Exception $e) {
        echo "   ⚠ Campo numero_cierre ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 3: Crear tabla estado_caja
    // ============================================
    echo "\n3. Creando tabla estado_caja...\n";
    try {
        $sql = "CREATE TABLE `estado_caja` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `empresa_id` INT NOT NULL,
          `sucursal_id` INT NOT NULL,
          `fecha` DATE NOT NULL,
          `estado` ENUM('ABIERTA','CERRADA') DEFAULT 'CERRADA',
          `saldo_inicial` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Fondo con el que se abrió la caja',
          `usuario_apertura` VARCHAR(50) DEFAULT NULL,
          `fecha_apertura` DATETIME DEFAULT NULL,
          `usuario_cierre` VARCHAR(50) DEFAULT NULL,
          `fecha_cierre` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_caja_dia` (`empresa_id`, `sucursal_id`, `fecha`),
          FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";
        
        $pdo->exec($sql);
        echo "   ✓ Tabla estado_caja creada\n";
    } catch (Exception $e) {
        echo "   ⚠ Tabla estado_caja ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 4: Crear tabla cierres_caja_audit
    // ============================================
    echo "\n4. Creando tabla cierres_caja_audit...\n";
    try {
        $sql = "CREATE TABLE `cierres_caja_audit` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `cierre_id` INT NOT NULL,
          `accion` ENUM('CREADO','MODIFICADO','ANULADO') NOT NULL,
          `usuario` VARCHAR(50) NOT NULL,
          `fecha_accion` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `datos_anteriores` JSON DEFAULT NULL,
          `datos_nuevos` JSON DEFAULT NULL,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          PRIMARY KEY (`id`),
          FOREIGN KEY (`cierre_id`) REFERENCES `cierres_caja`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3";
        
        $pdo->exec($sql);
        echo "   ✓ Tabla cierres_caja_audit creada\n";
    } catch (Exception $e) {
        echo "   ⚠ Tabla cierres_caja_audit ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 5: Agregar índices compuestos
    // ============================================
    echo "\n5. Agregando índices compuestos...\n";
    
    // 5.1 Índice en movimientos
    try {
        $sql = "ALTER TABLE `movimientos` 
                ADD INDEX `idx_movimientos_empresa_sucursal_cerrado` (`empresa_id`, `sucursal_id`, `cerrado`)";
        $pdo->exec($sql);
        echo "   ✓ Índice idx_movimientos_empresa_sucursal_cerrado creado\n";
    } catch (Exception $e) {
        echo "   ⚠ Índice en movimientos ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // 5.2 Índice en cierres_caja
    try {
        $sql = "ALTER TABLE `cierres_caja` 
                ADD INDEX `idx_cierres_empresa_sucursal_fecha` (`empresa_id`, `sucursal_id`, `fecha_cierre`)";
        $pdo->exec($sql);
        echo "   ✓ Índice idx_cierres_empresa_sucursal_fecha creado\n";
    } catch (Exception $e) {
        echo "   ⚠ Índice en cierres_caja ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 6: Poblar tabla estado_caja con registros históricos
    // ============================================
    echo "\n6. Poblando tabla estado_caja con datos históricos...\n";
    try {
        $sql = "INSERT INTO `estado_caja` (`empresa_id`, `sucursal_id`, `fecha`, `estado`, `fecha_cierre`, `usuario_cierre`)
                SELECT 
                  DISTINCT c.empresa_id, 
                  c.sucursal_id, 
                  DATE(c.fecha_cierre) as fecha,
                  'CERRADA' as estado,
                  c.fecha_cierre,
                  c.usuario
                FROM `cierres_caja` c
                WHERE NOT EXISTS (
                  SELECT 1 FROM `estado_caja` ec 
                  WHERE ec.empresa_id = c.empresa_id 
                    AND ec.sucursal_id = c.sucursal_id 
                    AND ec.fecha = DATE(c.fecha_cierre)
                )";
        
        $pdo->exec($sql);
        $count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "   ✓ $count registros históricos insertados en estado_caja\n";
    } catch (Exception $e) {
        echo "   ⚠ Error al poblar estado_caja: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 7: Actualizar movimientos de fondo inicial
    // ============================================
    echo "\n7. Actualizando movimientos de fondo inicial...\n";
    try {
        $sql = "UPDATE `movimientos` 
                SET `es_fondo_inicial` = 1 
                WHERE `detalle` LIKE 'FONDO INICIAL%'";
        $pdo->exec($sql);
        $count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "   ✓ $count movimientos marcados como fondo inicial\n";
    } catch (Exception $e) {
        echo "   ⚠ Error al actualizar movimientos: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 8: Actualizar saldo_inicial en cierres existentes
    // ============================================
    echo "\n8. Actualizando saldo_inicial en cierres existentes...\n";
    try {
        $sql = "UPDATE `cierres_caja` c
                SET `saldo_inicial` = (
                  SELECT COALESCE(SUM(m.monto), 0)
                  FROM `movimientos` m
                  WHERE m.empresa_id = c.empresa_id
                    AND m.sucursal_id = c.sucursal_id
                    AND m.es_fondo_inicial = 1
                    AND DATE(m.fecha) = DATE(c.fecha_cierre)
                    AND m.cerrado = 1
                )
                WHERE c.saldo_inicial IS NULL";
        $pdo->exec($sql);
        $count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "   ✓ $count cierres actualizados con saldo_inicial\n";
    } catch (Exception $e) {
        echo "   ⚠ Error al actualizar saldo_inicial: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 9: Crear función obtener_numero_cierre
    // ============================================
    echo "\n9. Creando función obtener_numero_cierre...\n";
    try {
        $sql = "DROP FUNCTION IF EXISTS obtener_numero_cierre";
        $pdo->exec($sql);
        
        $sql = "CREATE FUNCTION `obtener_numero_cierre`(
                  p_empresa_id INT,
                  p_sucursal_id INT
                ) RETURNS INT
                DETERMINISTIC
                BEGIN
                  DECLARE v_numero INT;
                  SELECT COALESCE(MAX(numero_cierre), 0) + 1 
                  INTO v_numero
                  FROM `cierres_caja`
                  WHERE empresa_id = p_empresa_id 
                    AND sucursal_id = p_sucursal_id;
                  RETURN v_numero;
                END";
        
        $pdo->exec($sql);
        echo "   ✓ Función obtener_numero_cierre creada\n";
    } catch (Exception $e) {
        echo "   ⚠ Error al crear función: " . $e->getMessage() . "\n";
    }
    
    // ============================================
    // PASO 10: Actualizar números de cierre existentes
    // ============================================
    echo "\n10. Actualizando números de cierre existentes...\n";
    try {
        // Obtener todos los cierres sin número
        $sql = "SELECT id, empresa_id, sucursal_id FROM cierres_caja 
                WHERE numero_cierre IS NULL 
                ORDER BY fecha_cierre ASC";
        $stmt = $pdo->query($sql);
        $cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($cierres as $cierre) {
            // Obtener el siguiente número para esta empresa/sucursal
            $sql_num = "SELECT COALESCE(MAX(numero_cierre), 0) + 1 as num 
                        FROM cierres_caja 
                        WHERE empresa_id = :empresa_id 
                          AND sucursal_id = :sucursal_id
                          AND id <= :id";
            $stmt_num = $pdo->prepare($sql_num);
            $stmt_num->execute([
                ':empresa_id' => $cierre['empresa_id'],
                ':sucursal_id' => $cierre['sucursal_id'],
                ':id' => $cierre['id']
            ]);
            $nuevo_numero = $stmt_num->fetchColumn();
            
            // Actualizar
            $sql_update = "UPDATE cierres_caja SET numero_cierre = :num WHERE id = :id";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([':num' => $nuevo_numero, ':id' => $cierre['id']]);
            $count++;
        }
        
        echo "   ✓ $count cierres actualizados con número secuencial\n";
    } catch (Exception $e) {
        echo "   ⚠ Error al actualizar números: " . $e->getMessage() . "\n";
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
    echo "1. Verificar que las tablas se crearon correctamente\n";
    echo "2. Probar la apertura de caja\n";
    echo "3. Probar el cierre de caja\n";
    echo "4. Verificar el reporte de cierres\n";
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