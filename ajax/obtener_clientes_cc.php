<?php
// ajax/obtener_clientes_cc.php
if (ob_get_level()) ob_end_clean(); // Limpiar buffers para asegurar JSON puro
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

header('Content-Type: application/json');

// Seguridad básica: verificar sesión (db_config ya inició sesión)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Cargamos todos los clientes habilitados para Cta. Cte.
    // COALESCE evita que el nombre completo sea NULL si el nombre está vacío
    $sql_clientes = "
        SELECT id AS id_cliente, 
               CONCAT(apellido, COALESCE(CONCAT(', ', nombre), '')) AS nombre_completo, 
               cuit
        FROM clientes 
        WHERE habilita_cta = 'Si' 
        ORDER BY apellido ASC";
    
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