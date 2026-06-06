<?php
// funciones/ticket_generator.php - VERSIÓN DE CONTACTO DINÁMICO
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

/**
 * Genera el HTML del ticket con datos de contacto desde datos_empresa.
 */
function generar_html_ticket_contenido(PDO $pdo, int|string $n_documento): string { 
    
    $n_documento = (int)$n_documento;
    
    if (!$pdo) {
        return "Error crítico: Conexión a DB no disponible.";
    }

    try {
        // --- 1. OBTENER DATOS DE CONTACTO DE LA EMPRESA ---
        $sql_empresa = "SELECT nombre_fantasia, direccion, localidad, telefono FROM datos_empresa WHERE id = 1";
        $stmt_emp = $pdo->query($sql_empresa);
        $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

        // Fallbacks por si algún campo está vacío en la DB
        $nombre = !empty($emp['nombre_fantasia']) ? $emp['nombre_fantasia'] : "";
        $direccion = isset($emp['direccion']) ? $emp['direccion'] : "";
        $localidad = isset($emp['localidad']) ? $emp['localidad'] : "";
        $telefono = isset($emp['telefono']) ? $emp['telefono'] : "";

        // --- 2. OBTENER DATOS DE LA VENTA ---
        $sql_venta = "
            SELECT 
                v.fecha_venta, v.total_venta, v.cond_pago, v.pago_efectivo, v.pago_transf,
                c.nombre AS nombre_cliente, c.apellido AS apellido_cliente
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id
            WHERE v.n_documento = :n_documento
        ";
        $stmt_venta = $pdo->prepare($sql_venta);
        $stmt_venta->execute([':n_documento' => $n_documento]);
        $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            return "Error: Venta N° $n_documento no encontrada.";
        }

        // --- 3. OBTENER DETALLE DE PRODUCTOS ---
        $sql_detalle = "SELECT descripcion, cant, p_unit, total FROM ventas_detalle WHERE n_documento = :n_documento";
        $stmt_detalle = $pdo->prepare($sql_detalle);
        $stmt_detalle->execute([':n_documento' => $n_documento]);
        $productos = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

        // --- 4. CÁLCULOS ---
        $total_venta = (float)$venta['total_venta'];
        $total_pagado = (float)$venta['pago_efectivo'] + (float)$venta['pago_transf'];
        $cambio_saldo = $total_pagado - $total_venta;
        
        // --- 5. GENERACIÓN DEL HTML ---
        $html = '<font size="2">'; 
        
        // --- ENCABEZADO PERSONALIZADO ---
        $html .= '<div class="center">';
        $html .= '<h3><b>' . htmlspecialchars($nombre) . '</b></h3>'; 
        
        if (!empty($direccion)) {
            $html .= '<p><b>' . htmlspecialchars($direccion) . '</b></p>';
        }
        
        if (!empty($localidad)) {
            $html .= '<p><b>' . htmlspecialchars($localidad) . '</b></p>';
        }

        if (!empty($telefono)) {
            $html .= '<p><b>Tel: ' . htmlspecialchars($telefono) . '</b></p>';
        }
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        // Datos del Ticket
        $html .= '<p>';
        $html .= 'Fecha: ' . date('d/m/Y H:i', strtotime($venta['fecha_venta'])) . '<br>';
        $html .= 'Comprobante: ' . str_pad($n_documento, 8, '0', STR_PAD_LEFT) . '<br>';
        $html .= '</p>';
        $html .= '<div class="sep"></div>';
        
        // Cliente
        $nom_cli = (empty($venta['apellido_cliente']) && empty($venta['nombre_cliente'])) 
            ? 'Consumidor Final' 
            : htmlspecialchars($venta['apellido_cliente'] . ', ' . $venta['nombre_cliente']);

        $html .= '<p>Cliente: ' . $nom_cli . '</p>';
        $html .= '<p>Cond. Pago: ' . $venta['cond_pago'] . '</p>';
        $html .= '<div class="sep"></div>';

        // --- ARTÍCULOS ---
        $html .= '<p>ARTÍCULO</p>';
        $html .= '<div class="sep"></div>';

        if (!empty($productos)) {
            foreach ($productos as $p) {
                $html .= '<p>' . htmlspecialchars($p['descripcion']) . '</p>';
                $html .= '<div class="line detail-line">';
                $html .= '<span>' . $p['cant'] . ' x $' . number_format($p['p_unit'], 2, ',', '.') . '</span>';
                $html .= '<span class="right"><strong>$' . number_format($p['total'], 2, ',', '.') . '</strong></span>'; 
                $html .= '</div>';
            }
        }
        
        $html .= '<div class="sep"></div>';

        // --- TOTAL ---
        $html .= '<div class="right">';
        $html .= '<div class="line"><strong>TOTAL:</strong> <strong>$' . number_format($total_venta, 2, ',', '.') . '</strong></div>';
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';

        // Pagos y Vuelto
        $html .= '<div class="right">';
        if ((float)$venta['pago_efectivo'] > 0) {
            $html .= '<div class="line"><span>Efectivo:</span> <span>$' . number_format($venta['pago_efectivo'], 2, ',', '.') . '</span></div>';
        }
        if ((float)$venta['pago_transf'] > 0) {
            $html .= '<div class="line"><span>Transferencia:</span> <span>$' . number_format($venta['pago_transf'], 2, ',', '.') . '</span></div>';
        }

        $label = ($cambio_saldo >= 0) ? 'Cambio:' : 'Saldo:';
        $html .= '<div class="line"><span><strong>' . $label . '</strong></span> <strong>$' . number_format(abs($cambio_saldo), 2, ',', '.') . '</strong></div>'; 
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        $html .= '<div class="center">';
        $html .= '<p>Gracias por su compra</p>';
        // $html .= '<p>*** NO VÁLIDO COMO FACTURA ***</p>';
        $html .= '</div>';
        
        $html .= '</font>';

        return $html;
        
    } catch (Exception $e) {
        return "Error al generar ticket: " . $e->getMessage();
    }
}