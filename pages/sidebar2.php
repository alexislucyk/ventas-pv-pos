<?php
// sidebar.php - VERSIÓN DINÁMICA COMPATIBLE CON PHP 5
// Asegúrate de tener la conexión PDO disponible en el archivo que incluya este sidebar

try {
    // Consultamos el nombre de la empresa de forma segura
    $sql_sidebar = "SELECT nombre_fantasia FROM datos_empresa WHERE id = 1 LIMIT 1";
    $stmt_sidebar = $pdo->query($sql_sidebar);
    $res_sidebar = $stmt_sidebar->fetch(PDO::FETCH_ASSOC);
    
    // Fallback por si la tabla está vacía
    $nombre_empresa_sidebar = (!empty($res_sidebar['nombre_fantasia'])) ? $res_sidebar['nombre_fantasia'] : "Electricidad Lucyk";
} catch (Exception $e) {
    $nombre_empresa_sidebar = "Electricidad Lucyk";
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    .sidebar a i {
        display: inline-block;
        width: 25px;
        margin-right: 10px;
        text-align: center;
        transition: transform 0.2s ease;
    }
    .sidebar a:hover i {
        transform: scale(1.2);
    }
    .user-info i {
        margin-right: 8px;
    }
    .sidebar-menu-container h4 {
        margin: 15px 0 10px 15px;
        color: #888;
        font-size: 0.85em;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    /* Estilo para que el nombre de empresa no se rompa si es largo */
    .empresa-title {
        text-align: center;
        padding: 10px;
        font-size: 1.2em;
        font-weight: bold;
        color: #fff;
    }
</style>

<div class="sidebar">
    <a href="../../pos/index.php" style="text-decoration: none; color: inherit;">
        <div class="empresa-title">
            <i class="fas fa-bolt" style="color: #FFD700;"></i> 
            <?php echo htmlspecialchars($nombre_empresa_sidebar); ?>
        </div>
    </a>
        
    <div class="user-info" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 15px 5px; width: 100%;">
        <i class="fas fa-user-circle" style="color: #00bcd4; font-size: 1.2em;"></i> 
        <div style="display: flex; align-items: baseline; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            <span style="font-weight: bold; color: #fff; font-size: 0.95em;">
                <?php echo $nombre_usuario; ?>
            </span>
            <span style="font-size: 0.8em; color: #aaa; text-transform: capitalize;">
                (<?php echo $rol; ?>)
            </span>
        </div>
    </div>

    <div class="sidebar-menu-container">
        <hr>
        <h4>Maestros</h4>
        <a href="../../pos/pages/abm_productos.php"><i class="fas fa-box" style="color: #2ecc71;"></i> Productos</a>
        <a href="../../pos/pages/abm_clientes.php"><i class="fas fa-users" style="color: #00bcd4;"></i> Clientes</a>
        <a href="../../pos/pages/abm_proveedores.php"><i class="fas fa-truck-loading" style="color: #e67e22;"></i> Proveedores</a>
        <a href="../../pos/pages/consulta_precios.php"><i class="fas fa-tag" style="color: #f1c40f;"></i> Consulta de Precios</a>
        <hr>
        <h4>Transacciones</h4>
        <a href="../../pos/pages/ventas.php"><i class="fas fa-shopping-cart" style="color: #f1c40f;"></i> Nueva Venta</a>
        <a href="../../pos/pages/presupuestos.php"><i class="fas fa-file-invoice-dollar" style="color: #3498db;"></i> Presupuestos</a>
        <a href="../../pos/pages/anulaciones.php"><i class="fas fa-undo-alt" style="color: #e74c3c;"></i> Anulaciones</a>
        <a href="../../pos/pages/compras.php"><i class="fas fa-receipt" style="color: #9b59b6;"></i> Compras</a>
        <a href="../../pos/pages/pagos_ctacte.php"><i class="fas fa-credit-card" style="color: #1abc9c;"></i> Pagos Cta. Cte.</a>
        <hr>
        <h4>Gestión de Caja</h4>
        <a href="../../pos/pages/caja_dashboard.php"><i class="fas fa-chart-pie" style="color: #9b59b6;"></i> Panel de Caja</a>
        <a href="../../pos/pages/movimiento_manual.php"><i class="fas fa-exchange-alt" style="color: #e67e22;"></i> Movimiento Manual</a>
        <a href="../../pos/pages/cierre_caja.php"><i class="fas fa-lock" style="color: #e74c3c;"></i> Cierre de Caja</a>
        <hr>
        <h4>Informes</h4>
        <a href="../../pos/pages/resumen_ventas.php"><i class="fas fa-list-alt" style="color: #34495e;"></i> Resumen de ventas</a>
        <a href="../../pos/pages/cuentas_corrientes.php"><i class="fas fa-user-clock" style="color: #e67e22;"></i> Cta.Cte Clientes</a>
        <a href="../../pos/pages/ctacte_proveedores.php"><i class="fas fa-history" style="color: #e74c3c;"></i> Cta.Cte Proveedores</a>
        <a href="../../pos/pages/reportes_inventario.php"><i class="fas fa-warehouse" style="color: #2ecc71;"></i> Inventario</a>
        <a href="../../pos/pages/reportes_financieros.php"><i class="fas fa-money-check-alt" style="color: #27ae60;"></i> Financieros</a>
        <hr>
        <h4>Seguridad</h4>
        <a href="../../pos/pages/usuarios.php"><i class="fas fa-users-cog" style="color: #00bcd4;"></i> Usuarios</a>
    </div>

    <div class="sidebar-footer">
        <hr>
        <a href="../../pos/pages/abm_empresa.php"><i class="fas fa-store" style="color: #f39c12;"></i> Datos de Empresa</a>
        <a href="../../pos/pages/configuracion.php"><i class="fas fa-cog" style="color: #bdc3c7;"></i> Configuración</a>
        <a href="../../pos/logout.php" class="logout"><i class="fas fa-sign-out-alt" style="color: #e74c3c;"></i> Cerrar Sesión</a>
    </div>
</div>