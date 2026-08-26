<?php
// Bloqueo de acceso directo (compatibilidad Apache/Nginx): si este archivo es el script solicitado por HTTP, responder 404.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit('Not Found'); }

/**
 * Router - Sistema de enrutamiento ligero para PHP puro
 *
 * Permite definir rutas con URLs amigables que se resuelven a
 * archivos PHP existentes o a closures (callbacks). Diseñado para
 * funcionar como Dispatcher Frontal manteniendo total compatibilidad
 * con el acceso directo a archivos (ajax/, api/, assets).
 *
 * Uso:
 *   $router = new Router();
 *   $router->get('/', 'pages/dashboard.php', 'dashboard');
 *   $router->get('/ventas', 'pages/ventas.php', 'ventas');
 *   $router->dispatch();
 */
class Router
{
    /** @var array<string, array<string, mixed>> Rutas agrupadas por método HTTP */
    private array $routes = [];

    /** @var array<string, string> Mapa de nombres → URI de patrón */
    private array $namedRoutes = [];

    /** @var string Método HTTP del request actual */
    private string $method;

    /** @var string URI normalizada del request actual */
    private string $uri;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->uri = $this->normalizeUri();
    }

    // ──────────────────────────────────────────────────────────
    //  Registro de rutas
    // ──────────────────────────────────────────────────────────

    /** Registrar una ruta GET */
    public function get(string $uri, $handler, ?string $name = null): self
    {
        return $this->add('GET', $uri, $handler, $name);
    }

    /** Registrar una ruta POST */
    public function post(string $uri, $handler, ?string $name = null): self
    {
        return $this->add('POST', $uri, $handler, $name);
    }

    /** Registrar una ruta PUT */
    public function put(string $uri, $handler, ?string $name = null): self
    {
        return $this->add('PUT', $uri, $handler, $name);
    }

    /** Registrar una ruta DELETE */
    public function delete(string $uri, $handler, ?string $name = null): self
    {
        return $this->add('DELETE', $uri, $handler, $name);
    }

    /** Registrar una ruta para TODOS los métodos HTTP */
    public function any(string $uri, $handler, ?string $name = null): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            $this->add($m, $uri, $handler, $name);
        }
        return $this;
    }

    private function add(string $method, string $uri, $handler, ?string $name): self
    {
        // Normalizar: quitar trailing slash (excepto para '/')
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

                        $this->routes[$method][$uri] = $handler;

        if ($name !== null) {
            $this->namedRoutes[$name] = $uri;
        }

        return $this;
    }

    /** Obtener rutas nombradas (para helpers) */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Encontrar la primera ruta cuyo handler (archivo) coincide con $file.
     * Útil para traducir un path físico (ej. 'pages/ventas.php') a su URL limpia.
     *
     * @param string $file Ruta tal como se registró en routes.php (ej. 'pages/ventas.php')
     * @return string|null URI limpia (ej. '/ventas') o null si no hay ruta registrada.
     */
    public function uriForFile(string $file): ?string
    {
        $file = ltrim($file, '/\\');
        foreach ($this->routes as $byUri) {
            foreach ($byUri as $uri => $handler) {
                if (is_string($handler) && ltrim($handler, '/\\') === $file) {
                    return $uri === '/' ? '/' : rtrim($uri, '/');
                }
            }
        }
        return null;
    }

    /** Obtener la URI normalizada del request actual */
    public function getUri(): string
    {
        return $this->uri;
    }

    /** Obtener el método HTTP del request actual */
    public function getMethod(): string
    {
        return $this->method;
    }

    // ──────────────────────────────────────────────────────────
    //  Dispatch
    // ──────────────────────────────────────────────────────────

    /**
     * Resolver y ejecutar la ruta que coincide con el request actual.
     */
    public function dispatch(): void
    {
        // 1. Coincidencia exacta o con parámetros dinámicos para el método actual
        if ($this->match($this->method)) {
            return;
        }

        // 2. Fallback: las páginas procesan su propio formulario (self-posting).
        //    Si no existe una ruta POST explícita para la URI, se atiende la
        //    petición con la ruta GET registrada (ej. <form method="POST"> sin
        //    action enviado a /ventas se resuelve con la ruta GET /ventas).
        if ($this->method !== 'GET' && $this->match('GET')) {
            return;
        }

        // 3. Nada coincidió → 404
        $this->notFound();
    }

    /**
     * Buscar y ejecutar la primera ruta que coincide con la URI para un método.
     */
    private function match(string $method): bool
    {
        $handlers = $this->routes[$method] ?? [];

        // Coincidencia exacta
        if (isset($handlers[$this->uri])) {
            $this->execute($handlers[$this->uri], []);
            return true;
        }

        // Coincidencia con parámetros dinámicos (ej. /producto/{id})
        foreach ($handlers as $pattern => $handler) {
            $params = $this->tryMatch($pattern, $this->uri);
            if ($params !== null) {
                $this->execute($handler, $params);
                return true;
            }
        }

        return false;
    }

    /**
     * Intentar emparejar un patrón de ruta con la URI.
     * Devuelve array de parámetros si coincide, null si no.
     */
    private function tryMatch(string $pattern, string $uri): ?array
    {
        // Solo tratar patrones con {param} como dinámicos
        if (strpos($pattern, '{') === false) {
            return null;
        }

        // Convertir {param} → ([^/]+)
        $regex = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $pattern);
        $regex = "#^$regex$#";

        if (preg_match($regex, $uri, $matches)) {
            // Extraer nombres de parámetros
            preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $pattern, $paramNames);
            $params = [];
            foreach ($paramNames[1] as $i => $name) {
                $params[$name] = $matches[$i + 1];
            }
            return $params;
        }

        return null;
    }

    /**
     * Ejecutar el handler (closure, callback o archivo).
     */
    private function execute($handler, array $params = []): void
    {
        // Closure o callable
        if (is_callable($handler) && !is_string($handler)) {
            call_user_func($handler, $params);
            return;
        }

        // String: puede ser un path de archivo
        if (is_string($handler)) {
            $file = $this->resolveFile($handler);

            if ($file !== null && is_file($file)) {
                $this->dispatchToFile($file, $params);
                return;
            }
        }

        // No se encontró nada
        $this->notFound();
    }

    /**
     * Resolver un path de handler a un path de archivo ABSOLUTO.
     * Devuelve siempre rutas absolutas para que dispatchToFile() pueda
     * hacer chdir() y luego include() sin perder la referencia al archivo
     * (si se devolviera una ruta relativa, el include fallaría tras el chdir).
     */
    private function resolveFile(string $handler): ?string
    {
        // Si es ruta absoluta (unix / o windows C:\) y el archivo existe,
        // devolverla normalizada con realpath().
        if ($this->isAbsolutePath($handler) && is_file($handler)) {
            return realpath($handler);
        }

        // Ruta relativa → resolver contra BASE_PATH (raíz de la app).
        if (defined('BASE_PATH') && BASE_PATH !== '' && !$this->isAbsolutePath($handler)) {
            $file = rtrim(BASE_PATH, '/\\') . '/' . ltrim($handler, '/\\');
            if (is_file($file)) {
                return realpath($file);
            }
        }

        return null;
    }

    /**
     * Determina si un path es absoluto (unix /... o windows C:\... / C:...).
     */
    private function isAbsolutePath(string $path): bool
    {
        // unix: /ruta/absoluta
        if (strpos($path, '/') === 0) {
            return true;
        }
        // windows: C:\\... o C:/...
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Incluir un archivo cambiando temporalmente el CWD
     * para que los includes relativos del archivo funcionen
     * correctamente (ej. include 'infosesion.php').
     *
     * Además, expone las variables globales definidas en el
     * front controller (index.php) —como $pdo— al ámbito donde
     * se ejecuta el archivo incluido.
     */
    private function dispatchToFile(string $file, array $params): void
    {
        $originalDir = getcwd();
        $fileDir = dirname($file);

        chdir($fileDir);

        // Exponer variables globales (p. ej. $pdo desde db_config.php)
        // al ámbito actual, de modo que los archivos incluidos puedan
        // acceder a ellas sin necesidad de global $pdo en cada uno.
        global $pdo;

        // Convertir parámetros de ruta en variables locales
        extract($params);

        // Exponer el archivo real servido en PHP_SELF para que el código que
        // usa basename($_SERVER['PHP_SELF']) (p. ej. la guardia de caja en
        // infosesion.php) siga funcionando con URLs limpias a través del router.
        $_SERVER['PHP_SELF'] = $file;

        include $file;

        chdir($originalDir);
    }

    // ──────────────────────────────────────────────────────────
    //  Generación de URLs
    // ──────────────────────────────────────────────────────────

    /**
     * Generar la URL para una ruta nombrada.
     *
     * @param string $name   Nombre de la ruta
     * @param array  $params Parámetros para reemplazar {param}
     * @return string URL absoluta relativa al host (ej. /pos_dev/ventas)
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            return '';
        }

        $uri = $this->namedRoutes[$name];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', $value, $uri);
        }

        $base = defined('URL_BASE') ? URL_BASE : '/';
        $base = rtrim($base, '/');

        return $base . $uri;
    }

    // ──────────────────────────────────────────────────────────
    //  Utilidades internas
    // ──────────────────────────────────────────────────────────

    /**
     * Normalizar la URI del request: quitar query string,
     * quitar el prefijo URL_BASE y trailing slash.
     */
    private function normalizeUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Quitar prefijo URL_BASE (ej. /pos_dev/)
        $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
        if ($base && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }

        $uri = '/' . ltrim($uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        return $uri;
    }

    /**
     * Mostrar página 404.
     */
    private function notFound(): void
    {
        http_response_code(404);

        // Intentar cargar página 404 personalizada
        if (defined('BASE_PATH')) {
            $file = rtrim(BASE_PATH, '/\\') . '/pages/404.php';
            if (is_file($file)) {
                $this->dispatchToFile($file, []);
                return;
            }
        }

        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página no encontrada</title>
    <style>
        body { background:#121212; color:#e0e0e0; font-family:"Segoe UI",sans-serif;
               display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .card { background:#1e1e1e; padding:50px; border-radius:16px; text-align:center;
                border:1px solid #333; box-shadow:0 20px 60px rgba(0,0,0,.5); max-width:520px; }
        h1 { font-size:4rem; color:#e74c3c; margin:0; line-height:1; }
        p  { color:#aaa; margin:15px 0 30px; }
        a  { color:#00bcd4; text-decoration:none; font-weight:600; padding:12px 30px;
             border:1px solid #333; border-radius:8px; display:inline-block; transition:.2s; }
        a:hover { background:#00bcd4; color:#000; border-color:#00bcd4; }
    </style>
</head>
<body>
    <div class="card">
        <div style="font-size:5rem;margin-bottom:20px;">🔍</div>
        <h1>404</h1>
        <p>La página que buscas no existe o has intentado acceder a una ruta desconocida.</p>
        <a href="' . (defined('URL_BASE') ? URL_BASE : '/') . '">← Volver al inicio</a>
    </div>
</body>
</html>';
    }
}