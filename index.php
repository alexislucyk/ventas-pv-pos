<?php
include 'pages/infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$hoy = date('Y-m-d');
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre'] ?? '');
$rol = htmlspecialchars($_SESSION['usuario_rol'] ?? '');

$vencimientos_prov = [];

try {
    $sql_efectivo = "SELECT SUM(total_venta) as total FROM ventas 
                     WHERE DATE(fecha_venta) = ? 
                     AND estado != 'Anulada' 
                     AND (cond_pago = 'Contado' OR cond_pago = 'Transferencia')
                     AND empresa_id = ?";
    $stmt_efectivo = $pdo->prepare($sql_efectivo);
    $stmt_efectivo->execute([$hoy, $empresa_id]);
    $result = $stmt_efectivo->fetch(PDO::FETCH_ASSOC);
    $total_contado = isset($result['total']) ? $result['total'] : 0;

    $sql_ctacte = "SELECT SUM(total_venta) as total FROM ventas 
                   WHERE DATE(fecha_venta) = ? 
                   AND estado != 'Anulada' 
                   AND cond_pago = 'Cuenta Corriente'
                   AND empresa_id = ?";
    $stmt_ctacte = $pdo->prepare($sql_ctacte);
    $stmt_ctacte->execute([$hoy, $empresa_id]);
    $result = $stmt_ctacte->fetch(PDO::FETCH_ASSOC);
    $total_ctacte = isset($result['total']) ? $result['total'] : 0;

    $sql_cant = "SELECT COUNT(*) as cantidad FROM ventas WHERE DATE(fecha_venta) = ? AND estado != 'Anulada' AND empresa_id = ?";
    $stmt_cant = $pdo->prepare($sql_cant);
    $stmt_cant->execute([$hoy, $empresa_id]);
    $result = $stmt_cant->fetch(PDO::FETCH_ASSOC);
    $cant_ventas = isset($result['cantidad']) ? $result['cantidad'] : 0;

    $sql_stock = "SELECT COUNT(*) FROM productos WHERE stock <= 2 AND empresa_id = ?";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([$empresa_id]);
    $stock_critico = $stmt_stock->fetchColumn();

    $sql_presup = "SELECT COUNT(*) FROM presupuestos WHERE DATE(fecha_presupuesto) = ? AND estado = 'Pendiente' AND empresa_id = ?";
    $stmt_presup = $pdo->prepare($sql_presup);
    $stmt_presup->execute([$hoy, $empresa_id]);
    $presup_pendientes = $stmt_presup->fetchColumn();

    $sql_top = "SELECT vd.cod_prod, vd.descripcion, SUM(vd.cant) as total_cant, p.stock 
                FROM ventas_detalle vd
                JOIN ventas v ON (vd.n_documento = v.n_documento)
                JOIN productos p ON (vd.cod_prod = p.cod_prod COLLATE utf8mb4_unicode_ci)
                WHERE v.estado = 'Finalizada' COLLATE utf8mb4_unicode_ci
                  AND v.empresa_id = :empresa_id
                GROUP BY vd.cod_prod, vd.descripcion, p.stock
                ORDER BY total_cant DESC
                LIMIT 10";
    $stmt_top = $pdo->prepare($sql_top);
    $stmt_top->execute([':empresa_id' => $empresa_id]);
    $top_productos = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

    $stmt_ult = $pdo->prepare("SELECT n_documento, total_venta, cond_pago, usuario FROM ventas WHERE estado = 'Finalizada' AND empresa_id = ? ORDER BY id DESC LIMIT 5");
    $stmt_ult->execute([$empresa_id]);
    $ultimas_ventas = $stmt_ult->fetchAll(PDO::FETCH_ASSOC);

    $ventas_semana = [];
    $labels_semana = [];
    for ($i = 6; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $labels_semana[] = date('d/m', strtotime($fecha));
        
        $sql_dia = "SELECT SUM(total_venta) as total FROM ventas WHERE DATE(fecha_venta) = ? AND estado != 'Anulada' AND empresa_id = ?";
        $stmt_dia = $pdo->prepare($sql_dia);
        $stmt_dia->execute([$fecha, $empresa_id]);
        $res_dia = $stmt_dia->fetch(PDO::FETCH_ASSOC);
        $ventas_semana[] = $res_dia['total'] ? (float)$res_dia['total'] : 0;
    }

    // 9. Próximos Vencimientos a Proveedores (Solo facturas a CRÉDITO con saldo pendiente)
    $sql_vencimientos = "SELECT 
                            c.n_documento, 
                            p.razon as proveedor, 
                            c.fecha_vencimiento, 
                            c.total_compra,
                            (c.total_compra - COALESCE(SUM(cc.debe), 0)) as saldo
                         FROM compras c
                         JOIN proveedores p ON c.cod_proveedor = p.cod_prov
                         LEFT JOIN ctacte_proveedores cc ON c.n_documento = cc.n_documento AND c.cod_proveedor = cc.id_proveedor AND cc.empresa_id = ?
                         WHERE c.cond_pago = 'CRÉDITO'
                           AND c.empresa_id = ?
                         GROUP BY c.id, c.n_documento, p.razon, c.fecha_vencimiento, c.total_compra
                         HAVING saldo > 0
                         ORDER BY c.fecha_vencimiento ASC
                         LIMIT 10";
    $stmt_venc = $pdo->prepare($sql_vencimientos);
    $stmt_venc->execute([$empresa_id, $empresa_id]);
    $vencimientos_prov = $stmt_venc->fetchAll(PDO::FETCH_ASSOC);

    // 10. Deuda a Proveedores Vencida
    // Calculamos el saldo de cada proveedor y restamos las facturas que aún no vencieron.
    $sql_vencido_prov = "
        SELECT SUM(CASE WHEN (saldo_prov - COALESCE(no_vencido_prov, 0)) > 0 
                        THEN (saldo_prov - COALESCE(no_vencido_prov, 0)) 
                        ELSE 0 END) as total_vencido
        FROM (
            SELECT 
                cp.id_proveedor,
                SUM(cp.haber - cp.debe) as saldo_prov,
                (SELECT SUM(c.total_compra) FROM compras c 
                 WHERE c.cod_proveedor = cp.id_proveedor 
                 AND c.cond_pago = 'CRÉDITO' 
                 AND (c.fecha_vencimiento >= ? OR c.fecha_vencimiento IS NULL)
                 AND c.empresa_id = ?
                ) as no_vencido_prov
            FROM ctacte_proveedores cp
            WHERE cp.empresa_id = ?
            GROUP BY cp.id_proveedor
        ) as calculo";
    $stmt_v_prov = $pdo->prepare($sql_vencido_prov);
    $stmt_v_prov->execute([$hoy, $empresa_id, $empresa_id]);
    $deuda_vencida_prov = (float)($stmt_v_prov->fetchColumn() ?: 0);

} catch (PDOException $e) {
    $error_db = "Error en el Dashboard: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-card: #1e1e1e;
            --accent: #00bcd4;
            --text-muted: #888;
        }

        body { 
            background-color: #121212; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: #e0e0e0;
            margin: 0;
        }

        .content { padding: 20px; }

        .welcome-banner {
            margin-bottom: 30px;
            border-left: 4px solid var(--accent);
            padding-left: 20px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 25px;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #333;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.5);
            border-color: #555;
        }

        /* Iconos "Ghost" de fondo */
        .stat-card .icon-bg {
            position: absolute;
            right: -15px;
            bottom: -15px;
            font-size: 5.5rem;
            opacity: 0.05;
            color: #fff;
            transform: rotate(-15deg);
        }

        .stat-card h3 {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 800;
            margin: 15px 0 5px 0;
            color: #fff;
        }

        .stat-card .footer {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
        }

        /* Colores por categoría */
        .card-green { border-top: 4px solid #4CAF50; }
        .card-green .footer { color: #4CAF50; }

        .card-orange { border-top: 4px solid #FF9800; }
        .card-orange .footer { color: #FF9800; }

        .card-blue { border-top: 4px solid #2196F3; }
        .card-blue .footer { color: #2196F3; }

        .card-red { border-top: 4px solid #F44336; }
        .card-red .footer { color: #F44336; }

        .card-purple { border-top: 4px solid #9C27B0; }
        .card-purple .footer { color: #9C27B0; }

        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .secondary-grid { grid-template-columns: 1fr !important; }
        }

        /* Accesos Rápidos Mejorados */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .action-btn {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: var(--bg-card);
            border: 1px solid #333;
            border-radius: 12px;
            text-decoration: none;
            color: #fff;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        }
        .action-btn:hover {
            transform: translateY(-3px);
            background: #252525;
            box-shadow: 0 8px 15px rgba(0,0,0,0.4);
        }
        .action-btn i {
            font-size: 1.4rem;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .action-btn span { font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .btn-venta i { color: #4CAF50; background: rgba(76, 175, 80, 0.1); }
        .btn-venta:hover { border-color: #4CAF50; }
        .btn-cc i { color: #FF9800; background: rgba(255, 152, 0, 0.1); }
        .btn-cc:hover { border-color: #FF9800; }
        .btn-compra i { color: #2196F3; background: rgba(33, 150, 243, 0.1); }
        .btn-compra:hover { border-color: #2196F3; }
        .btn-precio i { color: var(--accent); background: rgba(0, 188, 212, 0.1); }
        .btn-precio:hover { border-color: var(--accent); }

        /* Contenedor del Gráfico y Tablas secundarias */
        .chart-container { background: var(--bg-card); padding: 25px; border-radius: 12px; border: 1px solid #333; margin: 30px 0; }
        .secondary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; margin-top: 30px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .data-table th { text-align: left; color: var(--accent); padding: 10px; border-bottom: 1px solid #333; text-transform: uppercase; font-size: 0.7rem; }
        .data-table td { padding: 10px; border-bottom: 1px solid #252525; }
        .text-accent { color: var(--accent); font-weight: bold; }
    </style>
    <style>
        /* Parche para ocultar scrollbars nativos */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #121212; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #444; }
    </style>
</head>
<body>

<?php include 'pages/sidebar.php'; ?>

<div class="content">
    <?php if (isset($error_db)): ?>
        <div style="background: #b71c1c; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_db; ?>
        </div>
    <?php endif; ?>

    <div class="welcome-banner">
        <h1 style="font-weight: 300; margin: 0;">Panel <span style="color: var(--accent); font-weight: 700;">Principal</span></h1>
        <p style="color: var(--text-muted); margin: 5px 0;">Resumen del día: <?php echo date('d/m/Y'); ?> | Usuario: <strong><?php echo $nombre_usuario; ?></strong></p>
    </div>

    <div class="quick-actions">
        <a href="pages/ventas.php" class="action-btn btn-venta"><i class="fas fa-shopping-cart"></i><span>Nueva Venta</span></a>
        <a href="pages/cuentas_corrientes.php" class="action-btn btn-cc"><i class="fas fa-users"></i><span>Clientes CC</span></a>
        <a href="pages/compras.php" class="action-btn btn-compra"><i class="fas fa-truck-loading"></i><span>Cargar Compra</span></a>
        <a href="pages/consulta_precios.php" class="action-btn btn-precio"><i class="fas fa-search-dollar"></i><span>Consultar Precio</span></a>
        <a href="pages/reporte_cuotas.php" class="action-btn" style="border-color: #ff5252;"><i class="fas fa-hand-holding-usd" style="color: #ff5252; background: rgba(255, 82, 82, 0.1);"></i><span>Cuentas a Cobrar</span></a>
        <a href="pages/consignacion_reporte.php" class="action-btn" style="border-color: #9C27B0;"><i class="fas fa-clipboard-list" style="color: #9C27B0; background: rgba(156, 39, 176, 0.1);"></i><span>Consignaciones</span></a>
    </div>

    <div class="dashboard-grid">
        
        <div class="stat-card card-green">
            <i class="fas fa-cash-register icon-bg"></i>
            <h3>Efectivo / Transf.</h3>
            <div class="value">$<?php echo number_format($total_contado, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-check-circle"></i> Cobros liquidados hoy</div>
        </div>

        <div class="stat-card card-orange">
            <i class="fas fa-file-invoice-dollar icon-bg"></i>
            <h3>Cuenta Corriente</h3>
            <div class="value" style="color: #FF9800;">$<?php echo number_format($total_ctacte, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-user-clock"></i> Pendiente de cobro</div>
        </div>

        <a href="pages/resumen_ventas.php" class="stat-card card-blue">
            <i class="fas fa-shopping-basket icon-bg"></i>
            <h3>Operaciones</h3>
            <div class="value"><?php echo $cant_ventas; ?></div>
            <div class="footer"><i class="fas fa-list-ul"></i> Ver detalles de ventas</div>
        </a>

        <a href="pages/inventario.php" class="stat-card card-red">
            <i class="fas fa-boxes icon-bg"></i>
            <h3>Stock Crítico</h3>
            <div class="value" style="color: #F44336;"><?php echo $stock_critico; ?></div>
            <div class="footer"><i class="fas fa-exclamation-triangle"></i> Items para reponer</div>
        </a>

        <a href="pages/presupuestos.php" class="stat-card card-purple">
            <i class="fas fa-calculator icon-bg"></i>
            <h3>Presupuestos</h3>
            <div class="value"><?php echo $presup_pendientes; ?></div>
            <div class="footer"><i class="fas fa-hourglass-start"></i> Pendientes de cierre</div>
        </a>

        <a href="pages/ctacte_proveedores.php" class="stat-card card-red">
            <i class="fas fa-exclamation-triangle icon-bg"></i>
            <h3>Deuda Prov. Vencida</h3>
            <div class="value" style="color: #F44336;">$<?php echo number_format($deuda_vencida_prov, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-calendar-times"></i> Pagos con plazo expirado</div>
        </a>

    </div>

    <div class="chart-container">
        <h3 style="margin: 0 0 20px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase;">
            <i class="fas fa-chart-line" style="color: var(--accent);"></i> Flujo de Ventas (Últimos 7 días)
        </h3>
        <canvas id="salesChart" style="max-height: 250px;"></canvas>
    </div>

    <div class="secondary-grid">
        <!-- TOP 10 PRODUCTOS -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-trophy" style="color: #f1c40f;"></i> Top 10 Más Vendidos</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th style="text-align: center;">Vendido</th>
                        <th style="text-align: center;">Stock Actual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($top_productos as $tp): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tp['descripcion']); ?></td>
                        <td style="text-align: center;" class="text-accent"><?php echo number_format($tp['total_cant'], 0); ?></td>
                        <td style="text-align: center;">
                            <span style="color: <?php echo $tp['stock'] <= 2 ? '#f44336' : '#888'; ?>">
                                <?php echo $tp['stock']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- VENCIMIENTOS PROVEEDORES -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-calendar-alt" style="color: #FF5252;"></i> Vencimientos Proveedores</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th style="text-align: center;">Vence</th>
                        <th style="text-align: right;">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($vencimientos_prov as $vp): 
                        $es_vencida = ($vp['fecha_vencimiento'] && $vp['fecha_vencimiento'] < $hoy);
                    ?>
                    <tr>
                        <td>
                            <small style="color: #666;">#<?php echo htmlspecialchars($vp['n_documento']); ?></small><br>
                            <strong><?php echo htmlspecialchars($vp['proveedor']); ?></strong>
                        </td>
                        <td style="text-align: center; <?php echo $es_vencida ? 'color: #F44336; font-weight: bold;' : ''; ?>">
                            <?php echo $vp['fecha_vencimiento'] ? date('d/m/y', strtotime($vp['fecha_vencimiento'])) : '---'; ?>
                        </td>
                        <td style="text-align: right;" class="text-accent">$<?php echo number_format($vp['total_compra'], 2, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($vencimientos_prov)): ?>
                        <tr><td colspan="3" style="text-align:center; padding: 20px; color: #666;">Sin deudas a crédito registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ÚLTIMAS VENTAS -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-history"></i> Últimas Ventas</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ultimas_ventas as $uv): ?>
                    <tr>
                        <td><small>#<?php echo $uv['n_documento']; ?></small> <br> <span style="font-size: 0.7rem; color: #666;"><?php echo $uv['usuario']; ?></span></td>
                        <td style="text-align: right;" class="text-accent">$<?php echo number_format($uv['total_venta'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels_semana); ?>,
                datasets: [{
                    label: 'Ventas ($)',
                    data: <?php echo json_encode($ventas_semana); ?>,
                    borderColor: '#00bcd4',
                    backgroundColor: 'rgba(0, 188, 212, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#00bcd4'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#333' },
                        ticks: { 
                            color: '#aaa',
                            callback: function(value) { return '$' + value.toLocaleString('es-AR'); }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#aaa' }
                    }
                }
            }
        });
    });
</script>
</body>
</html>