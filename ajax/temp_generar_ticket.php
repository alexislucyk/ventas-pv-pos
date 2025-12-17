<?php
// ajax/generar_ticket.php
date_default_timezone_set('America/Argentina/Buenos_Aires');
header('Content-Type: text/html; charset=UTF-8');
session_start();

// Control de Acceso (Opcional, pero recomendado)
if (!isset($_GET['n_documento']) || empty($_GET['n_documento'])) {
    http_response_code(400);
    echo "Falta el número de documento para generar el ticket.";
    exit();
}

// =========================================================================
// ******************* CONFIGURACIÓN Y CONEXIÓN DB *************************
// =========================================================================

// La ruta para incluir la configuración de la DB debe ser relativa a este script
// Si este script está en 'ajax/', la ruta sería: '../config/db_config.php'
// Si este script está en la raíz, la ruta sería: 'config/db_config.php'
require '../config/db_config.php'; // Ajusta esta ruta si es necesario

$n_documento = (int)$_GET['n_documento'];

// Verificar si la conexión es válida
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Error interno: La conexión a la base de datos falló.";
    exit();
}

// =========================================================================
// ******************* CONSULTA DE DATOS DE LA VENTA ***********************
// =========================================================================

try {
    // 1. Obtener datos de la Cabecera de la Venta y Cliente
    $sql_cabecera = "
        SELECT
            v.fecha_venta, v.total_venta, v.cond_pago, v.pago_efectivo, v.pago_transf,
            c.nombre AS nombre_cliente, c.apellido AS apellido_cliente, c.cuit AS documento_cliente
        FROM ventas v
        LEFT JOIN clientes c ON v.id_cliente = c.id
        WHERE v.n_documento = :n_documento
    ";
    $stmt_cabecera = $pdo->prepare($sql_cabecera);
    $stmt_cabecera->execute([':n_documento' => $n_documento]);
    $venta = $stmt_cabecera->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo "Venta N° {$n_documento} no encontrada.";
        exit();
    }

    // 2. Obtener Detalles/Productos de la Venta
    $sql_detalle = "
        SELECT descripcion, cant, p_unit, total
        FROM ventas_detalle
        WHERE n_documento = :n_documento
    ";
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([':n_documento' => $n_documento]);
    $detalle = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Error DB al generar ticket: " . $e->getMessage());
    echo "Error de base de datos al buscar los datos de la venta.";
    exit();
}

// =========================================================================
// ******************* GENERACIÓN DEL HTML DEL TICKET **********************
// =========================================================================

// --- Configuración local ---
$nombre_empresa = "ELECTRICIDAD LUCYK";
$direccion = "Calle Falsa 123 - Ciudad";
$telefono = "341 555-5555";
$cuit_empresa = "20-99999999-7";
// ---------------------------

// Función auxiliar para formatear líneas de ticket
function formatLine($left, $right) {
    return "<div class='line'><span>" . htmlspecialchars($left) . "</span><span>" . htmlspecialchars($right) . "</span></div>\n";
}

$html_output = '';

// 1. Cabecera del Ticket
$html_output .= '<div class="center"><h3>' . $nombre_empresa . '</h3></div>';
$html_output .= '<div class="center">' . $direccion . '</div>';
$html_output .= '<div class="center">Tel: ' . $telefono . ' | CUIT: ' . $cuit_empresa . '</div>';
$html_output .= '<div class="sep"></div>';

// 2. Datos de la Venta
$fecha = new DateTime($venta['fecha_venta']);
$html_output .= formatLine("FECHA:", $fecha->format('d/m/Y'));
$html_output .= formatLine("N° DOC:", str_pad($n_documento, 8, '0', STR_PAD_LEFT));
$html_output .= formatLine("COND PAGO:", $venta['cond_pago']);
$html_output .= '<div class="sep"></div>';

// 3. Datos del Cliente
$nombre_completo = trim($venta['apellido_cliente'] . ', ' . $venta['nombre_cliente']);
if (empty($nombre_completo)) {
    $nombre_completo = "CONSUMIDOR FINAL";
    $documento = "0";
} else {
    $documento = isset($venta['documento_cliente']) ? $venta['documento_cliente'] : 'N/A';
}
$html_output .= formatLine("CLIENTE:", $nombre_completo);
$html_output .= formatLine("DOCUMENTO:", $documento);
$html_output .= '<div class="sep"></div>';

// 4. Detalle de Productos (Líneas largas para mejor alineación)
$html_output .= formatLine("CANT x PRECIO UNIT.", "SUBTOTAL");
$html_output .= '<div class="sep"></div>';

foreach ($detalle as $item) {
    $html_output .= '<p style="margin: 0;">' . htmlspecialchars($item['descripcion']) . '</p>';
    $cantidad_precio = sprintf("%.2f x $%.2f", $item['cant'], $item['p_unit']);
    $subtotal = sprintf("$%.2f", $item['total']);
    $html_output .= formatLine($cantidad_precio, $subtotal);
}
$html_output .= '<div class="sep"></div>';

// 5. Totales y Pagos
$html_output .= formatLine("TOTAL VENTA:", " **$" . number_format($venta['total_venta'], 2, '.', ',') . "**");
$html_output .= '<div class="sep"></div>';

$total_pagado = $venta['pago_efectivo'] + $venta['pago_transf'];

if ($venta['cond_pago'] === 'CONTADO') {
    $html_output .= formatLine("EFECTIVO:", "$" . number_format($venta['pago_efectivo'], 2, '.', ','));
    $html_output .= formatLine("TRANSFERENCIA:", "$" . number_format($venta['pago_transf'], 2, '.', ','));

    $cambio = $total_pagado - $venta['total_venta'];
    $html_output .= '<div class="sep"></div>';
    $html_output .= formatLine("CAMBIO/VUELTO:", "$" . number_format($cambio, 2, '.', ','));
} elseif ($venta['cond_pago'] === 'CUENTA CORRIENTE') {
    $saldo = $venta['total_venta'] - $total_pagado;
    if ($saldo > 0) {
        $html_output .= formatLine("PAGO A CUENTA:", "$" . number_format($total_pagado, 2, '.', ','));
        $html_output .= formatLine("SALDO C.CTE:", " **$" . number_format($saldo, 2, '.', ',') . "**");
    } else {
        $html_output .= '<div class="center">CUENTA CORRIENTE SALDADA</div>';
    }
}

$html_output .= '<div class="sep"></div>';

// 6. Mensaje Final
$html_output .= '<div class="center">GRACIAS POR SU COMPRA!</div>';
$html_output .= '<div class="center" style="font-size: 0.9em;">(No válido como Factura tipo A/B)</div>';
$html_output .= '<div class="sep" style="border-top: 3px solid black;"></div>';


// Imprime el HTML generado
echo $html_output;

// Asegúrate de que no haya caracteres adicionales
exit();
?>