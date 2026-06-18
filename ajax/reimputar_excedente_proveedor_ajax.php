<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'mensaje' => 'Sesión expirada.']);
    exit();
}

require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$data = json_decode(file_get_contents('php://input'), true);

$id_ctacte_excedente = filter_var($data['id_ctacte_excedente'] ?? 0, FILTER_VALIDATE_INT);
$id_proveedor        = filter_var($data['id_proveedor'] ?? 0, FILTER_VALIDATE_INT);
$monto_disponible    = filter_var($data['monto_excedente'] ?? 0, FILTER_VALIDATE_FLOAT);
$imputar_docs        = $data['imputar_docs'] ?? []; // IDs de compras

if (!$id_ctacte_excedente || $monto_disponible <= 0 || empty($imputar_docs)) {
    echo json_encode(['success' => false, 'mensaje' => 'Datos insuficientes para procesar.']);
    exit();
}

try {
    $pdo->beginTransaction();

    $monto_restante = $monto_disponible;
    $imputaciones_realizadas = 0;

    foreach ($imputar_docs as $id_compra) {
        if ($monto_restante <= 0.001) break;

        // 1. Consultar saldo real de la factura por su ID único
        $sql_saldo = "SELECT c.n_documento, (c.total_compra - COALESCE((SELECT SUM(debe) FROM ctacte_proveedores WHERE compra_id = c.id), 0)) as saldo 
                      FROM compras c 
                      WHERE c.id = ? AND c.cod_proveedor = ?";
        $stmt_saldo = $pdo->prepare($sql_saldo);
        $stmt_saldo->execute([(int)$id_compra, $id_proveedor]);
        $row_factura = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
        
        $saldo_factura = (float)($row_factura['saldo'] ?? 0);
        $n_doc = $row_factura['n_documento'] ?? 'S/D';

        if ($saldo_factura > 0) {
            $pago_parcial = min($monto_restante, $saldo_factura);
            
            // 2. Crear nueva línea de imputación vinculada a la factura
            $sql_ins = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id) 
                        VALUES (?, ?, ?, 0, ?, NOW(), ?, ?)";
            $pdo->prepare($sql_ins)->execute([
                $id_proveedor, 
                "IMPUTACIÓN DE EXCEDENTE A FACT. $n_doc", 
                $pago_parcial, 
                $n_doc, 
                $_SESSION['usuario_id'], 
                $id_compra
            ]);

            $monto_restante -= $pago_parcial;
            $imputaciones_realizadas++;
        }
    }

    // 3. Actualizar el movimiento original de excedente
    if ($imputaciones_realizadas > 0) {
        if ($monto_restante <= 0.001) {
            // Si se usó todo, lo marcamos como procesado
            $sql_upd = "UPDATE ctacte_proveedores SET debe = 0, movimiento = CONCAT(movimiento, ' (RE-IMPUTADO TOTAL)') WHERE id = ?";
            $pdo->prepare($sql_upd)->execute([$id_ctacte_excedente]);
        } else {
            // Si quedó un remanente, actualizamos el monto
            $sql_upd = "UPDATE ctacte_proveedores SET debe = ? WHERE id = ?";
            $pdo->prepare($sql_upd)->execute([$monto_restante, $id_ctacte_excedente]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'mensaje' => 'Saldo re-imputado correctamente.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error en re-imputacion: " . $e->getMessage());
    echo json_encode(['success' => false, 'mensaje' => 'Error: ' . $e->getMessage()]);
}
?>