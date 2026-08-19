<?php
/**
 * Script de VERIFICACIÓN de Cajas Históricas
 * 
 * PROPÓSITO:
 * Mostrar qué cajas se cerrarían sin realizar cambios en la base de datos
 * Este script es SOLO LECTURA y no modifica nada
 * 
 * FECHA DE CREACIÓN: 05/08/2026
 * VERSIÓN: 1.0.0
 * 
 * INSTRUCCIONES:
 * 1. Ejecutar primero este script para verificar
 * 2. Revisar el listado de cajas que se cerrarían
 * 3. Si todo está correcto, ejecutar cerrar_cajas_historicas.php
 */

// Iniciar sesión y cargar configuración
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuración de zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');
setlocale(LC_NUMERIC, 'C');

// Detectar entorno según la CARPETA REAL (filesystem) donde está instalada la app.
// Este script vive en procesos/, así que la raíz de la app está un nivel arriba.
$app_folder = basename(dirname(__DIR__)); // ej. 'pos_dev', 'ventas_dev', 'pos_prod'
$db_name = 'pos_dev';

if (substr($app_folder, -4) === '_dev') {
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
$inicio = microtime(true);
$cajas_encontradas = [];
$errores = [];

// Fecha límite: 05/08/2026
$fecha_limite = '2026-08-05';

try {
    // Conectar a base de datos
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    echo "<!DOCTYPE html>";
    echo "<html lang='es'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>Verificación de Cajas Históricas</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
    echo ".container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }";
    echo ".header { background: #004a54; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }";
    echo ".info-box { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 15px 0; }";
    echo ".warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }";
    echo ".success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 15px 0; }";
    echo ".error-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 15px 0; }";
    echo "table { width: 100%; border-collapse: collapse; margin-top: 20px; }";
    echo "th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }";
    echo "th { background: #f8f9fa; font-weight: bold; }";
    echo "tr:hover { background: #f5f5f5; }";
    echo ".badge { padding: 5px 10px; border-radius: 3px; font-size: 12px; }";
    echo ".badge-success { background: #28a745; color: white; }";
    echo ".badge-warning { background: #ffc107; color: #333; }";
    echo ".btn { padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }";
    echo ".btn:hover { background: #0056b3; }";
    echo ".btn-danger { background: #dc3545; }";
    echo ".btn-danger:hover { background: #c82333; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    
    echo "<div class='header'>";
    echo "<h1>🔍 Verificación de Cajas Históricas</h1>";
    echo "<p>Script de solo lectura - No modifica la base de datos</p>";
    echo "</div>";
    
    echo "<div class='info-box'>";
    echo "<strong>📋 Información del Sistema</strong><br>";
    echo "Ambiente: <strong>$ambiente</strong><br>";
    echo "Base de datos: <strong>$db_name</strong><br>";
    echo "Fecha/Hora: <strong>" . date('Y-m-d H:i:s') . "</strong><br>";
    echo "Fecha límite: <strong>$fecha_limite</strong>";
    echo "</div>";
    
    // Verificar que existe la tabla estado_caja
    $sql_check = "SHOW TABLES LIKE 'estado_caja'";
    $stmt_check = $pdo->query($sql_check);
    
    if (!$stmt_check->fetch()) {
        echo "<div class='error-box'>";
        echo "<strong>❌ ERROR:</strong> La tabla 'estado_caja' no existe.<br>";
        echo "Debe ejecutar la migración primero: <code>php procesos/ejecutar_migracion_21.php</code>";
        echo "</div>";
        exit;
    }
    
    echo "<div class='success-box'>";
    echo "<strong>✓</strong> Tabla 'estado_caja' encontrada";
    echo "</div>";
    
    // Obtener todas las cajas abiertas anteriores a la fecha límite
    $sql_cajas = "SELECT 
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
    
    $stmt_cajas = $pdo->prepare($sql_cajas);
    $stmt_cajas->execute([':fecha_limite' => $fecha_limite]);
    $cajas_abiertas = $stmt_cajas->fetchAll();
    
    $total_cajas = count($cajas_abiertas);
    
    echo "<div class='warning-box'>";
    echo "<strong>⚠️ Cajas encontradas:</strong> $total_cajas cajas abiertas anteriores al $fecha_limite";
    echo "</div>";
    
    if ($total_cajas === 0) {
        echo "<div class='success-box'>";
        echo "<strong>✓ Excelente!</strong> No hay cajas abiertas para cerrar.<br>";
        echo "El sistema está normalizado. No es necesario ejecutar el script de cierre.";
        echo "</div>";
    } else {
        echo "<h2>📊 Detalle de Cajas a Cerrar</h2>";
        echo "<table>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>#</th>";
        echo "<th>Empresa</th>";
        echo "<th>Sucursal</th>";
        echo "<th>Fecha</th>";
        echo "<th>Saldo Inicial</th>";
        echo "<th>Usuario Apertura</th>";
        echo "<th>Fecha Apertura</th>";
        echo "<th>Movimientos</th>";
        echo "<th>Estado</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        $total_movimientos = 0;
        $total_saldo_inicial = 0;
        
        foreach ($cajas_abiertas as $index => $caja) {
            // Obtener cantidad de movimientos para esta caja
            $sql_mov = "SELECT COUNT(*) as cantidad, 
                               SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                                        THEN monto ELSE 0 END) as ing_efectivo,
                               SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
                        FROM movimientos 
                        WHERE empresa_id = :empresa_id 
                          AND sucursal_id = :sucursal_id
                          AND DATE(fecha) = :fecha
                          AND cerrado = 0";
            
            $stmt_mov = $pdo->prepare($sql_mov);
            $stmt_mov->execute([
                ':empresa_id' => $caja['empresa_id'],
                ':sucursal_id' => $caja['sucursal_id'],
                ':fecha' => $caja['fecha']
            ]);
            $mov_data = $stmt_mov->fetch(PDO::FETCH_ASSOC);
            
            $cant_mov = (int)($mov_data['cantidad'] ?? 0);
            $ing_efectivo = (float)($mov_data['ing_efectivo'] ?? 0);
            $egresos = (float)($mov_data['egresos'] ?? 0);
            $saldo_esperado = $ing_efectivo - $egresos;
            
            $total_movimientos += $cant_mov;
            $total_saldo_inicial += (float)$caja['saldo_inicial'];
            
            echo "<tr>";
            echo "<td>" . ($index + 1) . "</td>";
            echo "<td>" . htmlspecialchars($caja['empresa_nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($caja['sucursal_nombre']) . "</td>";
            echo "<td><strong>" . $caja['fecha'] . "</strong></td>";
            echo "<td>$" . number_format($caja['saldo_inicial'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($caja['usuario_apertura']) . "</td>";
            echo "<td>" . date('d/m/Y H:i', strtotime($caja['fecha_apertura'])) . "</td>";
            echo "<td><span class='badge badge-warning'>$cant_mov movimientos</span></td>";
            echo "<td><span class='badge badge-success'>ABIERTA</span></td>";
            echo "</tr>";
            
            $cajas_encontradas[] = $caja;
        }
        
        echo "</tbody>";
        echo "</table>";
        
        // Resumen
        echo "<div class='info-box' style='margin-top: 20px;'>";
        echo "<strong>📈 Resumen:</strong><br>";
        echo "Total de cajas a cerrar: <strong>$total_cajas</strong><br>";
        echo "Total de movimientos a cerrar: <strong>$total_movimientos</strong><br>";
        echo "Suma de saldos iniciales: <strong>$" . number_format($total_saldo_inicial, 2) . "</strong>";
        echo "</div>";
        
        // Advertencia y acciones
        echo "<div class='warning-box'>";
        echo "<strong>⚠️ Acciones a realizar:</strong><br>";
        echo "1. Verifique que los datos sean correctos<br>";
        echo "2. Si todo está bien, ejecute: <code>php procesos/cerrar_cajas_historicas.php</code><br>";
        echo "3. O desde el navegador: <a href='cerrar_cajas_historicas.php' class='btn btn-danger'>CERRAR CAJAS</a><br>";
        echo "<strong>IMPORTANTE:</strong> El cierre marcará todos los movimientos como cerrados y generará registros en cierres_caja";
        echo "</div>";
    }
    
    $tiempo = round(microtime(true) - $inicio, 2);
    echo "<div class='info-box'>";
    echo "<strong>⏱️ Tiempo de ejecución:</strong> $tiempo segundos";
    echo "</div>";
    
    echo "</div>";
    echo "</body>";
    echo "</html>";
    
} catch (Exception $e) {
    echo "<div class='error-box'>";
    echo "<strong>❌ ERROR:</strong> " . $e->getMessage();
    echo "</div>";
}
?>