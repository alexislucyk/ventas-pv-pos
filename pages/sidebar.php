<?php
// Este archivo solo contiene el HTML del menú lateral (sidebar)
// Las variables $nombre_usuario y $rol deben estar definidas en el archivo que lo incluye (ej. index.php)
?>
<div class="sidebar">
    <h3>Electricidad Lucyck</h3>
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
    <a href="#">📝 Registrar Compra</a>
    <a href="#">💳 Pagos Cta. Cte.</a>

    <hr>

    <h4>Informes</h4>
    <a href="#">✂️ Corte de Caja</a>
    
    <a href="../../pos/logout.php" class="logout">❌ Cerrar Sesión</a>
</div>