<?php
// 1. Cabecera JSON obligatoria al inicio
header('Content-Type: application/json');

// 2. Iniciar sesión sin generar salida de texto
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Validación de seguridad silenciosa
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['exito' => false, 'mensaje' => 'Sesión expirada.']);
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

// 4. Limpieza de búfer para evitar espacios en blanco accidentales
ob_clean();

try {
    // Obtener y Validar Datos
    $id_proveedor = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT);
    $monto_pago   = filter_input(INPUT_POST, 'monto_pago', FILTER_VALIDATE_FLOAT);
    $tipo_pago    = isset($_POST['tipo_pago']) ? $_POST['tipo_pago'] : 'Efectivo';
    $ref_pago     = isset($_POST['ref_pago']) ? $_POST['ref_pago'] : '';
    $fecha_pago   = date('Y-m-d H:i:s');

    if (!$id_proveedor || $monto_pago <= 0) {
        echo json_encode(['exito' => false, 'mensaje' => 'Datos de pago inválidos.']);
        exit();
    }

    $pdo->beginTransaction();

    // El pago va al DEBE para restar del HABER (deuda)
    $sql = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha) 
            VALUES (:id_prov, :mov, :monto, 0, :ref_doc, :fecha)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_prov' => $id_proveedor,
        ':mov'     => 'PAGO (' . $tipo_pago . ')',
        ':monto'   => $monto_pago,
        ':ref_doc' => empty($ref_pago) ? 'PAGO REGISTRADO' : $ref_pago,
        ':fecha'   => $fecha_pago
    ]);

    $pdo->commit();

    echo json_encode([
        'exito' => true, 
        'mensaje' => "✅ Pago de $" . number_format($monto_pago, 2, ',', '.') . " registrado con éxito."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error Proveedores: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>