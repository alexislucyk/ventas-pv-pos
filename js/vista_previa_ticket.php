<?php
include 'infosesion.php';
require '../config/db_config.php';

$n_doc = $_GET['n_documento'] ?? 0;

// 1. Obtener datos de la venta y cliente
$stmt = $pdo->prepare("SELECT v.*, c.apellido, c.nombre, c.cuit, c.dni FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.n_documento = ?");
$stmt->execute([$n_doc]);
$venta = $stmt->fetch();

if (!$venta) die("Venta no encontrada.");

// 2. Obtener detalle de productos
$stmt_d = $pdo->prepare("SELECT * FROM ventas_detalle WHERE n_documento = ?");
$stmt_d->execute([$n_doc]);
$detalles = $stmt_d->fetchAll();

// 3. Obtener datos de la empresa
$empresa_id = $_SESSION['empresa_id'] ?? 1;
$stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
$stmt_emp->execute([$empresa_id]);
$emp = $stmt_emp->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket N° <?php echo $n_doc; ?></title>
    <link rel="stylesheet" href="../css/temptocketprint.css">
    <style>
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print();">
    <div class="no-print" style="background: #333; padding: 10px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">Imprimir Ticket</button>
    </div>

    <div id="ticket-vista-previa">
        <div class="center-text">
            <h3><?php echo strtoupper($emp['nombre_fantasia']); ?></h3>
            <p><?php echo $emp['direccion']; ?></p>
            <p><?php echo $emp['localidad']; ?></p>
            <p>CUIT: <?php echo $emp['cuit']; ?></p>
        </div>

        <div class="sep"></div>
        <p>FECHA: <?php echo date('d/m/Y H:i', strtotime($venta['fecha_venta'])); ?></p>
        <p>TICKET N°: <?php echo str_pad($venta['n_documento'], 8, '0', STR_PAD_LEFT); ?></p>
        <p>CLIENTE: <?php echo $venta['apellido'] ? ($venta['apellido'] . ' ' . $venta['nombre']) : 'Consumidor Final'; ?></p>
        <div class="sep"></div>

        <?php 
        $total_bruto_acumulado = 0;
        foreach ($detalles as $item): 
            $subtotal_item_bruto = $item['p_unit'] * $item['cant'];
            $total_bruto_acumulado += $subtotal_item_bruto;
        ?>
            <div class="line">
                <span><?php echo $item['descripcion']; ?></span>
            </div>
            <div class="line detail-line">
                <span><?php echo (float)$item['cant']; ?> x $<?php echo number_format($item['p_unit'], 2); ?></span>
                <span>$<?php echo number_format($subtotal_item_bruto, 2); ?></span>
            </div>
            <?php if ($item['descuento_unitario'] > 0): 
                $ahorro_item = $subtotal_item_bruto * ($item['descuento_unitario'] / 100);
            ?>
                <div class="line detail-line discount-text">
                    <span>Descuento (<?php echo (float)$item['descuento_unitario']; ?>%)</span>
                    <span>-$<?php echo number_format($ahorro_item, 2); ?></span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="sep"></div>
        
        <div class="line">
            <span>Subtotal:</span>
            <span>$<?php echo number_format($total_bruto_acumulado, 2); ?></span>
        </div>

        <?php 
        // Cálculo de descuentos totales para el resumen
        $total_ahorro_items = 0;
        foreach($detalles as $d) { $total_ahorro_items += (($d['p_unit'] * $d['cant']) * ($d['descuento_unitario'] / 100)); }
        
        if ($total_ahorro_items > 0): ?>
            <div class="line discount-text">
                <span>Descuento por productos:</span>
                <span>-$<?php echo number_format($total_ahorro_items, 2); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($venta['descuento_global'] > 0): ?>
            <div class="line discount-text">
                <span>Descuento Global:</span>
                <span>-$<?php echo number_format($venta['descuento_global'], 2); ?></span>
            </div>
        <?php endif; ?>

        <div class="line" style="font-size: 16px; font-weight: bold; margin-top: 5px;">
            <span>TOTAL:</span>
            <span>$<?php echo number_format($venta['total_venta'], 2); ?></span>
        </div>
    </div>
</body>
</html>