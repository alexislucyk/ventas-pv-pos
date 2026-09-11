<?php
// ajax/buscar_ventas_sin_facturar.php
include '../pages/infosesion.php';
require_once '../config/db_config.php';

header('Content-Type: application/json');

// Verificar permiso
require_permiso('pages/comprobantes_externos.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    echo json_encode(['status' => 'error', 'message' => 'No se encontró empresa en sesión.']);
    exit;
}

// Capturar filtros
$fecha_desde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
$cliente_buscar = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';

try {
    $sql = "SELECT 
                v.id as venta_id,
                v.n_documento,
                v.fecha_venta,
                v.total_venta,
                v.cond_pago,
                v.estado,
                COALESCE(CONCAT(c.apellido, ', ', c.nombre), 'Sin Cliente') as cliente,
                c.cuit as cuit_cliente
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = v.empresa_id
            LEFT JOIN ventas_afip va ON v.id = va.id_venta
            LEFT JOIN venta_comprobante_externo vce ON v.id = vce.venta_id
            WHERE v.empresa_id = ? AND v.estado = 'Finalizada' AND va.id IS NULL AND vce.id IS NULL";
    
    $params = [$empresa_id];
    
    // Aplicar filtros de fecha
    if (!empty($fecha_desde)) {
        $sql .= " AND DATE(v.fecha_venta) >= ?";
        $params[] = $fecha_desde;
    }
    if (!empty($fecha_hasta)) {
        $sql .= " AND DATE(v.fecha_venta) <= ?";
        $params[] = $fecha_hasta;
    }
    
    // Aplicar filtro de cliente
    if (!empty($cliente_buscar)) {
        $sql .= " AND (c.apellido LIKE ? OR c.nombre LIKE ? OR c.cuit LIKE ? OR v.n_documento LIKE ?)";
        $like = "%$cliente_buscar%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    
    $sql .= " ORDER BY v.fecha_venta DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'data' => $ventas,
        'total' => count($ventas)
    ]);
    
} catch (PDOException $e) {
    error_log("Error al buscar ventas sin facturar: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error al buscar ventas: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general al buscar ventas: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error inesperado.']);
}
