<?php
// guardar_presupuesto_backend.php
require '../config/db_config.php';
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No hay datos']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Obtener empresa_id de la sesión
    $empresa_id = $_SESSION['empresa_id'] ?? null;
    
    if (!$empresa_id) {
        throw new Exception('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
    }

    // 1. Insertar Cabecera (Agregamos la columna observaciones y empresa_id)
    $sqlH = "INSERT INTO presupuestos (id_cliente, fecha_presupuesto, total_presupuesto, observaciones, empresa_id) VALUES (?, NOW(), ?, ?, ?)";
    $stmtH = $pdo->prepare($sqlH);
    
    // Capturamos el campo del JSON, si no existe mandamos un string vacío
    $obs = isset($data['observaciones']) ? $data['observaciones'] : '';
    
    $stmtH->execute([
        $data['id_cliente'], 
        $data['total'], 
        $obs,
        $empresa_id
    ]);
    
    $idPresupuesto = $pdo->lastInsertId();

    // 2. Insertar Detalles (incluyendo empresa_id)
    $sqlD = "INSERT INTO presupuestos_detalle (id_presupuesto, cod_prod, descripcion, cantidad, precio_unitario, subtotal, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmtD = $pdo->prepare($sqlD);

    foreach ($data['productos'] as $prod) {
        $cantidad = (float)$prod['cantidad'];
        $precio = (float)$prod['precio'];
        $subtotal = $cantidad * $precio;
        $stmtD->execute([
            $idPresupuesto, 
            $prod['codigo'], 
            $prod['descripcion'], 
            $prod['cantidad'], 
            $prod['precio'],
            $subtotal,
            $empresa_id
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'id_presupuesto' => $idPresupuesto]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}