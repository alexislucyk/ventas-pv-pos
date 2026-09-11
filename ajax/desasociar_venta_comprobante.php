<?php
// ajax/desasociar_venta_comprobante.php
include '../pages/infosesion.php';
require_once '../config/db_config.php';

header('Content-Type: application/json');

// Verificar permiso
require_permiso('pages/comprobantes_externos.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    echo json_encode(['status' => 'error', 'message' => 'No se encontró empresa en sesión.']);
    exit;
}

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

// Capturar datos
$venta_id = isset($_POST['venta_id']) ? (int)$_POST['venta_id'] : 0;
$comprobante_externo_id = isset($_POST['comprobante_externo_id']) ? (int)$_POST['comprobante_externo_id'] : 0;

// Validaciones
if ($venta_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Debe especificar una venta válida.']);
    exit;
}
if ($comprobante_externo_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Debe especificar un comprobante válido.']);
    exit;
}

try {
    // Verificar que la asociación existe
    $stmt_check = $pdo->prepare("SELECT id FROM venta_comprobante_externo 
                                  WHERE venta_id = ? AND comprobante_externo_id = ? AND empresa_id = ?");
    $stmt_check->execute([$venta_id, $comprobante_externo_id, $empresa_id]);
    
    if (!$stmt_check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'No se encontró la asociación especificada.']);
        exit;
    }
    
    // Eliminar asociación
    $sql = "DELETE FROM venta_comprobante_externo 
            WHERE venta_id = ? AND comprobante_externo_id = ? AND empresa_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$venta_id, $comprobante_externo_id, $empresa_id]);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Venta desasociada correctamente del comprobante externo.'
    ]);
    
} catch (PDOException $e) {
    error_log("Error al desasociar venta: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error al desasociar: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general al desasociar venta: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error inesperado.']);
}
