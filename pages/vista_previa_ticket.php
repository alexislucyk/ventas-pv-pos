<?php
// pages/vista_previa_ticket.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    die('Falta empresa_id en sesión.');
}

require_once '../config/db_config.php'; 
require_once '../funciones/ticket_generator.php'; 

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Error: Documento no proporcionado.";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

$stmt_cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ticket_ancho'");
$ancho_papel = $stmt_cfg->fetchColumn() ?: '80mm';

$html_ticket_content = generar_html_ticket_contenido($pdo, $n_documento, $empresa_id);

// 4. Envolver el contenido del ticket en una página completa, cargando los estilos.
$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vista Previa Ticket #' . $n_documento . '</title>
    <link rel="stylesheet" href="../css/temptocketprint.css">
    <style>
        @media print {
            @page { 
                margin: 0; 
                size: auto; 
            }
            body { 
                margin: 0 !important; 
                padding: 0 !important; 
                width: ' . $ancho_papel . ' !important;
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
';

$html .= $html_ticket_content; // Inserta el contenido del ticket

$html .= '
    </div>
    <div style="text-align: center; margin-top: 20px;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">Imprimir de Nuevo</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; margin-left: 10px;">Cerrar Vista Previa</button>
    </div>
</body>
</html>';

echo $html;

exit();
?>