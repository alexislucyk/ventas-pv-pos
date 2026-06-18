<?php
// 1. Cabecera JSON obligatoria al inicio
header('Content-Type: application/json');

// 2. Iniciar sesión sin generar salida de texto
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Validación de seguridad silenciosa
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['exito' => false, 'mensaje' => 'Sesión expirada.']);
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

// 4. Limpieza de búfer para evitar espacios en blanco accidentales
ob_clean();

try {
    // Obtener y Validar Datos
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

            // Consultamos el saldo usando el ID único de la compra para evitar errores con n_documento duplicados
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
                
                $mov_nombre = "PAGO ($tipo_pago) - IMPUT. FACT. $n_doc";
                if (!empty($ref_pago)) $mov_nombre .= " [REC: $ref_pago]";

                $sql_ins = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id) 
                            VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                $pdo->prepare($sql_ins)->execute([
                    $id_proveedor, $mov_nombre, $pago_parcial, $n_doc, $fecha_pago, $_SESSION['usuario_id'], $id_compra
                ]);

                $monto_restante -= $pago_parcial;
                $imputaciones_realizadas++;
            }
        }
    }

    // Si sobró dinero o no se seleccionó ninguna factura, el monto restante va como Pago General
    if ($monto_restante > 0.001) {
        $mov_nombre = "PAGO ($tipo_pago)" . ($imputaciones_realizadas > 0 ? " - SALDO EXCEDENTE" : "");
        if (!empty($ref_pago)) $mov_nombre .= " [REC: $ref_pago]";
        
        $n_doc_gen = !empty($ref_pago) ? $ref_pago : 'PAGO GENERAL';

        $sql_gen = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id) 
                    VALUES (?, ?, ?, 0, ?, ?, ?)";
        $pdo->prepare($sql_gen)->execute([
            $id_proveedor, $mov_nombre, $monto_restante, $n_doc_gen, $fecha_pago, $_SESSION['usuario_id']
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