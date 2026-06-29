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
    /* Estilo para los títulos de sección en el menú */
    .sidebar h4, 
    .sidebar-menu-container h4 {
        color: #f0f0f0;
        font-size: 0.85em;
        margin: 25px 0 10px 0; /* Aumentamos un poco el margen superior para separar grupos */
        padding-left: 25px;    /* AUMENTADO: de 15px a 25px para alejarlo del borde */
        border-bottom: 1px solid #383838;
        padding-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 1.5px; /* Mejora la legibilidad en mayúsculas */
        opacity: 0.8;          /* Un toque sutil para que no compita con los links */
    }
    /* Estilos de los links para que NO se vean azules */
    .sidebar-menu-container a, .sidebar-footer a {
        color: #f0f0f0 !important;
        text-decoration: none;
        display: block;
        padding: 12px 20px;
        transition: background-color 0.3s;
        border-left: 5px solid transparent;
    }
    /* Compactar los links solo en la parte fija inferior */
    .sidebar-footer a {
        padding: 8px 20px !important; 
        font-size: 0.98em;
    }
    .sidebar-menu-container a:hover {
        background-color: #383838;
        border-left-color: #00bcd4;
    }
    .sidebar-footer {
        padding: 0;
        margin-bottom: 0;
        border-top: 1px solid #333;
        background: #111;
        flex-shrink: 0;
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
            <div style="font-size: 0.55em; opacity: 0.4; font-weight: normal; margin-top: 2px; letter-spacing: 1px;">
                VERSION <?php echo defined('APP_VERSION') ? APP_VERSION : '1.0.0'; ?>
            </div>
        </div>
    </a>

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

        <?php if (tiene_permiso('pages/compras_rapidas.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/compras_rapidas.php"><i class="fas fa-bolt" style="color: #f1c40f;"></i> Compra Rápida</a>
        <?php endif; ?>
        
        <?php if (tiene_permiso('pages/pagos_ctacte.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/pagos_ctacte.php"><i class="fas fa-credit-card" style="color: #1abc9c;"></i> Pagos Cta. Cte.</a>
        <?php endif; ?>

        <?php if (tiene_permiso('pages/cobro_cuotas.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/cobro_cuotas.php"><i class="fas fa-hand-holding-usd" style="color: #2ecc71;"></i> Cobro de Cuotas</a>
        <?php endif; ?>

        <hr>
        <h4>Facturación (ARCA)</h4>
        <?php if (tiene_permiso('pages/facturacion_arca.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/facturacion_arca.php"><i class="fas fa-file-invoice" style="color: #00bcd4;"></i> Comprobantes AFIP</a>
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
        
        <?php if (tiene_permiso('pages/reporte_cuotas.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/reporte_cuotas.php"><i class="fas fa-hand-holding-usd" style="color: #ff5252;"></i> Cuentas a Cobrar</a>
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
        <?php if (tiene_permiso('pages/abm_empresa.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/abm_empresa.php"><i class="fas fa-store" style="color: #f39c12;"></i> Datos de Empresa</a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/configuracion.php')): ?>
            <a href="<?php echo URL_BASE; ?>pages/configuracion.php"><i class="fas fa-cog" style="color: #bdc3c7;"></i> Configuración</a>
        <?php endif; ?>
        <a href="<?php echo URL_BASE; ?>logout.php" class="logout"><i class="fas fa-sign-out-alt" style="color: #e74c3c;"></i> Cerrar Sesión</a>
    </div>
</div>

<!-- Modal de Confirmación Global -->
<div id="modalConfirmacionGlobal" class="modal">
    <div class="modal-content" style="max-width: 400px; border-top: 4px solid #f1c40f; text-align: center;">
        <h2 id="conf_titulo" style="color: #f1c40f; margin-top: 0; font-size: 1.4rem;">Confirmar Acción</h2>
        <p id="conf_mensaje" style="margin: 20px 0; color: #eee; line-height: 1.5;"></p>
        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button id="conf_btn_confirmar" class="btn btn-danger" style="flex: 1; padding: 12px; font-weight: bold;">CONFIRMAR</button>
            <button onclick="cerrarConfirmacionGlobal()" class="btn btn-secondary" style="flex: 1; padding: 12px;">CANCELAR</button>
        </div>
    </div>
</div>

<!-- Modal de Mensaje Global (Éxito/Error) -->
<div id="modalMensajeGlobal" class="modal">
    <div class="modal-content" style="max-width: 400px; border-top: 4px solid #2ecc71; text-align: center;">
        <h2 id="msg_titulo" style="color: #2ecc71; margin-top: 0; font-size: 1.4rem;">Mensaje</h2>
        <p id="msg_mensaje" style="margin: 20px 0; color: #eee; line-height: 1.5;"></p>
        <div style="margin-top: 25px;">
            <button id="msg_btn_cerrar" class="btn btn-success" style="width: 100%; padding: 12px; font-weight: bold;">ACEPTAR</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-abrir la sección según la URL actual
    const currentPath = window.location.pathname;
    const allLinks = document.querySelectorAll('.sidebar-menu-container a');
    
    allLinks.forEach(link => {
        const href = link.getAttribute('href');
        // Verificamos si la URL actual contiene el href del link
        if (currentPath.includes(href.replace('<?php echo URL_BASE; ?>', ''))) {
            // Resaltamos el link activo
            link.style.borderLeft = '5px solid #00bcd4';
            link.style.background = '#222';
        }
    });
});

// Lógica Global de Confirmación Estilizada
window.confirmarAccion = function(titulo, mensaje, btnTexto, btnClase, callback) {
    const modal = document.getElementById('modalConfirmacionGlobal');
    document.getElementById('conf_titulo').innerText = titulo;
    document.getElementById('conf_mensaje').innerText = mensaje;
    
    const btn = document.getElementById('conf_btn_confirmar');
    btn.innerText = btnTexto;
    btn.className = 'btn ' + btnClase;
    
    btn.onclick = function() {
        callback();
        cerrarConfirmacionGlobal();
    };

    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
};

window.cerrarConfirmacionGlobal = function() {
    document.getElementById('modalConfirmacionGlobal').style.display = 'none';
};

// Lógica Global de Mensajes (Alertas Estilizadas)
window.mostrarMensaje = function(titulo, mensaje, tipo = 'success', callback = null) {
    const modal = document.getElementById('modalMensajeGlobal');
    const tituloEl = document.getElementById('msg_titulo');
    const msgEl = document.getElementById('msg_mensaje');
    const contentEl = modal.querySelector('.modal-content');
    const btn = document.getElementById('msg_btn_cerrar');
    
    tituloEl.innerText = titulo;
    msgEl.innerText = mensaje;
    
    if (tipo === 'error') {
        contentEl.style.borderTopColor = '#e74c3c';
        tituloEl.style.color = '#e74c3c';
        btn.className = 'btn btn-danger';
    } else {
        contentEl.style.borderTopColor = '#2ecc71';
        tituloEl.style.color = '#2ecc71';
        btn.className = 'btn btn-success';
    }

    btn.onclick = function() {
        document.getElementById('modalMensajeGlobal').style.display = 'none';
        if (callback) callback();
    };

    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
};
</script>