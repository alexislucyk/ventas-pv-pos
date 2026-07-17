<?php
header('Content-Type: application/json');
include '../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['error' => 'Falta empresa_id en sesión.']);
    exit();
}

require '../config/db_config.php';

$id_proveedor = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id_proveedor) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de proveedor inválido o faltante.']);
    exit();
}

try {
    $sql = "SELECT 
                cc.id as ctacte_id,
                cc.fecha,
                cc.movimiento,
                cc.n_documento,
                cc.haber,
                cc.debe,
                c.id as compra_id,
                c.total_compra,
                CASE
                    WHEN c.id IS NOT NULL THEN
                        c.total_compra - (
                            SELECT COALESCE(SUM(sub_cc.debe), 0) 
                            FROM ctacte_proveedores sub_cc 
                            WHERE sub_cc.compra_id = c.id 
                               OR (sub_cc.compra_id IS NULL 
                                   AND sub_cc.n_documento = c.n_documento 
                                   AND sub_cc.id_proveedor = c.cod_proveedor)
                        )
                    ELSE 0
                END as saldo_pendiente_factura,
                c.fecha_vencimiento,
                c.observaciones
            FROM 
                ctacte_proveedores cc
            LEFT JOIN
                compras c ON (cc.compra_id = c.id OR (cc.compra_id IS NULL AND cc.n_documento = c.n_documento AND cc.id_proveedor = c.cod_proveedor))
            WHERE 
                cc.id_proveedor = :id_proveedor AND cc.empresa_id = :empresa_id
            ORDER BY 
                cc.fecha ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_proveedor', $id_proveedor, PDO::PARAM_INT);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'exito' => true,
        'movimientos' => $movimientos
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Error al cargar Cta. Cte. Proveedor: " . $e->getMessage());
    echo json_encode(['error' => 'Error en la base de datos al cargar el historial.']);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Error general al cargar Cta. Cte. Proveedor: " . $e->getMessage());
    echo json_encode(['error' => 'Ocurrió un error inesperado.']);
}

?>