<?php
// sidebar.php - VERSIÓN DINÁMICA CON CONTROL DE PERMISOS
// Requiere que db_config.php e infosesion.php hayan sido incluidos previamente

try {
    $sql_sidebar = "SELECT nombre_fantasia FROM datos_empresa WHERE id = 1 LIMIT 1";
    $stmt_sidebar = $pdo->query($sql_sidebar);
    $res_sidebar = $stmt_sidebar->fetch(PDO::FETCH_ASSOC);
    
    $nombre_empresa_sidebar = (!empty($res_sidebar['nombre_fantasia'])) ? $res_sidebar['nombre_fantasia'] : "Mi Negocio";
} catch (Exception $e) {
    $nombre_empresa_sidebar = "Mi Negocio";
}

/**
 * Función para validar si el usuario tiene acceso al link
 * El rol 'developer' siempre tiene permiso total.
 */
function tiene_permiso($archivo_buscado) {
    if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
        return true;
    }
    
    if (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
        return in_array($archivo_buscado, $_SESSION['permisos']);
    }
    
    return false;
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
    .sidebar a:hover i { transform: scale(1.2); }
    .user-info i { margin-right: 8px; }
    .sidebar-menu-container h4 {
        margin: 15px 0 10px 15px;
        color: #888;
        font-size: 0.85em;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .empresa-title {
        text-align: center;
        padding: 10px;
        font-size: 1.2em;
        font-weight: bold;
        color: #fff;
    }
    .badge-dev {
        background: #e74c3c;
        color: white;
        text-align: center;
        font-size: 9px;
        padding: 3px 0;
        font-weight: bold;
        letter-spacing: 1px;
    }
</style>

<div class="sidebar">
    <?php if (defined('URL_BASE') && URL_BASE == '/pos_dev/'): ?>
        <div class="badge-dev">MODO DESARROLLO (PRUEBAS)</div>
    <?php endif; ?>

    <a href="<?php echo URL_BASE; ?>index.php" style="text-decoration: none; color: inherit;">
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
        <?php if (tiene_permiso('pages/abm_productos.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/abm_productos.php"><i class="fas fa-box" style="color: #2ecc71;"></i> Productos</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/abm_clientes.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/abm_clientes.php"><i class="fas fa-users" style="color: #00bcd4;"></i> Clientes</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/abm_proveedores.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/abm_proveedores.php"><i class="fas fa-truck-loading" style="color: #e67e22;"></i> Proveedores</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/consulta_precios.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/consulta_precios.php"><i class="fas fa-tag" style="color: #f1c40f;"></i> Consulta de Precios</a>
        <?php endif; ?>

        <hr>
        <h4>Transacciones</h4>
        <?php if (tiene_permiso('pages/ventas.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/ventas.php"><i class="fas fa-shopping-cart" style="color: #f1c40f;"></i> Nueva Venta</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/presupuestos.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/presupuestos.php"><i class="fas fa-file-invoice-dollar" style="color: #3498db;"></i> Presupuestos</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/anulaciones.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/anulaciones.php"><i class="fas fa-undo-alt" style="color: #e74c3c;"></i> Anulaciones</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/compras.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/compras.php"><i class="fas fa-receipt" style="color: #9b59b6;"></i> Compras</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/pagos_ctacte.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/pagos_ctacte.php"><i class="fas fa-credit-card" style="color: #1abc9c;"></i> Pagos Cta. Cte.</a>
        <?php endif; ?>

        <hr>
        <h4>Gestión de Caja</h4>
        <?php if (tiene_permiso('pages/caja_dashboard.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/caja_dashboard.php"><i class="fas fa-chart-pie" style="color: #9b59b6;"></i> Panel de Caja</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/movimiento_manual.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/movimiento_manual.php"><i class="fas fa-exchange-alt" style="color: #e67e22;"></i> Movimiento Manual</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/cierre_caja.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/cierre_caja.php"><i class="fas fa-lock" style="color: #e74c3c;"></i> Cierre de Caja</a>
        <?php endif; ?>

        <hr>
        <h4>Informes</h4>
        <?php if (tiene_permiso('pages/resumen_ventas.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/resumen_ventas.php"><i class="fas fa-list-alt" style="color: #34495e;"></i> Resumen de ventas</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/cuentas_corrientes.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/cuentas_corrientes.php"><i class="fas fa-user-clock" style="color: #e67e22;"></i> Cta.Cte Clientes</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/ctacte_proveedores.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/ctacte_proveedores.php"><i class="fas fa-history" style="color: #e74c3c;"></i> Cta.Cte Proveedores</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/reportes_inventario.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/reportes_inventario.php"><i class="fas fa-warehouse" style="color: #2ecc71;"></i> Inventario</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/reportes_financieros.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/reportes_financieros.php"><i class="fas fa-money-check-alt" style="color: #27ae60;"></i> Financieros</a>
        <?php endif; ?>

        <?php if (tiene_permiso('pages/usuarios.php') || $_SESSION['usuario_rol'] === 'developer'): ?>
            <hr>
            <h4>Seguridad</h4>
            <a href="<?php echo URL_BASE; ?>pages/usuarios.php"><i class="fas fa-users-cog" style="color: #00bcd4;"></i> Usuarios</a>
        <?php endif; ?>
        
        <?php if ($_SESSION['usuario_rol'] === 'developer'): ?>
            <hr>
            <h4>Súper Usuario</h4>
            <a href="<?php echo URL_BASE; ?>pages/abm_permisos_usuarios.php">
                <i class="fas fa-user-shield" style="color: #ff9800;"></i> Permisos por Usuario
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <hr>
        <?php if (tiene_permiso('pages/abm_empresa.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/abm_empresa.php"><i class="fas fa-store" style="color: #f39c12;"></i> Datos de Empresa</a>
        <?php endif; ?>
        <a href="<?php echo URL_BASE; ?>logout.php" class="logout"><i class="fas fa-sign-out-alt" style="color: #e74c3c;"></i> Cerrar Sesión</a>
    </div>
</div>