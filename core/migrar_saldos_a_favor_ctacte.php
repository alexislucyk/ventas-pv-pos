<?php
/**
 * Aplicador idempotente de la migración 49.
 * Uso: php core/migrar_saldos_a_favor_ctacte.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/db_config.php';

try {
    $sql = file_get_contents(__DIR__ . '/../migrations/49_saldos_a_favor_ctacte.sql');
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer la migración 49.');
    }
    $pdo->exec($sql);
    echo "Migración 49 aplicada correctamente.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
