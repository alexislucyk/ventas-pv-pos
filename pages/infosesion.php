<?php
// pages/infosesion.php - GUARDIA DE SESIÓN DINÁMICO
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Manejador Global de Excepciones para PHP 8
// Captura Throwable para incluir tanto Errores internos de PHP como Excepciones de usuario
set_exception_handler(function (Throwable $e) {
    error_log("POS-DEV FATAL ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());

    // Detectar modo desarrollo (por URL o si el usuario es 'developer')
    $url_has_dev = defined('URL_BASE') && (strpos(URL_BASE, 'dev') !== false);
    $is_dev_user = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer';
    $is_dev = $url_has_dev || $is_dev_user;
    
    if (ob_get_length()) ob_clean(); // Limpiamos cualquier salida parcial
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Error del Sistema</title>
        <style>
            body { background: #121212; color: #eee; font-family: 'Segoe UI', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .error-card { background: #1e1e1e; padding: 40px; border-radius: 12px; border: 1px solid #333; max-width: 500px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
            h2 { color: #e74c3c; margin-top: 0; }
            .details { text-align: left; background: #000; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 0.85em; color: #00bcd4; margin-top: 20px; overflow-x: auto; border-left: 4px solid #00bcd4; }
            .btn-back { display: inline-block; margin-top: 25px; padding: 12px 25px; background: #00bcd4; color: #000; text-decoration: none; border-radius: 6px; font-weight: bold; transition: 0.3s; }
            .btn-back:hover { background: #008ba3; transform: scale(1.05); }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div style="font-size: 3rem; margin-bottom: 15px;">🛠️</div>
            <h2>¡Lo sentimos!</h2>
            <p>Ha ocurrido un error inesperado en el sistema. El administrador ha sido notificado.</p>
            <?php if ($is_dev): ?>
                <div class="details">
                    <strong>Mensaje:</strong> <?php echo htmlspecialchars($e->getMessage()); ?><br>
                    <strong>Ubicación:</strong> <?php echo htmlspecialchars($e->getFile()); ?>:<?php echo $e->getLine(); ?>
                </div>
            <?php endif; ?>
            <a href="<?php echo defined('URL_BASE') ? URL_BASE : '/'; ?>index.php" class="btn-back">Volver al Inicio</a>
        </div>
    </body>
    </html>
    <?php
    exit();
});

// 1. Incluimos el config para tener acceso a URL_BASE si no está cargado
// Usamos dirname(__FILE__) para que encuentre el config sin importar desde dónde se llame
require_once dirname(__FILE__) . '/../config/db_config.php';

// 2. GUARDIA DE SEGURIDAD: Si no hay sesión, redirigir al login
if (!isset($_SESSION['usuario_id'])) {
    // Redirigimos a la raíz del entorno actual (pos_dev o pos_prod)
    header('Location: ' . URL_BASE . 'login.php');
    exit();
}

// Datos de la sesión para mostrar
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre']); // O $_SESSION['usuario_nombre'] si lo usas así
$rol = htmlspecialchars($_SESSION['usuario_rol']);

/**
 * Función para validar si el usuario tiene acceso al link
 * El rol 'developer' siempre tiene permiso total.
 */
if (!function_exists('tiene_permiso')) {
    function tiene_permiso($archivo_buscado) {
        if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
            return true;
        }
        
        if (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
            return in_array($archivo_buscado, $_SESSION['permisos']);
        }
        
        return false;
    }
}
?>