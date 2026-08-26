<?php
// ajax/generar_ticket.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db_config.php'; 
// FIX: el path anterior ('../../pos/funciones/') apuntaba fuera de la app
// (la carpeta hermana pos/ no existe) y provocaba un fatal error.
require_once dirname(__DIR__) . '/funciones/ticket_generator.php';

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

// Ancho de papel configurado (80mm / 58mm). El QR al pie solo se agrega en 80mm.
$stmt_cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ticket_ancho'");
$ancho_papel = $stmt_cfg->fetchColumn() ?: '80mm';

$html_ticket = generar_html_ticket_contenido($pdo, $n_documento, $empresa_id, $ancho_papel); 

echo $html_ticket;

exit();
?>