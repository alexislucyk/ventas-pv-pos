<?php
// archivo: ajax/enviar_whatsapp_nodered.php
include '../pages/infosesion.php';
header('Content-Type: application/json');

// Silenciar cualquier error de PHP para que no rompa el JSON de salida
error_reporting(0);
ini_set('display_errors', '0');

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

    // Aseguramos el prefijo 549 (Argentina) si el número no lo tiene al inicio
    if (!empty($telefono) && strpos($telefono, '549') !== 0) {
        $telefono = '549' . $telefono;
    }

    $mensaje = isset($dataInput['mensaje']) ? $dataInput['mensaje'] : '';
    $pdfUrl = isset($dataInput['pdfUrl']) ? $dataInput['pdfUrl'] : '';
    $pdfBase64 = '';
    $archivo_temp = null;

    // --- NUEVA LÓGICA: CONVERSIÓN A BASE64 ---
    // --- NUEVA LÓGICA: ENVÍO COMO ARCHIVO REAL ---
    if (!empty($pdfUrl)) {
        // Intentamos obtener el contenido del PDF generado
        // Usamos un timeout corto por si el servidor local no responde
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $pdfContenido = @file_get_contents($pdfUrl, false, $ctx);
        
        if ($pdfContenido !== false) {
            $pdfBase64 = base64_encode($pdfContenido);
            // Creamos un archivo temporal en el servidor
            $archivo_temp = tempnam(sys_get_temp_dir(), 'tk_');
            rename($archivo_temp, $archivo_temp .= '.pdf');
            file_put_contents($archivo_temp, $pdfContenido);
        } else {
            error_log("No se pudo obtener el PDF desde la URL interna: " . $pdfUrl);
        }
    }

    if (empty($telefono) || empty($mensaje)) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos (teléfono o mensaje).']);
        exit;
    }

    // Tu configuración de Node-RED
    $nodeRedUrl = "http://10.80.7.95:1880/test-whatsapp";
    
    // Preparamos el array de datos
    $payload = [
        "telefono" => $telefono,
        "mensaje"  => $mensaje
    ];

    // Solo agregamos campos de PDF si realmente hay contenido
    if (!empty($pdfBase64)) {
        $payload["pdfBase64"] = $pdfBase64;
        $payload["filename"] = "Comprobante_Lucyk.pdf";
    }

    $headers = [];
    // Si tenemos el archivo, lo adjuntamos usando CURLFile
    if ($archivo_temp && file_exists($archivo_temp)) {
        $payload['documento'] = new CURLFile($archivo_temp, 'application/pdf', 'Ticket_Venta.pdf');
    }

    // Configurar la petición POST via cURL (basado en tu código)
    $ch = curl_init($nodeRedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 segundos para conectar
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);        // 25 segundos máximo de espera

    // Si no hay archivo físico (CURLFile), enviamos como JSON para mejor compatibilidad con Node-RED
    if (!isset($payload['documento'])) {
        $jsonPayload = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        $headers[] = 'Content-Type: application/json';
    } else {
        // cURL detecta automáticamente multipart si recibe un array (necesario para CURLFile)
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $error_msg = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Limpiamos el archivo temporal
    if ($archivo_temp && file_exists($archivo_temp)) { @unlink($archivo_temp); }

    if (ob_get_length()) ob_clean(); // Asegurar que no haya basura antes del JSON
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['success' => true]);
    } else {
        $error_desc = $error_msg ?: "Código HTTP $httpCode";
        echo json_encode(['success' => false, 'error' => "Error en servidor de mensajería: $error_desc"]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}