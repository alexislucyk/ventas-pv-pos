<?php
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
/**
 * Genera el HTML completo de un ticket de venta no fiscal con estilo de impresora térmica.
 * @param integer $n_documento Número de documento de la venta a imprimir.
 * @return string HTML completo del ticket, o un mensaje de error.
 */
// CÓDIGO CORREGIDO (Funciona en versiones antiguas de PHP):
function generar_html_ticket($pdo, $n_documento) { 
    
    // Convertir a entero explícitamente dentro de la función para seguridad
    $n_documento = (int)$n_documento;
    
    //global $pdo; // Usamos la conexión PDO definida en db_config.php

    if (!$pdo) {
        return "Error crítico: Conexión a DB no disponible.";
    }

    try {
        // --- 1. OBTENER DATOS DE LA VENTA (CABECERA) ---
        $sql_venta = "
            SELECT 
                v.fecha_venta, v.total_venta, v.cond_pago, v.pago_efectivo, v.pago_transf,
                c.nombre AS nombre_cliente, c.apellido AS apellido_cliente, c.cuit AS cuit_cliente
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

        // --- 2. OBTENER DETALLE DE PRODUCTOS ---
        // Recuperamos la información del detalle
        $sql_detalle = "
            SELECT descripcion, cant, p_unit, total 
            FROM ventas_detalle 
            WHERE n_documento = :n_documento
        ";
        $stmt_detalle = $pdo->prepare($sql_detalle);
        $stmt_detalle->execute([':n_documento' => $n_documento]);
        $productos = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

        // --- 3. CÁLCULOS NECESARIOS ---
        $total_venta = (float)$venta['total_venta'];
        
        // **ASUNCIÓN** (dado que no manejas impuestos explícitamente en ventas.php):
        // Asumimos que el IVA es del 21% para el cálculo de ejemplo (SubTotal = Total / 1.21)
        // SI NO USAS IVA, DEJA IVA = 0 Y SUBT = TOTAL
        
        $iva_rate = 0.0; // 21%
        $subtotal = $total_venta;
        $iva = $total_venta - $subtotal;
        
        // Cálculos de pago
        $total_pagado = $venta['pago_efectivo'] + $venta['pago_transf'];
        $cambio_saldo = $total_pagado - $total_venta;
        
        $nombre_tienda = "Electricidad Lucyk"; // Reemplazar con dato real de configuración
        
        // --- 4. GENERACIÓN DEL HTML ---

        $html = '<!DOCTYPE html><html><head>';
        $html .= '<title>Ticket #' . $n_documento . '</title>';
        $html .= '<style>';
        $html .= '
            body { 
                /* AUMENTADO: Ancho para utilizar más espacio en la impresora de 80mm */
                width: 280px; /* Incrementado de 280px a 300px */
                margin: 0 auto;
                font-family: "Courier New", monospace; 
                font-size: 13px; 
                line-height: 1.2;
                padding: 5px 0;
            }
            .center { text-align: center; } 
            .right { text-align: right; }
            /* Se mantiene el separador punteado/guiones */
            .sep { border-top: 1px dashed #000; margin: 5px 0; height: 1px; } 
            .line { display: flex; justify-content: space-between; margin: 1px 0; }
            .line strong { font-size: 1.1em; }
            .no-print { display: none; } 
            
            /* Optimización de impresión */
            @media print { 
                /* Asegura que no haya márgenes de página */
                body { padding: 0 !important; margin: 0 !important; }
                /* Oculta los botones */
                .no-print { display: none !important; } 
            }
        ';
        $html .= '</style>';
        $html .= '</head><body>';
        
        // --- 1. ENCABEZADO (HEADER) ---
        $html .= '<div class="center">';
        $html .= '<h3 style="margin: 5px 0;">' . htmlspecialchars($nombre_tienda) . '</h3>';
        // Simulación de datos fiscales/contacto (A rellenar)
        
        $html .= '<p style="margin: 0;">Av. San Martin 698</p>';
        $html .= '<p style="margin: 0;">Gregoria Pérez de Denis</p>';
        $html .= '<p style="margin: 0;">Tel: 3491-438555</p>';
        $html .= '</div>';
        $html .= '<div class="sep"></div>';
        
        // Datos de Venta y Cliente
        $html .= '<p style="margin: 0;">';
        $html .= date('d/m/Y H:i:s', strtotime($venta['fecha_venta'])) . ' p. m.<br>';
        $html .= 'Venta: ' . str_pad($n_documento, 6, '0', STR_PAD_LEFT) . '<br>';
        $html .= '</p>';
        $html .= '<div class="sep"></div>';
        
        $nombre_cliente = ($venta['apellido_cliente'] === null) ? 'Publico en General' : htmlspecialchars($venta['apellido_cliente'] . ', ' . $venta['nombre_cliente']);

        $html .= '<p style="margin: 0;">Cliente: ' . $nombre_cliente . '</p>';
        $html .= '<div class="sep"></div>';

        // --- 2. CUERPO (DETALLE DE PRODUCTOS) ---

        $html .= '<p style="margin: 0 0 5px 0;">ARTÍCULO</p>';
        $html .= '<div class="sep"></div>';

        foreach ($productos as $prod) {
            // Línea 1: Nombre del producto
            $html .= '<p style="margin: 0;">' . htmlspecialchars($prod['descripcion']) . '</p>';
            
            // Línea 2: Cantidad, Precio Unitario y Subtotal alineados
            $html .= '<div class="line" style="padding-left: 10px;">';
            
            // Columna Izquierda: Cantidad x Precio Unitario
            $html .= '    <span>&bull; ' . $prod['cant'] . ' x $' . number_format($prod['p_unit'], 2) . '</span>';
            
            // Columna Derecha: Total del Producto
            $html .= '    <span class="right">$' . number_format($prod['total'], 2) . '</span>';
            $html .= '</div>';
        }
        $html .= '<div class="sep"></div>';

        // --- 3. PIE DE PÁGINA (FOOTER) ---
        
        // Totales (Usando Subtotal e IVA calculado)
        $html .= '<div class="right">';
        
        $html .= '<div class="line"><span>SubTotal</span><span>$' . number_format($subtotal, 2) . '</span></div>';
        
        $html .= '<div class="line"><strong>Total</strong><strong>$' . number_format($total_venta, 2) . '</strong></div>';
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        // Pagos y Cambio
        $html .= '<div class="right">';
        $html .= '<div class="line"><span>Efectivo</span><span>$' . number_format($venta['pago_efectivo'], 2) . '</span></div>';
        
        // Si hay transferencia, mostrarla, sino solo el pago principal (efectivo)
        if ($venta['pago_transf'] > 0) {
             $html .= '<div class="line"><span>Transferencia</span><span>$' . number_format($venta['pago_transf'], 2) . '</span></div>';
        }

        $html .= '<div class="line total-line">';
        
        $etiqueta_cambio = ($cambio_saldo >= 0) ? 'Cambio M.N' : 'Saldo Pendiente';
        $valor_cambio = abs($cambio_saldo);
        
        // Usamos STRONG en la etiqueta y el valor
        $html .= '<span><strong>' . $etiqueta_cambio . '</strong></span><strong>$' . number_format($valor_cambio, 2) . '</strong>'; 
        
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="sep"></div>';
        
        // Mensajes
        $html .= '<div class="center">';
        $html .= '<p style="margin: 0;">Estado: Pagado</p>';
        $html .= '<div class="sep"></div>';
        $html .= '<p style="margin: 5px 0;">Gracias por su compra..!!</p>';
        $html .= '</div>';
        
        // Botones de control (fuera del diseño del ticket)
        $html .= '<div class="center no-print" style="margin-top: 20px; padding-bottom: 20px;">';
        $html .= '<button onclick="window.print()">Imprimir Ticket</button>';
        $html .= '</div>';

        $html .= '</body></html>';
        
        return $html;
        
    } catch (Exception $e) {
        error_log("Error al generar ticket: " . $e->getMessage());
        return "Error al generar el ticket. Venta N° $n_documento. Detalles: " . $e->getMessage();
    }
}
?>