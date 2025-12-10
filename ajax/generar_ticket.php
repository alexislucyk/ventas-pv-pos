<?php
// ajax/generar_ticket.php

// Incluir archivos necesarios (db_config y ticket_generator)
require_once '../config/db_config.php'; 
require_once '../../pos/funciones/ticket_generator.php'; // Aquí debe estar la función generar_html_ticket

// Establecer encabezados
header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Error: Documento no proporcionado.";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

// CAMBIO CLAVE: Pasar la variable $pdo a la función
$html_ticket = generar_html_ticket($pdo, $n_documento); 

echo $html_ticket;

exit();
?>