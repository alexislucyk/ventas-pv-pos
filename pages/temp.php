<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

// 1. Control de Sesión
if (!isset($_SESSION['usuario_id'])) {
	header('Location: login.php'); 
	exit();
}

// =========================================================================
// ******************* REQUERIMIENTOS CRÍTICOS *****************************
// =========================================================================

require '../config/db_config.php'; 

// VERIFICACIÓN CLAVE: Si la conexión ($pdo) falla en db_config.php, detente.
if (!isset($pdo) || !($pdo instanceof PDO)) {
	// Esto previene el error Fatal error: Call to a member function query() on null
	$mensaje = "❌ ERROR CRÍTICO: La conexión a la base de datos no está disponible.";
	error_log($mensaje);
} else {
	$mensaje = '';

	// --- Bloque para obtener clientes ---
	try {
		$sql_clientes = "SELECT 
							id AS id_cliente,
							CONCAT(apellido, ', ', nombre) AS nombre_completo,
							cuit AS num_documento 
						FROM clientes 
						ORDER BY nombre_completo ASC";
						
		$stmt_clientes = $pdo->query($sql_clientes);
		$clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC); 
	} catch (Exception $e) {
		error_log("Error al cargar clientes: " . $e->getMessage());
		$mensaje = "⚠️ Advertencia: No se pudieron cargar los clientes.";
		$clientes = []; 
	}
	// --- Fin bloque clientes ---

	// --- Bloque para obtener el Siguiente N° de Documento (Factura) ---
	$siguiente_n_documento = 1; 
	try {
		$sql_ultimo_doc = "SELECT MAX(n_documento) AS ultimo_doc FROM ventas";
		$stmt_ultimo_doc = $pdo->query($sql_ultimo_doc);
		$resultado = $stmt_ultimo_doc->fetch(PDO::FETCH_ASSOC);

		if ($resultado && $resultado['ultimo_doc'] !== null) {
			$siguiente_n_documento = $resultado['ultimo_doc'] + 1;
		}
	} catch (Exception $e) {
		error_log("Error al buscar el último N° de Documento: " . $e->getMessage());
		$siguiente_n_documento = 1; 
	}
	// --- Fin Bloque N° Documento ---

// =====================================================
// LÓGICA DE PROCESAMIENTO DE VENTA (FINALIZAR O PENDIENTE)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {
	
	$accion = $_POST['venta_action'];
	$es_finalizar = ($accion === 'Finalizar'); 
	$estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';
	
	///$id_venta_existente = (int)($_POST['id_venta_existente'] ?? 0);
    $id_venta_existente = (int)(isset($_POST['id_venta_existente']) ? $_POST['id_venta_existente'] : 0);
	
	// 1. Obtener y sanitizar datos de la cabecera
	$id_cliente = (int)$_POST['cliente_id'];
	$cond_pago = trim($_POST['cond_pago']); 
	$n_documento = (int)$_POST['n_documento'];
	$total_venta = max(0.0, (float)$_POST['total_venta']); 
	$pago_efectivo = max(0.0, (float)$_POST['pago_efectivo']); 
	$pago_transf = max(0.0, (float)$_POST['pago_transf']); 
	$fecha_venta = date('Y-m-d H:i:s');

	$detalle_productos = json_decode($_POST['detalle_productos'], true); 

	if (empty($detalle_productos)) {
		$mensaje = "❌ Error: No se puede registrar una venta sin productos.";
	} else {
		try {
			$pdo->beginTransaction(); // === INICIA LA TRANSACCIÓN ===
			
			// --- A) Lógica para Venta Existente (Actualizar Pendiente) ---
			if ($id_venta_existente > 0) {
				
				// 1. Actualizar Cabecera
				$sql_update_venta = "UPDATE ventas SET id_cliente=?, cond_pago=?, total_venta=?, pago_efectivo=?, pago_transf=?, estado=?, fecha_venta=? WHERE id=?";
				$stmt_update_venta = $pdo->prepare($sql_update_venta);
				$stmt_update_venta->execute([
					$id_cliente, $cond_pago, $total_venta, $pago_efectivo, $pago_transf, $estado_venta, $fecha_venta, $id_venta_existente
				]);

				// 2. Eliminar Detalle anterior para reinsertar el nuevo
				$sql_delete_detalle = "DELETE FROM ventas_detalle WHERE n_documento = ?";
				$pdo->prepare($sql_delete_detalle)->execute([$n_documento]);
				
				$id_venta_actual = $id_venta_existente;
				
			} 
			// --- B) Lógica para Venta Nueva ---
			else { 
				
				// 1. Insertar Cabecera
				$sql_venta = "INSERT INTO ventas (id_cliente, cond_pago, n_documento, total_venta, pago_efectivo, pago_transf, fecha_venta, estado) 
							VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
				$stmt_venta = $pdo->prepare($sql_venta);
				$stmt_venta->execute([
					$id_cliente, $cond_pago, $n_documento, $total_venta, $pago_efectivo, $pago_transf, $fecha_venta, $estado_venta
				]);
				$id_venta_actual = $pdo->lastInsertId();
			}

			// --- C) Insertar Detalle ---
			$sql_detalle = "INSERT INTO ventas_detalle (cod_prod, descripcion, cant, p_unit, total, n_documento, fecha) 
							VALUES (?, ?, ?, ?, ?, ?, ?)";
			
			foreach ($detalle_productos as $item) {
				$stmt_detalle = $pdo->prepare($sql_detalle);
				
				// Corregido con tabulación y sintaxis array correcta
				$stmt_detalle->execute([
					$item['cod_prod'], 
					$item['descripcion'], 
					$item['cant'], 
					$item['p_unit'], 
					$item['total'], 
					$n_documento, 
					$fecha_venta
				]);
			}
			
			// --- D) Actualizar Stock (Solo si es Venta Finalizada) ---
			if ($es_finalizar) {
				$sql_stock_update = "UPDATE productos SET stock = stock - ? WHERE cod_prod = ?";
				foreach ($detalle_productos as $item) {
					$stmt_stock = $pdo->prepare($sql_stock_update);
					$stmt_stock->execute([ (float)$item['cant'], $item['cod_prod'] ]);
				}
			}


			$pdo->commit(); // === CONFIRMA LA TRANSACCIÓN ===
			
			if ($es_finalizar) {
				$mensaje = "✅ Venta (Documento {$n_documento}) FINALIZADA y stock actualizado con éxito.";
				$_SESSION['ticket_a_imprimir_doc'] = $n_documento; 
			} else {
				$mensaje = "📝 Venta N° {$n_documento} guardada como PENDIENTE con éxito. ID: {$id_venta_actual}";
			}
			
			// Redirige para limpiar el POST
			header("Location: ventas.php"); 
			exit();

		} catch (Exception $e) {
			$pdo->rollBack(); // === REVierte la transacción si algo falló ===
			$mensaje = "❌ Error al procesar la venta. La transacción fue revertida. Mensaje: " . $e->getMessage();
			error_log("Error de transacción en ventas: " . $e->getMessage());
		}
	}
}
} // Fin del 'else' de verificación de conexión $pdo

// Lógica para capturar el N° de documento a imprimir después de la redirección
$ticket_doc_a_imprimir = null;
if (isset($_SESSION['ticket_a_imprimir_doc'])) {
	$ticket_doc_a_imprimir = (int)$_SESSION['ticket_a_imprimir_doc'];
	unset($_SESSION['ticket_a_imprimir_doc']);
}

?>


<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<title>Nueva Venta | Electricidad Lucyk</title>
	<link rel="stylesheet" href="../css/style.css"> 
	<style>
		/* Estilos CSS internos necesarios para la vista */
		.venta-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
		#carrito tbody tr:hover { background-color: #333; }
		.producto-encontrado, .resultado-cliente-item { cursor: pointer; padding: 5px; border-bottom: 1px solid #444; }
		.producto-encontrado:hover, .resultado-cliente-item:hover { background-color: #555; }
		#resultadosBusqueda { max-height: 200px; overflow-y: auto; background: #222; border: 1px solid #444; position: absolute; width: 45%; z-index: 10; }
		#resultadosBusquedaClientes { max-height: 200px; overflow-y: auto; background: #222; border: 1px solid #444; position: absolute; width: 300px; z-index: 10; }
		.text-right { text-align: right; }
		.alert-error { background-color: #f44336; }
		.alert-success { background-color: #4caf50; }
		.alert { padding: 10px; margin-bottom: 20px; border-radius: 5px; color: white; }
		/* Estilos para el modal (si se perdió) */
		.modal {
			display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
			overflow: auto; background-color: rgba(0,0,0,0.4);
		}
		.modal-content {
			background-color: #2c2c2c; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%;
			max-width: 600px; border-radius: 8px; color: white;
		}
		/* CLASE CRÍTICA PARA CORREGIR EL ACHICAMIENTO DE PANTALLA */
		.modal-open-fix {
			overflow: hidden !important; 
		}
        /* --- ESTILOS CRÍTICOS PARA VISTA DE TICKET --- */
        #ticketModal body {
            font-family: 'Courier New', monospace;
            font-size: 13px; 
            line-height: 1.2;
            padding: 5px 0;
            margin: 0;
            width: 100%; /* Asegurar que se adapte al modal */
            background: #2c2c2c; /* Fondo del modal */
            color: white; /* Color del texto del modal (aunque la vista previa es blanca) */
        }

        /* Aplicar el estilo de ticket solo al contenedor interno blanco */
        #ticket-vista-previa { 
            background-color: white !important; 
            color: black !important;
        }

        /* Resetear estilos para el contenido del ticket insertado */
        #ticket-vista-previa .center { text-align: center; } 
        #ticket-vista-previa .right { text-align: right; }
        /* Usamos color negro para el ticket */
        #ticket-vista-previa p, #ticket-vista-previa h3 { color: black !important; }

        /* Separador: CRÍTICO: Debe ser guiones/puntos NEGROS */
        #ticket-vista-previa .sep { 
            border-top: 1px dashed black; /* Asegurar color negro */
            margin: 5px 0; 
            height: 1px; 
        } 

        /* Líneas de Totales y Productos */
        #ticket-vista-previa .line { 
            display: flex; 
            justify-content: space-between; 
            margin: 1px 0; 
            width: 100%; /* Asegurar que ocupe todo el ancho */
        }

        /* Estilos de impresión (para la ventana de impresión separada) */
        @media print {
            /* ... Asegúrate de mantener aquí los estilos @media print que eliminaste de ticket_generator.php ... */
            
            body { 
                padding: 0 !important; 
                margin: 0 !important; 
                width: 80mm !important; /* Si tu impresora es de 80mm */
            }
            .no-print { display: none !important; }
            .sep { border-top: 1px dashed #000; }
            /* etc. */
        }
	</style>
</head>
<body>

	<?php include 'sidebar.php'; ?> 
	<?php include 'infosesion.php'; ?> 
	<div class="content">
		<h1>Nueva Venta</h1>
		
		<?php if ($mensaje): ?>
			<div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
				<?php echo $mensaje; ?>
			</div>
		<?php endif; ?>

		<div class="venta-grid">
			
			<div class="card">
				<h2>Detalle de Productos</h2>
				
				<label for="buscar_producto">Buscar Producto (Código o Descripción)</label>
				<input type="text" id="buscar_producto" class="input-field" placeholder="Escriba aquí el código o nombre del producto">
				<div id="resultadosBusqueda"></div>

				<hr>

				<h3>Carrito de Venta</h3>
				<table id="carrito" style="width: 100%;">
					<thead>
						<tr>
							<th>Código</th>
							<th>Descripción</th>
							<th class="text-right">Precio</th>
							<th style="width: 10%;">Cant.</th>
							<th class="text-right">Subtotal</th>
							<th>Acción</th>
						</tr>
					</thead>
					<tbody>
					</tbody>
				</table>
			</div>
			
			<div class="card">
				<form id="formVenta" method="POST" action="ventas.php">
					<input type="hidden" name="guardar_venta" value="1">
					<input type="hidden" name="detalle_productos" id="detalle_productos_input">

					<h2>Datos de la Venta</h2>

					<div class="contenedor-busqueda-cliente" style="margin-bottom: 20px; position: relative;"> 
						<label for="buscar_cliente">Buscar Cliente (Nombre o CUIT)</label>
						<input type="text" id="buscar_cliente" class="input-field" placeholder="Venta Genérica">
						<div id="resultadosBusquedaClientes" style="left: 0;"></div>
					</div>
					
					<div style="margin-bottom: 10px;">
						Cliente Actual: <strong id="nombre_cliente_display">Venta Genérica</strong>
					</div>

					<input type="hidden" name="cliente_id" id="cliente_id_hidden" value="0">

					<label for="num_documento_display">CUIT/Documento</label>
					<input type="text" id="num_documento_display" class="input-field" value="" readonly>

					<label for="n_documento">N° Documento (Factura)*</label>
					<input 
						type="number" 
						id="n_documento" 
						name="n_documento" 
						class="input-field" 
						value="<?php echo htmlspecialchars($siguiente_n_documento); ?>" 
						readonly 
						required>

					<hr>

					<h3>Totales y Pago</h3>

					<label for="cond_pago">Condición de Pago</label>
					<select id="cond_pago" name="cond_pago" class="input-field" required>
						<option value="CONTADO" selected>CONTADO</option>
						<option value="CUENTA CORRIENTE">CUENTA CORRIENTE</option>
					</select>

					<div id="contenedor_pagos"> 
						
						<div style="display: flex; justify-content: space-between; font-size: 1.2em; margin-bottom: 10px;">
							<strong>TOTAL VENTA:</strong> 
							<strong id="total_venta_display" style="color: lightgreen;">$0.00</strong>
						</div>

						<input type="hidden" name="total_venta" id="total_venta_input" value="0.00">

						<label for="pago_efectivo">Pago en Efectivo</label>
						<input type="number" step="0.01" id="pago_efectivo" name="pago_efectivo" class="input-field pago-input" value="" min="0">

						<label for="pago_transf">Pago con Transferencia</label>
						<input type="number" step="0.01" id="pago_transf" name="pago_transf" class="input-field pago-input" value="" min="0">
						
						<div style="display: flex; justify-content: space-between; font-size: 1.1em; margin-top: 15px;">
							<strong>Cambio / Saldo:</strong> 
							<strong id="cambio_saldo_display">$0.00</strong> 
						</div>
					</div>
					
					<button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;" id="btnFinalizarVenta">
						Finalizar y Guardar Venta
					</button>
					
					<button type="button" class="btn btn-green" style="width: 100%; margin-top: 10px;" id="btnGuardarPendiente">
						Guardar como Pendiente
					</button>
					
					<input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">
					
					<input type="hidden" name="id_venta_existente" id="id_venta_existente_input" value="0">
											
				</form>

				<button type="button" class="btn btn-yellow" style="width: 100%; margin-top: 10px;" id="btnVerPendientes">
					Ver Ventas Pendientes
				</button>
				<div id="pendientesModal" class="modal">
					<div class="modal-content" style="max-width: 800px;">
						<span class="close-btn" onclick="cerrarModalPendientes()">&times;</span> 
						<h2>Ventas en Espera</h2>
						<div id="listaPendientes">Cargando...</div>
					</div>
				</div>
			</div>
			
		</div>
	</div>
	
	<div id="ticketModal" class="modal">
		<div class="modal-content">
			<span class="close-btn" onclick="cerrarModalTicket()">&times;</span>
			<div id="ticket-vista-previa" style="color: black; background-color: white; padding: 10px; border-radius: 5px;">
				Cargando vista previa...
			</div>
			<button id="btnImprimirTicket" class="btn btn-green" style="margin-top: 15px;">Imprimir Ticket</button>
			<p id="errorTicket" style="color: red; margin-top: 10px; display: none;">Error al cargar la vista previa.</p>
		</div>
	</div>


</body>
</html>

<script>
	document.addEventListener('DOMContentLoaded', function() {
		let carrito = []; 
		let clientesData = <?php echo json_encode($clientes); ?>;
		
		// ===========================================
		// 1. FUNCIONALIDAD DEL CARRITO Y CÁLCULOS
		// ===========================================

		function calcularTotales() {
			const selectCondPago = document.getElementById('cond_pago');
			const pagoEfectivoInput = document.getElementById('pago_efectivo');
			const pagoTransfInput = document.getElementById('pago_transf');
			const cambioSaldoStrong = document.getElementById('cambio_saldo_display');
			const totalVentaInputHidden = document.getElementById('total_venta_input');
			const totalVentaDisplay = document.getElementById('total_venta_display');

			// 1. Calcular el Total de la Venta 
			let totalVenta = carrito.reduce((sum, item) => sum + item.total, 0);

			// Actualizar el display y el campo oculto
			totalVentaDisplay.textContent = '$' + totalVenta.toFixed(2);
			totalVentaInputHidden.value = totalVenta.toFixed(2); 

			// 2. LÓGICA DE CONDICIÓN DE PAGO
			if (selectCondPago.value === 'Cuenta Corriente') {
				cambioSaldoStrong.textContent = 'Cta. Cte.';
				cambioSaldoStrong.style.color = '#00bcd4'; 
				
				// Forzar los valores de pago a 0.00 para el POST
				pagoEfectivoInput.value = '0.00';
				pagoTransfInput.value = '0.00';
				return; 
			}
			
			// Si es Contado, sumar ambos pagos
			const pagoEfectivo = parseFloat(pagoEfectivoInput.value) || 0;
			const pagoTransferencia = parseFloat(pagoTransfInput.value) || 0;
			const totalPagado = pagoEfectivo + pagoTransferencia;
			
			const cambio = totalPagado - totalVenta;
			
			// 3. Mostrar el Cambio / Saldo
			cambioSaldoStrong.textContent = `$${cambio.toFixed(2)}`;
			
			if (cambio < 0) {
				cambioSaldoStrong.style.color = '#f44336'; // Rojo: Saldo Pendiente
			} else {
				cambioSaldoStrong.style.color = '#4caf50'; // Verde: Cambio a Devolver
			}
		}

		function renderizarCarrito() {
			const tbody = document.querySelector('#carrito tbody');
			tbody.innerHTML = '';
			
			carrito.forEach((item, index) => {
				const row = tbody.insertRow();
				row.dataset.index = index;
				
				row.innerHTML = `
					<td>${item.cod_prod}</td>
					<td>${item.descripcion}</td>
					<td class="text-right">$${item.p_unit.toFixed(2)}</td>
					<td>
						<input type="number" min="1" step="any" value="${item.cant}" data-cod-prod="${item.cod_prod}"
							class="input-field update-cantidad" style="width: 60px; padding: 5px;">
					</td>
					<td class="text-right">$${item.total.toFixed(2)}</td>
					<td>
						<button type="button" class="btn btn-danger btn-sm remover-item" data-cod-prod="${item.cod_prod}">X</button>
					</td>
				`;
			});
			calcularTotales();
		}

		// Eventos para actualizar cantidad y remover item
		document.querySelector('#carrito').addEventListener('change', function(e) {
			if (e.target.classList.contains('update-cantidad')) {
				const cod_prod = e.target.dataset.codProd;
				const nuevaCantidad = parseFloat(e.target.value);
				
				if (nuevaCantidad > 0) {
					const index = carrito.findIndex(item => item.cod_prod === cod_prod);
					if (index !== -1) {
						
						carrito[index].cant = nuevaCantidad; 
						carrito[index].total = nuevaCantidad * carrito[index].p_unit;
						renderizarCarrito();
					}
				} else {
					e.target.value = 1; 
				}
			}
		});

		document.querySelector('#carrito').addEventListener('click', function(e) {
			if (e.target.classList.contains('remover-item')) {
				const cod_prod = e.target.dataset.codProd;
				carrito = carrito.filter(item => item.cod_prod !== cod_prod);
				renderizarCarrito();
			}
		});

		// Eventos para pagos y condición
		const pagoEfectivoInput = document.getElementById('pago_efectivo');
		const pagoTransfInput = document.getElementById('pago_transf');
		const selectCondPago = document.getElementById('cond_pago');
		const contenedorPagos = document.getElementById('contenedor_pagos');
		
		pagoEfectivoInput.addEventListener('input', calcularTotales);
		pagoTransfInput.addEventListener('input', calcularTotales);
		
		selectCondPago.addEventListener('change', function() {
			if (this.value === 'Cuenta Corriente') {
				contenedorPagos.style.display = 'none'; 
				pagoEfectivoInput.value = '0.00'; 
				pagoTransfInput.value = '0.00'; 
			} else { 
				contenedorPagos.style.display = 'block';
			}
			calcularTotales(); 
		});


		// ===========================================
		// 2. BÚSQUEDA DE PRODUCTOS (AJAX)
		// ===========================================

		const inputBuscar = document.getElementById('buscar_producto');
		const resultadosDiv = document.getElementById('resultadosBusqueda');

		inputBuscar.addEventListener('input', function() {
			const busqueda = inputBuscar.value.trim();
			if (busqueda.length < 3) {
				resultadosDiv.innerHTML = '';
				return;
			}

			const xhr = new XMLHttpRequest();
			xhr.open('GET', 'buscar_producto_ajax.php?q=' + encodeURIComponent(busqueda), true); 
			xhr.onload = function() {
				if (this.status == 200) {
					try {
						const productos = JSON.parse(this.responseText);
						mostrarResultados(productos);
					} catch (e) {
						resultadosDiv.innerHTML = 'Error al procesar la respuesta.';
					}
				} else {
					resultadosDiv.innerHTML = 'Error en la búsqueda (HTTP ' + this.status + ').';
				}
			};
			xhr.send();
		});

		function mostrarResultados(productos) {
			resultadosDiv.innerHTML = '';
			if (productos.length === 0) {
				resultadosDiv.innerHTML = '<div style="padding: 10px;">No se encontraron productos.</div>';
				return;
			}

			productos.forEach(producto => {
				const div = document.createElement('div');
				div.classList.add('producto-encontrado');
				div.textContent = `[${producto.cod_prod}] ${producto.descripcion} ($${parseFloat(producto.p_venta).toFixed(2)}) - Stock: ${producto.stock}`;
				div.dataset.producto = JSON.stringify(producto);
				resultadosDiv.appendChild(div);
			});
			resultadosDiv.style.display = 'block';
		}

		resultadosDiv.addEventListener('click', function(e) {
			if (e.target.classList.contains('producto-encontrado')) {
				const producto = JSON.parse(e.target.dataset.producto);
				
				// Buscar si ya está en el carrito
				const index = carrito.findIndex(item => item.cod_prod === producto.cod_prod);
				
				if (index !== -1) {
					// Si ya está, incrementar cantidad
					if (carrito[index].cant + 1 <= producto.stock_disponible) { // Uso stock_disponible si lo tienes en el objeto producto, sino usa producto.stock
						carrito[index].cant += 1;
						carrito[index].total = carrito[index].cant * carrito[index].p_unit;
					} else {
						alert("Stock insuficiente.");
					}
				} else {
					// Si no está, añadir nuevo item
					if (producto.stock > 0) {
						carrito.push({
							cod_prod: producto.cod_prod,
							descripcion: producto.descripcion,
							p_unit: parseFloat(producto.p_venta),
							cant: 1,
							total: parseFloat(producto.p_venta),
							stock_disponible: parseInt(producto.stock) 
						});
					} else {
						alert("El producto no tiene stock disponible.");
					}
				}
				
				inputBuscar.value = '';
				resultadosDiv.innerHTML = '';
				renderizarCarrito();
			}
		});
		
		// Clic fuera para ocultar resultados de búsqueda de productos
		document.addEventListener('click', function(e) {
			if (!inputBuscar.contains(e.target) && !resultadosDiv.contains(e.target)) {
				resultadosDiv.style.display = 'none';
			}
		});


		// ===========================================
		// 3. BÚSQUEDA DE CLIENTES
		// ===========================================
		
		const inputBuscarCliente = document.getElementById('buscar_cliente');
		const resultadosDivClientes = document.getElementById('resultadosBusquedaClientes');
		const nombreClienteDisplay = document.getElementById('nombre_cliente_display');
		const clienteIdHidden = document.getElementById('cliente_id_hidden');
		const numDocumentoDisplay = document.getElementById('num_documento_display');

		inputBuscarCliente.addEventListener('input', function() {
			const busqueda = inputBuscarCliente.value.trim().toLowerCase();
			resultadosDivClientes.innerHTML = '';

			if (busqueda.length < 2) {
				resultadosDivClientes.style.display = 'none';
				return;
			}

			const resultados = clientesData.filter(cliente => 
				cliente.nombre_completo.toLowerCase().includes(busqueda) || 
				cliente.num_documento.includes(busqueda)
			);

			if (resultados.length > 0) {
				resultados.forEach(cliente => {
					const div = document.createElement('div');
					div.classList.add('resultado-cliente-item');
					div.textContent = `${cliente.nombre_completo} (${cliente.num_documento})`;
					div.dataset.cliente = JSON.stringify(cliente);
					resultadosDivClientes.appendChild(div);
				});
				resultadosDivClientes.style.display = 'block';
			} else {
				resultadosDivClientes.style.display = 'none';
			}
		});

		resultadosDivClientes.addEventListener('click', function(e) {
			if (e.target.classList.contains('resultado-cliente-item')) {
				const cliente = JSON.parse(e.target.dataset.cliente);
				
				nombreClienteDisplay.textContent = cliente.nombre_completo;
				clienteIdHidden.value = cliente.id_cliente;
				numDocumentoDisplay.value = cliente.num_documento;
				inputBuscarCliente.value = cliente.nombre_completo;
				
				resultadosDivClientes.style.display = 'none';
			}
		});

		// Clic fuera para ocultar resultados de búsqueda de clientes
		document.addEventListener('click', function(e) {
			if (!inputBuscarCliente.contains(e.target) && !resultadosDivClientes.contains(e.target)) {
				resultadosDivClientes.style.display = 'none';
			}
		});

		// Establecer Venta Genérica por defecto al cargar
		function setVentaGenerica() {
			nombreClienteDisplay.textContent = 'Venta Genérica';
			clienteIdHidden.value = '0';
			numDocumentoDisplay.value = '';
			inputBuscarCliente.value = '';
		}

		setVentaGenerica();
		
		// ===========================================
		// 4. ENVÍO DEL FORMULARIO
		// ===========================================
		document.getElementById('formVenta').addEventListener('submit', function(e) {
			if (carrito.length === 0) {
				alert("Debe agregar al menos un producto al carrito para guardar la venta.");
				e.preventDefault();
				return;
			}

			// Actualizar el campo oculto con el detalle del carrito
			document.getElementById('detalle_productos_input').value = JSON.stringify(carrito);
			
			// Lógica de validación de pago (ejemplo: si es 'Contado', debe cubrir el total)
			const condPago = document.getElementById('cond_pago').value;
			const totalVenta = parseFloat(document.getElementById('total_venta_input').value);
			
			if (condPago === 'Contado') {
				const pagoEfectivo = parseFloat(document.getElementById('pago_efectivo').value) || 0;
				const pagoTransf = parseFloat(document.getElementById('pago_transf').value) || 0;
				const totalPagado = pagoEfectivo + pagoTransf;
				
				if (totalPagado < totalVenta) {
					alert(`El pago ($${totalPagado.toFixed(2)}) es menor al total de la venta ($${totalVenta.toFixed(2)}). Por favor, ingrese el monto completo.`);
					e.preventDefault();
					return;
				}
			}
		});

		// Manejadores para el botón de "Guardar como Pendiente"
		document.getElementById('btnGuardarPendiente').addEventListener('click', function(e) {
			// Cambiar el valor del campo oculto y enviar
			document.getElementById('venta_action_input').value = 'Pendiente';
			document.getElementById('formVenta').submit();
		});
		
		// Asegurar que el botón de finalizar mantenga el valor correcto
		document.getElementById('btnFinalizarVenta').addEventListener('click', function(e) {
			document.getElementById('venta_action_input').value = 'Finalizar';
		});


		// ===========================================
		// 5. MODAL DEL TICKET Y PENDIENTES
		// ===========================================
		
		const ticketModal = document.getElementById('ticketModal');
		const pendientesModal = document.getElementById('pendientesModal');
		const body = document.body;
		const ticketVistaPrevia = document.getElementById('ticket-vista-previa');
		const btnImprimirTicket = document.getElementById('btnImprimirTicket');
		const errorTicket = document.getElementById('errorTicket');
		const documentoAImprimir = <?php echo json_encode($ticket_doc_a_imprimir); ?>;
		
		
		// --- Funciones de control de Modal de Ticket ---
		
		function abrirModalTicket() {
			ticketModal.style.display = 'block';
			body.classList.add('modal-open-fix'); 
		}

		function cerrarModalTicket() {
			ticketModal.style.display = 'none';
			body.classList.remove('modal-open-fix');
		}
		
		window.cerrarModalTicket = cerrarModalTicket; 


		function cargarVistaPreviaTicket(n_documento) {
			errorTicket.style.display = 'none';
			ticketVistaPrevia.innerHTML = 'Cargando vista previa...';
			abrirModalTicket();

			const xhr = new XMLHttpRequest();
			// Ruta ajustada a 'vista_previa_ticket.php'
			xhr.open('GET', '/pos/pages/vista_previa_ticket.php?n_documento=' + n_documento, true);
			
			xhr.onload = function() {
				if (this.status === 200) {
					// 1. Mostrar el contenido puro del ticket
                    ticketVistaPrevia.innerHTML = this.responseText;

                    // 2. Definir la acción del botón Imprimir para abrir una nueva ventana con el contenido
                    btnImprimirTicket.onclick = function() {
                        imprimirContenidoTicket(ticketVistaPrevia.innerHTML);
                    };
				} else {
					ticketVistaPrevia.innerHTML = 'Error al cargar el ticket. (Status: ' + this.status + ')';
					errorTicket.style.display = 'block';
				}
			};
			xhr.onerror = function() {
				ticketVistaPrevia.innerHTML = 'Error de red al intentar cargar el ticket.';
				errorTicket.style.display = 'block';
			};
			xhr.send();
		}

		// Lógica para mostrar el ticket automáticamente después de la redirección POST/GET
		if (documentoAImprimir !== null) {
			cargarVistaPreviaTicket(documentoAImprimir);
		}
		
		
		// --- Funciones de control de Modal de Pendientes ---
		
		function cerrarModalPendientes() {
			pendientesModal.style.display = 'none';
			body.classList.remove('modal-open-fix');
		}
		
		window.cerrarModalPendientes = cerrarModalPendientes; 


		document.getElementById('btnVerPendientes').addEventListener('click', function() {
			pendientesModal.style.display = 'block';
			body.classList.add('modal-open-fix');
			
			// AJAX para cargar la lista de ventas pendientes (Implementación pendiente)
			document.getElementById('listaPendientes').innerHTML = 'Ventas pendientes cargadas (AJAX pendiente).';
			
		});
		
		// Inicializar la visualización de totales al cargar
		calcularTotales();

        // En ventas.php, al final del <script>
        function imprimirContenidoTicket(htmlContenido) {
            const ventanaImpresion = window.open('', '_blank', 'width=350,height=600');
            ventanaImpresion.document.write('<!DOCTYPE html><html><head><title>Ticket Impresión</title>');
            
            // Necesitas copiar los estilos críticos del ticket aquí, o incluir el archivo CSS si lo tienes
            ventanaImpresion.document.write('<style> body { font-family: "Courier New", monospace; font-size: 13px; line-height: 1.2; width: 280px; margin: 0 auto; padding: 5px 0; } .center { text-align: center; } .right { text-align: right; } .sep { border-top: 1px dashed #000; margin: 5px 0; height: 1px; } .line { display: flex; justify-content: space-between; margin: 1px 0; } .line strong { font-size: 1.1em; } @media print { body { padding: 0 !important; margin: 0 !important; } } </style>');
            
            ventanaImpresion.document.write('</head><body>');
            ventanaImpresion.document.write(htmlContenido);
            ventanaImpresion.document.write('</body></html>');
            ventanaImpresion.document.close();
            
            // Forzar la impresión después de un pequeño retraso
            ventanaImpresion.focus();
            setTimeout(() => {
                ventanaImpresion.print();
                ventanaImpresion.close();
            }, 500);
        }

	}); 
</script>