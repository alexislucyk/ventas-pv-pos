<?php
// pages/procesar_cierre.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

// C) ENDURECER ENDPOINT: cierre de caja es acción crítica, requiere permiso
require_permiso('pages/cierre_caja.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario = $_SESSION['usuario'] ?? 'Sistema';

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar que la caja esté abierta
        if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
            throw new Exception('La caja está cerrada. No se puede procesar el cierre.');
        }
        
        // NOTA: Se permite cerrar la caja múltiples veces por día por el mismo usuario
        // No hay validación de cierre único
        
        // Obtener datos del POST
        $saldo_real_efectivo = (float)str_replace(',', '.', $_POST['saldo_real_efectivo'] ?? '0');
        $fondo_vuelto_manana = (float)str_replace(',', '.', $_POST['fondo_vuelto'] ?? '0');
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        // Obtener rango de fechas del POST (período automático desde la apertura de la sesión)
        $caja_actual = obtener_caja_abierta($pdo, $empresa_id, $sucursal_id);
        $fecha_desde = $_POST['fecha_desde'] ?? ($caja_actual['fecha_apertura'] ?? date('Y-m-d H:i:s', strtotime('-1 day')));
        $fecha_hasta = $_POST['fecha_hasta'] ?? date('Y-m-d H:i:s');
        
        // Validaciones
        if ($saldo_real_efectivo < 0) {
            throw new Exception('El saldo real no puede ser negativo.');
        }
        
        if ($fondo_vuelto_manana < 0) {
            throw new Exception('El fondo de vuelto no puede ser negativo.');
        }
        
        if ($fondo_vuelto_manana > $saldo_real_efectivo) {
            throw new Exception("El fondo reservado ($fondo_vuelto_manana) no puede ser mayor al efectivo contado ($saldo_real_efectivo).");
        }
        
        // Validar cantidades de billetes (si se envían)
        $denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];
        $total_calculado = 0;
        
        foreach ($denominaciones as $valor) {
            $cantidad = isset($_POST["b_$valor"]) ? (int)$_POST["b_$valor"] : 0;
            
            if ($cantidad < 0) {
                throw new Exception("La cantidad de billetes de $$valor no puede ser negativa.");
            }
            
            $total_calculado += ($valor * $cantidad);
        }
        
        // Validar que el total coincida con el envío (si hay billetes)
        if ($total_calculado > 0 && abs($total_calculado - $saldo_real_efectivo) > 0.01) {
            throw new Exception("El total calculado de billetes ($ $total_calculado) no coincide con el saldo real ($ $saldo_real_efectivo).");
        }
        
        // Usar función auxiliar para cerrar caja (con rango de fechas si aplica)
        $resultado = cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo_vuelto_manana, $fecha_desde, $fecha_hasta);
        
        if (!$resultado['success']) {
            throw new Exception($resultado['mensaje']);
        }
        
        // Actualizar cierre con datos del formulario
        $cierre_id = $resultado['cierre_id'];
        
        // Recalcular saldo esperado y diferencia (incluyendo saldo inicial)
        $estado_cierre = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
        $saldo_inicial = (float)($estado_cierre['saldo_inicial'] ?? 0);
        
        $sql_totales = "SELECT 
            SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                     THEN monto ELSE 0 END) as ingresos_efectivo,
            SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
        FROM movimientos 
        WHERE cerrado = 1 
          AND empresa_id = :empresa_id 
          AND sucursal_id = :sucursal_id
          AND DATE(fecha) BETWEEN :fecha_desde AND :fecha_hasta";
        
        $stmt_totales = $pdo->prepare($sql_totales);
        $stmt_totales->execute([
            ':empresa_id' => $empresa_id, 
            ':sucursal_id' => $sucursal_id,
            ':fecha_desde' => $fecha_desde,
            ':fecha_hasta' => $fecha_hasta
        ]);
        $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
        
        $saldo_esperado = $saldo_inicial + (float)($totales['ingresos_efectivo'] ?? 0) - (float)($totales['egresos'] ?? 0);
        $diferencia = $saldo_real_efectivo - $saldo_esperado;
        
        // Actualizar con valores correctos
        $sql_update = "UPDATE cierres_caja 
                       SET saldo_real_efectivo = :saldo_real,
                           diferencia = :diferencia,
                           observaciones = :observaciones
                       WHERE id = :id";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            ':saldo_real' => $saldo_real_efectivo,
            ':diferencia' => $diferencia,
            ':observaciones' => $observaciones,
            ':id' => $cierre_id
        ]);
        
        // Registrar en log de auditoría
        $sql_audit = "INSERT INTO cierres_caja_audit 
                      (cierre_id, accion, usuario, datos_nuevos)
                      VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
        
        $datos_audit = json_encode([
            'saldo_real' => $saldo_real_efectivo,
            'diferencia' => $diferencia,
            'fondo_vuelto' => $fondo_vuelto_manana,
            'observaciones' => $observaciones
        ]);
        
        $stmt_audit = $pdo->prepare($sql_audit);
        $stmt_audit->execute([
            ':cierre_id' => $cierre_id,
            ':usuario' => $usuario,
            ':datos' => $datos_audit
        ]);
        
        // Mensaje de éxito
        $msj_tipo = ($diferencia == 0) ? "✅ Caja cerrada correctamente." : "⚠️ Caja cerrada con diferencia de $ " . number_format($diferencia, 2, ',', '.');
        $_SESSION['status_msj'] = $msj_tipo;
        
        header("Location: " . url('caja-dashboard'));
        exit();
        
    } catch (Exception $e) {
        // El rollback ya se maneja dentro de cerrar_caja()
        error_log("Error en cierre de caja: " . $e->getMessage());
        die("❌ Error crítico: " . $e->getMessage());
    }
} else {
    header("Location: " . url('cierre-caja'));
    exit();
}
