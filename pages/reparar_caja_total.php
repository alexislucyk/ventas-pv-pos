<?php
// reparar_caja_total.php
include 'infosesion.php'; // Para asegurar que estás logueado
require_once '../config/validar_permisos.php';
restringirPagina('developer'); // Solo el rol 'developer' puede ejecutar este script
require '../config/db_config.php';

// Configurar zona horaria por las dudas
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

echo "<body style='background:#1a1a1a; color:#eee; font-family:sans-serif; padding:20px;'>";
echo "<h2>🛠️ Procesando Regularización de Caja</h2>";

try {
    $pdo->beginTransaction();

    $sql = "SELECT * FROM ventas WHERE estado = 'Finalizada' AND empresa_id = :empresa_id ORDER BY fecha_venta ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
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
        $total_v   = floatval($v['total_venta']);
        $pago_efe  = floatval($v['pago_efectivo']);
        $pago_tra  = floatval($v['pago_transf']);
        
        $monto_ingresado = $pago_efe + $pago_tra;

        if ($condicion === 'CONTADO') {
            $monto_a_caja = min($monto_ingresado, $total_v);
            $detalle      = "VENTA CONTADO N° $n_doc";
        } else {
            $monto_a_caja = $monto_ingresado;
            $detalle      = "ENTREGA/PAGO - VENTA N° $n_doc (CTA. CTE.)";
        }

        if ($monto_a_caja > 0) {
            $metodo = 'EFECTIVO';
            if ($pago_efe > 0 && $pago_tra > 0) {
                $metodo = 'MIXTO';
            } elseif ($pago_tra > 0) {
                $metodo = 'TRANSFERENCIA';
            }

            $sql_ins = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) 
                        VALUES ('INGRESO', ?, ?, ?, ?, ?, 0, ?, ?)";
            $pdo->prepare($sql_ins)->execute([
                $monto_a_caja,
                $metodo,
                $detalle,
                $fecha,
                $usuario,
                $empresa_id,
                $sucursal_id
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