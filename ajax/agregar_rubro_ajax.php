<?php
// ajax/agregar_rubro_ajax.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
        exit;
    }

    try {
        $stmt_id = $pdo->query("SELECT id FROM rubros ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id = $ultimo ? (intval($ultimo['id']) + 1) : 1;

        $stmt = $pdo->prepare("INSERT INTO rubros (id, nombre, empresa_id) VALUES (?, ?, ?)");
        $stmt->execute([$nuevo_id, $nombre, $empresa_id]);
        
        echo json_encode(['success' => true, 'nombre' => $nombre]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'No se pudo agregar. El rubro ya existe o hay un problema con la base de datos.']);
    }
}
?>