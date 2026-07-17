<?php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Falta empresa_id en sesión.']);
    exit();
}

header('Content-Type: application/json'); // Indicamos que la respuesta es JSON

// 1. Control de Conexión y Sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'Debe iniciar sesión.']);
    exit();
}

require '../config/db_config.php'; 

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['error' => 'Conexión a la base de datos no disponible.']);
    exit();
}

$id_venta = isset($_GET['id_venta']) ? (int)$_GET['id_venta'] : 0;
$n_documento_get = isset($_GET['n_documento']) ? (int)$_GET['n_documento'] : 0;

if ($id_venta <= 0 && $n_documento_get <= 0) {
    echo json_encode(['error' => 'ID de venta no válido.']);
    exit();
}

$response = [
    'cabecera' => null,
    'cliente' => [
        'id_cliente' => 0,
        'nombre_completo' => 'Venta Genérica',
        'num_documento' => ''
    ],
    'detalle' => []
];

try {
    $where = "v.id = :id";
    $params = [':id' => $id_venta, ':empresa_id' => $empresa_id];
    if ($id_venta <= 0 && $n_documento_get > 0) {
        $where = "v.n_documento = :n_documento";
        $params[':n_documento'] = $n_documento_get;
    }

    $sql_cabecera = "
        SELECT 
            v.*, 
            c.id AS cliente_id,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
            c.cuit AS num_documento
        FROM ventas v
        LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id
        WHERE $where AND v.empresa_id = :empresa_id";
    
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->execute($params);
    $venta = $stmt_cabecera->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        echo json_encode(['error' => 'Venta no encontrada.']);
        exit();
    }

    // Rellenar la respuesta
    $response['cabecera'] = $venta;
    
    // Si la venta tiene un cliente asociado
    if ($venta['cliente_id']) {
        $response['cliente']['id_cliente'] = $venta['cliente_id'];
        $response['cliente']['nombre_completo'] = $venta['nombre_completo'];
        $response['cliente']['num_documento'] = $venta['num_documento'];
    }

    // --- B) Obtener Detalle de la Venta (Productos del Carrito) ---
    $n_documento = $venta['n_documento'];
    
    $sql_detalle = "
        SELECT 
            cod_prod, 
            descripcion, 
            cant, 
            p_unit, 
            descuento_unitario,
            total
        FROM ventas_detalle 
        WHERE n_documento = :n_documento AND empresa_id = :empresa_id";
    
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_id]);
    $detalle_productos = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

    // Ajustamos los tipos de dato (de string a numérico) para que JS los maneje bien
    foreach ($detalle_productos as &$item) {
        $item['p_unit'] = (float)$item['p_unit'];
        $item['cant'] = (float)$item['cant'];
        $item['total'] = (float)$item['total'];
    }
    unset($item); // Importante para liberar la referencia

    $response['detalle'] = $detalle_productos;

    // --- C) Devolver la respuesta final ---
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error al obtener detalle de venta: " . $e->getMessage());
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
}