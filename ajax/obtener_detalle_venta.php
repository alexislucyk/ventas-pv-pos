<?php
// ajax/obtener_detalle_venta.php
// Incluir archivos necesarios (db_config y ticket_generator)
require_once '../config/db_config.php'; 
require_once '../../pos/funciones/ticket_generator.php'; // Aquí debe estar la función generar_html_ticket
header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "<p style='color: red;'>Error: N° Documento no proporcionado.</p>";
    exit();
}

$n_documento = (int)$_GET['n_documento'];

try {
    // --- 1. OBTENER CABECERA Y CLIENTE ---
    $sql_cabecera = "
        SELECT 
            v.*,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
        FROM ventas v
        LEFT JOIN clientes c ON v.id_cliente = c.id
        WHERE v.n_documento = :n_documento
    ";
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->execute([':n_documento' => $n_documento]);
    $venta = $stmt_cabecera->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        echo "<p style='color: red;'>Error: Venta N° {$n_documento} no encontrada.</p>";
        exit();
    }

    // --- 2. OBTENER DETALLE DE PRODUCTOS ---
    $sql_detalle = "
        SELECT descripcion, cant, p_unit, total 
        FROM ventas_detalle 
        WHERE n_documento = :n_documento
    ";
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([':n_documento' => $n_documento]);
    $detalle = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. CONSTRUIR HTML ---
    
    $html = '
        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #555; padding-bottom: 5px;">
            <div>
                <strong>Cliente:</strong> ' . htmlspecialchars($venta['nombre_cliente'] ?: 'Público General') . '<br>
                <strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime($venta['fecha_venta'])) . '
            </div>
            <div>
                <strong>Condición:</strong> ' . htmlspecialchars($venta['cond_pago']) . '<br>
                <strong>Total Venta:</strong> $<strong style="color: lightgreen; font-size: 1.2em;">' . number_format($venta['total_venta'], 2) . '</strong>
            </div>
        </div>
        
        <h3>Productos:</h3>
        <table style="width: 100%;">
            <thead>
                <tr>
                    <th style="text-align: left;">Descripción</th>
                    <th style="width: 10%;">Cant.</th>
                    <th style="width: 15%;">P. Unit.</th>
                    <th style="width: 15%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($detalle as $item) {
        $html .= '
            <tr>
                <td>' . htmlspecialchars($item['descripcion']) . '</td>
                <td style="text-align: center;">' . number_format($item['cant'], 2) . '</td>
                <td style="text-align: right;">$' . number_format($item['p_unit'], 2) . '</td>
                <td style="text-align: right;">$' . number_format($item['total'], 2) . '</td>
            </tr>';
    }

    $html .= '
            </tbody>
        </table>
        <hr style="border-top: 1px solid #555;">
        <div style="text-align: right;">
            <strong>Pago Efectivo:</strong> $' . number_format($venta['pago_efectivo'], 2) . '<br>
            <strong>Pago Transferencia:</strong> $' . number_format($venta['pago_transf'], 2) . '
        </div>
    ';
    
    echo $html;

} catch (Exception $e) {
    http_response_code(500);
    echo "<p style='color: red;'>Error interno del servidor: " . $e->getMessage() . "</p>";
}
?>