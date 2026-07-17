<?php
// pages/buscar_cliente_ajax.php
// Aseguramos sesión y encabezados
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


require '../config/db_config.php';

// Para cuentas_corrientes.php el buscador solo requiere empresa_id.
// usuario_id a veces no está en esta pantalla/flujo, así que no bloqueamos.
if (empty($_SESSION['empresa_id'])) {
    echo json_encode(['error' => 'Sesión inválida (empresa_id faltante)']);
    http_response_code(401);
    exit;
}


$empresa_id = (int)$_SESSION['empresa_id'];
$busqueda = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Para probar rápido: si llega vacío, devolvemos vacío.
if (strlen($busqueda) < 1) {
    echo json_encode([]);
    exit;
}


try {
    $sql = "SELECT 
                id AS id_cliente,
                CONCAT(apellido, ', ', nombre) AS nombre_completo,
                cuit AS num_documento 
            FROM clientes 
            WHERE empresa_id = :empresa_id
              AND (CONCAT(apellido, ', ', nombre) LIKE :busqueda_nombre OR cuit LIKE :busqueda_cuit)
            ORDER BY nombre_completo 
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $param = '%' . $busqueda . '%';

    $stmt->execute([':empresa_id' => $empresa_id, ':busqueda_nombre' => $param, ':busqueda_cuit' => $param]);
     
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
     
    echo json_encode($clientes);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error de DB", "detalle" => $e->getMessage()]);
}
?>