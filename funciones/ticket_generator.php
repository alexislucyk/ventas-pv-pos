<?php
// funciones/ticket_generator.php - VERSIÓN FINAL SIMÉTRICA PARA PHP 5
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

/**
 * Genera el HTML del ticket de venta.
 * Corregido para alineación simétrica y compatibilidad con PHP 5.
 */
function generar_html_ticket_contenido(PDO $pdo, int|string $n_documento, int $empresa_id): string { 
    
    $n_documento = (int)$n_documento;
    
    if (!$pdo) {
        return "Error crítico: Conexión a DB no disponible.";
    }

    try {
        // La venta se busca por n_documento SIN depender de la empresa de la sesión:
        // la impresión puede ocurrir con una sesión/empresa distinta a la del alta.
        $sql_venta = "
            SELECT
                v.empresa_id, v.fecha_venta, v.total_venta, v.descuento_global, v.cond_pago,
                v.pago_efectivo, v.pago_transf, v.observaciones,
                c.nombre AS nombre_cliente, c.apellido AS apellido_cliente
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = v.empresa_id
            WHERE v.n_documento = :n_documento
            ORDER BY v.id DESC
            LIMIT 1
        ";
        $stmt_venta = $pdo->prepare($sql_venta);
        $stmt_venta->execute([':n_documento' => $n_documento]);
        $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            return "Error: Venta N° " . $n_documento . " no encontrada.";
        }

        // Empresa real de la venta (no la de la sesión)
        $empresa_real = (int)($venta['empresa_id'] ?? $empresa_id);

        $sql_empresa = "SELECT * FROM empresas WHERE id = ? LIMIT 1";
        $stmt_emp = $pdo->prepare($sql_empresa);
        $stmt_emp->execute([$empresa_real]);
        $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

        $nombre    = (!empty($emp['nombre_fantasia'])) ? $emp['nombre_fantasia'] : "Mi Negocio";
        $direccion = (!empty($emp['direccion']))       ? $emp['direccion']       : "";
        $localidad = (!empty($emp['localidad']))       ? $emp['localidad']       : "";
        $telefono  = (!empty($emp['telefono']))        ? $emp['telefono']        : "";

        $observaciones = !empty($venta['observaciones']) ? trim($venta['observaciones']) : '';

        $sql_detalle = "SELECT descripcion, cant, p_unit, descuento_unitario, total FROM ventas_detalle WHERE n_documento = :n_documento AND empresa_id = :empresa_id";
        $stmt_detalle = $pdo->prepare($sql_detalle);
        $stmt_detalle->execute([':n_documento' => $n_documento, ':empresa_id' => $empresa_real]);
        $productos = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

        // --- 4. CÁLCULOS ---
        $total_final_venta  = (float)$venta['total_venta'];
        $desc_global        = (!empty($venta['descuento_global'])) ? (float)$venta['descuento_global'] : 0.0;
        $pago_efec    = (float)$venta['pago_efectivo'];
        $pago_trans   = (float)$venta['pago_transf'];
        $total_pagado = $pago_efec + $pago_trans;
        $cambio_saldo = $total_pagado - $total_final_venta;
        
        // --- 5. GENERACIÓN DEL HTML ---
        $html = '<font size="2">'; 
        
        // --- ENCABEZADO (CONTACTO) ---
        $html .= '<div class="center">';
        $html .= '<h3><b>' . htmlspecialchars($nombre) . '</b></h3>'; 
        
        if ($direccion != "") {
            $html .= '<p><b>' . htmlspecialchars($direccion) . '</b></p>';
        }
        
        if ($localidad != "") {
            $html .= '<p><b>' . htmlspecialchars($localidad) . '</b></p>';
        }

        if ($telefono != "") {
            $html .= '<p><b>Tel: ' . htmlspecialchars($telefono) . '</b></p>';
        }
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        $html .= '<div class="line"><span>Fecha y Hora:</span> <span>' . date('d/m/Y H:i', strtotime($venta['fecha_venta'])) . '</span></div>';
        $html .= '<div class="line"><span>Orden Vta. N°:</span> <span>' . str_pad($n_documento, 8, '0', STR_PAD_LEFT) . '</span></div>';

        $html .= '<div class="sep"></div>';
        
        // Cliente
        $cli_nombre   = !empty($venta['nombre_cliente']) ? $venta['nombre_cliente'] : "";
        $cli_apellido = !empty($venta['apellido_cliente']) ? $venta['apellido_cliente'] : "";
        
        if ($cli_nombre == "" && $cli_apellido == "") {
            $nom_cli = "CONSUMIDOR FINAL";
        } else {
            $nom_cli = htmlspecialchars($cli_apellido . ", " . $cli_nombre);
        }

        $html .= '<div class="line"><span>Cliente:</span> <span>' . $nom_cli . '</span></div>';
        $html .= '<div class="line"><span>Cond. Pago:</span> <span>' . $venta['cond_pago'] . '</span></div>';
        $html .= '<div class="sep"></div>';

        // --- ARTÍCULOS (TABLA SIMÉTRICA) ---
        $html .= '<p>DETALLE DE COMPRA</p>';
        $html .= '<div class="sep"></div>';

        if (!empty($productos)) {
            // Estructura de tabla con ancho fijo para evitar desbordes
            $html .= '<table style="width: 100%; border-collapse: collapse; table-layout: fixed;">';
            $total_bruto_items = 0;
            
            foreach ($productos as $p) {
                $sub_bruto_linea = (float)$p['cant'] * (float)$p['p_unit'];
                $total_bruto_items += $sub_bruto_linea;

                // Fila 1: Descripción
                $html .= '<tr>';
                $html .= '<td colspan="2" style="padding-top: 5px; word-wrap: break-word;">' . htmlspecialchars($p['descripcion']) . '</td>';
                $html .= '</tr>';
                
                // Fila 2: Cantidad (Izquierda) y Subtotal (Derecha)
                $html .= '<tr>';
                $html .= '<td style="text-align: left; font-size: 0.9em; padding-bottom: 5px;">';
                $html .= $p['cant'] . ' x $' . number_format($p['p_unit'], 2, ',', '.');
                $html .= '</td>';
                $html .= '<td style="text-align: right; font-weight: bold; padding-bottom: 5px;">';
                $html .= '$' . number_format((float)$p['total'], 2, ',', '.');
                $html .= '</td>';
                $html .= '</tr>';

                // Fila 3: Descuento por Producto (si aplica)
                if (!empty($p['descuento_unitario']) && (float)$p['descuento_unitario'] > 0) {
                    $monto_ahorro_it = $sub_bruto_linea * ((float)$p['descuento_unitario'] / 100);
                    $html .= '<tr>';
                    $html .= '<td colspan="2" class="discount-text" style="padding-bottom: 5px; text-align: left;">';
                    $html .= 'Descuento (' . (float)$p['descuento_unitario'] . '%): -$' . number_format($monto_ahorro_it, 2, ',', '.');
                    $html .= '</td>';
                    $html .= '</tr>';
                }
            }
            
            $html .= '</table>';
        }
        
        $html .= '<div class="sep"></div>';

        // --- TOTAL ---
        $html .= '<table style="width: 100%;">';
        
        // Subtotal Bruto (Suma de Precio x Cantidad sin ningún descuento)
        $html .= '<tr>';
        $html .= '<td style="text-align: left;">Subtotal:</td>';
        $html .= '<td style="text-align: right;">$' . number_format($total_bruto_items, 2, ',', '.') . '</td>';
        $html .= '</tr>';

        // Ahorro total por productos individuales
        $total_ahorro_items = 0;
        foreach($productos as $p) { 
            $total_ahorro_items += ((float)$p['cant'] * (float)$p['p_unit'] * ((float)($p['descuento_unitario'] ?? 0) / 100)); 
        }
        
        if ($total_ahorro_items > 0) {
            $html .= '<tr class="discount-text">';
            $html .= '<td style="text-align: left;">Descuento Productos:</td>';
            $html .= '<td style="text-align: right;">-$' . number_format($total_ahorro_items, 2, ',', '.') . '</td>';
            $html .= '</tr>';
        }

        // Descuento Global
        if ($desc_global > 0) {
            $html .= '<tr class="discount-text">';
            $html .= '<td style="text-align: left;">Descuento Global:</td>';
            $html .= '<td style="text-align: right;">-$' . number_format($desc_global, 2, ',', '.') . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr>';
        $html .= '<td style="text-align: left;"><strong>TOTAL:</strong></td>';
        $html .= '<td style="text-align: right;"><strong>$' . number_format($total_final_venta, 2, ',', '.') . '</strong></td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        $html .= '<div class="sep"></div>';

        // Pagos y Vuelto
        $html .= '<div style="width: 100%; text-align: right;">';
        if ($pago_efec > 0) {
            $html .= '<p>Efectivo: $' . number_format($pago_efec, 2, ',', '.') . '</p>';
        }
        if ($pago_trans > 0) {
            $html .= '<p>Transf/Otros: $' . number_format($pago_trans, 2, ',', '.') . '</p>';
        }

        $label = ($cambio_saldo >= 0) ? 'Cambio:' : 'Saldo:';
        $html .= '<p><strong>' . $label . ' $' . number_format(abs($cambio_saldo), 2, ',', '.') . '</strong></p>'; 
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        // Observaciones (solo si la venta tiene datos cargados)
        if ($observaciones !== '') {
            $html .= '<table style="width: 100%; margin-top: 4px;">';
            $html .= '<tr>';
            $html .= '<td style="text-align: left; vertical-align: top;"><strong>Observaciones:</strong></td>';
            $html .= '</tr>';
            $html .= '<tr>';
            $html .= '<td style="text-align: left; vertical-align: top; padding-bottom: 4px;">' . htmlspecialchars($observaciones) . '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '<div class="sep"></div>';
        }
        
        // Pie
        $html .= '<div class="center">';
        $html .= '<p>Gracias por su compra!</p>';
        $html .= '<p>*** DOCUMENTO NO FISCAL ***</p>';
        $html .= '</div>';
        
        $html .= '</font>';

        return $html;
        
    } catch (Exception $e) {
        return "Error al generar ticket: " . $e->getMessage();
    }
}
?>