<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();
// Asegúrate de que el usuario esté logueado y que los paths sean correctos
require '../config/db_config.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. Saneamiento y Captura de Datos ---
    $id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);
    $monto_pago = filter_input(INPUT_POST, 'monto_pago', FILTER_VALIDATE_FLOAT);
    $n_recibo   = filter_input(INPUT_POST, 'n_recibo', FILTER_SANITIZE_STRING);
    $movimiento = "Pago Cta.Cte."; // Definimos el tipo de movimiento

    // Validaciones básicas
    if (!$id_cliente || $monto_pago <= 0) {
        header('Location: /ruta/al/formulario.php?error=Datos inválidos.');
        exit();
    }
    
    $fecha_movimiento = date('Y-m-d H:i:s');
    $cero = 0; // El campo 'debe' es 0 para un pago

    // --- 2. Preparar e Insertar SQL ---
    try {
        $sql = "
            INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha)
            VALUES (:id_cliente, :movimiento, :n_doc, :debe, :haber, :fecha)
        ";
        
        $stmt = $pdo->prepare($sql);
        
        $stmt->bindParam(':id_cliente', $id_cliente, PDO::PARAM_INT);
        $stmt->bindParam(':movimiento', $movimiento, PDO::PARAM_STR);
        $stmt->bindParam(':n_doc', $n_recibo, PDO::PARAM_STR); 
        $stmt->bindParam(':debe', $cero, PDO::PARAM_INT);
        $stmt->bindParam(':haber', $monto_pago, PDO::PARAM_STR); 
        $stmt->bindParam(':fecha', $fecha_movimiento, PDO::PARAM_STR);
        
        $stmt->execute();

        // CAPTURAR EL ID DEL RECIBO/MOVIMIENTO RECIÉN CREADO
        $id_movimiento_generado = $pdo->lastInsertId();

        // --- 3. Redirección de Éxito: REDIRIGIR A LA VISTA DEL RECIBO ---
        // Pasamos el ID del movimiento para que la vista lo consulte
        header('Location: ../pages/vista_recibo.php?id_mov=' . $id_movimiento_generado);
        exit();

    } catch (PDOException $e) {
        // Manejo de errores de base de datos
        error_log("Error al registrar pago CC: " . $e->getMessage());
        header('Location: ../pages/pagos_ctacte.php?error=Error en la base de datos: ' . urlencode($e->getMessage()));
        exit();
    }

} else {
    // Si se accede directamente sin POST
    header('Location: /ruta/al/formulario.php');
    exit();
}
?>