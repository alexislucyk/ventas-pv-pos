<?php
include 'infosesion.php';
// VALIDACIÓN CRÍTICA:
require_once '../config/validar_permisos.php';
//restringirPagina('developer'); // Solo desarrolladores pueden acceder a este reporte financiero avanzado
date_default_timezone_set('America/Argentina/Buenos_Aires');

require '../config/db_config.php'; 

$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$fecha_fin_inclusive = $fecha_fin . ' 23:59:59';

$reporte_utilidad = [];
$total_ingresos_ventas = 0;
$total_costos_cmv = 0;
$mensaje_error = '';

// Resumen de Caja Real
$caja_real = [
    'ventas_contado' => 0,
    'cobros_ctacte' => 0,
    'pagos_proveedores' => 0,
    'total_entrada' => 0,
    'neto_caja' => 0
];

try {
    // 1. UTILIDAD BRUTA (Ventas - Costo Histórico)
    $sql_utilidad = "
        SELECT 
            vd.cod_prod,
            vd.descripcion,
            SUM(vd.total) AS total_venta,
            SUM(vd.cant * vd.p_costo_venta) AS total_costo,
            (SUM(vd.total) - SUM(vd.cant * vd.p_costo_venta)) AS utilidad_bruta_linea
        FROM ventas_detalle vd
        JOIN ventas v ON vd.n_documento = v.n_documento
        WHERE v.fecha_venta BETWEEN :f1 AND :f2
          AND v.estado = 'Finalizada'
        GROUP BY vd.cod_prod, vd.descripcion
        ORDER BY utilidad_bruta_linea DESC";

    $stmt = $pdo->prepare($sql_utilidad);
    $stmt->execute([':f1' => $fecha_inicio, ':f2' => $fecha_fin_inclusive]);
    $reporte_utilidad = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reporte_utilidad as $item) {
        $total_ingresos_ventas += (float)$item['total_venta'];
        $total_costos_cmv += (float)$item['total_costo'];
    }

    // 2. FLUJO DE CAJA (Dinero Tangible)
    
    // A) Ventas al contado (Efectivo + Transferencia en el momento)
    $sql_contado = "SELECT SUM(pago_efectivo + pago_transf) FROM ventas 
                    WHERE fecha_venta BETWEEN :f1 AND :f2 AND estado = 'Finalizada'";
    $stmt_contado = $pdo->prepare($sql_contado);
    $stmt_contado->execute([':f1' => $fecha_inicio, ':f2' => $fecha_fin_inclusive]);
    $caja_real['ventas_contado'] = (float)$stmt_contado->fetchColumn() ?: 0;

    // B) Cobros de Cuentas Corrientes (Clientes pagando deudas)
    // En tu tabla 'ctacte', el dinero que ENTRA es lo que se registra en HABER
    $sql_cobros = "SELECT SUM(haber) FROM ctacte 
                   WHERE movimiento LIKE 'PAGO%' AND fecha BETWEEN :f1 AND :f2";
    $stmt_cobros = $pdo->prepare($sql_cobros);
    $stmt_cobros->execute([':f1' => $fecha_inicio, ':f2' => $fecha_fin_inclusive]);
    $caja_real['cobros_ctacte'] = (float)$stmt_cobros->fetchColumn() ?: 0;

    // C) Pagos a Proveedores
    $sql_pagos = "SELECT SUM(debe) FROM ctacte_proveedores 
                  WHERE movimiento LIKE 'PAGO%' AND fecha BETWEEN :f1 AND :f2";
    $stmt_pagos = $pdo->prepare($sql_pagos);
    $stmt_pagos->execute([':f1' => $fecha_inicio, ':f2' => $fecha_fin_inclusive]);
    $caja_real['pagos_proveedores'] = (float)$stmt_pagos->fetchColumn() ?: 0;

    // Cálculos Finales de Caja
    $caja_real['total_entrada'] = $caja_real['ventas_contado'] + $caja_real['cobros_ctacte'];
    $caja_real['neto_caja'] = $caja_real['total_entrada'] - $caja_real['pagos_proveedores'];

} catch (Exception $e) {
    $mensaje_error = "❌ Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .reporte-container { display: flex; flex-wrap: wrap; gap: 20px; }
        .col-main { flex: 2; min-width: 600px; }
        .col-side { flex: 1; min-width: 300px; }
        .card-stats { padding: 20px; border-radius: 10px; background: #1a1a1a; border-left: 5px solid #007bff; margin-bottom: 15px; }
        .text-success { color: #2ecc71; }
        .text-danger { color: #e74c3c; }
        .text-info { color: #3498db; }
        .big-number { font-size: 1.8em; font-weight: bold; display: block; margin-top: 10px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <h1>📈 Análisis Financiero</h1>

        <div class="card" style="margin-bottom: 25px;">
            <form method="GET" style="display: flex; gap: 20px; align-items: center;">
                <input type="date" name="fecha_inicio" class="input-field" value="<?php echo $fecha_inicio; ?>">
                <input type="date" name="fecha_fin" class="input-field" value="<?php echo $fecha_fin; ?>">
                <button type="submit" class="btn btn-primary">Actualizar Informe</button>
            </form>
        </div>

        <div class="reporte-container">
            <div class="col-main">
                <div class="card">
                    <h2>Rentabilidad por Producto</h2>
                    <table class="table-full">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-right">Venta</th>
                                <th class="text-right">Costo (CMV)</th>
                                <th class="text-right">Utilidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporte_utilidad as $i): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($i['descripcion']); ?></td>
                                <td class="text-right">$<?php echo number_format((float)($i['total_venta'] ?? 0), 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format((float)($i['total_costo'] ?? 0), 2, ',', '.'); ?></td>
                                <td class="text-right text-success"><strong>$<?php echo number_format((float)($i['utilidad_bruta_linea'] ?? 0), 2, ',', '.'); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-side">
                <div class="card-stats">
                    <span>Ventas Mostrador (Hoy)</span>
                    <span class="big-number text-info">$<?php echo number_format((float)($caja_real['ventas_contado'] ?? 0), 2, ',', '.'); ?></span>
                </div>
                <div class="card-stats">
                    <span>Cobros de Deudas (Cta. Cte.)</span>
                    <span class="big-number text-success">$<?php echo number_format((float)($caja_real['cobros_ctacte'] ?? 0), 2, ',', '.'); ?></span>
                </div>
                <div class="card-stats" style="border-left-color: #e74c3c;">
                    <span>Pagos a Proveedores</span>
                    <span class="big-number text-danger">$<?php echo number_format((float)($caja_real['pagos_proveedores'] ?? 0), 2, ',', '.'); ?></span>
                </div>
                <div class="card" style="background: #222; border: 1px solid #444;">
                    <h3>Saldo Neto en Caja</h3>
                    <p class="big-number <?php echo $caja_real['neto_caja'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        $<?php echo number_format((float)($caja_real['neto_caja'] ?? 0), 2, ',', '.'); ?>
                    </p>
                    <small>Este es el dinero real disponible.</small>
                </div>
                
                <div class="card" style="margin-top: 20px; background: #002b36;">
                    <h3>Ganancia Proyectada</h3>
                    <p>Utilidad Bruta: <br><strong>$<?php echo number_format((float)($total_ingresos_ventas - $total_costos_cmv), 2, ',', '.'); ?></strong></p>
                    <small>(Incluso lo no cobrado aún)</small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>