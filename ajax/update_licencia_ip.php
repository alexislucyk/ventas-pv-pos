<?php
// ajax/update_licencia_ip.php
include '../pages/infosesion.php';
require_once '../config/licencia_manager.php';
header('Content-Type: application/json');

// --- CONTROL DE ACCESO ---
// Solo el Developer puede modificar la IP del servidor de licencias
$permiso_clave = 'gestionar_licencia'; // Reutilizamos el permiso de gestión de licencia
$tiene_acceso = false;

if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
    $tiene_acceso = true;
} elseif (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
    $tiene_acceso = in_array($permiso_clave, $_SESSION['permisos']);
}

if (!$tiene_acceso) {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar la IP del servidor de licencias.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newIp = trim($_POST['new_ip'] ?? '');

    if ($newIp) {
        if (setLicenciaServerIp($newIp)) {
            echo json_encode(['success' => true, 'message' => 'IP del servidor de licencias actualizada correctamente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al guardar la IP en el archivo.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'La IP proporcionada no es válida.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Método de solicitud no permitido.']);
}
?>