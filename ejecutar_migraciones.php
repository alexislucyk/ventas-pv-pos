<?php
/**
 * Script para ejecutar migraciones de cierre de caja
 * Fecha: 08/08/2026
 * 
 * Ejecuta las migraciones 26 y 27 necesarias para el sistema de cierre de caja
 */

require_once 'config/db_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ejecutar Migraciones</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #1e1e1e; color: #fff; }
        .result { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .ok { background: #28a745; }
        .error { background: #dc3545; }
        .info { background: #17a2b8; }
        h1 { color: #00bcd4; }
        code { background: #2c3e50; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔧 Ejecutar Migraciones de Cierre de Caja</h1>
    
    <?php
    echo "<h2>Migración 26: Agregar rango de fechas a cierres_caja</h2>";
    
    try {
        // Verificar si la columna fecha_desde existe
        $sql_check = "SHOW COLUMNS FROM cierres_caja LIKE 'fecha_desde'";
        $stmt_check = $pdo->query($sql_check);
        
        if ($stmt_check->fetch()) {
            echo '<div class="result info">ℹ️ La columna <code>fecha_desde</code> ya existe. No es necesario ejecutar la migración 26.</div>';
        } else {
            // Ejecutar migración 26
            $sql_mig26 = "
            ALTER TABLE cierres_caja 
            ADD COLUMN fecha_desde DATETIME NULL AFTER sucursal_id,
            ADD COLUMN fecha_hasta DATETIME NULL AFTER fecha_desde;
            
            UPDATE cierres_caja 
            SET fecha_desde = DATE(fecha_cierre),
                fecha_hasta = DATE(fecha_cierre) + INTERVAL 1 DAY - INTERVAL 1 SECOND
            WHERE fecha_desde IS NULL;
            
            ALTER TABLE cierres_caja 
            MODIFY COLUMN fecha_desde DATETIME NOT NULL,
            MODIFY COLUMN fecha_hasta DATETIME NOT NULL;
            
            ALTER TABLE cierres_caja 
            ADD INDEX idx_fecha_rango (empresa_id, sucursal_id, fecha_desde, fecha_hasta);
            
            ALTER TABLE cierres_caja 
            DROP COLUMN tipo_cierre;
            ";
            
            $pdo->exec($sql_mig26);
            echo '<div class="result ok">✅ Migración 26 ejecutada correctamente.</div>';
            echo '<div class="result info">📝 Se agregaron las columnas <code>fecha_desde</code> y <code>fecha_hasta</code> a <code>cierres_caja</code>.</div>';
        }
    } catch (Exception $e) {
        echo '<div class="result error">❌ Error en migración 26: ' . $e->getMessage() . '</div>';
    }
    
    echo "<h2>Migración 27: Agregar métodos de pago adicionales</h2>";
    
    try {
        // Verificar si la columna ingresos_cheques existe
        $sql_check = "SHOW COLUMNS FROM cierres_caja LIKE 'ingresos_cheques'";
        $stmt_check = $pdo->query($sql_check);
        
        if ($stmt_check->fetch()) {
            echo '<div class="result info">ℹ️ La columna <code>ingresos_cheques</code> ya existe. No es necesario ejecutar la migración 27.</div>';
        } else {
            // Ejecutar migración 27
            $sql_mig27 = "
            ALTER TABLE cierres_caja 
            ADD COLUMN ingresos_cheques DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_transf,
            ADD COLUMN ingresos_tarjetas DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_cheques,
            ADD COLUMN ingresos_otros DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_tarjetas;
            
            UPDATE cierres_caja 
            SET ingresos_cheques = 0,
                ingresos_tarjetas = 0,
                ingresos_otros = 0
            WHERE ingresos_cheques IS NULL;
            ";
            
            $pdo->exec($sql_mig27);
            echo '<div class="result ok">✅ Migración 27 ejecutada correctamente.</div>';
            echo '<div class="result info">📝 Se agregaron las columnas <code>ingresos_cheques</code>, <code>ingresos_tarjetas</code> y <code>ingresos_otros</code> a <code>cierres_caja</code>.</div>';
        }
    } catch (Exception $e) {
        echo '<div class="result error">❌ Error en migración 27: ' . $e->getMessage() . '</div>';
    }
    
    echo "<h2>Verificación Final</h2>";
    
    try {
        // Verificar estructura final
        $sql_verify = "DESCRIBE cierres_caja";
        $stmt = $pdo->query($sql_verify);
        $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $columnas_requeridas = [
            'fecha_desde', 'fecha_hasta', 'numero_cierre',
            'ingresos_cheques', 'ingresos_tarjetas', 'ingresos_otros'
        ];
        
        $columnas_existentes = array_column($columnas, 'Field');
        $faltantes = array_diff($columnas_requeridas, $columnas_existentes);
        
        if (empty($faltantes)) {
            echo '<div class="result ok">✅ Todas las columnas requeridas existen en <code>cierres_caja</code>.</div>';
            echo '<div class="result info">📋 Columnas encontradas: <code>' . implode(', ', $columnas_existentes) . '</code></div>';
        } else {
            echo '<div class="result error">❌ Faltan columnas: ' . implode(', ', $faltantes) . '</div>';
        }
    } catch (Exception $e) {
        echo '<div class="result error">❌ Error al verificar: ' . $e->getMessage() . '</div>';
    }
    
    echo '<div class="result info" style="margin-top: 30px;">';
    echo '<strong>📋 Próximos pasos:</strong><br>';
    echo '1. Ahora puedes ir a <a href="pages/cierre_caja.php" style="color: #ffc107;">pages/cierre_caja.php</a><br>';
    echo '2. El sistema debería funcionar correctamente<br>';
    echo '3. Si hay problemas, revisa el mensaje de error arriba';
    echo '</div>';
    ?>
</body>
</html>
<?php
$pdo = null;
?>