<?php
// reparar_caja_total.php
include 'infosesion.php'; // Para asegurar que estás logueado
require_once '../config/validar_permisos.php';
restringirPagina('developer'); // Solo el rol 'developer' puede ejecutar este script
require '../config/db_config.php';

// Configurar zona horaria por las dudas
date_default_timezone_set('America/Argentina/Buenos_Aires');

echo "<body style='background:#1a1a1a; color:#eee; font-family:sans-serif; padding:20px;'>";
echo "<h2>🛠️ Procesando Regularización de Caja</h2>";

try {
    // Sugerencia: Podrías verificar si ya existen movimientos para evitar duplicados accidentales
    // 1. Opcional: Limpiar la tabla de movimientos (ya que mencionaste que quieres ordenarla de cero)
    // $pdo->exec("TRUNCATE TABLE movimientos");
    // echo "<p style='color:#f1c40f;'>> Tabla movimientos vaciada.</p>";

    $pdo->beginTransaction();

    // 2. Traemos TODAS las ventas finalizadas ordenadas por fecha
    $sql = "SELECT * FROM ventas WHERE estado = 'Finalizada' ORDER BY fecha_venta ASC";
    $stmt = $pdo->query($sql);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ventas)) {
        throw new Exception("No se encontraron ventas finalizadas para procesar.");
    }

    $insertados = 0;
    $total_dinero = 0;

    foreach ($ventas as $v) {
        $n_doc     = $v['n_documento'];
        $fecha     = $v['fecha_venta'];
        $usuario   = $v['usuario'];
        $condicion = $v['cond_pago'];
        // Forzamos el parseo a float asegurando que el string de la DB (que viene con punto) no se altere
        $total_v   = floatval($v['total_venta']);
        $pago_efe  = floatval($v['pago_efectivo']);
        $pago_tra  = floatval($v['pago_transf']);
        
        $monto_ingresado = $pago_efe + $pago_tra;

        // --- LÓGICA DE MONTO PARA CAJA ---
        if ($condicion === 'CONTADO') {
            /** * Si pagó $1500 una venta de $1000, en caja solo entran $1000.
             * El min() asegura que si el pago fue menor al total (error de carga), 
             * tome el pago, pero si fue mayor, tome solo el total de la venta.
             */
            $monto_a_caja = min($monto_ingresado, $total_v);
            $detalle      = "VENTA CONTADO N° $n_doc";
        } else {
            /**
             * En CUENTA CORRIENTE, todo lo que entregó el cliente entra a caja 
             * como un pago a cuenta, sin importar si supera o no el total.
             */
            $monto_a_caja = $monto_ingresado;
            $detalle      = "ENTREGA/PAGO - VENTA N° $n_doc (CTA. CTE.)";
        }

        // Solo insertamos si realmente hubo movimiento de dinero (efectivo o transf)
        if ($monto_a_caja > 0) {
            // Determinar método de pago para el registro
            $metodo = 'EFECTIVO';
            if ($pago_efe > 0 && $pago_tra > 0) {
                $metodo = 'MIXTO';
            } elseif ($pago_tra > 0) {
                $metodo = 'TRANSFERENCIA';
            }

            $sql_ins = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) 
                        VALUES ('INGRESO', ?, ?, ?, ?, ?, 0)";
            
            $stmt_ins = $pdo->prepare($sql_ins);
            $stmt_ins->execute([
                $monto_a_caja, 
                $metodo, 
                $detalle, 
                $fecha, 
                $usuario
            ]);
            
            $insertados++;
            $total_dinero += $monto_a_caja;
        }
    }

    $pdo->commit();

    echo "<div style='background:#2ecc71; color:white; padding:15px; border-radius:8px; margin-top:20px;'>";
    echo "<h3>✅ Proceso Finalizado con Éxito</h3>";
    echo "<ul>";
    echo "<li><b>Ventas procesadas:</b> " . count($ventas) . "</li>";
    echo "<li><b>Registros en caja creados:</b> $insertados</li>";
    echo "<li><b>Total dinero regularizado:</b> $" . number_format($total_dinero, 2, ',', '.') . "</li>";
    echo "</ul>";
    echo "</div>";
    echo "<p>⚠️ <b>Recordatorio:</b> Borra este archivo (reparar_caja_total.php) de tu servidor ahora mismo.</p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div style='background:#e74c3c; color:white; padding:15px; border-radius:8px; margin-top:20px;'>";
    echo "<h3>❌ Error durante la ejecución</h3>";
    echo $e->getMessage();
    echo "</div>";
}

echo "</body>";