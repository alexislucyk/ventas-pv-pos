<?php
// 1. Cabecera JSON obligatoria al inicio
header('Content-Type: application/json');

// 2. Iniciar sesión sin generar salida de texto
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2b. Cargar guardia de sesión y helper de permisos
include '../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['exito' => false, 'mensaje' => 'Falta empresa_id en sesión.']);
    exit();
}

// C) ENDURECER ENDPOINT: pago a proveedor es acción crítica, requiere permiso
require_permiso('pages/ctacte_proveedores.php');

date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

// 4. Limpieza de búfer para evitar espacios en blanco accidentales
ob_clean();

try {
    $id_proveedor = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT);
    $monto_total  = filter_input(INPUT_POST, 'monto_pago', FILTER_VALIDATE_FLOAT);
    $tipo_pago    = isset($_POST['tipo_pago']) ? $_POST['tipo_pago'] : 'Efectivo';
    $ref_pago     = isset($_POST['ref_pago']) ? $_POST['ref_pago'] : '';
    $imputar_docs_json = isset($_POST['imputar_docs']) ? $_POST['imputar_docs'] : '[]';
    $fecha_pago   = date('Y-m-d H:i:s');

    if (!$id_proveedor || $monto_total <= 0) {
        echo json_encode(['exito' => false, 'mensaje' => 'Datos de pago inválidos.']);
        exit();
    }

    $pdo->beginTransaction();

    $monto_restante = $monto_total;
    $imputar_docs = json_decode($imputar_docs_json, true);
    $imputaciones_realizadas = 0;

    if (is_array($imputar_docs) && !empty($imputar_docs)) {
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
                
                $mov_nombre = "PAGO ($tipo_pago) - IMPUT. FACT. $n_doc";
                if (!empty($ref_pago)) $mov_nombre .= " [REC: $ref_pago]";

                $sql_ins = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_ins)->execute([
                    $id_proveedor, $mov_nombre, $pago_parcial, $n_doc, $fecha_pago, $_SESSION['usuario_id'], $id_compra, $empresa_id
                ]);

                $monto_restante -= $pago_parcial;
                $imputaciones_realizadas++;
            }
        }
    }

    if ($monto_restante > 0.001) {
        $mov_nombre = "PAGO ($tipo_pago)" . ($imputaciones_realizadas > 0 ? " - SALDO EXCEDENTE" : "");
        if (!empty($ref_pago)) $mov_nombre .= " [REC: $ref_pago]";
        
        $n_doc_gen = !empty($ref_pago) ? $ref_pago : 'PAGO GENERAL';

        $sql_gen = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, empresa_id) 
                    VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
        $pdo->prepare($sql_gen)->execute([
            $id_proveedor, $mov_nombre, $monto_restante, $n_doc_gen, $fecha_pago, $_SESSION['usuario_id'], $empresa_id
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'exito' => true, 
        'mensaje' => "✅ Pago de $" . number_format($monto_total, 2, ',', '.') . " registrado con éxito."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error Proveedores: " . $e->getMessage());
    echo json_encode(['exito' => false, 'mensaje' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>