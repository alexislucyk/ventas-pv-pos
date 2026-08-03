<?php
// ajax/aplicar_interes_ajax.php
// Aplica intereses por mora a un cliente

include '../pages/infosesion.php';
require '../config/db_config.php';
require '../funciones/funciones_intereses.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$id_cliente = $_POST['id_cliente'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario_id = $_SESSION['user_id'] ?? null;

if (!$id_cliente || !$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    // Verificar que el cliente existe
    $sql_cliente = "SELECT id FROM clientes WHERE id = :id AND empresa_id = :empresa_id";
    $stmt_cliente = $pdo->prepare($sql_cliente);
    $stmt_cliente->execute([
        ':id' => $id_cliente,
        ':empresa_id' => $empresa_id
    ]);
    
    if (!$stmt_cliente->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['success' => false, 'error' => 'Cliente no encontrado']);
        exit;
    }
    
    // Aplicar intereses
    $resultado = aplicarInteresesMora($id_cliente, $pdo, $usuario_id);
    
    if ($resultado['success']) {
        echo json_encode([
            'success' => true,
            'mensaje' => "Intereses aplicados exitosamente: " . formatearMontoInteres($resultado['monto_aplicado']),
            'monto' => $resultado['monto_aplicado'],
            'id_movimiento' => $resultado['id_movimiento'],
            'detalle' => $resultado['detalle']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $resultado['error'] ?? 'No se pudo aplicar intereses'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error en aplicar_interes_ajax.php: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>