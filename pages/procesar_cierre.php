<?php
// pages/procesar_cierre.php
include 'infosesion.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Capturar datos del formulario
        $fecha_cierre = date('Y-m-d H:i:s');
        $saldo_real_efectivo = (float)$_POST['saldo_real_efectivo'];
        $fondo_vuelto_manana = (float)$_POST['fondo_vuelto']; // El dinero que queda en el cajón
        $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';
        $usuario = $_SESSION['usuario']; 

        // 2. Recalcular totales del sistema para seguridad
        // Sumamos Ingresos (Efectivo y Mixto) y restamos Egresos que no estén cerrados
        $hoy = date('Y-m-d');
        $sql_sistema = "SELECT 
            SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') THEN monto ELSE 0 END) as ingresos_efectivo,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as ingresos_transf,
            SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
        FROM movimientos 
        WHERE cerrado = 0"; // Tomamos todos los movimientos abiertos (por si hubo olvido de cierre ayer)
        
        $stmt = $pdo->prepare($sql_sistema);
        $stmt->execute();
        $sistema = $stmt->fetch(PDO::FETCH_ASSOC);

        $ing_efectivo = (float)$sistema['ingresos_efectivo'];
        $ing_transf = (float)$sistema['ingresos_transf'];
        $egresos = (float)$sistema['egresos'];
        
        $saldo_esperado = $ing_efectivo - $egresos;
        
        // 3. Calcular Diferencia
        $diferencia = $saldo_real_efectivo - $saldo_esperado;

        // 4. Insertar en la tabla cierres_caja
        $sql_ins = "INSERT INTO cierres_caja 
                    (fecha_cierre, ingresos_efectivo, ingresos_transf, egresos, saldo_esperado_efectivo, saldo_real_efectivo, diferencia, fondo_reservado_vuelto, observaciones, usuario) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
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
            $usuario
        ]);

        // 5. Marcar movimientos actuales como "CERRADOS"
        $sql_update = "UPDATE movimiento SET cerrado = 1 WHERE cerrado = 0";
        $pdo->prepare($sql_update)->execute();

        // 6. GENERAR EL FONDO INICIAL PARA EL DÍA SIGUIENTE
        // Este movimiento aparecerá mañana en el dashboard como el primer ingreso
        if ($fondo_vuelto_manana > 0) {
            $mañana = date('Y-m-d 07:00:00', strtotime('+1 day'));
            $sql_fondo = "INSERT INTO movimiento (tipo, monto, metodo_pago, detalle, fecha, cerrado) 
                          VALUES ('INGRESO', ?, 'EFECTIVO', 'FONDO INICIAL (VUELTO)', ?, 0)";
            $pdo->prepare($sql_fondo)->execute([$fondo_vuelto_manana, $mañana]);
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