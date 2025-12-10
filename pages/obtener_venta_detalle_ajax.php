<?php
// 1. CORRECCIÓN: Zona Horaria
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
// obtener_venta_detalle_ajax.php
session_start();
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

if ($id_venta <= 0) {
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
    'detalle' => [] // Array de productos del carrito
];

try {
    // --- A) Obtener Cabecera de la Venta y datos del Cliente ---
    $sql_cabecera = "
        SELECT 
            v.*, 
            c.id AS cliente_id,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
            c.cuit AS num_documento
        FROM ventas v
        LEFT JOIN clientes c ON v.id_cliente = c.id
        WHERE v.id = ?";
    
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->execute([$id_venta]);
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
    // Usamos el n_documento para obtener el detalle, que es el campo de enlace
    $n_documento = $venta['n_documento'];
    
    $sql_detalle = "
        SELECT 
            cod_prod, 
            descripcion, 
            cant, 
            p_unit, 
            total
            /* NOTA: Para ser perfectos, aquí deberíamos traer el stock actual 
               de la tabla 'productos' para la validación JS, pero lo omitiremos 
               por simplicidad inicial. */
        FROM ventas_detalle 
        WHERE n_documento = ?";
    
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([$n_documento]);
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