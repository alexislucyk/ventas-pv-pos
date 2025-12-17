<?php
// Este archivo solo contiene el HTML del menú lateral (sidebar)
// Las variables $nombre_usuario y $rol deben estar definidas en el archivo que lo incluye (ej. index.php)
?>
<div class="sidebar">
	
	<a href="../../pos/index.php" style="text-decoration: none; color: inherit;">
		<h3>Electricidad Lucyck</h3>
	</a>
		
	<p>Usuario: "<?php echo htmlspecialchars($nombre_usuario); ?>"</p>
	<p style="margin-top: -10px;">Rol: (<?php echo htmlspecialchars($rol); ?>)</p>
	<hr>
	
	<h4>Maestros (ABM)</h4>
	<a href="../../pos/pages/abm_productos.php">🛒 Productos</a>
	<a href="../../pos/pages/abm_clientes.php" style="border-left-color: #00bcd4;">👥 Clientes</a>
	<a href="../../pos/pages/abm_proveedores.php">🚚 Proveedores</a>
	<a href="../../pos/pages/consulta_precios.php">📋 Consulta de Precios</a>

	<hr>

	<h4>Transacciones</h4>
	<a href="../../pos/pages/ventas.php">💰 Nueva Venta</a>
	<a href="../../pos/pages/compras.php">📝 Registrar Compra</a>
	<a href="../../pos/pages/pagos_ctacte.php">💳 Pagos Cta. Cte.</a>

	<hr>

	<h4>Informes</h4>
	<a href="../../pos/pages/resumen_ventas.php">Resumen de ventas</a>
	<a href="../../pos/pages/cuentas_corrientes.php">Cta.Cte Clientes</a>
	<a href="../../pos/pages/ctacte_proveedores.php">Cta.Cte Proveedores</a>
	<a href="../../pos/pages/reportes_inventario.php">Reporte de Inventario</a>
	<a href="../../pos/pages/reportes_financieros.php">Reporte Financieros</a>
	<a href="#">✂️ Corte de Caja</a>

	<hr>
	<h4>Mantenimiento</h4>
	<a href="../../pos/pages/configuracion.php">Configuración</a>
	
	<a href="../../pos/logout.php" class="logout">❌ Cerrar Sesión</a>
</div>