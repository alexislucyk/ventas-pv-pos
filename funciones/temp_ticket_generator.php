<?php
// funciones/ticket_generator.php - VERSIÓN FINAL Y CORREGIDA (FORZADO HTML)
    date_default_timezone_set('America/Argentina/Buenos_Aires'); 

    /**
     * Genera el HTML del CONTENIDO de un ticket de venta no fiscal con estilo de impresora térmica.
     * @param PDO $pdo Conexión a la base de datos.
     * @param integer $n_documento Número de documento de la venta a imprimir.
     * @return string HTML puro del ticket, o un mensaje de error.
     */
    function generar_html_ticket_contenido($pdo, $n_documento) { 
        
        $n_documento = (int)$n_documento;
        
        if (!$pdo) {
            return "Error crítico: Conexión a DB no disponible.";
        }

        try {
            // --- 1. OBTENER DATOS DE LA VENTA (CABECERA) ---
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

            // --- 2. OBTENER DETALLE DE PRODUCTOS (LA CONSULTA) ---
            $sql_detalle = "
                SELECT descripcion, cant, p_unit, total 
                FROM ventas_detalle 
                WHERE n_documento = :n_documento
            ";
            $stmt_detalle = $pdo->prepare($sql_detalle);
            $stmt_detalle->execute([':n_documento' => $n_documento]);
            $productos = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC); // <--- Aquí se cargan

            // --- 3. CÁLCULOS NECESARIOS ---
            $total_venta = (float)$venta['total_venta'];
            $subtotal = $total_venta; 
            $total_pagado = (float)$venta['pago_efectivo'] + (float)$venta['pago_transf'];
            $cambio_saldo = $total_pagado - $total_venta;
            $nombre_tienda = "Electricidad Lucyk"; 
            
            // --- 4. GENERACIÓN DEL HTML (Puro Contenido) ---
            
            $html = '';
            // **INICIO: FORZAR TAMAÑO GRANDE EN TODO EL CONTENIDO**
            $html .= '<font size="2">'; 
            
            // --- ENCABEZADO (HEADER) ---
            $html .= '<div class="center">';
            // **FORZAR NEGRILLAS EN EL ENCABEZADO**
            $html .= '<h3><b>' . htmlspecialchars($nombre_tienda) . '</b></h3>'; 
            $html .= '<p><b>Av. San Martin 698</b></p>'; 
            $html .= '<p><b>Gregoria Pérez de Denis</b></p>'; 
            $html .= '<p><b>Tel: 3491-438555</b></p>';
            $html .= '</div>';
            $html .= '<div class="sep"></div>';
            
            // Datos de Venta y Cliente
            $html .= '<p>';
            $html .= date('d/m/Y', strtotime($venta['fecha_venta'])) . '<br>';
            $html .= 'Venta: ' . str_pad($n_documento, 6, '0', STR_PAD_LEFT) . '<br>';
            $html .= '</p>';
            $html .= '<div class="sep"></div>';
            
            $nombre_cliente = (empty($venta['apellido_cliente']) && empty($venta['nombre_cliente'])) ? 'Publico en General' : htmlspecialchars($venta['apellido_cliente'] . ', ' . $venta['nombre_cliente']);

            $html .= '<p>Cliente: ' . $nombre_cliente . '</p>';
            $html .= '<div class="sep"></div>';
            $html .= '<p>Cond.Pago: ' . $venta['cond_pago'] . '</p>';
            $html .= '<div class="sep"></div>';

            // --- CUERPO (DETALLE DE PRODUCTOS) ---
            $html .= '<p>ARTÍCULO</p>';
            $html .= '<div class="sep"></div>';

            // **¡BLOQUE CRÍTICO CORREGIDO PARA MOSTRAR PRODUCTOS!**
            if (is_array($productos) && !empty($productos)) {
                foreach ($productos as $prod) {
                    // Línea 1: Nombre del producto
                    $html .= '<p>' . htmlspecialchars($prod['descripcion']) . '</p>';
                    
                    // Línea 2: Cantidad, Precio Unitario y Subtotal alineados
                    $html .= '<div class="line detail-line">';
                    
                    // Columna Izquierda: Cantidad x Precio Unitario
                    $html .= '<span>&bull; ' . $prod['cant'] . ' x $' . number_format($prod['p_unit'], 2) . '</span>';
                    
                    // Columna Derecha: Total del Producto (Usamos <strong>)
                    $html .= '<span class="right"><strong>$' . number_format($prod['total'], 2) . '</strong></span>'; 
                    $html .= '</div>';
                }
            } else {
                $html .= '<p class="center">(No se encontraron artículos para esta venta)</p>';
            }
            // ------------------------------------
            $html .= '<div class="sep"></div>';

            // --- PIE DE PÁGINA (FOOTER) ---
            
            // Totales (Usamos <strong> para negrita)
            $html .= '<div class="right">';
            
            $html .= '<div class="line"><span>SubTotal</span><span><strong>$' . number_format($subtotal, 2) . '</strong></span></div>';
            $html .= '<div class="line"><strong>Total</strong><strong>$' . number_format($total_venta, 2) . '</strong></div>';
            $html .= '</div>';
            
            $html .= '<div class="sep"></div>';

            // Pagos y Cambio
            $html .= '<div class="right">';
            $html .= '<div class="line"><span>Efectivo</span><span>$' . number_format($venta['pago_efectivo'], 2) . '</span></div>';
            
            if ((float)$venta['pago_transf'] > 0) {
                $html .= '<div class="line"><span>Transferencia</span><span>$' . number_format($venta['pago_transf'], 2) . '</span></div>';
            }

            $etiqueta_cambio = ($cambio_saldo >= 0) ? 'Cambio M.N' : 'Saldo Pendiente';
            $valor_cambio = abs($cambio_saldo);
            
            $html .= '<div class="line"><span><strong>' . $etiqueta_cambio . '</strong></span><strong>$' . number_format($valor_cambio, 2) . '</strong></div>'; 
            $html .= '</div>';
            
            $html .= '<div class="sep"></div>';
            
            // Mensajes
            $html .= '<div class="center">';
            $html .= '<div class="sep"></div>';
            $html .= '<p>Gracias por su compra..!!</p>'; 
            $html .= '</div>';
            
            // **FIN: CERRAR LA ETIQUETA DE FORZADO DE TAMAÑO**
            $html .= '</font>';

            return $html;
            
        } catch (Exception $e) {
            error_log("Error al generar ticket: " . $e->getMessage());
            return "Error al generar el ticket. Venta N° $n_documento. Detalles: " . $e->getMessage();
        }
    }
?>