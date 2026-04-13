<?php
// ajax/obtener_detalle_venta.php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/../config/db_config.php'; 

// Forzar que los errores se vean si algo falla
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    die("Error: N° Documento no proporcionado.");
}

$n_documento = (int)$_GET['n_documento'];
$formato = isset($_GET['formato']) ? $_GET['formato'] : 'html';

try {
    // 1. OBTENER CABECERA
    $sql_cabecera = "SELECT v.*, CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente 
                     FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id 
                     WHERE v.n_documento = :n_documento";
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->execute([':n_documento' => $n_documento]);
    $venta = $stmt_cabecera->fetch(PDO::FETCH_ASSOC);

    if (!$venta) die("Venta no encontrada.");

    // 2. OBTENER DETALLE
    $sql_detalle = "SELECT cod_prod, descripcion, cant, p_unit, total 
                    FROM ventas_detalle WHERE n_documento = :n_documento";
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([':n_documento' => $n_documento]);
    $detalle = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

    // 3. RESPUESTA JSON (Para reanudar venta)
    if ($formato === 'json') {
        header('Content-Type: application/json');
        $venta['cliente_nombre_completo'] = $venta['nombre_cliente'] ?: 'Público General';
        echo json_encode(['cabecera' => $venta, 'productos' => $detalle]);
        exit();
    }

    // 4. RESPUESTA HTML (Para ver en el modal)
    $html = '
        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #555; padding-bottom: 5px;">
            <div>
                <strong>Cliente:</strong> ' . htmlspecialchars($venta['nombre_cliente'] ?: 'Público General') . '<br>
                <strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime($venta['fecha_venta'])) . '
            </div>
            <div>
                <strong>Condición:</strong> ' . htmlspecialchars($venta['cond_pago']) . '<br>
                <strong>Total:</strong> <span style="color: #2ecc71; font-weight: bold;">$' . number_format($venta['total_venta'], 2, ',', '.') . '</span>
            </div>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
            <thead>
                <tr style="background: #444; color: white;">
                    <th style="text-align: left; padding: 5px;">Descripción</th>
                    <th style="width: 15%; text-align: center;">Cant.</th>
                    <th style="width: 20%; text-align: right;">P. Unit.</th>
                    <th style="width: 20%; text-align: right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($detalle as $item) {
        $html .= '
            <tr style="border-bottom: 1px solid #444;">
                <td style="padding: 5px;">' . htmlspecialchars($item['descripcion']) . '</td>
                <td style="text-align: center;">' . number_format($item['cant'], 2) . '</td>
                <td style="text-align: right;">$' . number_format($item['p_unit'], 2, ',', '.') . '</td>
                <td style="text-align: right;">$' . number_format($item['total'], 2, ',', '.') . '</td>
            </tr>';
    }

    $html .= '
            </tbody>
        </table>
        <div style="text-align: right; margin-top: 15px; border-top: 1px solid #555; padding-top: 10px;">
            <p><strong>Efectivo:</strong> $' . number_format($venta['pago_efectivo'], 2, ',', '.') . '</p>
            <p><strong>Transferencia:</strong> $' . number_format($venta['pago_transf'], 2, ',', '.') . '</p>
        </div>';
    
    echo $html;

} catch (Exception $e) {
    echo "Error interno: " . $e->getMessage();
}