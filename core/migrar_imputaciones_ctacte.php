<?php
/**
 * Migrador idempotente de imputaciones de pagos de cuenta corriente.
 * Uso: php core/migrar_imputaciones_ctacte.php
 *
 * La lógica vive en core/migraciones_ctacte.php para poder reutilizarla desde
 * el módulo de actualizaciones (ver aplicar_actualizacion).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/migraciones_ctacte.php';

/** Alias retrocompatible con el nombre histórico del script. */
function ejecutarMigracionImputaciones(PDO $pdo): array {
    return ejecutarMigracionImputacionesCc($pdo);
}

try {
    echo json_encode(ejecutarMigracionImputacionesCc($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}