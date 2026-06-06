<?php
// ajax/agregar_cliente_rapido.php
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apellido = trim($_POST['apellido'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $id_tipo_iva = isset($_POST['id_tipo_iva']) ? intval($_POST['id_tipo_iva']) : 99;
    $cuit = trim($_POST['cuit'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');

    if (empty($apellido)) {
        echo json_encode(['success' => false, 'error' => 'El apellido es obligatorio.']);
        exit;
    }

    try {
        // Obtener el ID más alto actual y sumar 1
        $stmt_id = $pdo->query("SELECT id FROM clientes ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id = $ultimo ? (intval($ultimo['id']) + 1) : 1;

        $sql = "INSERT INTO clientes (id, nombre, apellido, dni, id_tipo_iva, cuit, telefono, direccion, estado, habilita_cta, relacion) 
                VALUES (?, ?, ?, ?, ?, ?, ?, '', 'Activo', 'No', '')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nuevo_id, $nombre, $apellido, $dni, $id_tipo_iva, $cuit, $telefono]);
        
        $nombre_completo = $apellido . ($nombre ? ", " . $nombre : "");

        echo json_encode([
            'success' => true, 
            'id_cliente' => $nuevo_id, 
            'nombre_completo' => $nombre_completo
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}