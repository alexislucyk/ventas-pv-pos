<?php
// pages/buscar_cliente_ajax.php
header('Content-Type: application/json');

require '../config/db_config.php'; // Asegúrate de que la ruta es correcta

$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($busqueda) < 3) { // Buscamos a partir de 3 caracteres
    echo json_encode([]);
    exit;
}

try {
    // Usamos CONCAT y las columnas reales (apellido, nombre, cuit)
    $sql = "SELECT 
                id AS id_cliente,
                CONCAT(apellido, ', ', nombre) AS nombre_completo,
                cuit AS num_documento 
            FROM clientes 
            WHERE CONCAT(apellido, ' ', nombre) LIKE ? OR cuit LIKE ?
            ORDER BY nombre_completo 
            LIMIT 10"; 
            
    $stmt = $pdo->prepare($sql);
    $param = '%' . $busqueda . '%';
    
    // Vinculamos el parámetro dos veces (para nombre y para cuit)
    $stmt->execute([$param, $param]);
    
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clientes);

} catch (Exception $e) {
    error_log("Error en la búsqueda de clientes AJAX: " . $e->getMessage());
    echo json_encode(["error" => "Error de DB"]); 
}
?>