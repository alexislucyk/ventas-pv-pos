<?php
// config/db_config.php - CONFIGURACIÓN DINÁMICA POS_DEV / POS_PROD
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. Detección automática del entorno según la URL
$uri = $_SERVER['REQUEST_URI'];

if (strpos($uri, '/pos_dev/') !== false) {
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
define('URL_BASE', $folder); 
define('PATH_BASE', $_SERVER['DOCUMENT_ROOT'] . $folder);

// 3. Datos de conexión (Ajustados a tu IP y pass)
$host    = '192.168.7.45';
$user    = 'root';
$pass    = 'isidoro9';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";

// Opciones PDO para PHP 5
$options = array(
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
);

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
     die("Error crítico: No se pudo conectar a la base de datos " . $db_name . ". " . $e->getMessage());
}
?>