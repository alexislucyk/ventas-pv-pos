<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db_config.php';

$id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? '';

$items = [];
$res = null;

// 1. Verificación para impresión inmediata desde sesión (con desglose de ítems)
if (isset($_SESSION['last_op_data']) && ($id == 0 || $id == $_SESSION['last_op_data']['op_n'])) {
    $data = $_SESSION['last_op_data'];
    $res = [
        'monto' => $data['total_reintegrado'],
        'detalle' => $data['motivo'],
        'fecha' => date('Y-m-d H:i:s')
    ];
    $items = $data['items'];
    $id = $data['op_n'];
} 
// 2. Búsqueda histórica
else if ($id > 0) {
    // Consulta la tabla 'devoluciones' directamente usando op_n y cond_pago
    $stmt = $pdo->prepare("SELECT * FROM devoluciones WHERE op_n = ? AND cond_pago = ?");
    $stmt->execute([$id, $tipo]);
    $dev = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dev) {
        $res = [
            'monto' => $dev['total_reintegrado'],
            'detalle' => $dev['motivo'],
            'fecha' => $dev['fecha']
        ];
        $stmt_it = $pdo->prepare("SELECT descripcion as `desc`, cantidad as cant, subtotal as total FROM devoluciones_detalle WHERE id_devolucion = ?");
        $stmt_it->execute([$dev['id']]); // Usamos el 'id' de la tabla 'devoluciones'
        $items = $stmt_it->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!$res) die("No se encontró el comprobante.");

$stmt_cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ticket_ancho'");
$ancho_papel = $stmt_cfg->fetchColumn() ?: '80mm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket Devolución OP#<?php echo $id; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/temptocketprint.css'); ?>">
    <style>
        @media print { body { width: <?php echo $ancho_papel; ?> !important; } }
    </style>
</head>
<body onload="window.print()">
    <div id="ticket-vista-previa">
        <div class="center"><h3>COMPROBANTE DE DEVOLUCIÓN</h3></div>
        <div class="line"><span>Operación:</span><span>OP#<?php echo $id; ?></span></div>
        <div class="line"><span>Fecha:</span><span><?php echo date('d/m/Y H:i', strtotime($res['fecha'])); ?></span></div>
        <div class="sep"></div>

        <div>
            <div class="center" style="font-weight: bold;">Motivo / Detalle:</div>
            <p style="white-space: pre-line;"><?php echo htmlspecialchars($res['detalle'] ?? ''); ?></p>

            <?php if (!empty($items)): ?>
                <div class="sep"></div>
                <div class="center" style="font-weight: bold;">PRODUCTOS DEVUELTOS:</div>
                <?php foreach ($items as $it): ?>
                    <div class="line">
                        <span><?php echo htmlspecialchars($it['desc']); ?> (x<?php echo $it['cant']; ?>)</span>
                        <span>$<?php echo number_format($it['total'], 2, ',', '.'); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sep"></div>
        <div class="line total-amount"><span>TOTAL:</span><span>$<?php echo number_format($res['monto'], 2, ',', '.'); ?></span></div>

        <div class="sep"></div>
        <div class="center">Documento no válido como factura.<br>Gracias.</div>
    </div>
    
    <div class="no-print" style="margin-top:20px;">
        <button onclick="window.print()">Imprimir</button>
        <button onclick="window.close()">Cerrar</button>
    </div>
</body>
</html>