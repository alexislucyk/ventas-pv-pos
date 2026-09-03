<?php
// pages/buscar_producto_ajax.php

header('Content-Type: application/json');

// 1. INCLUSIÓN DE CONFIGURACIÓN
require '../config/db_config.php'; 

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

// 2. VALIDACIÓN DEL PARÁMETRO DE BÚSQUEDA
if (!isset($_GET['q']) || empty(trim($_GET['q'])) || !$empresa_id) {
    echo json_encode([]);
    exit;
}

// 3. DEFINICIÓN DE LA BÚSQUEDA
$busqueda = trim($_GET['q']);
$param_busqueda = '%' . $busqueda . '%';

try {
    // 4. CONSULTA SQL CON SENTENCIA PREPARADA
    // Importante: los stocks pueden tener empresa_id distinto al de sesión por migración;
    // por eso el JOIN usa p.empresa_id (la del producto) como fallback para el stock.
    if ($sucursal_id == 0) {
        // Central: sumar stock de TODAS las sucursales (sin filtrar por empresa en stocks,
        // porque pueden tener empresa_id viejo por migración)
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_compra, p.p_venta, p.moneda, p.rubro, p.unidad_medida, p.es_consignacion,
                       COALESCE((SELECT SUM(s2.stock_actual) FROM stocks s2 WHERE s2.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci), 0) AS stock 
                FROM productos p 
                WHERE (p.cod_prod LIKE ? OR p.descripcion LIKE ?)
                AND p.empresa_id = ?
                ORDER BY p.descripcion
                LIMIT 50";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$param_busqueda, $param_busqueda, $empresa_id]);
    } else {
        $sql = "SELECT p.cod_prod, p.descripcion, p.p_compra, p.p_venta, p.moneda, p.rubro, p.unidad_medida, p.es_consignacion, COALESCE(s.stock_actual, 0) AS stock 
                FROM productos p 
                LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.sucursal_id = ?
                WHERE (p.cod_prod LIKE ? OR p.descripcion LIKE ?)
                AND p.empresa_id = ?
                ORDER BY p.descripcion
                LIMIT 50";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sucursal_id, $param_busqueda, $param_busqueda, $empresa_id]);
    }

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
    $dolar_operativo = null;
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
    }
    unset($p);

    echo json_encode($productos);

} catch (Exception $e) {
    error_log("Error en la búsqueda AJAX: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Error interno al buscar productos."]); 
}

?>