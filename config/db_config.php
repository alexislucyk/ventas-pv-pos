<?php
// config/db_config.php - CONFIGURACIÓN DINÁMICA POS_DEV / POS_PROD

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
// Forzar separador decimal de punto para evitar truncamiento con comas en cálculos y DB
setlocale(LC_NUMERIC, 'C');

// 1. Detección automática del entorno y del directorio de la aplicación
// El directorio raíz puede llamarse de cualquier manera (ventas_dev, pos_dev,
// ventas_prod, etc.). El entorno se determina por el SUFIJO del directorio:
//   - termina en "_dev"  → MODO DESARROLLO  (Base de datos: pos_dev)
//   - cualquier otro     → PRODUCCIÓN        (Base de datos: pos_prod)
// Carpeta real de la app en el filesystem: este archivo vive en config/, así que
// la raíz de la app está un nivel arriba. basename() devuelve el NOMBRE de la carpeta.
$app_root_real = dirname(__DIR__);
$app_folder    = basename($app_root_real); // ej. 'pos_dev', 'ventas_dev', 'pos_prod', 'pos_prod_v2'

// --- CARGAR VARIABLES DE ENTORNO (.env) ---
// El cargador ligero está en core/env.php (sin dependencias de Composer).
require_once __DIR__ . '/../core/env.php';
cargarEnv();

// Determinar entorno según el SUFIJO de la carpeta REAL de instalación:
// independiente de la URL con la que se acceda (SCRIPT_NAME/REQUEST_URI).
if (substr($app_folder, -4) === '_dev') {
    $db_name  = 'pos_dev';        // Base de datos de desarrollo
    $ambiente = "MODO DESARROLLO (PRUEBAS)";
} else {
    $db_name  = 'pos_prod';       // Base de datos de producción
        $ambiente = "PRODUCCIÓN";
}

// Permitir override del nombre de BD vía .env (prioridad absoluta)
$_nombre_bd_env = env('DB_NAME');
if (!empty($_nombre_bd_env)) {
    $db_name = $_nombre_bd_env;
}

// El folder de URL es el directorio real en el que corre la app (dinámico).
// Usamos SCRIPT_NAME (más estable que REQUEST_URI) para el segmento de URL;
// si la app está en la raíz web (sin subcarpeta) el directorio queda vacío.
$script_path = trim($_SERVER['SCRIPT_NAME'], '/'); // ej. 'ventas_dev/index.php'
$partes = explode('/', $script_path);
$dir_app = '';
if (isset($partes[0]) && strpos($partes[0], '.php') === false) {
    $dir_app = $partes[0];
}

$folder = $dir_app !== '' ? '/' . $dir_app . '/' : '/';

// 2. Definición de Constantes para evitar rutas absolutas "quemadas"
if (!defined('URL_BASE')) {
    define('URL_BASE', $folder);
}
if (!defined('PATH_BASE')) {
    define('PATH_BASE', $_SERVER['DOCUMENT_ROOT'] . $folder);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $app_root_real);
}
if (!defined('CORE_PATH')) {
    define('CORE_PATH', BASE_PATH . '/core/');
}

// 3. Datos de conexión — leídos de .env con fallback a valores heredados
//    (mantiene compatibilidad durante la migración a .env).
$host    = env('DB_HOST', '192.168.7.45');
$user    = env('DB_USER', 'root');
$pass    = env('DB_PASS', 'isidoro9');

$dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";

// Opciones PDO para PHP 5
$options = array(
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION collation_connection = utf8mb4_unicode_ci"
);

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
     die("Error crítico: No se pudo conectar a la base de datos " . $db_name . ". " . $e->getMessage());
}
?>