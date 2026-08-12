<?php
// ajax/obtener_venta_anulacion.php
include '../pages/infosesion.php';
header('Content-Type: application/json');
require '../config/db_config.php';

error_log("=== OBTENER_VENTA_ANULACION.PHP ===");
error_log("GET params: " . print_r($_GET, true));

$empresa_id = $_SESSION['empresa_id'] ?? null;
error_log("empresa_id: " . $empresa_id);

if (!$empresa_id || !isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    error_log("Error: Faltan parámetros");
    echo json_encode(["error" => "No se proporcionó un número de documento."]);
    exit;
}

$n_documento = $_GET['n_documento'];
error_log("n_documento buscado: " . $n_documento);

try {
    $sql_cabecera = "SELECT v.n_documento, v.fecha_venta, v.total_venta, v.estado, 
                            CASE 
                                WHEN v.id_cliente = 0 THEN 'CONSUMIDOR FINAL'
                                ELSE CONCAT(c.apellido, ', ', c.nombre)
                            END as cliente_nombre 
                     FROM ventas v 
                     LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = ?
                     WHERE v.n_documento = ? AND v.empresa_id = ?";
    
    $stmt = $pdo->prepare($sql_cabecera);
    $stmt->execute([$empresa_id, $n_documento, $empresa_id]);
    $cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Cabecera encontrada: " . print_r($cabecera, true));

    if (!$cabecera) {
        error_log("No se encontró venta");
        echo json_encode(["error" => "No se encontró ninguna venta con el N° $n_documento"]);
        exit;
    }

    $sql_detalle = "SELECT cod_prod, descripcion, cant, p_unit, total 
                    FROM ventas_detalle 
                    WHERE n_documento = ? AND empresa_id = ?";
    
    $stmt_det = $pdo->prepare($sql_detalle);
    $stmt_det->execute([$n_documento, $empresa_id]);
    $detalle = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    error_log("Detalle encontrado: " . print_r($detalle, true));
    error_log("=== FIN OBTENER_VENTA_ANULACION.PHP ===");

    echo json_encode([
        "cabecera" => $cabecera,
        "detalle" => $detalle
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => "Error en el servidor: " . $e->getMessage()]);
}