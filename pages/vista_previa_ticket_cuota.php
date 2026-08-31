<?php
/**
 * Archivo: /pages/vista_previa_ticket_cuota.php
 */
include 'infosesion.php';
require_once '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    die('Falta empresa_id en sesión.');
}

$id_pago = (int)($_GET['id_pago'] ?? 0);
if ($id_pago <= 0) die("ID de pago no válido.");

$stmt_cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ticket_ancho'");
$ancho_papel = $stmt_cfg->fetchColumn() ?: '80mm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Pago #<?php echo $id_pago; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/print/temptocketprint.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/print/vista_recibo.css'); ?>">
    <style>/* Dinamico: ancho calculado por PHP */ @media print { body { width: <?php echo $ancho_papel; ?> !important; } }</style>
</head>
<body onload="window.print()">
    <div id="ticket-vista-previa">
        <?php include __DIR__ . '/../ajax/generar_ticket_cuota.php'; ?>
    </div>
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor:pointer;">Reintentar Impresión</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor:pointer; margin-left:10px;">Cerrar</button>
    </div>
</body>
</html>