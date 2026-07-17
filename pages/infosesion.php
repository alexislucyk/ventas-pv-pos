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
    header('Location: ' . URL_BASE . 'login.php');
    exit();
}

if (empty($_SESSION['empresa_id'])) {
    header('Location: ' . URL_BASE . 'login.php');
    exit();
}

// Normalizamos sucursal si falta
if (!isset($_SESSION['sucursal_id']) || (int)$_SESSION['sucursal_id'] <= 0) {
    $_SESSION['sucursal_id'] = 1;
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

/**
 * Helper de seguridad para endpoints: termina con 403 si no tiene el permiso.
 * Evita repetir el bloque de validación en cada procesar_*.php / ajax/*.php.
 * Detecta si la petición es AJAX/JSON para responder adecuadamente.
 */
if (!function_exists('require_permiso')) {
    function require_permiso($archivo_buscado) {
        if (tiene_permiso($archivo_buscado)) {
            return true;
        }

        // Determinar si la respuesta debe ser JSON o redirección HTML
        $es_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($es_json || strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'post') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'No tiene permiso para realizar esta acción.']);
        } else {
            http_response_code(403);
            echo '<div style="background:#e74c3c;color:#fff;padding:20px;font-family:sans-serif;">'
                . 'Acceso denegado: no tiene permiso para realizar esta acción.</div>';
        }
        exit();
    }
}

/**
 * Obtiene el nombre de la empresa desde la base de datos.
 * Prioridad de búsqueda:
 *   1. empresas (tabla unificada, configurada en "Perfil del Negocio")
 *   2. configuracion (clave 'nombre_empresa')
 *   3. Fallback: 'Mi Negocio'
 * El resultado se cachea en $_SESSION para evitar consultas repetitivas.
 */
if (!function_exists('obtener_nombre_empresa')) {
    function obtener_nombre_empresa() {
        // Cache en sesión para evitar consultas repetidas
        if (!empty($_SESSION['_cache_nombre_empresa'])) {
            return $_SESSION['_cache_nombre_empresa'];
        }
        
        try {
            global $pdo;
            $empresa_id = $_SESSION['empresa_id'] ?? null;
            
            if (isset($pdo) && $empresa_id) {
                // 1. Intentar desde empresas (tabla unificada)
                $stmt = $pdo->prepare("SELECT nombre_fantasia FROM empresas WHERE id = ? LIMIT 1");
                $stmt->execute([$empresa_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!empty($row['nombre_fantasia'])) {
                    $_SESSION['_cache_nombre_empresa'] = $row['nombre_fantasia'];
                    return $row['nombre_fantasia'];
                }
                
                // 2. Fallback: tabla configuracion
                $stmt2 = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'nombre_empresa' LIMIT 1");
                $stmt2->execute();
                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if (!empty($row2['valor'])) {
                    $_SESSION['_cache_nombre_empresa'] = $row2['valor'];
                    return $row2['valor'];
                }
            }
        } catch (Exception $e) {
            // Silencioso - usamos fallback
        }
        
        $_SESSION['_cache_nombre_empresa'] = 'Mi Negocio';
        return 'Mi Negocio';
    }
}

// Cargar nombre de empresa para uso global en todas las páginas
$nombre_empresa_sistema = obtener_nombre_empresa();
?>
