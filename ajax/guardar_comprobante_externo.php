<?php
// ajax/guardar_comprobante_externo.php
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
$punto_venta = isset($_POST['punto_venta']) ? (int)$_POST['punto_venta'] : 0;
$n_comprobante = isset($_POST['n_comprobante']) ? (int)$_POST['n_comprobante'] : 0;
$tipo_comprobante = isset($_POST['tipo_comprobante']) ? (int)$_POST['tipo_comprobante'] : 11;
$fecha_emision = isset($_POST['fecha_emision']) ? trim($_POST['fecha_emision']) : '';
$total_comprobante = isset($_POST['total_comprobante']) ? (float)$_POST['total_comprobante'] : 0;
$cuit_cliente = isset($_POST['cuit_cliente']) ? trim($_POST['cuit_cliente']) : '';
$razon_social_cliente = isset($_POST['razon_social_cliente']) ? trim($_POST['razon_social_cliente']) : '';
$cond_iva = isset($_POST['cond_iva']) ? trim($_POST['cond_iva']) : null;
$observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : null;

// Validaciones
$errores = [];

if ($punto_venta <= 0) {
    $errores[] = 'El punto de venta debe ser mayor a 0.';
}
if ($n_comprobante <= 0) {
    $errores[] = 'El número de comprobante debe ser mayor a 0.';
}
if (empty($fecha_emision)) {
    $errores[] = 'La fecha de emisión es obligatoria.';
}
if ($total_comprobante <= 0) {
    $errores[] = 'El total del compprobante debe ser mayor a 0.';
}
if (empty($cuit_cliente)) {
    $errores[] = 'El CUIT del cliente es obligatorio.';
} else {
    // Validar formato CUIT (11 dígitos)
    $cuit_limpio = preg_replace('/[^0-9]/', '', $cuit_cliente);
    if (strlen($cuit_limpio) !== 11) {
        $errores[] = 'El CUIT debe tener 11 dígitos.';
    }
}
if (empty($razon_social_cliente)) {
    $errores[] = 'La razón social del cliente es obligatoria.';
}

if (!empty($errores)) {
    echo json_encode(['status' => 'error', 'message' => implode(' ', $errores)]);
    exit;
}

try {
    // Verificar que no exista el comprobante
    $stmt_check = $pdo->prepare("SELECT id FROM comprobantes_externos 
                                  WHERE empresa_id = ? AND punto_venta = ? AND n_comprobante = ? AND tipo_comprobante = ?");
    $stmt_check->execute([$empresa_id, $punto_venta, $n_comprobante, $tipo_comprobante]);
    
    if ($stmt_check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe un comprobante con ese punto de venta, número y tipo.']);
        exit;
    }

    // Insertar comprobante externo
    $sql = "INSERT INTO comprobantes_externos 
            (empresa_id, punto_venta, n_comprobante, tipo_comprobante, fecha_emision, 
             total_comprobante, cuit_cliente, razon_social_cliente, cond_iva, observaciones, usuario_carga) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $empresa_id, $punto_venta, $n_comprobante, $tipo_comprobante, $fecha_emision,
        $total_comprobante, $cuit_cliente, $razon_social_cliente, $cond_iva, $observaciones, $usuario
    ]);
    
    $nuevo_id = $pdo->lastInsertId();
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Comprobante externo registrado correctamente.',
        'id' => $nuevo_id
    ]);
    
} catch (PDOException $e) {
    error_log("Error al guardar comprobante externo: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general al guardar comprobante externo: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error inesperado.']);
}
