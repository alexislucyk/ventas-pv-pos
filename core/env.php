<?php
// Bloqueo de acceso directo (compatibilidad Apache/Nginx): si este archivo es el script solicitado por HTTP, responder 404.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit('Not Found'); }

/**
 * core/env.php - Cargador ligero de variables de entorno (.env)
 * ──────────────────────────────────────────────────────────────
 * No depende de Composer. Lee el archivo .env ubicado en la raíz
 * del proyecto y lo carga en $_ENV y $_SERVER para uso en toda
 * la aplicación.
 *
 * Uso:  require_once CORE_PATH . 'env.php';  cargarEnv();
 */

if (!function_exists('cargarEnv')) {
    /**
     * Carga las variables definidas en .env (sino ya cargadas).
     * Busca el archivo .env en la raíz del proyecto (BASE_PATH).
     */
    function cargarEnv(): void
    {
        // Evitar recargas duplicadas
        static $cargado = false;
        if ($cargado) {
            return;
        }

        $envPath = defined('BASE_PATH')
            ? rtrim(BASE_PATH, '/\\') . '/.env'
            : dirname(__DIR__) . '/.env';

        if (!is_file($envPath)) {
            // Sin .env: nada que cargar (modo dev con defaults)
            $cargado = true;
            return;
        }

        $lineas = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            // Ignorar comentarios y líneas vacías
            if ($linea === '' || $linea[0] === '#') {
                continue;
            }

            // Separar clave = valor
            if (strpos($linea, '=') === false) {
                continue;
            }

            list($clave, $valor) = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor);

            // Quitar comillas simples/dobles si las tiene
            if (strlen($valor) >= 2) {
                if (($valor[0] === "'" && substr($valor, -1) === "'") ||
                    ($valor[0] === '"' && substr($valor, -1) === '"')) {
                    $valor = substr($valor, 1, -1);
                }
            }

            // No sobreescribir variables ya definidas en el entorno real
            if (!getenv($clave) && !isset($_ENV[$clave]) && !isset($_SERVER[$clave])) {
                putenv($clave . '=' . $valor);
                $_ENV[$clave] = $valor;
                $_SERVER[$clave] = $valor;
            }

            $cargado = true;
        }
    }
}

if (!function_exists('env')) {
    /**
     * Obtiene una variable de entorno con valor por defecto.
     *
     * @param string $clave     Clave a buscar.
     * @param mixed  $default   Valor por defecto si no existe.
     * @return mixed
     */
    function env(string $clave, $default = null)
    {
        $valor = getenv($clave);
        if ($valor === false) {
            $valor = $_ENV[$clave] ?? $_SERVER[$clave] ?? null;
        }
        return ($valor !== null && $valor !== '') ? $valor : $default;
    }
}
