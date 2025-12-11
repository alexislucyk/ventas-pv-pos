<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
// pages/cuentas_corrientes.php (REESTRUCTURADO)
session_start();
if (!isset($_SESSION['usuario_id'])) {
	header('Location: login.php');
	exit();
}
require '../config/db_config.php'; 

// --- 1. CONSULTA PARA OBTENER SOLO LA LISTA DE CLIENTES CON CC ---
try {
	// Obtener solo los clientes que tienen movimientos registrados en ctacte
	$sql_clientes = "
		SELECT DISTINCT
			c.id AS id_cliente,
			CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
			c.cuit
		FROM clientes c
		INNER JOIN ctacte m ON c.id = m.id_cliente
		ORDER BY c.apellido ASC;
	";
	
	$stmt_clientes = $pdo->query($sql_clientes);
	$clientes_cc = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
	error_log("Error al cargar lista de clientes CC: " . $e->getMessage());
	$clientes_cc = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<title>Consulta Cuentas Corrientes</title>
	<link rel="stylesheet" href="../css/style.css"> 
	<style>
		.saldo-negativo { color: red; font-weight: bold; }
		.saldo-positivo { color: green; font-weight: bold; }
		.saldo-cero { color: gray; }
		#tablaMovimientos { min-height: 100px; }
		#clienteSelector { padding: 10px; width: 100%; max-width: 400px; margin-bottom: 20px; border-radius: 5px;}
	</style>
</head>
<body>
	<?php include 'sidebar.php'; ?>
	<div class="content">
		<h1>🔎 Consulta de Cuentas Corrientes</h1>
		
		<div class="card">
			<h3>Seleccione un Cliente</h3>
			<select id="clienteSelector" onchange="cargarMovimientos()">
				<option value="">-- Seleccione un Cliente --</option>
				<?php foreach ($clientes_cc as $cliente): ?>
					<option value="<?php echo $cliente['id_cliente']; ?>">
						<?php echo htmlspecialchars($cliente['nombre_completo']) . " (CUIT: " . htmlspecialchars($cliente['cuit']) . ")"; ?>
					</option>
				<?php endforeach; ?>
			</select>
			<a href="pagos_ctacte.php" class="btn-primary" 
			style="padding: 10px 15px; text-decoration: none; border-radius: 4px; font-family: 'Open Sans', sans-serif; color: #f0f0f0;">
				Pago Cta. Cte.
			</a>
						
			<div id="contenedorMovimientos" style="margin-top: 30px;">
				<h2>Historial de Movimientos</h2>
				<table style="width: 100%;">
					<thead>
						<tr>
							<th>Fecha</th>
							<th>Movimiento</th>
							<th>N° Doc.</th>
							<th class="text-right">Debe ($)</th>
							<th class="text-right">Haber ($)</th>
							<th class="text-right">Saldo Acumulado ($)</th>
						</tr>
					</thead>
					<tbody id="tablaMovimientos">
						<tr>
							<td colspan="6" class="center-text">Esperando selección de cliente...</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<script>
	const tablaMovimientos = document.getElementById('tablaMovimientos');
	const clienteSelector = document.getElementById('clienteSelector');

	function cargarMovimientos() {
		const idCliente = clienteSelector.value;
		
		if (!idCliente) {
			tablaMovimientos.innerHTML = '<tr><td colspan="6" class="center-text">Esperando selección de cliente...</td></tr>';
			return;
		}

		tablaMovimientos.innerHTML = '<tr><td colspan="6" class="center-text">Cargando movimientos...</td></tr>';

		// Ruta al archivo AJAX
		// ⚠️ Asegúrate que la ruta al AJAX sea correcta desde /pages/
		const url = '../ajax/obtener_movimientos_cc.php?id_cliente=' + idCliente;

		fetch(url)
			.then(response => {
				if (!response.ok) throw new Error('Error de red o servidor.');
				return response.text();
			})
			.then(html => {
				tablaMovimientos.innerHTML = html;
			})
			.catch(error => {
				console.error('Error al cargar movimientos:', error);
				tablaMovimientos.innerHTML = '<tr><td colspan="6" style="color: red;">❌ Error al cargar los datos.</td></tr>';
			});
	}
</script>
</body>
</html>