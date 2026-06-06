<?php
// ajax/agregar_rubro_ajax.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
        exit;
    }

    try {
        // Obtener el ID más alto actual y sumar 1
        $stmt_id = $pdo->query("SELECT id FROM rubros ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id = $ultimo ? (intval($ultimo['id']) + 1) : 1;

        // Insertar especificando el ID calculado
        $stmt = $pdo->prepare("INSERT INTO rubros (id, nombre) VALUES (?, ?)");
        $stmt->execute([$nuevo_id, $nombre]);
        
        echo json_encode(['success' => true, 'nombre' => $nombre]);
    } catch (Exception $e) {
        // Probablemente clave duplicada
        echo json_encode(['success' => false, 'error' => 'No se pudo agregar. El rubro ya existe o hay un problema con la base de datos.']);
    }
}
?>