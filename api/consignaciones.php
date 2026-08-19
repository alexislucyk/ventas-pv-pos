<?php
// API Endpoint para consulta de consignaciones
// Acceso exclusivo por VPN Radmin con token de autenticación

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Token de acceso (debe coincidir con el configurado en la página de consulta)
define('API_TOKEN', 'consignaciones_remote_2024_vpn');

// Validar token de acceso
$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($token !== API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Token de acceso inválido o faltante']);
    exit();
}

// Parámetros de consulta
$proveedor = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$desde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
$hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

if (empty($proveedor)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetro proveedor requerido']);
    exit();
}

try {
    require_once '../config/db_config.php';
    
    // Obtener empresa_id (en un entorno real, esto podría venir de un parámetro o configuración)
    // Por ahora asumimos una empresa por defecto o la primera disponible
    $stmt_empresa = $pdo->query("SELECT id FROM empresas LIMIT 1");
    $empresa_id = $stmt_empresa->fetchColumn();
    
    if (!$empresa_id) {
        throw new Exception('No se encontró empresa configurada');
    }
    
    // Validar que el proveedor consultado esté autorizado globalmente
    $stmt_auth = $pdo->prepare(
        "SELECT COUNT(*) FROM proveedores_autorizados 
         WHERE TRIM(proveedor_nombre) COLLATE utf8mb4_unicode_ci = TRIM(CONVERT(:proveedor USING utf8mb4)) COLLATE utf8mb4_unicode_ci
           AND empresa_id = :empresa_id"
    );
    $stmt_auth->execute([
        ':proveedor' => $proveedor,
        ':empresa_id' => $empresa_id
    ]);
    $autorizado = (int)$stmt_auth->fetchColumn();
    
    if ($autorizado === 0) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Acceso denegado',
            'mensaje' => 'El proveedor seleccionado no está autorizado para consulta remota.'
        ]);
        exit();
    }
    
    $sql = "SELECT
                vd.cod_prod, 
                vd.descripcion, 
                SUM(vd.cant) as total_cant,
                vd.p_unit as precio_venta,
                COALESCE(p.p_compra, 0) as costo_unitario,
                SUM(vd.total) as subtotal_venta,
                SUM(COALESCE(p.p_compra, 0) * vd.cant) as subtotal_costo,
                SUM(vd.total - (COALESCE(p.p_compra, 0) * vd.cant)) as ganancia_total
            FROM ventas_detalle vd
            JOIN ventas v ON vd.n_documento = v.n_documento AND v.empresa_id = :empresa_id1
            JOIN productos p ON vd.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci AND p.empresa_id = :empresa_id2
            WHERE v.estado = 'Finalizada' 
              AND TRIM(p.proveedor) = :proveedor
              AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta
            GROUP BY vd.cod_prod, vd.descripcion, vd.p_unit, p.p_compra
            ORDER BY vd.descripcion ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id1' => $empresa_id,
        ':empresa_id2' => $empresa_id,
        ':proveedor' => $proveedor,
        ':desde' => $desde,
        ':hasta' => $hasta
    ]);
    
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular totales
    $totales = ['venta' => 0, 'costo' => 0, 'ganancia' => 0];
    foreach ($resultados as $r) {
        $totales['venta'] += $r['subtotal_venta'];
        $totales['costo'] += $r['subtotal_costo'];
        $totales['ganancia'] += $r['ganancia_total'];
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'proveedor' => $proveedor,
        'desde' => $desde,
        'hasta' => $hasta,
        'totales' => [
            'venta' => round($totales['venta'], 2),
            'costo' => round($totales['costo'], 2),
            'ganancia' => round($totales['ganancia'], 2),
            'pagar_proveedor' => round($totales['costo'] + ($totales['ganancia'] / 2), 2),
            'utilidad_negocio' => round($totales['ganancia'] / 2, 2)
        ],
        'detalle' => array_map(function($r) {
            return [
                'cod_prod' => $r['cod_prod'],
                'descripcion' => $r['descripcion'],
                'total_cant' => round($r['total_cant'], 2),
                'precio_venta' => round($r['precio_venta'], 2),
                'costo_unitario' => round($r['costo_unitario'], 2),
                'subtotal_venta' => round($r['subtotal_venta'], 2),
                'subtotal_costo' => round($r['subtotal_costo'], 2),
                'ganancia_total' => round($r['ganancia_total'], 2),
                'mi_parte' => round($r['ganancia_total'] / 2, 2),
                'parte_proveedor' => round($r['ganancia_total'] / 2, 2)
            ];
        }, $resultados)
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al procesar la consulta',
        'mensaje' => $e->getMessage()
    ]);
}
?>