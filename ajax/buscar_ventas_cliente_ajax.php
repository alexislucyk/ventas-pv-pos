<?php
// archivo: ajax/buscar_ventas_cliente_ajax.php
include '../pages/infosesion.php';
require_once '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$id_cliente = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

if (!$empresa_id || $id_cliente <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT n_documento, fecha_venta, total_venta, cond_pago, estado 
                           FROM ventas 
                           WHERE id_cliente = :id_cliente AND empresa_id = :empresa_id
                           ORDER BY n_documento DESC");
    $stmt->execute([':id_cliente' => $id_cliente, ':empresa_id' => $empresa_id]);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($ventas);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}