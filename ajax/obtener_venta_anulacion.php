<?php
// ajax/obtener_venta_anulacion.php
header('Content-Type: application/json');
require '../config/db_config.php';

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    echo json_encode(["error" => "No se proporcionó un número de documento."]);
    exit;
}

$n_documento = $_GET['n_documento'];

try {
    // 1. Buscar la Cabecera de la Venta
    // Ajustado: c.id en lugar de id_cliente y CONCAT para el nombre completo
    $sql_cabecera = "SELECT v.n_documento, v.fecha_venta, v.total_venta, v.estado, 
                            CONCAT(c.apellido, ', ', c.nombre) as cliente_nombre 
                     FROM ventas v 
                     INNER JOIN clientes c ON v.id_cliente = c.id 
                     WHERE v.n_documento = ?";
    
    $stmt = $pdo->prepare($sql_cabecera);
    $stmt->execute([$n_documento]);
    $cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cabecera) {
        echo json_encode(["error" => "No se encontró ninguna venta con el N° $n_documento"]);
        exit;
    }

    // 2. Buscar el Detalle de la Venta
    $sql_detalle = "SELECT cod_prod, descripcion, cant, p_unit, total 
                    FROM ventas_detalle 
                    WHERE n_documento = ?";
    
    $stmt_det = $pdo->prepare($sql_detalle);
    $stmt_det->execute([$n_documento]);
    $detalle = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    // 3. Respuesta JSON
    echo json_encode([
        "cabecera" => $cabecera,
        "detalle" => $detalle
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Error en el servidor: " . $e->getMessage()]);
}