<?php
// ajax/generar_ticket.php
session_start();
require_once '../config/db_config.php'; 
require_once '../../pos/funciones/ticket_generator.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    http_response_code(400);
    echo "Error: Falta empresa_id en sesión.";
    exit();
}

header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Error: Documento no proporcionado.";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

$html_ticket = generar_html_ticket_contenido($pdo, $n_documento, $empresa_id); 

echo $html_ticket;

exit();
?>