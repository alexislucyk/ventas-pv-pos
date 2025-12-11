<?php
// pages/vista_previa_ticket.php
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

// 1. Incluir la configuración de DB y la función de generación de ticket
require_once '../config/db_config.php'; 
// 🚨 RUTA CORREGIDA
require_once '../funciones/ticket_generator.php'; 

// 2. Controlar la entrada
if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Error: Documento no proporcionado.";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

// 3. Generar el HTML del ticket (usando $pdo y n_documento)
// 🚨 NOMBRE DE FUNCIÓN CORREGIDO
$html_ticket_content = generar_html_ticket_contenido($pdo, $n_documento);

// 4. Envolver el contenido del ticket en una página completa, cargando los estilos.
$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vista Previa Ticket #' . $n_documento . '</title>
        <link rel="stylesheet" href="../css/ticket_print.css">
    <style>
        /* Estilos base para la vista en pantalla. Los estilos de impresión vienen de ticket_print.css */
        body {
            width: 320px; 
            margin: 20px auto; 
            padding: 0;
            color: black; /* Asegurar que el texto sea visible */
            background: white; /* Asegurar fondo blanco para el ticket */
        }
        /* Forzar la aplicación del CSS de ticket_print.css para la vista previa */
        #ticket-vista-previa {
            padding: 10px; /* Margen interno para el contenido */
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
        <button onclick="window.print()" style="padding: 10px 20px;">Imprimir de Nuevo</button>
        <button onclick="window.close()" style="padding: 10px 20px;">Cerrar Vista Previa</button>
    </div>
</body>
</html>';

echo $html;

exit();
?>