<?php
include 'pages/infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$hoy = date('Y-m-d');
require 'config/db_config.php';

$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre']);
$rol = htmlspecialchars($_SESSION['usuario_rol']);

try {
    // 1. Total Contado y Transferencias (Blindado con DATE para evitar fallos por hora)
    $sql_efectivo = "SELECT SUM(total_venta) as total FROM ventas 
                     WHERE DATE(fecha_venta) = ? 
                     AND estado != 'Anulada' 
                     AND (cond_pago = 'Contado' OR cond_pago = 'Transferencia')";
    $stmt_efectivo = $pdo->prepare($sql_efectivo);
    $stmt_efectivo->execute([$hoy]);
    $result = $stmt_efectivo->fetch(PDO::FETCH_ASSOC);
    $total_contado = isset($result['total']) ? $result['total'] : 0;

    // 2. Total Cuenta Corriente
    $sql_ctacte = "SELECT SUM(total_venta) as total FROM ventas 
                   WHERE DATE(fecha_venta) = ? 
                   AND estado != 'Anulada' 
                   AND cond_pago = 'Cuenta Corriente'";
    $stmt_ctacte = $pdo->prepare($sql_ctacte);
    $stmt_ctacte->execute([$hoy]);
    $result = $stmt_ctacte->fetch(PDO::FETCH_ASSOC);
    $total_ctacte = isset($result['total']) ? $result['total'] : 0;

    // 3. Cantidad de ventas
    $sql_cant = "SELECT COUNT(*) as cantidad FROM ventas WHERE DATE(fecha_venta) = ? AND estado != 'Anulada'";
    $stmt_cant = $pdo->prepare($sql_cant);
    $stmt_cant->execute([$hoy]);
    $result = $stmt_cant->fetch(PDO::FETCH_ASSOC);
    $cant_ventas = isset($result['cantidad']) ? $result['cantidad'] : 0;

    // 4. Stock Crítico (Productos con stock <= 2)
    $sql_stock = "SELECT COUNT(*) FROM productos WHERE stock <= 2";
    $stock_critico = $pdo->query($sql_stock)->fetchColumn();

    // 5. Presupuestos Pendientes
    $sql_presup = "SELECT COUNT(*) FROM presupuestos WHERE DATE(fecha_presupuesto) = ? AND estado = 'Pendiente'";
    $stmt_presup = $pdo->prepare($sql_presup);
    $stmt_presup->execute([$hoy]);
    $presup_pendientes = $stmt_presup->fetchColumn();

} catch (PDOException $e) {
    $error_db = "Error en el Dashboard: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | Electricidad Lucyk</title>
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
        }
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

        <a href="pages/ventas_historial.php" class="stat-card card-blue">
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

    </div>
</div>

</body>
</html>