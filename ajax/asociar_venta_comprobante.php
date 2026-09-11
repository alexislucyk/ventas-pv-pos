<?php
// ajax/asociar_venta_comprobante.php
include '../pages/infosesion.php';
require_once '../config/db_config.php';

header('Content-Type: application/json');

// Verificar permiso
require_permiso('pages/comprobantes_externos.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';

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
$monto_asignado = isset($_POST['monto_asignado']) ? (float)$_POST['monto_asignado'] : 0;

// Validaciones
if ($venta_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar una venta válida.']);
    exit;
}
if ($comprobante_externo_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar un comprobante válido.']);
    exit;
}
if ($monto_asignado <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'El monto asignado debe ser mayor a 0.']);
    exit;
}

try {
    // Verificar que la venta existe y pertenece a la empresa
    $stmt_venta = $pdo->prepare("SELECT id, total_venta FROM ventas WHERE id = ? AND empresa_id = ? AND estado = 'Finalizada'");
    $stmt_venta->execute([$venta_id, $empresa_id]);
    $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);
    
    if (!$venta) {
        echo json_encode(['status' => 'error', 'message' => 'La venta no existe o no está finalizada.']);
        exit;
    }
    
    // Verificar que el monto no supere el total de la venta
    if ($monto_asignado > $venta['total_venta']) {
        echo json_encode(['status' => 'error', 'message' => 'El monto asignado no puede superar el total de la venta ($' . number_format($venta['total_venta'], 2, ',', '.') . ').']);
        exit;
    }
    
    // Verificar que el comprobante existe y pertenece a la empresa
    $stmt_comp = $pdo->prepare("SELECT id, total_comprobante FROM comprobantes_externos WHERE id = ? AND empresa_id = ?");
    $stmt_comp->execute([$comprobante_externo_id, $empresa_id]);
    $comprobante = $stmt_comp->fetch(PDO::FETCH_ASSOC);
    
    if (!$comprobante) {
        echo json_encode(['status' => 'error', 'message' => 'El comprobante externo no existe.']);
        exit;
    }
    
    // Verificar que la venta no esté ya asociada a otro comprobante
    $stmt_check = $pdo->prepare("SELECT id FROM venta_comprobante_externo WHERE venta_id = ? AND empresa_id = ?");
    $stmt_check->execute([$venta_id, $empresa_id]);
    
    if ($stmt_check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Esta venta ya está asociada a un comprobante externo.']);
        exit;
    }
    
    // Verificar que no se exceda el total del comprobante
    $stmt_sum = $pdo->prepare("SELECT COALESCE(SUM(monto_asignado), 0) as total_asignado 
                                FROM venta_comprobante_externo 
                                WHERE comprobante_externo_id = ? AND empresa_id = ?");
    $stmt_sum->execute([$comprobante_externo_id, $empresa_id]);
    $suma = $stmt_sum->fetch(PDO::FETCH_ASSOC);
    
    if (($suma['total_asignado'] + $monto_asignado) > $comprobante['total_comprobante']) {
        $disponible = $comprobante['total_comprobante'] - $suma['total_asignado'];
        echo json_encode(['status' => 'error', 'message' => 'El monto excede el disponible del comprobante. Disponible: $' . number_format($disponible, 2, ',', '.')]);
        exit;
    }
    
    // Insertar asociación
    $sql = "INSERT INTO venta_comprobante_externo 
            (empresa_id, venta_id, comprobante_externo_id, monto_asignado, usuario_asociacion) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id, $venta_id, $comprobante_externo_id, $monto_asignado, $usuario]);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Venta asociada correctamente al comprobante externo.'
    ]);
    
} catch (PDOException $e) {
    // Capturar error del trigger
    if ($e->getCode() == '45000') {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } else {
        error_log("Error al asociar venta: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error al asociar: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    error_log("Error general al asociar venta: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error inesperado.']);
}
