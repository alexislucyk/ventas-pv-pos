<?php
// config/licencia_manager.php

/**
 * Gestor de Licencia para Conexión con App Engine Propio
 */
// Cargador de .env (safety: en caso de que se incluya sin db_config.php)
if (!function_exists('env')) {
    require_once __DIR__ . '/../core/env.php';
    cargarEnv();
}

// Configuración de la Licencia
define('APP_NAME', 'LS-POS-PRO'); // Nombre identificador de la app
// Versión leída de .env (sincronizada con los tags de Git: v2.7.1).
// Fallback a '2.5.0' solo si .env no define APP_VERSION.
define('APP_VERSION', env('APP_VERSION', '2.5.0'));
define('LICENCIA_KEY', APP_NAME . '-' . env('LICENCIA_KEY_SUFFIX', '2024-XXXX-XXXX'));

// --- FUNCIONES PARA GESTIONAR LA IP DEL SERVIDOR DE LICENCIAS ---
function getLicenciaServerIp() {
    $ip_file = dirname(__FILE__) . '/.licencia_ip.conf';
    if (file_exists($ip_file)) {
        $ip = trim(file_get_contents($ip_file));
        // Validación básica de IP
        // Permitimos IP o nombres de host (dominios)
        if (!empty($ip)) {
            return $ip;
        }
    }
    // IP por defecto si el archivo no existe o es inválido
    $default_ip = '190.100.100.50'; // IP por defecto
    file_put_contents($ip_file, $default_ip); // Crea el archivo con la IP por defecto
    return $default_ip;
}

function setLicenciaServerIp($newUrl) {
    $ip_file = dirname(__FILE__) . '/.licencia_ip.conf';
    return file_put_contents($ip_file, trim($newUrl)) !== false;
}

/**
 * Genera un Identificador único del equipo (Hardware ID)
 * Combina el UUID del sistema con el prefijo LUCYK-PRO.
 */
function obtenerHardwareID() {
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $uid = "";

    if ($os === 'WIN') {
        // Obtiene el UUID único de la placa base vía WMIC
        $uid_raw = @shell_exec('wmic csproduct get uuid');
        if ($uid_raw) {
            $lines = explode("\n", trim($uid_raw));
            $uid = isset($lines[1]) ? trim($lines[1]) : "";
        }
    }
    
    if (empty($uid) || trim($uid) === 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF') {
        $uid = gethostname() . php_uname('v'); 
    }

    $hash = strtoupper(md5(APP_NAME . '-' . $uid));
    return "LP-" . substr($hash, 0, 4) . "-" . substr($hash, 4, 4) . "-" . substr($hash, 8, 4);
}

// La URL de la API ahora es dinámica
$serverInput = getLicenciaServerIp();
// Limpiamos espacios y slashes accidentales
$serverInput = trim($serverInput, " /");

$apiUrlBase = (strpos($serverInput, 'http') === 0) ? $serverInput : 'http://' . $serverInput;

if (!defined('LICENCIA_API_URL')) {
    define('LICENCIA_API_URL', $apiUrlBase . '/app_engine/api/check_license.php');
}

function validarLicenciaSistema() {
    $cache_file = dirname(__FILE__) . '/.licencia_last_success';

    // 1. Evitar chequeos innecesarios (Caché por Sesión)
    // Validamos online cada 24 horas, a menos que se fuerce la sincronización
    if (!isset($_GET['force_sync']) && isset($_SESSION['licencia_status']) && $_SESSION['licencia_status'] === 'active') {
        if (isset($_SESSION['licencia_last_ping']) && (time() - $_SESSION['licencia_last_ping'] < 86400)) {
            return true;
        }
    }

    // 2. Preparar datos para el App Engine
    $data = [
        'license_key' => LICENCIA_KEY,
        'host'        => $_SERVER['HTTP_HOST'],
        'client_ip'   => $_SERVER['REMOTE_ADDR'],
        'os'          => PHP_OS,
        'version_pos' => APP_VERSION,
        'hwid'        => obtenerHardwareID()
    ];

    // 3. Petición vía cURL a tu App Engine
    $ch = curl_init(LICENCIA_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // Timeout rápido de conexión
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);         // Timeout de espera

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // 4. Procesar Respuesta
    if ($curl_error || $http_code !== 200) {
        // Guardamos el error para debug
        $_SESSION['licencia_last_error'] = ($curl_error ?: "Error HTTP $http_code") . " al conectar con " . LICENCIA_API_URL;

        // Si falla la conexión por internet, aplicamos el periodo de gracia de 20 días
        if (file_exists($cache_file)) {
            $last_success = (int)file_get_contents($cache_file);
            // 20 días = 1,728,000 segundos (20 * 24 * 60 * 60)
            if ((time() - $last_success) < 1728000) {
                $_SESSION['licencia_status'] = 'active';
                return true;
            }
        }
        return false;
    }

    $res = json_decode($response, true);

    if (isset($res['status'])) {
        if ($res['status'] === 'active') {
            unset($_SESSION['licencia_last_error']);
            $_SESSION['licencia_status'] = 'active';
            $_SESSION['licencia_last_ping'] = time();
            // Guardamos el timestamp actual localmente para el periodo de gracia
            file_put_contents($cache_file, time());
            return true;
        } elseif ($res['status'] === 'blocked') {
            $_SESSION['licencia_status'] = 'blocked';
            $_SESSION['licencia_last_error'] = $res['message'] ?? 'Licencia bloqueada por el administrador.';
            // Eliminar el archivo de caché para invalidar el periodo de gracia inmediatamente
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }
            return false;
        } else {
            // Cualquier otro estado (ej. 'expired', o desconocido)
            $_SESSION['licencia_status'] = 'expired';
            $_SESSION['licencia_last_error'] = $res['message'] ?? 'Licencia expirada o inválida.';
            return false;
        }
    } else {
        // No hay campo 'status' en la respuesta del servidor
        $_SESSION['licencia_status'] = 'expired';
        $_SESSION['licencia_last_error'] = 'Respuesta inválida del servidor de licencias.';
        return false;
    }
}

/**
 * Retorna un array con el detalle de la licencia para mostrar en la interfaz
 */
function obtenerEstadoLicencia() {
    $cache_file = dirname(__FILE__) . '/.licencia_last_success';
    $data = [
        'key' => LICENCIA_KEY,
        'server_ip' => getLicenciaServerIp(), // Obtener la IP dinámicamente
        'status' => $_SESSION['licencia_status'] ?? 'unknown',
        'last_error' => $_SESSION['licencia_last_error'] ?? null,
        'last_check' => 'Nunca',
        'grace_days_left' => 0,
        'is_offline' => false,
        'hw_id' => obtenerHardwareID()
    ];

    if (file_exists($cache_file)) {
        $last_success = (int)file_get_contents($cache_file);
        $data['last_check'] = date('d/m/Y H:i', $last_success);
        
        $diff = time() - $last_success;
        $remaining_seconds = 1728000 - $diff; // 20 días (1.728.000 seg)
        $data['grace_days_left'] = max(0, floor($remaining_seconds / 86400));
        // Si pasaron más de 24 horas desde el último ping exitoso, se considera modo offline
        if ($diff > 86400) $data['is_offline'] = true;
    }

    return $data;
}