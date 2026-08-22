<?php
/**
 * helpers.php - Funciones auxiliares globales para el Router
 *
 * Estas funciones están disponibles en todas las páginas y rutas.
 * Proporcionan utilidades para generación de URLs, autenticación,
 * redirecciones y protección CSRF.
 */


if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Acceso a la instancia global del Router.
 */
function app(): ?Router
{
    global $router;

    // Bootstrap perezoso: si no hay router (acceso directo por URL física,
    // ej. /pos_dev/pages/historial_compras.php), lo instanciamos y cargamos
    // las rutas para que route()/route_file() sigan generando URLs limpias.
    if ($router === null && !defined('ROUTER_BOOTSTRAPPED')) {
        define('ROUTER_BOOTSTRAPPED', true);

        $router_file = BASE_PATH . '/core/Router.php';
        $routes_file = BASE_PATH . '/app/routes.php';

        if (file_exists($router_file)) {
            require_once $router_file;

            if (class_exists('Router')) {
                $router = new Router();

                if (file_exists($routes_file)) {
                    // routes.php espera encontrar $router en su ámbito
                    $loadRoutes = function () use (&$router, $routes_file) {
                        require $routes_file;
                    };
                    $loadRoutes();
                }
            }
        }
    }

    return $router;
}

/**
 * Generar la URL para una ruta nombrada.
 *
 * @param string $name   Nombre de la ruta
 * @param array  $params Parámetros para reemplazar {param}
 * @return string URL completa (ej. /pos_dev/ventas)
 */
function route(string $name, array $params = []): string
{
    // Sin router (acceso directo por URL física): fallback a URL amigable simple
    if (app() === null) {
        return url(ltrim($name, '/'));
    }

    return app()->url($name, $params);
}

/**
 * Generar la URL limpia correspondiente a un archivo de página físico.
 *
 * Convierte 'pages/ventas.php' → '/ventas' usando las rutas registradas en
 * routes.php. Si el archivo no tiene ruta registrada, devuelve la URL física
 * (fallback) para no romper enlaces hasta que se registre la ruta.
 *
 * @param string $file Ruta física relativa a la raíz (ej. 'pages/ventas.php')
 * @return string URL completa (ej. /pos_dev/ventas)
 */
function route_file(string $file): string
{
    $base  = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
    $router = app();
    $uri   = $router !== null ? $router->uriForFile($file) : null;

    if ($uri !== null) {
        return $base . ($uri === '/' ? '/' : $uri);
    }

    // Fallback: archivo sin ruta registrada → URL física
    return $base . '/' . ltrim($file, '/');
}

/**
 * Redirigir a una URL o ruta nombrada.
 *
 * @param string $to    Ruta nombrada o URL
 * @param int    $code  Código de redirección (default 302)
 */
function redirect(string $to, int $code = 302): void
{
    // Si es una ruta nombrada, generar la URL (requiere router activo)
    if (app() !== null) {
        $named = app()->getNamedRoutes();
        if (isset($named[$to])) {
            $to = app()->url($to);
        }
    }

    header('Location: ' . $to, true, $code);
    exit();
}

/**
 * Verificar si el usuario está autenticado.
 */
function auth(): bool
{
    return isset($_SESSION['usuario_id']) && isset($_SESSION['empresa_id']);
}

/**
 * Requerir autenticación: si no está logueado, redirigir al login.
 */
function require_login(): void
{
    if (!auth()) {
        redirect('login');
    }
}

/**
 * Verificar permisos (delega al sistema existente tiene_permiso).
 * Si el usuario no tiene permiso, retorna 403 o JSON según el contexto.
 */
function require_permiso(string $archivo): void
{
    if (function_exists('tiene_permiso') && tiene_permiso($archivo)) {
        return;
    }

    $es_json = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
         strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) &&
            strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) &&
            strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );

    if ($es_json || $_SERVER['REQUEST_METHOD'] === 'POST') {
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

/**
 * Generar un token CSRF y guardarlo en sesión.
 * @return string
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Emitir un input hidden con el token CSRF para formularios.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verificar el token CSRF enviado en un formulario.
 */
function csrf_verify(): bool
{
    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
}

/**
 * Generar una URL absoluta basada en una ruta relativa.
 * Útil para assets y enlaces que no usan route().
 */
function url(string $path = ''): string
{
    $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
    if ($path === '' || $path === '/') {
        return $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}
