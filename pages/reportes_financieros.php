<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php'); 
    exit();
}

require '../config/db_config.php'; 

$fecha_inicio = isset($_GET['fecha_inicio']) ? htmlspecialchars($_GET['fecha_inicio']) : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? htmlspecialchars($_GET['fecha_fin']) : date('Y-m-d');
$reporte_utilidad = [];
$total_ingresos = 0;
$total_costos = 0;
$total_utilidad = 0;
$mensaje_error = '';

// -----------------------------------------------------
// 1. LÓGICA: REPORTE DE UTILIDAD BRUTA (Ventas - Costo)
// -----------------------------------------------------
try {
    // Para calcular la utilidad bruta necesitamos:
    // a) El precio de venta total de cada línea de venta.
    // b) El costo de la mercadería vendida (CMV). Asumimos que el costo es p_compra.
    
    // NOTA IMPORTANTE: Para una venta real, el costo (CMV) debería ser el p_compra 
    // que estaba vigente en la tabla 'productos' en la FECHA de la venta.
    // Asumimos que tu tabla 'ventas_detalle' registra el costo histórico de cada producto
    // en el momento de la venta (p_costo_venta o similar).
    
    // SI TU TABLA ventas_detalle NO REGISTRA EL COSTO HISTÓRICO, este cálculo será INEXACTO.
    // Usaremos un campo supuesto: 'costo_cmv' en ventas_detalle. Si tienes otro nombre, ajústalo.

    // =========================================================================
    // 🛑 CORRECCIÓN APLICADA AQUÍ: Se cambió 'vd.costo_cmv' por 'vd.p_costo_venta'
    // =========================================================================
    $sql_utilidad = "
        SELECT 
            vd.cod_prod,
            vd.descripcion,
            SUM(vd.total_linea) AS total_venta,
            SUM(vd.cant * vd.p_costo_venta) AS total_costo, /* ¡CORREGIDO! Usando p_costo_venta */
            (SUM(vd.total_linea) - SUM(vd.cant * vd.p_costo_venta)) AS utilidad_bruta_linea
        FROM 
            ventas_detalle vd
        JOIN 
            ventas v ON vd.n_documento = v.n_documento
        WHERE 
            v.fecha_venta BETWEEN :fecha_inicio AND :fecha_fin_inclusive
        GROUP BY 
            vd.cod_prod, vd.descripcion
        ORDER BY 
            utilidad_bruta_linea DESC";
    
    // =========================================================================

    $stmt_utilidad = $pdo->prepare($sql_utilidad);
    
    // Ajuste de fecha fin para incluir todo el día
    $fecha_fin_inclusive = $fecha_fin . ' 23:59:59'; 
    
    $stmt_utilidad->execute([
        ':fecha_inicio' => $fecha_inicio,
        ':fecha_fin_inclusive' => $fecha_fin_inclusive
    ]);
    
    $reporte_utilidad = $stmt_utilidad->fetchAll(PDO::FETCH_ASSOC);

    // Calcular Totales Globales
    foreach ($reporte_utilidad as $item) {
        $total_ingresos += (float)$item['total_venta'];
        $total_costos += (float)$item['total_costo'];
    }
    $total_utilidad = $total_ingresos - $total_costos;

} catch (Exception $e) {
    error_log("Error al generar Reporte de Utilidad Bruta: " . $e->getMessage());
    $mensaje_error = "❌ Error: No se pudo generar el reporte de utilidad. Revise la estructura de la tabla 'ventas_detalle' y el campo 'p_costo_venta'.";
}
// -----------------------------------------------------
// 2. LÓGICA: REPORTE DE FLUJO DE EFECTIVO (Flujo de Cobros y Pagos)
// -----------------------------------------------------

// NOTA: Para este reporte, se asume que las tablas ctacte_clientes y ctacte_proveedores 
// registran movimientos de 'PAGO' y 'COBRO' respectivamente.
$reporte_flujo = [
    'cobros' => 0, 
    'pagos' => 0,
    'neto' => 0
];

try {
    // A) Cobros (Entradas - Columna DEBE en Cta. Cte. Clientes, donde se registran los pagos)
    $sql_cobros = "
        SELECT 
            SUM(debe) AS total_cobros 
        FROM 
            ctacte_clientes
        WHERE 
            movimiento LIKE 'COBRO%' AND fecha BETWEEN :f_ini AND :f_fin_inc";
    
    $stmt_cobros = $pdo->prepare($sql_cobros);
    $stmt_cobros->execute([':f_ini' => $fecha_inicio, ':f_fin_inc' => $fecha_fin_inclusive]);
    $reporte_flujo['cobros'] = (float)$stmt_cobros->fetchColumn() ?: 0;
    
    // B) Pagos (Salidas - Columna DEBE en Cta. Cte. Proveedores, donde se registran los pagos)
    $sql_pagos = "
        SELECT 
            SUM(debe) AS total_pagos 
        FROM 
            ctacte_proveedores
        WHERE 
            movimiento LIKE 'PAGO%' AND fecha BETWEEN :f_ini AND :f_fin_inc";

    $stmt_pagos = $pdo->prepare($sql_pagos);
    $stmt_pagos->execute([':f_ini' => $fecha_inicio, ':f_fin_inc' => $fecha_fin_inclusive]);
    $reporte_flujo['pagos'] = (float)$stmt_pagos->fetchColumn() ?: 0;
    
    $reporte_flujo['neto'] = $reporte_flujo['cobros'] - $reporte_flujo['pagos'];
    
} catch (Exception $e) {
    error_log("Error al generar Reporte de Flujo: " . $e->getMessage());
    // Se mantiene el flujo de utilidad, este es un error secundario.
}


?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Financieros</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .reporte-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .totales-utilidad { font-size: 1.2em; padding: 15px; border-radius: 8px; margin-top: 15px; }
        .flujo-caja-resumen { padding: 15px; border-radius: 8px; }
        .flujo-entrada { color: #4CAF50; } /* Verde para entradas */
        .flujo-salida { color: #F44336; } /* Rojo para salidas */
        .flujo-neto { font-weight: bold; font-size: 1.5em; }
        .flujo-positivo { color: #4CAF50; }
        .flujo-negativo { color: #F44336; }
        .flujo-cero { color: #aaa; }
    </style>
</head>
<body>

    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 
    
    <div class="content">
        <h1>📈 Reportes Financieros y Rentabilidad</h1>
        
        <?php if (!empty($mensaje_error)): ?>
            <div class="alert alert-error"><?php echo $mensaje_error; ?></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom: 20px;">
            <form method="GET" action="reportes_financieros.php" style="display: flex; gap: 15px; align-items: flex-end;">
                <div>
                    <label for="fecha_inicio">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="input-field" value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                </div>
                <div>
                    <label for="fecha_fin">Fecha Fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="input-field" value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary">Generar Reportes</button>
            </form>
        </div>
        
        <div class="reporte-grid">
            
            <div class="card">
                <h2>1. Utilidad Bruta por Producto 

[Image of formula for Gross Profit]
</h2>
                <small>Periodo: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></small>
                
                <table style="width: 100%; margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Código</th>
                            <th>Descripción</th>
                            <th class="text-right" style="width: 18%;">Ventas Netas ($)</th>
                            <th class="text-right" style="width: 18%;">Costo CMV ($)</th>
                            <th class="text-right" style="width: 18%;">Utilidad Bruta ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reporte_utilidad)): ?>
                            <tr><td colspan="5" class="text-center">No hay ventas registradas en el periodo seleccionado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reporte_utilidad as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['cod_prod']); ?></td>
                                    <td><?php echo htmlspecialchars($item['descripcion']); ?></td>
                                    <td class="text-right">$<?php echo number_format($item['total_venta'], 2, ',', '.'); ?></td>
                                    <td class="text-right">$<?php echo number_format($item['total_costo'], 2, ',', '.'); ?></td>
                                    <td class="text-right">$<?php echo number_format($item['utilidad_bruta_linea'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <div class="totales-utilidad" style="background-color: #333;">
                    <p><strong>TOTAL INGRESOS:</strong> <span class="flujo-entrada">$<?php echo number_format($total_ingresos, 2, ',', '.'); ?></span></p>
                    <p><strong>TOTAL COSTOS (CMV):</strong> <span class="flujo-salida">$<?php echo number_format($total_costos, 2, ',', '.'); ?></span></p>
                    <hr>
                    <p class="flujo-neto">
                        UTILIDAD BRUTA TOTAL: 
                        <span class="<?php echo ($total_utilidad >= 0) ? 'flujo-positivo' : 'flujo-negativo'; ?>">
                            $<?php echo number_format($total_utilidad, 2, ',', '.'); ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <div class="card">
                <h2>2. Flujo de Efectivo (Cobros y Pagos) 

[Image of Cash Flow Diagram]
</h2>
                
                <div class="flujo-caja-resumen" style="background-color: #333;">
                    <p>➡️ **Cobros de Clientes (Entrada):** <span class="flujo-entrada" style="float: right;">
                                $<?php echo number_format($reporte_flujo['cobros'], 2, ',', '.'); ?>
                            </span>
                    </p>
                    <p>⬅️ **Pagos a Proveedores (Salida):** <span class="flujo-salida" style="float: right;">
                                $<?php echo number_format($reporte_flujo['pagos'], 2, ',', '.'); ?>
                            </span>
                    </p>
                    <hr>
                    <p class="flujo-neto">
                        NETO OPERATIVO:
                        <?php 
                            $clase_neto = 'flujo-cero';
                            if ($reporte_flujo['neto'] > 0) $clase_neto = 'flujo-positivo';
                            if ($reporte_flujo['neto'] < 0) $clase_neto = 'flujo-negativo';
                        ?>
                        <span class="<?php echo $clase_neto; ?>" style="float: right;">
                            $<?php echo number_format($reporte_flujo['neto'], 2, ',', '.'); ?>
                        </span>
                    </p>
                </div>
            </div>

        </div> </div>
    
</body>
<script src="../js/global.js"></script> 
</html>