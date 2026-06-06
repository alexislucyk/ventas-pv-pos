<?php
require '../config/db_config.php';
$id = (int)($_GET['id'] ?? 0);

try {
    $stmt = $pdo->prepare("SELECT m.*, CONCAT(c.apellido, ', ', c.nombre) as cliente 
                           FROM ctacte m 
                           INNER JOIN clientes c ON m.id_cliente = c.id 
                           WHERE m.id = ?");
    $stmt->execute([$id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) echo "<p>No se encontró información del pago.</p>";
    else {
        echo "
        <div style='background:#222; padding:15px; border-radius:8px;'>
            <p><strong>Recibo N°:</strong> " . ($res['n_documento'] ?: $res['id']) . "</p>
            <p><strong>Fecha:</strong> " . date('d/m/Y H:i', strtotime($res['fecha'])) . "</p>
            <p><strong>Cliente:</strong> " . htmlspecialchars($res['cliente']) . "</p>
            <p style='font-size:1.2em; border-top:1px solid #444; padding-top:10px; margin-top:10px;'>
                <strong>Monto Abonado:</strong> 
                <span style='color:#2ecc71;'>$ " . number_format((float)$res['haber'], 2, ',', '.') . "</span>
            </p>
            <p><strong>Concepto:</strong> " . htmlspecialchars($res['movimiento']) . "</p>
        </div>
        <div style='margin-top:20px; color:#aaa; font-style:italic; font-size:0.9em; text-align:center;'>
            Este documento es un comprobante de ingreso de valores a cuenta corriente.
        </div>";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>