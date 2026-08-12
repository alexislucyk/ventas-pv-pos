<?php
/**
 * Script de Cierre Masivo de Cajas Históricas
 * 
 * PROPÓSITO:
 * Cerrar todas las cajas abiertas anteriores al 05/08/2026
 * Este script debe ejecutarse UNA SOLA VEZ para normalizar el sistema
 * 
 * FECHA DE CREACIÓN: 05/08/2026
 * VERSIÓN: 1.0.0
 * 
 * INSTRUCCIONES:
 * 1. Ejecutar desde navegador: http://tudominio/pos_dev/procesos/cerrar_cajas_historicas.php
 * 2. O desde línea de comandos: php procesos/cerrar_cajas_historicas.php
 * 3. El script generará un log detallado de todas las operaciones
 * 4. Una vez ejecutado, ELIMINAR o MOVER este archivo para evitar re-ejecuciones
 */

// Iniciar sesión y cargar configuración
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuración de zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');
setlocale(LC_NUMERIC, 'C');

// Detectar entorno
$script_path = $_SERVER['SCRIPT_NAME'];
$db_name = 'pos_dev'; // Por defecto desarrollo

if (preg_match('#/([a-zA-Z0-9_-]+)_dev/#', $script_path)) {
    $db_name = 'pos_dev';
    $ambiente = "DESARROLLO";
} else {
    $db_name = 'pos_prod';
    $ambiente = "PRODUCCIÓN";
}

// Credenciales de base de datos
$host = '192.168.7.45';
$user = 'root';
$pass = 'isidoro9';
$dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";

$options = array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
);

// Variables de control
$inicio_ejecucion = microtime(true);
$log = [];
$errores = [];
$cierres_generados = 0;
$movimientos_cerrados = 0;

// Fecha límite: 05/08/2026
$fecha_limite = '2026-08-05';
$fecha_limite_datetime = $fecha_limite . ' 23:59:59';

try {
    // Conectar a base de datos
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    $log[] = "==========================================";
    $log[] = "SCRIPT DE CIERRE MASIVO DE CAJAS";
    $log[] = "==========================================";
    $log[] = "Fecha/Hora de ejecución: " . date('Y-m-d H:i:s');
    $log[] = "Ambiente: $ambiente";
    $log[] = "Base de datos: $db_name";
    $log[] = "Fecha límite: $fecha_limite";
    $log[] = "==========================================";
    $log[] = "";
    
    // Verificar que existe la tabla estado_caja
    $sql_check_tabla = "SHOW TABLES LIKE 'estado_caja'";
    $stmt_check = $pdo->query($sql_check_tabla);
    
    if (!$stmt_check->fetch()) {
        throw new Exception("ERROR: La tabla 'estado_caja' no existe. Debe ejecutar la migración primero.");
    }
    
    $log[] = "✓ Tabla estado_caja encontrada";
    
    // Verificar que existe la tabla cierres_caja
    $sql_check_cierres = "SHOW TABLES LIKE 'cierres_caja'";
    $stmt_check_cierres = $pdo->query($sql_check_cierres);
    
    if (!$stmt_check_cierres->fetch()) {
        throw new Exception("ERROR: La tabla 'cierres_caja' no existe.");
    }
    
    $log[] = "✓ Tabla cierres_caja encontrada";
    
    // PASO 1: Obtener todas las cajas abiertas anteriores a la fecha límite
    $log[] = "";
    $log[] = "PASO 1: Buscando cajas abiertas anteriores a $fecha_limite...";
    
    $sql_cajas_abiertas = "SELECT 
        ec.id,
        ec.empresa_id,
        ec.sucursal_id,
        ec.fecha,
        ec.saldo_inicial,
        ec.usuario_apertura,
        ec.fecha_apertura,
        e.nombre_fantasia as empresa_nombre,
        s.nombre_sucursal as sucursal_nombre
    FROM estado_caja ec
    INNER JOIN empresas e ON ec.empresa_id = e.id
    INNER JOIN sucursales s ON ec.sucursal_id = s.id
    WHERE ec.estado = 'ABIERTA'
      AND ec.fecha < :fecha_limite
    ORDER BY ec.empresa_id, ec.sucursal_id, ec.fecha";
    
    $stmt_cajas = $pdo->prepare($sql_cajas_abiertas);
    $stmt_cajas->execute([':fecha_limite' => $fecha_limite]);
    $cajas_abiertas = $stmt_cajas->fetchAll();
    
    $total_cajas = count($cajas_abiertas);
    $log[] = "✓ Cajas abiertas encontradas: $total_cajas";
    
    if ($total_cajas === 0) {
        $log[] = "";
        $log[] = "✓ No hay cajas abiertas para cerrar. Proceso completado.";
        finalizar_script($log, $errores, $cierres_generados, $movimientos_cerrados, $inicio_ejecucion);
        exit;
    }
    
    // Mostrar detalle de cajas encontradas
    $log[] = "";
    $log[] = "Detalle de cajas a cerrar:";
    foreach ($cajas_abiertas as $caja) {
        $log[] = sprintf(
            "  - Empresa: %s | Sucursal: %s | Fecha: %s | Saldo Inicial: $%s",
            $caja['empresa_nombre'],
            $caja['sucursal_nombre'],
            $caja['fecha'],
            number_format($caja['saldo_inicial'], 2)
        );
    }
    
    // PASO 2: Procesar cada caja
    $log[] = "";
    $log[] = "PASO 2: Procesando cierres...";
    $log[] = str_repeat("-", 60);
    
    $pdo->beginTransaction();
    
    foreach ($cajas_abiertas as $caja) {
        try {
            $empresa_id = $caja['empresa_id'];
            $sucursal_id = $caja['sucursal_id'];
            $fecha_caja = $caja['fecha'];
            $saldo_inicial = (float)$caja['saldo_inicial'];
            
            $log[] = "";
            $log[] = "Procesando: {$caja['empresa_nombre']} | {$caja['sucursal_nombre']} | $fecha_caja";
            
            // Calcular totales de movimientos para esa fecha
            $sql_totales = "SELECT 
                SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                         THEN monto ELSE 0 END) as ingresos_efectivo,
                SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                         THEN monto ELSE 0 END) as ingresos_transf,
                SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
            FROM movimientos 
            WHERE empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id
              AND DATE(fecha) = :fecha
              AND cerrado = 0";
            
            $stmt_totales = $pdo->prepare($sql_totales);
            $stmt_totales->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':fecha' => $fecha_caja
            ]);
            
            $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
            $ing_efectivo = (float)($totales['ingresos_efectivo'] ?? 0);
            $ing_transf = (float)($totales['ingresos_transf'] ?? 0);
            $egresos = (float)($totales['egresos'] ?? 0);
            $saldo_esperado = $ing_efectivo - $egresos;
            
            $log[] = sprintf(
                "  Totales: Ing.Efectivo=$%s | Ing.Transf=$%s | Egresos=$%s | Saldo Esperado=$%s",
                number_format($ing_efectivo, 2),
                number_format($ing_transf, 2),
                number_format($egresos, 2),
                number_format($saldo_esperado, 2)
            );
            
            // Obtener número de cierre
            $sql_num_cierre = "SELECT COALESCE(MAX(numero_cierre), 0) + 1 as numero 
                              FROM cierres_caja 
                              WHERE empresa_id = :empresa_id 
                                AND sucursal_id = :sucursal_id";
            
            $stmt_num = $pdo->prepare($sql_num_cierre);
            $stmt_num->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id
            ]);
            $numero_cierre = (int)$stmt_num->fetchColumn();
            
            // Fecha de cierre: usar el final del día de la caja
            $fecha_cierre = $fecha_caja . ' 23:59:59';
            $usuario_sistema = 'Sistema (Cierre Histórico)';
            
            // Insertar en cierres_caja
            $sql_cierre = "INSERT INTO cierres_caja 
                          (empresa_id, sucursal_id, fecha_cierre, saldo_inicial, 
                           ingresos_efectivo, ingresos_transf, egresos, 
                           saldo_esperado_efectivo, saldo_real_efectivo, diferencia,
                           fondo_reservado_vuelto, tipo_cierre, numero_cierre, usuario)
                          VALUES (:empresa_id, :sucursal_id, :fecha_cierre, :saldo_inicial,
                                  :ingresos_efectivo, :ingresos_transf, :egresos,
                                  :saldo_esperado, :saldo_real, :diferencia,
                                  :fondo_vuelto, 'DIARIO', :numero_cierre, :usuario)";
            
            $stmt_cierre = $pdo->prepare($sql_cierre);
            $stmt_cierre->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':fecha_cierre' => $fecha_cierre,
                ':saldo_inicial' => $saldo_inicial,
                ':ingresos_efectivo' => $ing_efectivo,
                ':ingresos_transf' => $ing_transf,
                ':egresos' => $egresos,
                ':saldo_esperado' => $saldo_esperado,
                ':saldo_real' => $saldo_esperado,
                ':diferencia' => 0,
                ':fondo_vuelto' => 0,
                ':numero_cierre' => $numero_cierre,
                ':usuario' => $usuario_sistema
            ]);
            
            $cierre_id = $pdo->lastInsertId();
            $cierres_generados++;
            
            $log[] = sprintf("  ✓ Cierre #%s generado (ID: %s)", $numero_cierre, $cierre_id);
            
            // Marcar movimientos como cerrados
            $sql_update_mov = "UPDATE movimientos 
                              SET cerrado = 1 
                              WHERE empresa_id = :empresa_id 
                                AND sucursal_id = :sucursal_id
                                AND DATE(fecha) = :fecha
                                AND cerrado = 0";
            
            $stmt_update = $pdo->prepare($sql_update_mov);
            $stmt_update->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':fecha' => $fecha_caja
            ]);
            
            $movimientos_afectados = $stmt_update->rowCount();
            $movimientos_cerrados += $movimientos_afectados;
            
            $log[] = sprintf("  ✓ %s movimientos marcados como cerrados", $movimientos_afectados);
            
            // Actualizar estado de caja a CERRADA
            $sql_estado = "UPDATE estado_caja 
                          SET estado = 'CERRADA', 
                              usuario_cierre = :usuario,
                              fecha_cierre = :fecha_cierre
                          WHERE id = :id";
            
            $stmt_estado = $pdo->prepare($sql_estado);
            $stmt_estado->execute([
                ':usuario' => $usuario_sistema,
                ':fecha_cierre' => $fecha_cierre,
                ':id' => $caja['id']
            ]);
            
            $log[] = "  ✓ Estado de caja actualizado a CERRADA";
            
            // Registrar en log de auditoría (si existe la tabla)
            try {
                $sql_audit = "INSERT INTO cierres_caja_audit 
                             (cierre_id, accion, usuario, datos_nuevos)
                             VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
                
                $datos_audit = json_encode([
                    'empresa_id' => $empresa_id,
                    'sucursal_id' => $sucursal_id,
                    'fecha_cierre' => $fecha_cierre,
                    'ingresos_efectivo' => $ing_efectivo,
                    'ingresos_transf' => $ing_transf,
                    'egresos' => $egresos,
                    'saldo_esperado' => $saldo_esperado,
                    'fondo_vuelto' => 0,
                    'origen' => 'CIERRE_HISTORICO'
                ]);
                
                $stmt_audit = $pdo->prepare($sql_audit);
                $stmt_audit->execute([
                    ':cierre_id' => $cierre_id,
                    ':usuario' => $usuario_sistema,
                    ':datos' => $datos_audit
                ]);
                
                $log[] = "  ✓ Auditoría registrada";
            } catch (Exception $e) {
                // Si no existe la tabla de auditoría, continuar sin error
                $log[] = "  - Tabla de auditoría no disponible (opcional)";
            }
            
        } catch (Exception $e) {
            $errores[] = "Error en caja {$caja['fecha']} - {$caja['empresa_nombre']}: " . $e->getMessage();
            $log[] = "  ✗ ERROR: " . $e->getMessage();
        }
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    $log[] = "";
    $log[] = str_repeat("-", 60);
    $log[] = "✓ Transacción confirmada";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errores[] = "Error general: " . $e->getMessage();
    $log[] = "";
    $log[] = "✗ ERROR CRÍTICO: " . $e->getMessage();
    $log[] = "✗ Transacción revertida (rollback)";
}

// Finalizar y mostrar resultados
finalizar_script($log, $errores, $cierres_generados, $movimientos_cerrados, $inicio_ejecucion);

/**
 * Función para finalizar el script y mostrar resultados
 */
function finalizar_script($log, $errores, $cierres_generados, $movimientos_cerrados, $inicio_ejecucion) {
    $fin_ejecucion = microtime(true);
    $tiempo_total = round($fin_ejecucion - $inicio_ejecucion, 2);
    
    $log[] = "";
    $log[] = "==========================================";
    $log[] = "RESUMEN DE EJECUCIÓN";
    $log[] = "==========================================";
    $log[] = "Cierres generados: $cierres_generados";
    $log[] = "Movimientos cerrados: $movimientos_cerrados";
    $log[] = "Errores encontrados: " . count($errores);
    $log[] = "Tiempo de ejecución: {$tiempo_total} segundos";
    $log[] = "==========================================";
    
    if (count($errores) > 0) {
        $log[] = "";
        $log[] = "ERRORES DETECTADOS:";
        foreach ($errores as $error) {
            $log[] = "  ✗ $error";
        }
    }
    
    $log[] = "";
    $log[] = "==========================================";
    $log[] = "✓ PROCESO COMPLETADO";
    $log[] = "==========================================";
    $log[] = "";
    $log[] = "IMPORTANTE: Este script debe ejecutarse UNA SOLA VEZ.";
    $log[] = "Después de la ejecución, ELIMINE o MUEVA este archivo.";
    $log[] = "";
    
    // Mostrar output
    echo "<pre>\n";
    echo implode("\n", $log);
    echo "</pre>\n";
    
    // Guardar log en archivo
    $archivo_log = __DIR__ . '/logs/cierre_masivo_' . date('Ymd_His') . '.log';
    
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
    
    file_put_contents($archivo_log, implode("\n", $log));
    
    echo "\n<div style='background: #f0f0f0; padding: 15px; margin: 20px; border-left: 4px solid #007bff;'>";
    echo "<strong>📄 Log guardado en:</strong> $archivo_log";
    echo "</div>";
    
    // Mostrar advertencia final
    if ($cierres_generados > 0) {
        echo "\n<div style='background: #fff3cd; padding: 15px; margin: 20px; border-left: 4px solid #ffc107;'>";
        echo "<strong>⚠️ ADVERTENCIA:</strong><br>";
        echo "Se cerraron <strong>$cierres_generados</strong> cajas históricas.<br>";
        echo "Verifique los resultados en el sistema antes de continuar.<br>";
        echo "<strong>Recuerde ELIMINAR este script después de verificar.</strong>";
        echo "</div>";
    }
}
?>