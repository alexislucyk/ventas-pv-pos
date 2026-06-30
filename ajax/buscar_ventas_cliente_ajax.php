<?php
// archivo: ajax/buscar_ventas_cliente_ajax.php
include '../pages/infosesion.php';
require_once '../config/db_config.php';

header('Content-Type: application/json');

$id_cliente = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

if ($id_cliente <= 0) {
    echo json_encode([]);
    exit;
}

try {
    // Obtenemos las últimas ventas del cliente, incluyendo las anuladas para referencia
    $stmt = $pdo->prepare("SELECT n_documento, fecha_venta, total_venta, cond_pago, estado 
                           FROM ventas 
                           WHERE id_cliente = ? 
                           ORDER BY n_documento DESC");
    $stmt->execute([$id_cliente]);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($ventas);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}