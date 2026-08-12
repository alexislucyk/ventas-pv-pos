<?php
// Script de diagnóstico para verificar el estado de cierres
include 'pages/infosesion.php';
require_once 'config/db_config.php';
require_once 'funciones/funciones_caja.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR: Falta empresa_id en sesión.');
}

echo "<h2>Diagnóstico de Cierres de Caja</h2>";
echo "<hr>";

// 1. Verificar fecha actual del servidor
echo "<h3>1. Fecha Actual del Servidor</h3>";
echo "<p><strong>Fecha/Hora:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><strong>Solo Fecha:</strong> " . date('Y-m-d') . "</p>";
echo "<hr>";

// 2. Verificar estado de caja actual
echo "<h3>2. Estado de Caja Actual</h3>";
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
if ($estado) {
    echo "<pre>";
    print_r($estado);
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠ No hay registro en estado_caja para hoy</p>";
}
echo "<hr>";

// 3. Verificar TODOS los cierres de los últimos 7 días
echo "<h3>3. Cierres de los Últimos 7 Días</h3>";
$sql = "SELECT id, empresa_id, sucursal_id, fecha_cierre, saldo_inicial, 
               saldo_esperado_efectivo, saldo_real_efectivo, diferencia, 
               usuario, numero_cierre
        FROM cierres_caja 
        WHERE empresa_id = :empresa_id 
          AND sucursal_id = :sucursal_id
          AND fecha_cierre >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY fecha_cierre DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
$cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($cierres) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #004a54; color: white;'>
            <th>ID</th>
            <th>Fecha Cierre</th>
            <th>Saldo Inicial</th>
            <th>Saldo Esperado</th>
            <th>Saldo Real</th>
            <th>Diferencia</th>
            <th>Usuario</th>
            <th>Número</th>
          </tr>";
    
    foreach ($cierres as $cierre) {
        $fecha_cierre = date('Y-m-d', strtotime($cierre['fecha_cierre']));
        $es_hoy = ($fecha_cierre === date('Y-m-d')) ? " style='background: #ffcccc; font-weight: bold;'" : "";
        
        echo "<tr{$es_hoy}>";
        echo "<td>{$cierre['id']}</td>";
        echo "<td>{$cierre['fecha_cierre']}</td>";
        echo "<td>\${$cierre['saldo_inicial']}</td>";
        echo "<td>\${$cierre['saldo_esperado_efectivo']}</td>";
        echo "<td>\${$cierre['saldo_real_efectivo']}</td>";
        echo "<td>\${$cierre['diferencia']}</td>";
        echo "<td>{$cierre['usuario']}</td>";
        echo "<td>{$cierre['numero_cierre']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: orange;'>⚠ No hay cierres en los últimos 7 días</p>";
}
echo "<hr>";

// 4. Verificar cierres específicos de HOY por usuario
echo "<h3>4. Verificación Específica de HOY (por usuario)</h3>";
$sql_hoy = "SELECT id, usuario, fecha_cierre, saldo_real_efectivo, diferencia 
            FROM cierres_caja 
            WHERE empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id 
              AND DATE(fecha_cierre) = CURDATE()
            ORDER BY fecha_cierre DESC";

$stmt_hoy = $pdo->prepare($sql_hoy);
$stmt_hoy->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
$cierres_hoy = $stmt_hoy->fetchAll(PDO::FETCH_ASSOC);

$usuario_actual = $_SESSION['usuario'] ?? 'Sistema';

if ($cierres_hoy) {
    echo "<p style='color: orange; font-weight: bold;'>⚠ Existen " . count($cierres_hoy) . " cierre(s) para HOY:</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-top: 10px;'>";
    echo "<tr style='background: #004a54; color: white;'>
            <th>ID</th>
            <th>Usuario</th>
            <th>Fecha/Hora</th>
            <th>Saldo Real</th>
            <th>Diferencia</th>
            <th>¿Es tu cierre?</th>
          </tr>";
    
    foreach ($cierres_hoy as $cierre) {
        $es_mi_cierre = ($cierre['usuario'] === $usuario_actual) ? " style='background: #ffcccc; font-weight: bold;'" : "";
        $es_mi_cierre_texto = ($cierre['usuario'] === $usuario_actual) ? "✅ SÍ (NO puedes cerrar de nuevo)" : "❌ NO (Otro usuario)";
        
        echo "<tr{$es_mi_cierre}>";
        echo "<td>{$cierre['id']}</td>";
        echo "<td>" . htmlspecialchars($cierre['usuario']) . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($cierre['fecha_cierre'])) . "</td>";
        echo "<td>\${$cierre['saldo_real_efectivo']}</td>";
        echo "<td>\${$cierre['diferencia']}</td>";
        echo "<td>{$es_mi_cierre_texto}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Verificar si el usuario actual ya cerró
    $ya_cerro = false;
    foreach ($cierres_hoy as $cierre) {
        if ($cierre['usuario'] === $usuario_actual) {
            $ya_cerro = true;
            break;
        }
    }
    
    if ($ya_cerro) {
        echo "<div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-top: 15px;'>";
        echo "<h4>⚠ No puedes cerrar la caja</h4>";
        echo "<p>El usuario <strong>" . htmlspecialchars($usuario_actual) . "</strong> ya realizó el cierre de caja hoy.</p>";
        echo "<p><strong>Soluciones:</strong></p>";
        echo "<ul>";
        echo "<li>Esperar hasta mañana para realizar un nuevo cierre</li>";
        echo "<li>Si necesitás corregir datos, contactá al administrador</li>";
        echo "<li>Otros cajeros SÍ pueden cerrar su caja (ver tabla arriba)</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin-top: 15px;'>";
        echo "<h4>✅ Podés cerrar la caja</h4>";
        echo "<p>El usuario <strong>" . htmlspecialchars($usuario_actual) . "</strong> aún no realizó el cierre hoy.</p>";
        echo "<p>Podés proceder con el cierre de caja normalmente.</p>";
        echo "</div>";
    }
} else {
    echo "<p style='color: green; font-weight: bold;'>✅ NO hay cierres para HOY - Podés cerrar la caja</p>";
}
echo "<hr>";

// 5. Verificar movimientos pendientes
echo "<h3>5. Movimientos Pendientes de Cierre (cerrado = 0)</h3>";
$sql_mov = "SELECT COUNT(*) as total, 
                   SUM(CASE WHEN tipo = 'INGRESO' THEN monto ELSE 0 END) as ingresos,
                   SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
            FROM movimientos 
            WHERE cerrado = 0 
              AND empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id";

$stmt_mov = $pdo->prepare($sql_mov);
$stmt_mov->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
$movimientos = $stmt_mov->fetch(PDO::FETCH_ASSOC);

echo "<p><strong>Total movimientos pendientes:</strong> {$movimientos['total']}</p>";
echo "<p><strong>Ingresos:</strong> \${$movimientos['ingresos']}</p>";
echo "<p><strong>Egresos:</strong> \${$movimientos['egresos']}</p>";
echo "<hr>";

// 6. Recomendación
echo "<h3>6. Diagnóstico y Solución</h3>";
echo "<div style='background: #d4edda; border-left: 4px solid #28a745; padding: 15px;'>";
echo "<h4>✅ Múltiples cierres permitidos</h4>";
echo "<p>El sistema permite cerrar la caja <strong>múltiples veces por día</strong> para el mismo usuario.</p>";
echo "<p>Podés cerrar la caja las veces que necesites sin restricciones.</p>";

if ($cierres_hoy) {
    echo "<p><strong>Historial de hoy:</strong> Hay " . count($cierres_hoy) . " cierre(s) registrado(s) hoy.</p>";
    echo "<p>Cada cierre genera un registro independiente en la tabla cierres_caja.</p>";
} else {
    echo "<p><strong>No hay cierres hoy:</strong> Podés realizar el primer cierre del día.</p>";
}

echo "<p><strong>Nota importante:</strong></p>";
echo "<ul>";
echo "<li>Cada cierre marca TODOS los movimientos pendientes como cerrados (cerrado = 1)</li>";
echo "<li>Si necesitás ver el historial, revisá la tabla cierres_caja</li>";
echo "<li>El estado de caja pasa a CERRADA después de cada cierre</li>";
echo "</ul>";
echo "</div>";
?>