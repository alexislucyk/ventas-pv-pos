<?php
// ajax/pos_productos_ajax.php - Listado paginado de productos para la caja registradora
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['ok' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$rubro = isset($_GET['rubro']) ? trim($_GET['rubro']) : '';
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina = 48;

$where = 'p.empresa_id = ?';
$params = [$empresa_id];

if ($q !== '') {
    $where .= ' AND (p.cod_prod LIKE ? OR p.descripcion LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($rubro !== '' && $rubro !== 'todos') {
    $where .= ' AND p.rubro = ?';
    $params[] = $rubro;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM productos p WHERE $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($pagina - 1) * $por_pagina;

    if ((int)$sucursal_id === 0) {
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_compra, p.p_venta, p.moneda, p.rubro,
                       COALESCE((SELECT SUM(s2.stock_actual) FROM stocks s2
                                 WHERE s2.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci), 0) AS stock
                FROM productos p
                WHERE $where
                ORDER BY p.descripcion ASC
                LIMIT $por_pagina OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_compra, p.p_venta, p.moneda, p.rubro,
                       COALESCE(s.stock_actual, 0) AS stock
                FROM productos p
                LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci
                                  AND s.sucursal_id = ?
                WHERE $where
                ORDER BY p.descripcion ASC
                LIMIT $por_pagina OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$sucursal_id], $params));
    }

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dolar_operativo = null;
    $cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
    if (file_exists($cache_path)) {
        $cache = json_decode(file_get_contents($cache_path), true);
        if (is_array($cache) && isset($cache['venta']) && is_numeric($cache['venta'])) {
            $dolar_operativo = (float)$cache['venta'] * 1.02;
        }
    }

    foreach ($productos as &$p) {
        $p['p_venta_pesos'] = null;
        if (($p['moneda'] ?? '') === 'dolar' && $dolar_operativo && $dolar_operativo > 0) {
            $p['p_venta_pesos'] = (float)$p['p_venta'] * $dolar_operativo;
        }
        $p['stock'] = (float)$p['stock'];
    }
    unset($p);

    echo json_encode([
        'ok' => true,
        'productos' => $productos,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $por_pagina,
        'tieneMas' => ($pagina * $por_pagina) < $total
    ]);
} catch (Exception $e) {
    error_log('Error en pos_productos_ajax: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno al listar productos.']);
}