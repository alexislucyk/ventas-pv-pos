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
        // Verificar si ya existe un rubro con ese nombre
        $stmt_check = $pdo->prepare("SELECT id FROM rubros WHERE nombre = ?");
        $stmt_check->execute([$nombre]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe un rubro con ese nombre.']);
            exit;
        }

        $stmt_id = $pdo->query("SELECT id FROM rubros ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id = $ultimo ? (intval($ultimo['id']) + 1) : 1;

        $stmt = $pdo->prepare("INSERT INTO rubros (id, nombre) VALUES (?, ?)");
        $stmt->execute([$nuevo_id, $nombre]);
        
        echo json_encode(['success' => true, 'nombre' => $nombre]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'No se pudo agregar el rubro: ' . $e->getMessage()]);
    }
}
?>