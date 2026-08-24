<?php
/**
 * api/_api_auth.php - Guardia de autenticación centralizada para endpoints API públicos
 * ──────────────────────────────────────────────────────────────────────────────
 * Centraliza:
 *   - Carga de .env (via core/env.php)
 *   - Validación del token de API
 *   - Configuración de CORS dinámica y restringida
 *
 * Uso al inicio de cada endpoint público:
 *   require_once __DIR__ . '/_api_auth.php';
 *   apiAuth();  // termina con 401 si el token es inválido
 */

// Cargar variables de entorno sin depender del front controller
if (!function_exists('env')) {
    require_once __DIR__ . '/../core/env.php';
    cargarEnv();
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Configura los headers CORS permitidos y retorna el origen permitido.
 * Lee la lista de orígenes desde .env (API_CORS_ORIGIN, separados por coma).
 * Si Access-Control-Allow-Origin no está configurado, deniega CORS (solo same-origin).
 */
function apiConfigurarCors(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $origenes_permitidos = env('API_CORS_ORIGIN', '');

    if (!empty($origenes_permitidos)) {
        $lista = array_map('trim', explode(',', $origenes_permitidos));
        $origen = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origen !== '' && in_array($origen, $lista)) {
            header('Access-Control-Allow-Origin: ' . $origen);
            header('Vary: Origin');
        }
    }
    // Si el origen no está en la lista o no hay lista configurada, NO se envía
    // Access-Control-Allow-Origin → el navegador impide el cross-origin.
}

/**
 * Valida el token de la API. Si es inválido, responde 401 y termina la ejecución.
 */
function apiAuth(): void
{
    apiConfigurarCors();

    // Manejar preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $token_esperado = env('API_CONSIGNACIONES_TOKEN', 'consignaciones_remote_2024_vpn');
    $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($token !== $token_esperado) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de acceso inválido o faltante']);
        exit();
    }
}
