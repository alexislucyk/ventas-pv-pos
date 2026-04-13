<?php
// ajax/obtener_movimientos_cc.php
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
require '../config/db_config.php'; 

if (!isset($_GET['id_cliente']) || !is_numeric($_GET['id_cliente'])) {
    echo "<tr><td colspan='6' style='color: red;'>Error: ID de cliente no válido.</td></tr>";
    exit();
}

$id_cliente = (int)$_GET['id_cliente'];

try {
    $sql_movimientos = "
        SELECT
            movimiento,
            n_documento,
            debe,
            haber,
            fecha
        FROM ctacte
        WHERE id_cliente = :id_cliente
        ORDER BY fecha ASC, id ASC
    ";
    
    $stmt_mov = $pdo->prepare($sql_movimientos);
    $stmt_mov->execute([':id_cliente' => $id_cliente]);
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error al cargar movimientos CC: " . $e->getMessage());
    echo "<tr><td colspan='6' style='color: red;'>Error al cargar el historial.</td></tr>";
    exit();
}

if (empty($movimientos)) {
    echo "<tr><td colspan='6' class='center-text'>No hay movimientos registrados para este cliente.</td></tr>";
    exit();
}

$saldo_acumulado = 0;
foreach ($movimientos as $mov) {
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

    // --- NUEVA LÓGICA: BOTÓN DE DETALLE ---
    $texto_movimiento = htmlspecialchars($mov['movimiento']);
    // Si el movimiento es FACTURA, agregamos el botón de la lupa
    if (strtoupper($mov['movimiento']) == 'FACTURA') {
        // Usamos n_documento como ID de la venta para el modal
        $id_ref = $mov['n_documento']; 
        $texto_movimiento .= " <button type='button' class='btn-lupa' onclick='verDetalleFactura($id_ref)' title='Ver detalle de productos'>🔍</button>";
    }
    
    echo "
        <tr>
            <td>" . date('d/m/Y', strtotime($mov['fecha'])) . "</td>
            <td>" . $texto_movimiento . "</td>
            <td>" . htmlspecialchars($mov['n_documento']) . "</td>
            <td class='text-right'>$" . number_format($debe_val, 2, ',', '.') . "</td> 
            <td class='text-right'>$" . number_format($haber_val, 2, ',', '.') . "</td> 
            <td class='text-right " . $clase_saldo . "'>$" . number_format($monto_final_a_mostrar, 2, ',', '.') . "</td>
        </tr>";
}
?>