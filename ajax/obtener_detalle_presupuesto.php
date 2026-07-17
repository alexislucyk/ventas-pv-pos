<?php
include '../pages/infosesion.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$id = isset($_GET['id']) ? $_GET['id'] : 0;

// 1. Obtener datos del presupuesto y cliente
$stmt = $pdo->prepare("SELECT p.*, CONCAT(c.apellido, ' ', c.nombre) as cliente, c.direccion, c.cuit 
                       FROM presupuestos p 
                       LEFT JOIN clientes c ON p.id_cliente = c.id 
                       WHERE p.id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

// 2. Obtener los productos del presupuesto
$stmtItems = $pdo->prepare("SELECT * FROM presupuestos_detalle WHERE id_presupuesto = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

if (!$p) { echo "No se encontró el presupuesto."; exit; }
?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; background: #1a1a1a; padding: 15px; border-radius: 5px;">
    <div>
        <strong>Cliente:</strong> <?php echo htmlspecialchars($p['cliente']); ?><br>
        <strong>CUIT:</strong> <?php echo $p['cuit']; ?><br>
        <strong>Dirección:</strong> <?php echo htmlspecialchars($p['direccion']); ?>
    </div>
    <div style="text-align: right;">
        <strong>Fecha:</strong> <?php echo date('d/m/Y H:i', strtotime($p['fecha_presupuesto'])); ?><br>
        <strong style="color: #2ecc71; font-size: 1.2em;">Total: $<?php echo number_format($p['total_presupuesto'], 2); ?></strong>
    </div>
</div>

<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr style="border-bottom: 2px solid #444; text-align: left;">
            <th style="padding: 10px;">Cant.</th>
            <th>Descripción</th>
            <th>P. Unit</th>
            <th>Subtotal</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
        <tr style="border-bottom: 1px solid #333;">
            <td style="padding: 10px;"><?php echo $item['cantidad']; ?></td>
            <td><?php echo htmlspecialchars($item['descripcion']); ?></td>
            <td>$<?php echo number_format($item['precio_unitario'], 2); ?></td>
            <td>$<?php echo number_format(($item['subtotal'] !== null && $item['subtotal'] !== '') ? $item['subtotal'] : ($item['cantidad'] * $item['precio_unitario']), 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($p['observaciones'])): ?>
<div style="margin-top: 20px; font-style: italic; color: #aaa;">
    <strong>Observaciones:</strong><br>
    <?php echo nl2br(htmlspecialchars($p['observaciones'])); ?>
</div>
<?php endif; ?>
