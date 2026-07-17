<?php
// pages/procesar_cierre.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

// C) ENDURECER ENDPOINT: cierre de caja es acción crítica, requiere permiso
require_permiso('pages/cierre_caja.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $fecha_cierre = date('Y-m-d H:i:s');
        $saldo_real_efectivo = (float)str_replace(',', '.', $_POST['saldo_real_efectivo'] ?? '0');
        $fondo_vuelto_manana = (float)str_replace(',', '.', $_POST['fondo_vuelto'] ?? '0');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $usuario = $_SESSION['usuario'] ?? 'Sistema'; 

        $sql_sistema = "SELECT 
            SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') THEN monto ELSE 0 END) as ingresos_efectivo,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as ingresos_transf,
            SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
        FROM movimientos 
        WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
        
        $stmt = $pdo->prepare($sql_sistema);
        $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
        $sistema = $stmt->fetch(PDO::FETCH_ASSOC);

        $ing_efectivo = (float)($sistema['ingresos_efectivo'] ?? 0);
        $ing_transf = (float)($sistema['ingresos_transf'] ?? 0);
        $egresos = (float)($sistema['egresos'] ?? 0);
        
        $saldo_esperado = $ing_efectivo - $egresos;
        
        // 3. Calcular Diferencia
        $diferencia = $saldo_real_efectivo - $saldo_esperado;

        // Validación de seguridad: El fondo reservado no puede ser mayor al dinero real en caja
        if ($fondo_vuelto_manana > $saldo_real_efectivo) {
            throw new Exception("El fondo reservado ($fondo_vuelto_manana) no puede ser mayor al efectivo contado ($saldo_real_efectivo).");
        }

        // 4. Insertar en la tabla cierres_caja
        $sql_ins = "INSERT INTO cierres_caja 
                    (fecha_cierre, ingresos_efectivo, ingresos_transf, egresos, saldo_esperado_efectivo, saldo_real_efectivo, diferencia, fondo_reservado_vuelto, observaciones, usuario, empresa_id, sucursal_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $pdo->prepare($sql_ins)->execute([
            $fecha_cierre,
            $ing_efectivo,
            $ing_transf,
            $egresos,
            $saldo_esperado,
            $saldo_real_efectivo,
            $diferencia,
            $fondo_vuelto_manana,
            $observaciones,
            $usuario,
            $empresa_id,
            $sucursal_id
        ]);

        // 5. Marcar movimientos actuales como "CERRADOS"
        $sql_update = "UPDATE movimientos SET cerrado = 1 WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
        $pdo->prepare($sql_update)->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);

        // 6. GENERAR EL FONDO INICIAL PARA EL DÍA SIGUIENTE
        if ($fondo_vuelto_manana > 0) {
            $mañana = date('Y-m-d 07:00:00', strtotime('+1 day'));
            $sql_fondo = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, cerrado, empresa_id, sucursal_id) 
                          VALUES ('INGRESO', ?, 'EFECTIVO', 'FONDO INICIAL (VUELTO)', ?, 0, ?, ?)";
            $pdo->prepare($sql_fondo)->execute([$fondo_vuelto_manana, $mañana, $empresa_id, $sucursal_id]);
        }

        $pdo->commit();

        // Guardar mensaje y redirigir
        $msj_tipo = ($diferencia == 0) ? "✅ Caja cerrada correctamente." : "⚠️ Caja cerrada con diferencia de $ " . number_format($diferencia, 2, ',', '.');
        $_SESSION['status_msj'] = $msj_tipo;
        
        header("Location: caja_dashboard.php");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error en cierre de caja: " . $e->getMessage());
        die("❌ Error crítico: " . $e->getMessage());
    }
} else {
    header("Location: cierre_caja.php");
    exit();
}