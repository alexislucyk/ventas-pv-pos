<?php
// ajax/obtener_venta_anulacion.php
include '../pages/infosesion.php';
header('Content-Type: application/json');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id || !isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    echo json_encode(["error" => "No se proporcionó un número de documento."]);
    exit;
}

$n_documento = $_GET['n_documento'];

try {
    $sql_cabecera = "SELECT v.n_documento, v.fecha_venta, v.total_venta, v.estado, 
                            CONCAT(c.apellido, ', ', c.nombre) as cliente_nombre 
                     FROM ventas v 
                     INNER JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id
                     WHERE v.n_documento = :n_documento AND v.empresa_id = :empresa_id";
    
    $stmt = $pdo->prepare($sql_cabecera);
    $stmt->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_id]);
    $cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cabecera) {
        echo json_encode(["error" => "No se encontró ninguna venta con el N° $n_documento"]);
        exit;
    }

    $sql_detalle = "SELECT cod_prod, descripcion, cant, p_unit, total 
                    FROM ventas_detalle 
                    WHERE n_documento = :n_documento AND empresa_id = :empresa_id";
    
    $stmt_det = $pdo->prepare($sql_detalle);
    $stmt_det->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_id]);
    $detalle = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "cabecera" => $cabecera,
        "detalle" => $detalle
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Error en el servidor: " . $e->getMessage()]);
}