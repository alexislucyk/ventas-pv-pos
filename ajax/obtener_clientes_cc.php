<?php
// ajax/obtener_clientes_cc.php
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

header('Content-Type: application/json');

try {
    // Consulta para obtener solo los clientes que tienen movimientos registrados en ctacte
    $sql_clientes = "
        SELECT DISTINCT
            c.id AS id_cliente,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
            c.cuit
        FROM clientes c
        INNER JOIN ctacte m ON c.id = m.id_cliente
        ORDER BY c.apellido ASC;
    ";
    
    $stmt_clientes = $pdo->query($sql_clientes);
    $clientes_cc = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($clientes_cc);

} catch (Exception $e) {
    // Manejo de errores
    error_log("Error al cargar lista de clientes CC (AJAX): " . $e->getMessage());
    // Devolvemos un array vacío en caso de error
    http_response_code(500);
    echo json_encode([]); 
}
?>