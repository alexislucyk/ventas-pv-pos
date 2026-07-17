<?php
// Test directo de backup
define('BACKUP_INCLUDED', true);

require_once dirname(__FILE__) . '/../config/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Simular usuario logueado
$_SESSION['usuario_id'] = 1;

echo "Iniciando test de backup directo...\n";
echo "Base de datos: " . $db_name . "\n";
echo "Ruta de backups: " . PATH_BASE . "backups/\n\n";

// Incluir el script de backup
ob_start();
include __DIR__ . '/backup_database.php';
$output = ob_get_clean();

echo "\nOutput del backup:\n";
echo $output . "\n\n";

echo "Test completado.\n";
?>
