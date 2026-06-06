<?php
require '../config/db_config.php';
$id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? '';

try {
    // Consulta la tabla 'devoluciones' directamente usando op_n y cond_pago
    $stmt = $pdo->prepare("SELECT total_reintegrado as monto, motivo as detalle, fecha, usuario FROM devoluciones WHERE op_n = ? AND cond_pago = ?");
    $stmt->execute([$id, $tipo]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) echo "<p>No se encontró información.</p>";
    else {
        echo "
        <div style='background:#222; padding:15px; border-radius:8px;'>
            <p><strong>Operación:</strong> OP#$id</p>
            <p><strong>Fecha y Hora:</strong> " . date('d/m/Y H:i', strtotime($res['fecha'])) . "</p>
            <p><strong>Monto Reintegrado:</strong> <span style='color:#ff7675;'>$ " . number_format((float)($res['monto'] ?? 0), 2, ',', '.') . "</span></p>
            <p><strong>Concepto/Motivo:</strong> " . htmlspecialchars($res['detalle'] ?? '') . "</p>
            <p><strong>Responsable:</strong> " . htmlspecialchars($res['usuario'] ?? '') . "</p>
        </div>
        <div style='margin-top:20px; color:#aaa; font-style:italic; font-size:0.9em;'>
            Nota: Las devoluciones parciales registran el reingreso de stock pero el detalle de ítems específicos solo está disponible en el comprobante emitido al momento de la operación.
        </div>";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>