<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
require __DIR__ . '/../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$busqueda = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
header('Content-Type: text/plain; charset=utf-8');
echo "EMPRESA_ID=" . var_export($empresa_id, true) . "\n";
echo "BUSQUEDA=" . var_export($busqueda, true) . "\n";
echo "LONG=" . strlen($busqueda) . "\n";

try {
    $sqlCount = "SELECT COUNT(*) FROM clientes WHERE empresa_id = :empresa_id";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute([':empresa_id' => $empresa_id]);
    echo "TOTAL_CLIENTES_EMPRESA=" . $stmtCount->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "ERROR_COUNT=" . $e->getMessage() . "\n";
}

if (strlen($busqueda) >= 3) {
    $likeActual = '%' . $busqueda . '%';
    try {
        $sql = "SELECT id, CONCAT(apellido, ', ', nombre) AS nombre_completo, cuit FROM clientes WHERE empresa_id = :empresa_id AND CONCAT(apellido, ', ', nombre) LIKE :busqueda LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':empresa_id' => $empresa_id, ':busqueda' => $likeActual]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "TOTAL_FILAS=" . count($rows) . "\n";
        foreach ($rows as $i => $row) {
            echo "FILA" . $i . "=" . json_encode($row) . "\n";
        }
    } catch (Throwable $e) {
        echo "ERROR_SQL=" . $e->getMessage() . "\n";
    }
}
