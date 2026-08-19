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
    <link rel="stylesheet" href="<?php echo url('css/temptocketprint.css'); ?>">
    <style>
        @media print {
            @page { 
                margin: 0; 
                size: auto; 
            }
            body { 
                margin: 0 !important; 
                padding: 0 !important; 
                width: <?php echo $ancho_papel; ?> !important;
            }
            .no-print { 
                display: none !important; 
            }
            #ticket-vista-previa {
                width: 100% !important;
                padding: 1mm 2mm 1mm 1mm !important;
                box-sizing: border-box !important;
                margin: 0 !important;
            }
        }
    </style>
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