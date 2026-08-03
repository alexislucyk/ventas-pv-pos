<?php
/**
 * Archivo: /ajax/generar_ticket_cuota.php
 * Descripción: Genera el HTML del ticket para cobro de cuotas (80mm).
 */
include '../pages/infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    exit("Falta empresa_id en sesión.");
}

header('Content-Type: text/html; charset=utf-8');

$id_pago = (int)($_GET['id_pago'] ?? 0);
if ($id_pago <= 0) exit("ID de pago no válido.");

try {
    $sql = "SELECT cp.*, cs.nro_cuota, cs.monto_original as monto_cuota, cs.monto_pagado as acumulado_pagado,
                   v.n_documento, v.fecha_venta as fecha_venta_orig,
                   c.apellido, c.nombre,
                   vf.cant_cuotas
            FROM cuotas_pagos cp
            JOIN cuotas_seguimiento cs ON cp.id_cuota = cs.id
            JOIN ventas v ON cs.id_venta = v.id AND v.empresa_id = :empresa_id1
            JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id2
            JOIN ventas_financiacion vf ON v.id = vf.id_venta
            WHERE cp.id = :id_pago AND v.empresa_id = :empresa_id3";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_pago' => $id_pago, ':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id, ':empresa_id3' => $empresa_id]);
    $p = $stmt->fetch();

    if (!$p) exit("Registro de pago no encontrado.");

    $stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
    $stmt_emp->execute([$empresa_id]);
    $emp = $stmt_emp->fetch();

    $nombre_cliente = htmlspecialchars($p['apellido'] . ', ' . $p['nombre']);
    $saldo_cuota = max(0, $p['monto_cuota'] - $p['acumulado_pagado']);
    ?>

    <div class="center">
        <h3><?php echo strtoupper($emp['nombre_fantasia']); ?></h3>
        <p><?php echo htmlspecialchars($emp['direccion']); ?><br>Tel: <?php echo htmlspecialchars($emp['telefono']); ?></p>
        <p><strong>*** COMPROBANTE DE PAGO ***</strong></p>
    </div>
    
    <div class="sep"></div>
    <div class="line"><span>Recibo N°:</span> <span><?php echo str_pad($p['id'], 6, "0", STR_PAD_LEFT); ?></span></div>
    <div class="line"><span>Fecha Pago:</span> <span><?php echo date('d/m/Y H:i', strtotime($p['fecha'])); ?></span></div>
    <div class="line"><span>Cliente:</span> <span><?php echo $nombre_cliente; ?></span></div>
    <div class="sep"></div>

    <div class="center" style="font-weight: bold; margin-bottom: 5px;">DETALLE DE FINANCIACIÓN</div>
    <div class="line"><span>Venta Original:</span> <span>#<?php echo $p['n_documento']; ?></span></div>
    <div class="line"><span>Cuota:</span> <span><?php echo $p['nro_cuota'] . ' / ' . $p['cant_cuotas']; ?></span></div>
    <div class="line"><span>Valor de Cuota:</span> <span>$<?php echo number_format($p['monto_cuota'], 2, ',', '.'); ?></span></div>
    
    <div class="sep"></div>
        
    <?php if (isset($p['descuento']) && (float)$p['descuento'] > 0): ?>
    <div class="line">
        <span>Ajuste / Bonificación:</span>
        <span>-$<?php echo number_format($p['descuento'], 2, ',', '.'); ?></span>
    </div>
    <?php endif; ?>

    <div class="line"><span>Medio de Pago:</span> <span><?php echo strtoupper($p['metodo_pago']); ?></span></div>

    <div class="line" style="font-size: 1.1em;">
        <strong>MONTO ABONADO:</strong>
        <strong>$<?php echo number_format($p['monto'], 2, ',', '.'); ?></strong>
    </div>
    
    <div class="sep"></div>
    <div class="line">
        <span>SALDO RESTANTE CUOTA:</span> 
        <span>$<?php echo number_format($saldo_cuota, 2, ',', '.'); ?></span>
    </div>

    <div class="sep"></div>
    <div class="center" style="margin-top: 10px;">
        <p>¡MUCHAS GRACIAS POR SU PAGO!</p>
        <p style="font-size: 0.8em; margin-top: 5px;">Documento no válido como factura fiscal.</p>
    </div>

    <?php
} catch (Exception $e) {
    error_log("Error Ticket Cuota: " . $e->getMessage());
    echo "Error al generar el comprobante.";
}