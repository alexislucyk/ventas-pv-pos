<?php
// Ejecutar migración 47 - movimientos.monto con dos decimales (centavos)
require_once dirname(__FILE__) . '/../config/db_config.php';

try {
    echo "Ejecutando migración 47: movimientos.monto DECIMAL(10,2)\n";
    echo "=========================================================\n\n";

    $sql_file = dirname(__FILE__) . '/../migrations/47_monto_movimientos_decimal.sql';
    $sql = file_get_contents($sql_file);

    if (!$sql) {
        throw new Exception("No se pudo leer el archivo de migración");
    }

    $tipo_antes = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'monto'")
                      ->fetchColumn();
    echo "Tipo anterior de movimientos.monto: $tipo_antes\n";

    $pdo->exec($sql);

    echo "✅ Migración ejecutada exitosamente\n\n";

    $tipo_despues = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'monto'")
                        ->fetchColumn();
    echo "Tipo actual de movimientos.monto: $tipo_despues\n";
    echo (stripos((string)$tipo_despues, 'decimal(10,2)') !== false
            ? "✅ Columna con dos decimales"
            : "❌ La columna sigue sin dos decimales") . "\n";

    echo "\n=========================================\n";
    echo "Migración completada exitosamente\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
