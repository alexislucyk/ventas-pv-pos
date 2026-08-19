<?php
// pages/buscar_producto_codigo_ajax.php
// Búsqueda exacta de producto por código (cod_prod / barcode) para escáner POS

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$codigo = isset($_GET['codigo']) ? trim($_GET['codigo']) : '';

if ($codigo === '') {
    echo json_encode(['success' => false, 'error' => 'Parámetro "codigo" requerido.']);
    exit;
}

try {
    $sql = "SELECT p.cod_prod, p.descripcion, p.p_compra, p.p_venta, p.moneda,
                   COALESCE(s.stock_actual, 0) AS stock
            FROM productos p
            LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci
                                AND s.empresa_id = ?
                                AND s.sucursal_id = ?
            WHERE p.cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
              AND p.empresa_id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id, $sucursal_id, $codigo, $empresa_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado.']);
        exit;
    }

    // Convertir precio a pesos si el producto está en dólares
    $producto['p_venta_pesos'] = null;
    if (($producto['moneda'] ?? '') === 'dolar') {
        $cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
        if (file_exists($cache_path)) {
            $cache = json_decode(file_get_contents($cache_path), true);
            if (is_array($cache) && isset($cache['venta']) && is_numeric($cache['venta'])) {
                $dolar_operativo = (float)$cache['venta'] * 1.02;
                $producto['p_venta_pesos'] = (float)$producto['p_venta'] * $dolar_operativo;
                $producto['dolar_operativo'] = $dolar_operativo;
            }
        }
    }

    echo json_encode(['success' => true, 'producto' => $producto]);
} catch (Exception $e) {
    error_log("Error en buscar_producto_codigo_ajax: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno al buscar producto.']);
}
