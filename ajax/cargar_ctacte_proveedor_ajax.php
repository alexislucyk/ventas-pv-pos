<?php
header('Content-Type: application/json');
session_start();

// Verifica sesión (seguridad)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Acceso no autorizado.']);
    exit();
}

require '../config/db_config.php';

// 1. Obtener y validar el ID del proveedor
$id_proveedor = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id_proveedor) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'ID de proveedor inválido o faltante.']);
    exit();
}

try {
    // 2. Consulta de movimientos
    // Ordenar por fecha para calcular el saldo en el frontend en orden cronológico
    $sql = "SELECT 
                cc.id as ctacte_id, -- ID del movimiento en ctacte_proveedores
                cc.fecha,
                cc.movimiento,
                cc.n_documento,
                cc.haber,
                cc.debe,
                c.id as compra_id, -- ID de la compra (si es un movimiento de compra)
                c.total_compra, -- Total de la compra (si es un movimiento de compra)
                -- Calcular el saldo pendiente para esta factura específica (si es una factura)
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
                cc.id_proveedor = :id_proveedor
            ORDER BY 
                cc.fecha ASC"; // La fecha debe estar en orden ascendente para el cálculo en JS

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_proveedor', $id_proveedor, PDO::PARAM_INT);
    $stmt->execute();
    
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Devolver la respuesta en formato JSON
    echo json_encode([
        'exito' => true,
        'movimientos' => $movimientos
    ]);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("Error al cargar Cta. Cte. Proveedor: " . $e->getMessage());
    echo json_encode(['error' => 'Error en la base de datos al cargar el historial.']);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Error general al cargar Cta. Cte. Proveedor: " . $e->getMessage());
    echo json_encode(['error' => 'Ocurrió un error inesperado.']);
}

?>