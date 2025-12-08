<?php
// Datos de conexión
$host = 'localhost';
$db   = 'ventas'; // Asegúrate de cambiar esto por el nombre real de tu DB
$user = 'root';      // Usuario de la base de datos
$pass = 'isidoro9';          // Contraseña (usa una en producción)
$charset = 'utf8mb4';

// Data Source Name (DSN)
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Opciones de PDO:
// 1. Mostrar errores como excepciones (crucial para debug).
// 2. Modo de obtención predeterminado: Asociativo (nombres de columna).
// 3. Desactivar emulación de sentencias preparadas (para mayor seguridad).
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     // Si la conexión es exitosa, la variable $pdo contiene el objeto de conexión
} catch (\PDOException $e) {
     // Si hay un error de conexión, terminamos el script y mostramos el error
     die("Error de Conexión a la Base de Datos: " . $e->getMessage());
}
?>