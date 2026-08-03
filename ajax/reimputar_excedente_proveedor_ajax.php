<?php
header('Content-Type: application/json');
include '../pages/infosesion.php';

// C) ENDURECER ENDPOINT: reimputación de excedente es acción crítica
require_permiso('pages/ctacte_proveedores.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'mensaje' => 'Falta empresa_id en sesión.']);
    exit();
}

require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$data = json_decode(file_get_contents('php://input'), true);

$id_ctacte_excedente = filter_var($data['id_ctacte_excedente'] ?? 0, FILTER_VALIDATE_INT);
$id_proveedor        = filter_var($data['id_proveedor'] ?? 0, FILTER_VALIDATE_INT);
$monto_disponible    = filter_var($data['monto_excedente'] ?? 0, FILTER_VALIDATE_FLOAT);
$imputar_docs        = $data['imputar_docs'] ?? [];

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

        $sql_saldo = "SELECT c.n_documento, (c.total_compra - COALESCE((SELECT SUM(debe) FROM ctacte_proveedores WHERE compra_id = c.id AND ctacte_proveedores.empresa_id = :empresa_id), 0)) as saldo 
                      FROM compras c 
                      WHERE c.id = ? AND c.cod_proveedor = ? AND c.empresa_id = :empresa_id";
        $stmt_saldo = $pdo->prepare($sql_saldo);
        $stmt_saldo->execute([':empresa_id' => $empresa_id, 'id' => (int)$id_compra, 'cod_proveedor' => $id_proveedor]);
        $row_factura = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
        
        $saldo_factura = (float)($row_factura['saldo'] ?? 0);
        $n_doc = $row_factura['n_documento'] ?? 'S/D';

        if ($saldo_factura > 0) {
            $pago_parcial = min($monto_restante, $saldo_factura);
            
            $sql_ins = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                        VALUES (?, ?, ?, 0, ?, NOW(), ?, ?, ?)";
            $pdo->prepare($sql_ins)->execute([
                $id_proveedor, 
                "IMPUTACIÓN DE EXCEDENTE A FACT. $n_doc", 
                $pago_parcial, 
                $n_doc, 
                $_SESSION['usuario_id'], 
                $id_compra,
                $empresa_id
            ]);

            $monto_restante -= $pago_parcial;
            $imputaciones_realizadas++;
        }
    }

    if ($imputaciones_realizadas > 0) {
        if ($monto_restante <= 0.001) {
            $sql_upd = "UPDATE ctacte_proveedores SET debe = 0, movimiento = CONCAT(movimiento, ' (RE-IMPUTADO TOTAL)') WHERE id = ? AND empresa_id = ?";
            $pdo->prepare($sql_upd)->execute([$id_ctacte_excedente, $empresa_id]);
        } else {
            $sql_upd = "UPDATE ctacte_proveedores SET debe = ? WHERE id = ? AND empresa_id = ?";
            $pdo->prepare($sql_upd)->execute([$monto_restante, $id_ctacte_excedente, $empresa_id]);
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