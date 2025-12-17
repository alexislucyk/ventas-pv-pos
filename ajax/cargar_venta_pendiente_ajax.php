<?php
// ../ajax/cargar_venta_pendiente_ajax.php
// 1. Configuración y Seguridad
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
header('Content-Type: application/json');

// Función para devolver errores en formato JSON
function sendError($message) {
    echo json_encode(['error' => $message]);
    exit();
}

if (!isset($_SESSION['usuario_id'])) {
    sendError("Debe iniciar sesión para cargar esta venta.");
}

require '../config/db_config.php'; 

if (!isset($pdo) || !($pdo instanceof PDO)) {
    sendError("Error crítico: Conexión a la base de datos no disponible.");
}

// 2. Obtener y Validar Entrada
if (!isset($_GET['n_documento']) || !is_numeric($_GET['n_documento'])) {
    sendError("Parámetro N° Documento no válido o faltante.");
}

$n_documento = (int)$_GET['n_documento'];

try {
    // =================================================================
    // A) CONSULTA DE CABECERA
    // =================================================================
    
    $sql_cabecera = "SELECT 
                        v.id, 
                        v.n_documento, 
                        v.id_cliente, 
                        v.cond_pago,
                        v.total_venta, 
                        v.pago_efectivo, 
                        v.pago_transf,
                        c.cuit AS num_documento,
                        CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
                     FROM ventas v
                     LEFT JOIN clientes c ON v.id_cliente = c.id
                     WHERE v.n_documento = :n_documento AND v.estado = 'Pendiente'";
    
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->bindParam(':n_documento', $n_documento, PDO::PARAM_INT);
    $stmt_cabecera->execute();
    $cabecera = $stmt_cabecera->fetch(PDO::FETCH_ASSOC);

    if (!$cabecera) {
        sendError("La venta N° $n_documento no se encontró o no está Pendiente.");
    }

    // =================================================================
    // B) CONSULTA DE DETALLE (Productos)
    // =================================================================

    $sql_detalle = "SELECT 
                        cod_prod, 
                        descripcion, 
                        cant, 
                        p_unit, 
                        total
                    FROM ventas_detalle
                    WHERE n_documento = :n_documento";
    
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->bindParam(':n_documento', $n_documento, PDO::PARAM_INT);
    $stmt_detalle->execute();
    $detalle = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

    if (empty($detalle)) {
        // Esto es raro pero posible si hubo un error al guardar, 
        // aunque devolver la cabecera sola podría ser suficiente para depurar.
        error_log("Advertencia: Venta N° $n_documento pendiente sin detalle de productos.");
    }

    // =================================================================
    // C) DEVOLVER DATOS (JSON)
    // =================================================================
    
    echo json_encode([
        'success' => true,
        'cabecera' => $cabecera,
        'detalle' => $detalle
    ]);

} catch (Exception $e) {
    error_log("Error al cargar venta pendiente $n_documento: " . $e->getMessage());
    sendError("Error interno del servidor al consultar la base de datos.");
}
?>