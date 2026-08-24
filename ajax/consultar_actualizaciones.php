<?php
// ajax/consultar_actualizaciones.php
// Endpoint para forzar la comprobación de actualizaciones desde GitHub
// (usado por la barra de progreso del botón "Comprobar de nuevo").
include '../pages/infosesion.php';
require '../config/db_config.php';
require PATH_BASE . 'config/actualizaciones.php';
require_once PATH_BASE . 'funciones/funcion_actualizaciones.php';

header('Content-Type: application/json; charset=utf-8');

// Solo el rol 'developer' puede consultar/forzar el estado
if (($_SESSION['usuario_rol'] ?? '') !== 'developer') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit();
}

// Forzamos el recálculo ignorando la caché y guardamos el nuevo estado
$estado = consultar_actualizaciones($pdo, true);

echo json_encode($estado);
exit();