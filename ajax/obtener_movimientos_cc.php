<?php
// ajax/obtener_movimientos_cc.php (CORREGIDO)
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
require '../config/db_config.php'; 

// 1. Verificar ID de Cliente
if (!isset($_GET['id_cliente']) || !is_numeric($_GET['id_cliente'])) {
    echo "<tr><td colspan='6' style='color: red;'>Error: ID de cliente no válido.</td></tr>";
    exit();
}

$id_cliente = (int)$_GET['id_cliente'];

// 2. Consulta para obtener todos los movimientos de ese cliente
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
    echo "<tr><td colspan='6' style='color: red;'>Error al cargar el historial: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
    exit();
}

// 3. Generar la tabla HTML
if (empty($movimientos)) {
    echo "<tr><td colspan='6' class='center-text'>No hay movimientos registrados para este cliente.</td></tr>";
    exit();
}

// ELIMINÉ las líneas de inicialización de $debe_val y $haber_val aquí

$saldo_acumulado = 0;
foreach ($movimientos as $mov) {
    // Los valores de 'debe' y 'haber' ahora son POSITIVOS en la DB.
    $debe_val = (float)$mov['debe']; 
    $haber_val = (float)$mov['haber'];

    // CÁLCULO DEL SALDO ACUMULADO
    $saldo_acumulado += $debe_val - $haber_val;
    
    // --- LÓGICA DE VISUALIZACIÓN DE SALDO (CORREGIDA) ---
    
    $clase_saldo = 'saldo-cero';
    $monto_final_a_mostrar = $saldo_acumulado; // Usamos el valor real, que puede ser positivo (deuda) o negativo (crédito)

    if ($saldo_acumulado > 0) {
        // El cliente DEBE (Deuda). Queremos: ROJO Y SIGNO NEGATIVO VISUALMENTE.
        $clase_saldo = 'saldo-negativo'; 
        // Mantenemos el monto positivo, pero lo forzamos a mostrar como negativo en el HTML.
        $monto_final_a_mostrar = -$saldo_acumulado; 

    } elseif ($saldo_acumulado < 0) {
        // Cliente tiene CRÉDITO A FAVOR. Queremos: VERDE y SIN SIGNO (o positivo).
        $clase_saldo = 'saldo-positivo'; 
        // Lo mostramos como positivo (sin signo) para que el verde indique que es favorable.
        $monto_final_a_mostrar = abs($saldo_acumulado); 
    }
    
    // --- GENERACIÓN DE LA FILA HTML ---
    echo "
        <tr>
            <td>" . date('d/m/Y', strtotime($mov['fecha'])) . "</td>
            <td>" . htmlspecialchars($mov['movimiento']) . "</td>
            <td>" . htmlspecialchars($mov['n_documento']) . "</td>
            
            <td class='text-right'>$" . number_format($debe_val, 2, ',', '.') . "</td> 
            <td class='text-right'>$" . number_format($haber_val, 2, ',', '.') . "</td> 
            
            <td class='text-right " . $clase_saldo . "'>$" . number_format($monto_final_a_mostrar, 2, ',', '.') . "</td>
        </tr>";
}
?>