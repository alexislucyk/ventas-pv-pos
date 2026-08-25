<?php
// sidebar.php - VERSIÓN MEJORADA CON SIDEBAR COLAPSABLE Y BÚSQUEDA
// Requiere que db_config.php e infosesion.php hayan sido incluidos previamente

// Incluir funciones de configuración
require_once PATH_BASE . 'funciones/funciones_configuracion.php';

try {
    // Usar la empresa activa de la sesión (no hardcodear id = 1)
    $empresa_id_sidebar = (int)($_SESSION['empresa_id'] ?? 1);
    $stmt_sidebar = $pdo->prepare("SELECT nombre_fantasia FROM empresas WHERE id = ? LIMIT 1");
    $stmt_sidebar->execute([$empresa_id_sidebar]);
    $res_sidebar = $stmt_sidebar->fetch(PDO::FETCH_ASSOC);
    
    $nombre_empresa_sidebar = (!empty($res_sidebar['nombre_fantasia'])) ? $res_sidebar['nombre_fantasia'] : "Mi Negocio";
} catch (Exception $e) {
    $nombre_empresa_sidebar = "Mi Negocio";
}

// Obtener versión desde la base de datos
$version_app = obtener_version_app($pdo);

?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo function_exists('url') ? url('css/sidebar.css') : (defined('URL_BASE') ? URL_BASE . 'css/sidebar.css' : '../css/sidebar.css'); ?>">

<div class="sidebar" id="sidebar">
    <?php if (defined('URL_BASE') && strpos(URL_BASE, 'dev') !== false): ?>
        <div class="badge-dev">⚠ MODO DESARROLLO (PRUEBAS)</div>
    <?php endif; ?>

    <!-- Botón Toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar Look">
        <i class="fas fa-chevron-left"></i>
    </button>

    <a href="<?php echo URL_BASE; ?>" style="text-decoration: none; color: inherit;">
        <div class="empresa-title">
            
            <span><?php echo htmlspecialchars($nombre_empresa_sidebar); ?></span>
            <div class="empresa-version">
                V. <?php echo htmlspecialchars($version_app); ?>
            </div>
        </div>
    </a>

    <?php include 'components/selector_empresa.php'; ?>

    <!-- Buscador del menú - PRUEBA CAMBIO -->
    <!-- <div class="sidebar-search">
        <i class="fas fa-search search-icon" style="color: #4db64d !important; font-size: 1.5em !important;"></i>
        <input type="text" id="sidebarSearch" placeholder="BUSCADOR MODIFICADO" autocomplete="off">
    </div> -->

    <div class="sidebar-menu-container" id="sidebarMenu">
        <!-- ===== MAESTROS ===== -->
        <div class="menu-section sec-maestros">
        <h4><i class="fas fa-database"></i> Maestros</h4>
        <?php if (tiene_permiso('pages/abm_productos.php')): ?>
            <a href="<?php echo route_file('pages/abm_productos.php'); ?>" data-title="Productos"><i class="fas fa-box"></i> <span>Productos</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/abm_clientes.php')): ?>
            <a href="<?php echo route_file('pages/abm_clientes.php'); ?>" data-title="Clientes"><i class="fas fa-users"></i> <span>Clientes</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/abm_proveedores.php')): ?>
            <a href="<?php echo route_file('pages/abm_proveedores.php'); ?>" data-title="Proveedores"><i class="fas fa-truck"></i> <span>Proveedores</span></a>
        <?php endif; ?>
        </div>

        <!-- ===== VENTAS ===== -->
        <div class="menu-section sec-ventas">
        <h4><i class="fas fa-shopping-cart"></i> Ventas</h4>
        <?php if (tiene_permiso('pages/ventas.php')): ?>
            <a href="<?php echo route_file('pages/ventas.php'); ?>" data-title="Nueva Venta"><i class="fas fa-shopping-cart"></i> <span>Nueva Venta</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/ventarapida.php')): ?>
            <a href="<?php echo route_file('pages/ventarapida.php'); ?>" data-title="Venta Rápida"><i class="fas fa-bolt"></i> <span>Venta Rápida</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/presupuestos.php')): ?>
            <a href="<?php echo route_file('pages/presupuestos.php'); ?>" data-title="Presupuestos"><i class="fas fa-file-invoice-dollar"></i> <span>Presupuestos</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/anulaciones.php')): ?>
            <a href="<?php echo route_file('pages/anulaciones.php'); ?>" data-title="Anulaciones"><i class="fas fa-undo-alt"></i> <span>Anulaciones</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/cobro_cuotas.php')): ?>
            <a href="<?php echo route_file('pages/cobro_cuotas.php'); ?>" data-title="Cobro de Cuotas"><i class="fas fa-hand-holding-usd"></i> <span>Cobro de Cuotas</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/pagos_ctacte.php')): ?>
            <a href="<?php echo route_file('pages/pagos_ctacte.php'); ?>" data-title="Pagos Cta. Cte."><i class="fas fa-credit-card"></i> <span>Pagos Cta. Cte.</span></a>
        <?php endif; ?>
        </div>

        <!-- ===== COMPRAS ===== -->
        <div class="menu-section sec-compras">
        <h4><i class="fas fa-truck-loading"></i> Compras</h4>
        <?php if (tiene_permiso('pages/compras.php')): ?>
            <a href="<?php echo route_file('pages/compras.php'); ?>" data-title="Compras"><i class="fas fa-receipt"></i> <span>Compras</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/compras_rapidas.php')): ?>
            <a href="<?php echo route_file('pages/compras_rapidas.php'); ?>" data-title="Compra Rápida"><i class="fas fa-bolt"></i> <span>Compra Rápida</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/historial_compras.php')): ?>
            <a href="<?php echo route_file('pages/historial_compras.php'); ?>" data-title="Historial de Compras"><i class="fas fa-history"></i> <span>Historial</span></a>
        <?php endif; ?>
        </div>

        <!-- ===== FACTURACIÓN Y CAJA ===== -->
        <div class="menu-section sec-caja">
        <h4><i class="fas fa-cash-register"></i> Facturación y Caja</h4>
        <?php if (tiene_permiso('pages/facturacion_arca.php')): ?>
            <a href="<?php echo route_file('pages/facturacion_arca.php'); ?>" data-title="Comprobantes AFIP"><i class="fas fa-file-invoice"></i> <span>Comprobantes AFIP</span></a>
        <?php endif; ?>
        <?php if (empresa_cierre_caja_habilitado()): ?>
        <?php if (tiene_permiso('pages/caja_dashboard.php')): ?>
            <a href="<?php echo route_file('pages/caja_dashboard.php'); ?>" data-title="Panel de Caja"><i class="fas fa-chart-pie"></i> <span>Panel de Caja</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/movimiento_manual.php')): ?>
            <a href="<?php echo route_file('pages/movimiento_manual.php'); ?>" data-title="Movimiento Manual"><i class="fas fa-exchange-alt"></i> <span>Movimiento Manual</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/cierre_caja.php')): ?>
            <a href="<?php echo route_file('pages/cierre_caja.php'); ?>" data-title="Cierre de Caja"><i class="fas fa-lock"></i> <span>Cierre de Caja</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reporte_cierres.php')): ?>
            <a href="<?php echo route_file('pages/reporte_cierres.php'); ?>" data-title="Reporte de Cierres"><i class="fas fa-clipboard-list"></i> <span>Reporte de Cierres</span></a>
        <?php endif; ?>
        <?php endif; ?>
        </div>

        <!-- ===== INFORMES ===== -->
        <div class="menu-section sec-informes">
        <h4><i class="fas fa-chart-bar"></i> Informes</h4>
        <?php if (tiene_permiso('pages/resumen_ventas.php')): ?>
            <a href="<?php echo route_file('pages/resumen_ventas.php'); ?>" data-title="Resumen de Ventas"><i class="fas fa-list-alt"></i> <span>Resumen de Ventas</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reporte_cuotas.php')): ?>
            <a href="<?php echo route_file('pages/reporte_cuotas.php'); ?>" data-title="Cuentas a Cobrar"><i class="fas fa-hand-holding-usd"></i> <span>Cuentas a Cobrar</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/cuentas_corrientes.php')): ?>
            <a href="<?php echo route_file('pages/cuentas_corrientes.php'); ?>" data-title="Cta. Cte. Clientes"><i class="fas fa-user-clock"></i> <span>Cta. Cte. Clientes</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/configuracion_intereses.php')): ?>
            <a href="<?php echo route_file('pages/configuracion_intereses.php'); ?>" data-title="Config. Intereses"><i class="fas fa-percentage"></i> <span>Config. Intereses</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/ctacte_proveedores.php')): ?>
            <a href="<?php echo route_file('pages/ctacte_proveedores.php'); ?>" data-title="Cta. Cte. Proveedores"><i class="fas fa-history"></i> <span>Cta. Cte. Proveedores</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reportes_inventario.php')): ?>
            <a href="<?php echo route_file('pages/reportes_inventario.php'); ?>" data-title="Inventario"><i class="fas fa-warehouse"></i> <span>Inventario</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reporte_movimientos_productos.php')): ?>
            <a href="<?php echo route_file('pages/reporte_movimientos_productos.php'); ?>" data-title="Mov. de Productos"><i class="fas fa-exchange-alt"></i> <span>Mov. de Productos</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reportes_financieros.php')): ?>
            <a href="<?php echo route_file('pages/reportes_financieros.php'); ?>" data-title="Financieros"><i class="fas fa-money-check-alt"></i> <span>Financieros</span></a>
        <?php endif; ?>
        </div>

        <!-- ===== ADMINISTRACIÓN ===== -->
        <div class="menu-section sec-admin">
        <?php if (tiene_permiso('pages/usuarios.php') || $_SESSION['usuario_rol'] === 'developer'): ?>
            <h4><i class="fas fa-shield-alt"></i> Administración</h4>
            <a href="<?php echo route_file('pages/usuarios.php'); ?>" data-title="Usuarios"><i class="fas fa-users-cog"></i> <span>Usuarios</span></a>
        <?php endif; ?>
        <?php if ($_SESSION['usuario_rol'] === 'developer'): ?>
            <a href="<?php echo route_file('pages/abm_permisos_usuarios.php'); ?>" data-title="Permisos por Usuario"><i class="fas fa-user-shield"></i> <span>Permisos por Usuario</span></a>
        <?php endif; ?>
        <?php if ($_SESSION['usuario_rol'] === 'developer' || $_SESSION['usuario_rol'] === 'admin'): ?>
            <a href="<?php echo route_file('pages/abm_proveedores_autorizados.php'); ?>" data-title="Proveedores Autorizados"><i class="fas fa-user-check"></i> <span>Proveedores Autorizados</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/backup.php')): ?>
            <a href="<?php echo route_file('pages/backup.php'); ?>" data-title="Backup"><i class="fas fa-database"></i> <span>Backup</span></a>
        <?php endif; ?>
        <?php if ($_SESSION['usuario_rol'] === 'developer' || tiene_permiso('pages/actualizaciones.php')): ?>
            <a href="<?php echo route_file('pages/actualizaciones.php'); ?>" data-title="Actualizar Sistema"><i class="fas fa-sync-alt"></i> <span>Actualizar Sistema</span></a>
        <?php endif; ?>
        </div>
    </div>

    <div class="sidebar-footer">
        <?php if (tiene_permiso('pages/abm_empresa.php')): ?>
            <a href="<?php echo route_file('pages/abm_empresa.php'); ?>" data-title="Datos de Empresa"><i class="fas fa-store"></i> <span>Datos de Empresa</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/configuracion.php')): ?>
            <a href="<?php echo route_file('pages/configuracion.php'); ?>" data-title="Configuración"><i class="fas fa-cog"></i> <span>Configuración</span></a>
        <?php endif; ?>
        <a href="<?php echo URL_BASE; ?>logout.php" class="logout" data-title="Cerrar Sesión"><i class="fas fa-sign-out-alt"></i> <span>Cerrar Sesión</span></a>
    </div>
</div>

<!-- Modal de Confirmación Global -->
<div id="modalConfirmacionGlobal" class="modal">
    <div class="modal-content" style="border-top: 4px solid #f1c40f;">
        <h2 id="conf_titulo" style="color: #f1c40f;">Confirmar Acción</h2>
        <p id="conf_mensaje"></p>
        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button id="conf_btn_confirmar" class="btn btn-danger" style="flex: 1; padding: 12px; font-weight: bold;">CONFIRMAR</button>
            <button onclick="cerrarConfirmacionGlobal()" class="btn btn-secondary" style="flex: 1; padding: 12px;">CANCELAR</button>
        </div>
    </div>
</div>

<!-- Modal de Mensaje Global (Éxito/Error) -->
<div id="modalMensajeGlobal" class="modal">
    <div class="modal-content" style="border-top: 4px solid #2ecc71;">
        <h2 id="msg_titulo" style="color: #2ecc71;">Mensaje</h2>
        <p id="msg_mensaje"></p>
        <div style="margin-top: 25px;">
            <button id="msg_btn_cerrar" class="btn btn-success" style="width: 100%; padding: 12px; font-weight: bold;">ACEPTAR</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const searchInput = document.getElementById('sidebarSearch');
    const menuContainer = document.getElementById('sidebarMenu');
    const allLinks = menuContainer.querySelectorAll('a');
    const allHeadings = menuContainer.querySelectorAll('h4');

    // ========== TOGGLE SIDEBAR ==========
    // Cargar estado guardado
    const savedState = localStorage.getItem('sidebar_collapsed');
    if (savedState === 'true') {
        sidebar.classList.add('collapsed');
        document.body.classList.add('sidebar-collapsed');
    }

    toggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        
        // Si se expande, restaurar búsqueda
        if (!sidebar.classList.contains('collapsed') && searchInput && searchInput.value) {
            setTimeout(() => searchInput.focus(), 350);
        }
    });

    // ========== BÚSQUEDA EN EL MENÚ ==========
    if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        
        allLinks.forEach(link => {
            const text = link.textContent.toLowerCase();
            const matches = !query || text.includes(query);
            link.classList.toggle('hidden-by-search', !matches);
        });

        allHeadings.forEach(h4 => {
            let section = h4;
            let hasVisibleLinks = false;
            let sibling = h4.nextElementSibling;
            
            while (sibling && sibling.tagName !== 'H4') {
                if (sibling.tagName === 'A' && !sibling.classList.contains('hidden-by-search')) {
                    hasVisibleLinks = true;
                    break;
                }
                sibling = sibling.nextElementSibling;
            }
            
            h4.classList.toggle('hidden-by-search', query.length > 0 && !hasVisibleLinks);
        });
        
        // Mostrar mensaje si no hay resultados
        let emptyMsg = menuContainer.querySelector('.search-empty-message');
        const visibleCount = menuContainer.querySelectorAll('a:not(.hidden-by-search)').length;
        
        if (query && visibleCount === 0) {
            if (!emptyMsg) {
                emptyMsg = document.createElement('div');
                emptyMsg.className = 'search-empty-message';
                emptyMsg.style.cssText = 'padding: 30px 20px; text-align: center; color: #666; font-size: 0.9em;';
                emptyMsg.innerHTML = '<i class="fas fa-search" style="font-size: 2em; display: block; margin-bottom: 10px; opacity: 0.5;"></i> No se encontraron resultados';
                menuContainer.appendChild(emptyMsg);
            }
            emptyMsg.style.display = 'block';
        } else if (emptyMsg) {
            emptyMsg.style.display = 'none';
        }
    });
    }

    // ========== DETECCIÓN DE PÁGINA ACTIVA ==========
    const currentPath = window.location.pathname;
    
    allLinks.forEach(link => {
        const href = link.getAttribute('href');
        // Extraer la ruta relativa (sin URL_BASE)
        let relativePath = href;
        // Limpiar URL_BASE del href si está presente
        const baseUrl = '<?php echo defined('URL_BASE') ? URL_BASE : '/'; ?>';
        if (href.startsWith(baseUrl)) {
            relativePath = href.substring(baseUrl.length);
        }
        
        // Quitar parámetros query
        relativePath = relativePath.split('?')[0];
        const currentClean = currentPath.split('?')[0];
        
        if (currentClean.includes(relativePath) && relativePath.length > 0) {
            link.classList.add('active');
        }
    });

    // ========== CERRAR BÚSQUEDA CON ESC ==========
    if (searchInput) {
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            this.dispatchEvent(new Event('input'));
            this.blur();
        }
    });
    }

    // ========== ACCESO RÁPIDO: CTRL+K para buscar ==========
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed') && searchInput) {
                searchInput.focus();
            }
        }
    });

    // ========== TOGGLE MOBILE ==========
    window.toggleSidebarMobile = function() {
        const isMobile = window.innerWidth <= 1100;
        if (isMobile) {
            sidebar.classList.toggle('active');
        }
    };

    // Cerrar sidebar en mobile al hacer clic en un link
    allLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1100) {
                sidebar.classList.remove('active');
            }
        });
    });
});

// ========== FUNCIONES GLOBALES DE MODALES ==========

// Confirmación Estilizada
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

// Alertas Estilizadas
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