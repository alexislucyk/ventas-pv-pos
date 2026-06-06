<?php
// config/db_config.php - CONFIGURACIÓN DINÁMICA POS_DEV / POS_PROD

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
// Forzar separador decimal de punto para evitar truncamiento con comas en cálculos y DB
setlocale(LC_NUMERIC, 'C');

// 1. Detección automática del entorno según la URL
// Usamos SCRIPT_NAME que es más estable que REQUEST_URI para detectar carpetas
$script_path = $_SERVER['SCRIPT_NAME'];

if (strpos($script_path, '/pos_dev/') !== false) {
    // --- AMBIENTE DE DESARROLLO ---
    $db_name = 'pos_dev';      // Tu DB de pruebas
    $folder  = '/pos_dev/';    // Tu carpeta de pruebas
    $ambiente = "MODO DESARROLLO (PRUEBAS)";
} else {
    // --- AMBIENTE DE PRODUCCIÓN ---
    $db_name = 'pos_prod';     // Tu DB real del negocio
    $folder  = '/pos_prod/';   // Tu carpeta del mostrador
    $ambiente = "PRODUCCIÓN";
}

// 2. Definición de Constantes para evitar rutas absolutas "quemadas"
if (!defined('URL_BASE')) {
    define('URL_BASE', $folder);
}
if (!defined('PATH_BASE')) {
    define('PATH_BASE', $_SERVER['DOCUMENT_ROOT'] . $folder);
}

// 3. Datos de conexión (Ajustados a tu IP y pass)
$host    = '192.168.7.45';
$user    = 'root';
$pass    = 'isidoro9';

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