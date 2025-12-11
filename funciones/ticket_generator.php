<?php
	date_default_timezone_set('America/Argentina/Buenos_Aires'); 
	/**
	 * Genera el HTML del CONTENIDO de un ticket de venta no fiscal con estilo de impresora térmica.
	 * No incluye las etiquetas <html>, <head> ni <body>.
	 * @param PDO $pdo Conexión a la base de datos.
	 * @param integer $n_documento Número de documento de la venta a imprimir.
	 * @return string HTML puro del ticket, o un mensaje de error.
	 */
	function generar_html_ticket_contenido($pdo, $n_documento) { 
		
		// Convertir a entero explícitamente dentro de la función para seguridad
		$n_documento = (int)$n_documento;
		
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
			
			$subtotal = $total_venta; // Asumiendo Subtotal = Total (sin impuestos desglosados)
			
			// Cálculos de pago
			$total_pagado = (float)$venta['pago_efectivo'] + (float)$venta['pago_transf'];
			$cambio_saldo = $total_pagado - $total_venta;
			
			$nombre_tienda = "Electricidad Lucyk"; 
			
			// --- 4. GENERACIÓN DEL HTML (Puro Contenido) ---
			
			$html = '';
			
			// --- 1. ENCABEZADO (HEADER) ---
			$html .= '<div class="center">';
			$html .= '<h3 style="margin: 5px 0;">' . htmlspecialchars($nombre_tienda) . '</h3>';
			// Simulación de datos fiscales/contacto
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
			
			// Lógica de cliente más robusta (maneja NULL o cadenas vacías)
			$nombre_cliente = (empty($venta['apellido_cliente']) && empty($venta['nombre_cliente'])) ? 'Publico en General' : htmlspecialchars($venta['apellido_cliente'] . ', ' . $venta['nombre_cliente']);

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
				$html .= '<span>&bull; ' . $prod['cant'] . ' x $' . number_format($prod['p_unit'], 2) . '</span>';
				
				// Columna Derecha: Total del Producto
				$html .= '<span class="right">$' . number_format($prod['total'], 2) . '</span>';
				$html .= '</div>';
			}
			$html .= '<div class="sep"></div>';

			// --- 3. PIE DE PÁGINA (FOOTER) ---
			
			// Totales
			$html .= '<div class="right">';
			
			$html .= '<div class="line"><span>SubTotal</span><span>$' . number_format($subtotal, 2) . '</span></div>';
			
			$html .= '<div class="line"><strong>Total</strong><strong>$' . number_format($total_venta, 2) . '</strong></div>';
			$html .= '</div>';
			
			$html .= '<div class="sep"></div>';
			
			// Pagos y Cambio
			$html .= '<div class="right">';
			$html .= '<div class="line"><span>Efectivo</span><span>$' . number_format($venta['pago_efectivo'], 2) . '</span></div>';
			
			// Si hay transferencia, mostrarla
			if ((float)$venta['pago_transf'] > 0) {
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
			///$html .= '<p style="margin: 0;">Estado: Pagado</p>';
			$html .= '<div class="sep"></div>';
			$html .= '<p style="margin: 5px 0;">Gracias por su compra..!!</p>';
			$html .= '</div>';
			
			// Retornar solo el contenido
			return $html;
			
		} catch (Exception $e) {
			error_log("Error al generar ticket: " . $e->getMessage());
			return "Error al generar el ticket. Venta N° $n_documento. Detalles: " . $e->getMessage();
		}
	}
?>