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
                fecha,
                movimiento,
                n_documento,
                haber,
                debe
            FROM 
                ctacte_proveedores
            WHERE 
                id_proveedor = :id_proveedor
            ORDER BY 
                fecha ASC"; // La fecha debe estar en orden ascendente para el cálculo en JS

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