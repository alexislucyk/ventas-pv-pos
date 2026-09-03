<?php
// Ejecutar migración 43 - Control del QR en ticket de venta
require_once dirname(__FILE__) . '/../config/db_config.php';

try {
    echo "Ejecutando migración 43: Control del QR en ticket de venta\n";
    echo "=========================================================\n\n";

    $sql_file = dirname(__FILE__) . '/../migrations/43_mostrar_qr_ticket.sql';
    $sql = file_get_contents($sql_file);

    if (!$sql) {
        throw new Exception("No se pudo leer el archivo de migración");
    }

    $pdo->exec($sql);

    echo "✅ Migración ejecutada exitosamente\n\n";

    // Verificar columna
    $col = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'mostrar_qr_ticket'")->fetchColumn();
    echo ($col ? "✅ Columna mostrar_qr_ticket creada" : "❌ Columna mostrar_qr_ticket NO encontrada") . "\n";

    echo "\n=========================================\n";
    echo "Migración completada exitosamente\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}