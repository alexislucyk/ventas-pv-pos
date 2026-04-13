<?php
// logout.php - VERSIÓN DINÁMICA
// Incluimos el config para saber en qué URL_BASE estamos
require_once 'config/db_config.php'; 
include 'pages/infosesion.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. Destruye todas las variables de la sesión
$_SESSION = array();

// 2. Si se desea destruir la cookie de sesión, también se debe borrar.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruye la sesión
session_destroy();

// 4. Redirige al usuario a la página de login usando la constante dinámica
// Esto enviará a /pos_dev/login.php o /pos_prod/login.php según corresponda
header("Location: " . URL_BASE . "login.php");
exit;
?>