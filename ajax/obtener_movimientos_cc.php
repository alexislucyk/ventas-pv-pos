<?php
// ajax/obtener_movimientos_cc.php
include '../pages/infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
require '../config/db_config.php'; 

error_log("=== INICIO obtener_movimientos_cc.php ===");
error_log("GET params: " . print_r($_GET, true));

$empresa_id = $_SESSION['empresa_id'] ?? null;
error_log("empresa_id desde sesión: " . $empresa_id);

if (!$empresa_id || !isset($_GET['id_cliente']) || !is_numeric($_GET['id_cliente'])) {
    error_log("ERROR: Validación fallida - empresa_id: $empresa_id, id_cliente: " . ($_GET['id_cliente'] ?? 'no definido'));
    echo "<tr><td colspan='7' style='color: red;'>Error: ID de cliente no válido. empresa_id=$empresa_id, id_cliente=" . ($_GET['id_cliente'] ?? 'undefined') . "</td></tr>";
    exit();
} 

$id_cliente = (int)$_GET['id_cliente'];
error_log("Consultando movimientos para cliente: $id_cliente, empresa: $empresa_id");

try {
    $sql_movimientos = "
        SELECT
            id,
            movimiento,
            n_documento,
            debe,
            haber,
            fecha
        FROM ctacte
        WHERE id_cliente = :id_cliente AND empresa_id = :empresa_id
        ORDER BY fecha ASC, id ASC
    ";
    
    $stmt_mov = $pdo->prepare($sql_movimientos);
    $stmt_mov->execute([':id_cliente' => $id_cliente, ':empresa_id' => $empresa_id]);
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Movimientos encontrados: " . count($movimientos));

} catch (Exception $e) {
    error_log("Error al cargar movimientos CC: " . $e->getMessage());
    echo "<tr><td colspan='7' style='color: red;'>Error al cargar el historial: " . $e->getMessage() . "</td></tr>";
    exit();
}

if (empty($movimientos)) {
    error_log("No hay movimientos para el cliente $id_cliente");
    echo "<tr><td colspan='7' class='center-text'>No hay movimientos registrados para este cliente.</td></tr>";
    exit();
}

$saldo_acumulado = 0;
foreach ($movimientos as $mov) {
    $id_mov = $mov['id'];
    $debe_val = (float)$mov['debe']; 
    $haber_val = (float)$mov['haber'];
    $saldo_acumulado += $debe_val - $haber_val;
    
    // Lógica visual de saldos
    $clase_saldo = 'saldo-cero';
    $monto_final_a_mostrar = $saldo_acumulado; 

    if ($saldo_acumulado > 0) {
        $clase_saldo = 'saldo-negativo'; 
        $monto_final_a_mostrar = -$saldo_acumulado; 
    } elseif ($saldo_acumulado < 0) {
        $clase_saldo = 'saldo-positivo'; 
        $monto_final_a_mostrar = abs($saldo_acumulado); 
    }

    // --- DETERMINAR TIPO DE MOVIMIENTO ---
    $texto_movimiento = htmlspecialchars($mov['movimiento']);
    $mov_upper = strtoupper($mov['movimiento']);
    $is_sale = (strpos($mov_upper, 'FACTURA') !== false);
    $is_dev = (strpos($mov_upper, 'ANULACIÓN') !== false || strpos($mov_upper, 'DEVOLUCIÓN') !== false);
    $is_payment = ($haber_val > 0 && !$is_dev);

    $columna_acciones = "";

    if ($is_sale) {
        $n_doc_val = $mov['n_documento'];
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$n_doc_val\", \"VENTA\", \"#$n_doc_val\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirTicket(\"$n_doc_val\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='descargarPDFVenta(\"$n_doc_val\")' title='PDF A5' style='color:#00bcd4;'><i class='fas fa-file-pdf'></i></button>";
    } elseif ($is_dev) {
        $op_n = 0;
        if (preg_match('/OP#(\d+)/', $mov['movimiento'], $matches)) { $op_n = $matches[1]; }
        if ($op_n > 0) {
            $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$op_n\", \"DEVOLUCION\", \"OP#$op_n\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
            $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$op_n\", true)' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
            $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$op_n\", true)' title='PDF A5' style='color:#e67e22;'><i class='fas fa-file-pdf'></i></button>";
        }
    } elseif ($is_payment) {
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$id_mov\", \"PAGO\", \"Recibo #$id_mov\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$id_mov\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
        $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$id_mov\")' title='PDF A5' style='color:#e74c3c;'><i class='fas fa-file-pdf'></i></button>";
    }

    // Si n_documento es 0 o está vacío, mostramos el ID interno como referencia sin el símbolo #
    $n_doc_display = ($mov['n_documento'] && $mov['n_documento'] != "0") ? htmlspecialchars($mov['n_documento']) : $id_mov;
    
    echo "
        <tr>
            <td>" . date('d/m/Y', strtotime($mov['fecha'])) . "</td>
            <td>" . $texto_movimiento . "</td>
            <td>" . $n_doc_display . "</td>
            <td class='text-right'>$" . number_format($debe_val, 2, ',', '.') . "</td> 
            <td class='text-right'>$" . number_format($haber_val, 2, ',', '.') . "</td> 
            <td class='text-right " . $clase_saldo . "'>$" . number_format($monto_final_a_mostrar, 2, ',', '.') . "</td>
            <td class='text-center'>" . $columna_acciones . "</td>
        </tr>";
}
?>