<?php
// ajax/verificar_documento_compra_ajax.php
header('Content-Type: application/json');
require_once '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$id_proveedor = filter_var($_GET['proveedor_id'] ?? 0, FILTER_VALIDATE_INT);
$n_documento = trim($_GET['n_documento'] ?? '');

if (!$empresa_id || !$id_proveedor || empty($n_documento)) {
    echo json_encode(['existe' => false, 'mensaje' => '']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, n_documento, documento, total_compra, fecha_compra 
                           FROM compras 
                           WHERE empresa_id = :empresa_id 
                           AND cod_proveedor = :proveedor_id 
                           AND n_documento = :n_documento 
                           LIMIT 1");
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':proveedor_id' => $id_proveedor,
        ':n_documento' => $n_documento
    ]);
    $compra = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($compra) {
        echo json_encode([
            'existe' => true,
            'mensaje' => "Documento duplicado: Compra #{$compra['id']} ({$compra['documento']}) registrada el " . date('d/m/Y', strtotime($compra['fecha_compra'])) . " por \${$compra['total_compra']}"
        ]);
    } else {
        echo json_encode(['existe' => false, 'mensaje' => '']);
    }
} catch (Exception $e) {
    error_log("Error verificando documento: " . $e->getMessage());
    echo json_encode(['existe' => false, 'mensaje' => 'Error al verificar']);
}
?>
