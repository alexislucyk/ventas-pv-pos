<?php
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
// pages/vista_previa_ticket.php

// 1. Incluir la configuración de DB y la función de generación de ticket
require_once '../config/db_config.php'; 
require_once '../../pos/funciones/ticket_generator.php';

// 2. Controlar la entrada
if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Error: Documento no proporcionado.";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

// 3. Generar el HTML del ticket (usando $pdo y n_documento)
// Recordatorio: Tu función debe ser: generar_html_ticket($pdo, $n_documento)
$html_ticket_content = generar_html_ticket($pdo, $n_documento);

// 4. Envolver el contenido del ticket en una página completa y limpia
$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vista Previa Ticket #' . $n_documento . '</title>
    <style>
        body { 
            /* Esto es solo para la vista en pantalla, para simular la anchura del ticket */
            width: 320px; 
            margin: 20px auto; 
            font-family: monospace;
            padding: 0;
        }
        @media print {
            /* Asegura que no haya márgenes en la impresión real */
            body { 
                margin: 0 !important; 
                width: 80mm !important;
            }
        }
    </style>
</head>
<body onload="window.print()">
';

$html .= $html_ticket_content; // Inserta el contenido del ticket

$html .= '
    <div style="text-align: center; margin-top: 20px;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px;">Imprimir de Nuevo</button>
        <button onclick="window.close()" style="padding: 10px 20px;">Cerrar Vista Previa</button>
    </div>
</body>
</html>';

echo $html;

exit();
?>