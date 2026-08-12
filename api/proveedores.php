<?php
// API Endpoint para obtener lista de proveedores
// Acceso exclusivo por VPN Radmin con token de autenticación
//
// FILTRADO DE PROVEEDORES:
// Devuelve SOLO los proveedores autorizados globalmente en la tabla
// proveedores_autorizados. Cualquier visitante de la consulta remota
// verá únicamente estos proveedores (sin depender del usuario).

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Token de acceso (debe coincidir con el configurado en la página de consulta)
define('API_TOKEN', 'consignaciones_remote_2024_vpn');

// Validar token de acceso
$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($token !== API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Token de acceso inválido o faltante']);
    exit();
}

// Obtener empresa_id desde parámetro GET o usar la primera disponible
$empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : null;

try {
    require_once '../config/db_config.php';
    
    if (!$empresa_id) {
        // Si no se proporciona empresa_id, obtener la primera disponible
        $stmt_empresa = $pdo->query("SELECT id FROM empresas LIMIT 1");
        $empresa_id = $stmt_empresa->fetchColumn();
        
        if (!$empresa_id) {
            throw new Exception('No se encontró empresa configurada');
        }
    }
    
    // Construir query: devolver SOLO los proveedores autorizados en la tabla global
    // proveedores_autorizados (sin importar el usuario). Cualquier visitante de la
    // consulta remota verá únicamente estos proveedores.
    $sql = "SELECT TRIM(proveedor_nombre) as proveedor_nombre 
            FROM proveedores_autorizados 
            WHERE empresa_id = :empresa_id 
              AND proveedor_nombre IS NOT NULL 
              AND TRIM(proveedor_nombre) != ''
            ORDER BY proveedor_nombre ASC";
    $params = [':empresa_id' => $empresa_id];
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $proveedores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $response = [
        'success' => true,
        'proveedores' => $proveedores
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al obtener proveedores',
        'mensaje' => $e->getMessage()
    ]);
}
?>