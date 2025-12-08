<?php
session_start();

// 1. Destruye todas las variables de la sesión
$_SESSION = array();

// 2. Si se desea destruir la cookie de sesión, también se debe borrar.
// Nota: Esto destuirá la sesión, y no solo los datos de sesión.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruye la sesión
session_destroy();

// 4. Redirige al usuario a la página de login
header("Location: login.php");
exit;
?>