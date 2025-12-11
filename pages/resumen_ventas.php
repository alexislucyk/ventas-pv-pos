<?php
	date_default_timezone_set('America/Argentina/Buenos_Aires'); 
	session_start();
	if (!isset($_SESSION['usuario_id'])) {
		header('Location: login.php');
		exit();
	}
	require '../config/db_config.php'; // Asegúrate de que $pdo esté disponible

	// Cargar todas las ventas para la tabla
	try {
		$sql_ventas = "SELECT 
							v.id AS id_venta,
							v.n_documento,
							v.fecha_venta,
							v.total_venta,
							CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
						FROM ventas v
						LEFT JOIN clientes c ON v.id_cliente = c.id
						ORDER BY v.n_documento DESC";
		$stmt_ventas = $pdo->query($sql_ventas);
		$ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);
	} catch (Exception $e) {
		error_log("Error al cargar ventas: " . $e->getMessage());
		$ventas = [];
	}
?>

<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<title>Resumen de Ventas</title>
	<link rel="stylesheet" href="../css/style.css"> 
	<link rel="stylesheet" href="../css/ticket_print.css">
	<style>
		.btn-action { margin-right: 5px; padding: 5px 10px; cursor: pointer; }
		.modal {
			display: none; position: fixed; z-index: 1000; left: 0; top: 0; 
			width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8);
		}
		.modal-content-lg {
			background-color: #333; margin: 5% auto; padding: 20px; border: 1px solid #888; 
			width: 80%; max-width: 900px; color: white;
		}
		.close-button { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
		#detalleBody { min-height: 100px; }
	</style>
</head>
<body>
	<?php include 'sidebar.php'; ?>
	<div class="content">
		<h1>📊 Resumen Histórico de Ventas</h1>
		
		<div class="card">
			<table id="tablaVentas" style="width: 100%;">
				<thead>
					<tr>
						<th>N° Doc.</th>
						<th>Fecha</th>
						<th>Cliente</th>
						<th>Total</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($ventas as $venta): ?>
						<tr>
							<td><?php echo htmlspecialchars($venta['n_documento']); ?></td>
							<td><?php echo date('d/m/Y', strtotime($venta['fecha_venta'])); ?></td>
							<td><?php echo htmlspecialchars($venta['nombre_cliente'] ?: 'Público General'); ?></td>
							<td class="text-right">$<?php echo number_format($venta['total_venta'], 2, ',', '.'); ?></td>
							<td>
								<button class="btn btn-primary btn-action" onclick="mostrarDetalle(<?php echo $venta['n_documento']; ?>)">Detalle</button>
								<button class="btn btn-success btn-action" onclick="imprimirTicket(<?php echo $venta['n_documento']; ?>)">Reimprimir</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div id="detalleModal" class="modal">
		<div class="modal-content-lg">
			<span class="close-button" onclick="document.getElementById('detalleModal').style.display='none';">&times;</span>
			<h2>Detalle de Venta #<span id="detalleNdocumento"></span></h2>
			
			<div id="detalleBody">
			</div>
			
			<button class="btn btn-success" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)" style="margin-top: 20px;">
				🖨️ Reimprimir desde Detalle
			</button>
		</div>
	</div>

<script>
	const detalleModal = document.getElementById('detalleModal');
	const detalleBody = document.getElementById('detalleBody');
	const detalleNdocumento = document.getElementById('detalleNdocumento');
	
	// Muestra el detalle de la venta en un modal
	function mostrarDetalle(nDocumento) {
		detalleNdocumento.textContent = nDocumento;
		detalleBody.innerHTML = 'Cargando detalle...';
		detalleModal.style.display = 'block';

		// Ruta AJAX para obtener el detalle (asumiendo 3 niveles arriba: ../../pos/ajax/...)
		fetch('../../pos/ajax/obtener_detalle_venta.php?n_documento=' + nDocumento)
			.then(response => {
				if (!response.ok) throw new Error('Error al cargar la respuesta.');
				return response.text();
			})
			.then(html => {
				detalleBody.innerHTML = html;
			})
			.catch(error => {
				console.error('Fetch Error:', error);
				detalleBody.innerHTML = '<p style="color: red;">❌ Error al cargar el detalle de la venta.</p>';
			});
	}

	// Función para Reimprimir (Abre una nueva ventana/pestaña con el ticket)
	function imprimirTicket(nDocumento) {
		// ⚠️ RUTA CRÍTICA: Debe coincidir con la ubicación de tu proyecto.
		// Asumiendo que /pos/ es el directorio raíz.
		const url = '/pos/pages/vista_previa_ticket.php?n_documento=' + nDocumento;
		
		// Abrir en una nueva ventana con tamaño de ticket, la cual se encargará de forzar la impresión
		window.open(url, '_blank', 'width=350,height=600,scrollbars=yes,resizable=yes');
		
		// Si la función se llama desde el modal de detalle, ciérralo
		const detalleModal = document.getElementById('detalleModal');
		if(detalleModal && detalleModal.style.display === 'block') {
			detalleModal.style.display='none';
		}
	}
</script>
</body>
</html>