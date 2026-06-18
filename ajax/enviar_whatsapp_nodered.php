<?php
// archivo: ajax/enviar_whatsapp_nodered.php
include '../pages/infosesion.php';
header('Content-Type: application/json');

// Silenciar cualquier error de PHP para que no rompa el JSON de salida
error_reporting(E_ALL); // Reportar todos los errores
ini_set('display_errors', '0'); // No mostrar errores en la salida JSON
ini_set('log_errors', '1');    // Loguear errores en el servidor para depuración

// Validación de seguridad: solo permitir si tiene el permiso específico
$permiso_clave = 'whatsapp_enviar';
$tiene_acceso = false;

if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
    $tiene_acceso = true; // El developer siempre tiene acceso
} elseif (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
    $tiene_acceso = in_array($permiso_clave, $_SESSION['permisos']);
}

if (!$tiene_acceso) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para realizar envíos de WhatsApp.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataInput = json_decode(file_get_contents('php://input'), true);
    
    // Limpiamos el teléfono: dejamos solo números
    $telefono = isset($dataInput['telefono']) ? preg_replace('/[^0-9]/', '', $dataInput['telefono']) : '';

    // Normalización para Argentina
    if (!empty($telefono)) {
        // 1. Quitar '0' inicial si existe (prefijo interurbano)
        if ($telefono[0] === '0') {
            $telefono = substr($telefono, 1);
        }
        // 2. Si tiene 10 dígitos (ej: 3764123456), agregar 549
        if (strlen($telefono) === 10) {
            $telefono = '549' . $telefono;
        }
        // 3. Si tiene 12 dígitos y empieza con 54 (ej: 54376...), insertar el 9 intermedio
        elseif (strlen($telefono) === 12 && substr($telefono, 0, 2) === '54') {
            $telefono = '549' . substr($telefono, 2);
        }
        // 4. Si no empieza con 549 y tiene longitud razonable, forzar prefijo
        elseif (strpos($telefono, '549') !== 0 && strlen($telefono) >= 7) {
            $telefono = '549' . $telefono;
        }
    }

    $mensaje = isset($dataInput['mensaje']) ? $dataInput['mensaje'] : '';

    if (empty($telefono) || empty($mensaje)) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos (teléfono o mensaje).']);
        exit;
    }

    $nodeRedUrl = "http://10.80.7.95:1880/test-whatsapp";
    
    $payload = [
        "telefono" => $telefono,
        "mensaje"  => $mensaje
    ];

    // LOG DE DEPURECIÓN: Registramos qué vamos a enviar
    error_log("WhatsApp Debug - Enviando a Node-RED: Tel: $telefono | Msg: $mensaje");

    $ch = curl_init($nodeRedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // LOG DE DEPURECIÓN: Registramos la respuesta del servidor Node-RED
    error_log("WhatsApp Debug - Respuesta Node-RED: HTTP $httpCode | Error: $curlError | Body: $response");

    if (ob_get_level()) ob_clean();

    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['success' => true]);
    } else {
        $msg_error = $curlError ?: "Error servidor Node-RED (Status: $httpCode)";
        echo json_encode(['success' => false, 'error' => $msg_error]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}