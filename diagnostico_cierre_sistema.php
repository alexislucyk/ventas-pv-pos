<?php
/**
 * Script de diagnóstico y verificación del sistema de cierre de caja
 * Fecha: 08/08/2026
 * 
 * Este script verifica:
 * 1. Estructura de base de datos
 * 2. Consistencia de datos
 * 3. Funcionalidad de las funciones principales
 */

require_once 'config/db_config.php';
require_once 'funciones/funciones_caja.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Diagnóstico Sistema de Cierre de Caja</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #1e1e1e; color: #fff; }
        .check { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .ok { background: #28a745; }
        .error { background: #dc3545; }
        .warning { background: #ffc107; color: #000; }
        .info { background: #17a2b8; }
        h1 { color: #00bcd4; }
        h2 { color: #ffc107; margin-top: 30px; }
        .result { font-weight: bold; }
        code { background: #2c3e50; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico del Sistema de Cierre de Caja</h1>
    
    <?php
    $empresa_id = 1; // ID de empresa para pruebas
    $sucursal_id = 1; // ID de sucursal para pruebas
    
    echo "<h2>1. Verificación de Estructura de Base de Datos</h2>";
    
    // Verificar tabla cierres_caja
    $sql = "DESCRIBE cierres_caja";
    $stmt = $pdo->query($sql);
    $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columnas_requeridas = [
        'id', 'empresa_id', 'sucursal_id', 'fecha_cierre', 'fecha_desde', 'fecha_hasta',
        'numero_cierre', 'saldo_inicial', 'ingresos_efectivo', 'ingresos_transf',
        'ingresos_cheques', 'ingresos_tarjetas', 'ingresos_otros', 'egresos',
        'saldo_esperado_efectivo', 'saldo_real_efectivo', 'diferencia',
        'fondo_reservado_vuelto', 'observaciones', 'usuario'
    ];
    
    $columnas_existentes = array_column($columnas, 'Field');
    $faltantes = array_diff($columnas_requeridas, $columnas_existentes);
    
    if (empty($faltantes)) {
        echo '<div class="check ok">✅ Tabla <code>cierres_caja</code>: Todas las columnas requeridas existen</div>';
    } else {
        echo '<div class="check error">❌ Tabla <code>cierres_caja</code>: Faltan columnas: ' . implode(', ', $faltantes) . '</div>';
    }
    
    // Verificar tabla cierres_caja_audit
    $sql = "SHOW TABLES LIKE 'cierres_caja_audit'";
    $stmt = $pdo->query($sql);
    if ($stmt->fetch()) {
        echo '<div class="check ok">✅ Tabla <code>cierres_caja_audit</code>: Existe</div>';
    } else {
        echo '<div class="check error">❌ Tabla <code>cierres_caja_audit</code>: No existe</div>';
    }
    
    // Verificar tabla estado_caja
    $sql = "SHOW TABLES LIKE 'estado_caja'";
    $stmt = $pdo->query($sql);
    if ($stmt->fetch()) {
        echo '<div class="check ok">✅ Tabla <code>estado_caja</code>: Existe</div>';
    } else {
        echo '<div class="check error">❌ Tabla <code>estado_caja</code>: No existe</div>';
    }
    
    // Verificar tabla movimientos
    $sql = "SHOW TABLES LIKE 'movimientos'";
    $stmt = $pdo->query($sql);
    if ($stmt->fetch()) {
        echo '<div class="check ok">✅ Tabla <code>movimientos</code>: Existe</div>';
    } else {
        echo '<div class="check error">❌ Tabla <code>movimientos</code>: No existe</div>';
    }
    
    echo "<h2>2. Verificación de Índices</h2>";
    
    // Verificar índice idx_fecha_rango
    $sql = "SHOW INDEX FROM cierres_caja WHERE Key_name = 'idx_fecha_rango'";
    $stmt = $pdo->query($sql);
    if ($stmt->fetch()) {
        echo '<div class="check ok">✅ Índice <code>idx_fecha_rango</code>: Existe en cierres_caja</div>';
    } else {
        echo '<div class="check warning">⚠️ Índice <code>idx_fecha_rango</code>: No existe (recomendado para rendimiento)</div>';
    }
    
    echo "<h2>3. Verificación de Datos</h2>";
    
    // Contar cierres
    $sql = "SELECT COUNT(*) as total FROM cierres_caja WHERE empresa_id = :empresa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
    $total_cierres = $stmt->fetchColumn();
    echo '<div class="check info">📊 Total de cierres registrados: <span class="result">' . $total_cierres . '</span></div>';
    
    // Verificar cierres sin tipo_cierre (por si hay datos antiguos)
    $sql = "SELECT COUNT(*) as total FROM cierres_caja WHERE tipo_cierre IS NOT NULL AND empresa_id = :empresa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
    $con_tipo = $stmt->fetchColumn();
    
    if ($con_tipo > 0) {
        echo '<div class="check warning">⚠️ Se encontraron <span class="result">' . $con_tipo . '</span> cierres con campo tipo_cierre (debería estar eliminado)</div>';
    } else {
        echo '<div class="check ok">✅ No hay cierres con campo tipo_cierre (correcto)</div>';
    }
    
    // Verificar cierres con fecha_desde/fecha_hasta
    $sql = "SELECT COUNT(*) as total FROM cierres_caja WHERE fecha_desde IS NULL OR fecha_hasta IS NULL AND empresa_id = :empresa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
    $sin_fechas = $stmt->fetchColumn();
    
    if ($sin_fechas > 0) {
        echo '<div class="check error">❌ Se encontraron <span class="result">' . $sin_fechas . '</span> cierres sin fecha_desde/fecha_hasta</div>';
    } else {
        echo '<div class="check ok">✅ Todos los cierres tienen fecha_desde y fecha_hasta</div>';
    }
    
    // Verificar numeración de cierres
    $sql = "SELECT 
            COUNT(*) as total,
            COUNT(DISTINCT numero_cierre) as numeros_distintos,
            MIN(numero_cierre) as min_numero,
            MAX(numero_cierre) as max_numero
            FROM cierres_caja 
            WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo '<div class="check info">📊 Numeración de cierres:</div>';
    echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Total: <span class="result">' . $stats['total'] . '</span></div>';
    echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Números distintos: <span class="result">' . $stats['numeros_distintos'] . '</span></div>';
    
    if ($stats['total'] > 0) {
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Rango: <span class="result">' . $stats['min_numero'] . ' - ' . $stats['max_numero'] . '</span></div>';
        
        if ($stats['total'] == $stats['numeros_distintos']) {
            echo '<div class="check ok">✅ Todos los números de cierre son únicos</div>';
        } else {
            echo '<div class="check error">❌ Hay números de cierre duplicados</div>';
        }
    }
    
    echo "<h2>4. Verificación de Estado de Caja</h2>";
    
    // Verificar estado de caja actual
    $estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
    
    if ($estado) {
        echo '<div class="check info">📊 Estado actual de caja:</div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Estado: <span class="result">' . $estado['estado'] . '</span></div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Fecha: <span class="result">' . $estado['fecha'] . '</span></div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Saldo inicial: <span class="result">$' . number_format($estado['saldo_inicial'] ?? 0, 2) . '</span></div>';
        
        if ($estado['estado'] === 'ABIERTA') {
            echo '<div class="check ok">✅ La caja está ABIERTA</div>';
        } else {
            echo '<div class="check warning">⚠️ La caja está CERRADA</div>';
        }
    } else {
        echo '<div class="check warning">⚠️ No hay registro de estado de caja para hoy</div>';
    }
    
    echo "<h2>5. Prueba de Funciones</h2>";
    
    // Probar obtener_numero_cierre
    try {
        $numero = obtener_numero_cierre($pdo, $empresa_id, $sucursal_id);
        echo '<div class="check ok">✅ Función <code>obtener_numero_cierre()</code>: Próximo número = <span class="result">' . $numero . '</span></div>';
    } catch (Exception $e) {
        echo '<div class="check error">❌ Función <code>obtener_numero_cierre()</code>: Error - ' . $e->getMessage() . '</div>';
    }
    
    // Probar obtener_resumen_caja
    try {
        $resumen = obtener_resumen_caja($pdo, $empresa_id, $sucursal_id);
        echo '<div class="check ok">✅ Función <code>obtener_resumen_caja()</code>: Funciona correctamente</div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Efectivo: $' . number_format($resumen['efectivo'] ?? 0, 2) . '</div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Transferencia: $' . number_format($resumen['transferencia'] ?? 0, 2) . '</div>';
        echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;Egresos: $' . number_format($resumen['egresos'] ?? 0, 2) . '</div>';
    } catch (Exception $e) {
        echo '<div class="check error">❌ Función <code>obtener_resumen_caja()</code>: Error - ' . $e->getMessage() . '</div>';
    }
    
    echo "<h2>6. Verificación de Movimientos</h2>";
    
    // Contar movimientos pendientes de cierre
    $sql = "SELECT COUNT(*) as total FROM movimientos 
            WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    $movimientos_pendientes = $stmt->fetchColumn();
    
    echo '<div class="check info">📊 Movimientos pendientes de cierre: <span class="result">' . $movimientos_pendientes . '</span></div>';
    
    if ($movimientos_pendientes > 0) {
        // Verificar métodos de pago usados
        $sql = "SELECT metodo_pago, COUNT(*) as cantidad, SUM(monto) as total
                FROM movimientos 
                WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id
                GROUP BY metodo_pago";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
        $metodos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<div class="check info">📊 Métodos de pago en movimientos pendientes:</div>';
        foreach ($metodos as $metodo) {
            echo '<div class="check info">&nbsp;&nbsp;&nbsp;&nbsp;<code>' . $metodo['metodo_pago'] . '</code>: ' . $metodo['cantidad'] . ' movimientos, Total: $' . number_format($metodo['total'], 2) . '</div>';
        }
    }
    
    echo "<h2>7. Resumen de Verificación</h2>";
    
    // Verificar si hay errores críticos
    $errores_criticos = false;
    
    // Revisar si faltan columnas
    if (!empty($faltantes)) {
        $errores_criticos = true;
    }
    
    // Revisar si hay cierres sin fechas
    if ($sin_fechas > 0) {
        $errores_criticos = true;
    }
    
    // Revisar si hay números duplicados
    if ($stats['total'] > 0 && $stats['total'] != $stats['numeros_distintos']) {
        $errores_criticos = true;
    }
    
    if ($errores_criticos) {
        echo '<div class="check error">❌ <strong>Se encontraron errores críticos que deben ser corregidos antes de usar el sistema.</strong></div>';
        echo '<div class="check warning">⚠️ Recomendación: Ejecutar las migraciones pendientes (26 y 27) para corregir la estructura.</div>';
    } else {
        echo '<div class="check ok">✅ <strong>El sistema está correctamente configurado y listo para usar.</strong></div>';
    }
    
    echo '<div class="check info" style="margin-top: 30px;">';
    echo '<strong>📋 Próximos pasos:</strong><br>';
    echo '1. Si hay errores, ejecutar las migraciones SQL<br>';
    echo '2. Probar cierre de caja en <a href="pages/cierre_caja.php" style="color: #ffc107;">pages/cierre_caja.php</a><br>';
    echo '3. Ver reporte de cierres en <a href="pages/reporte_cierres.php" style="color: #ffc107;">pages/reporte_cierres.php</a><br>';
    echo '4. Revisar documentación en <a href="docs/cierre_caja.md" style="color: #ffc107;">docs/cierre_caja.md</a>';
    echo '</div>';
    ?>
</body>
</html>
<?php
$pdo = null;
?>