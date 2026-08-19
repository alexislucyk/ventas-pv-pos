<?php
// sidebar.php - VERSIÓN MEJORADA CON SIDEBAR COLAPSABLE Y BÚSQUEDA
// Requiere que db_config.php e infosesion.php hayan sido incluidos previamente

// Incluir funciones de configuración
require_once PATH_BASE . 'funciones/funciones_configuracion.php';

try {
$sql_sidebar = "SELECT nombre_fantasia FROM empresas WHERE id = 1 LIMIT 1";
    $stmt_sidebar = $pdo->query($sql_sidebar);
    $res_sidebar = $stmt_sidebar->fetch(PDO::FETCH_ASSOC);
    
    $nombre_empresa_sidebar = (!empty($res_sidebar['nombre_fantasia'])) ? $res_sidebar['nombre_fantasia'] : "Mi Negocio";
} catch (Exception $e) {
    $nombre_empresa_sidebar = "Mi Negocio";
}

// Obtener versión desde la base de datos
$version_app = obtener_version_app($pdo);

?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ============================================================
   SIDEBAR - Estilos completos
   ============================================================ */
.sidebar {
    height: 100vh;
    width: 250px;
    position: fixed;
    top: 0;
    left: 0;
    background: linear-gradient(180deg, #161616 0%, #1a1a1a 100%);
    z-index: 900;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 3px 0 15px rgba(0, 0, 0, 0.5);
    border-right: 1px solid #252525;
}

/* --- Estado Colapsado --- */
.sidebar.collapsed {
    width: 60px;
}
.sidebar.collapsed .empresa-title span,
.sidebar.collapsed .empresa-version,
.sidebar.collapsed .sidebar-menu-container a span,
.sidebar.collapsed .sidebar-menu-container h4,
.sidebar.collapsed .sidebar-footer a span,
.sidebar.collapsed .sidebar-search,
.sidebar.collapsed .sidebar hr,
.sidebar.collapsed .badge-dev {
    display: none;
}
.sidebar.collapsed .sidebar-menu-container a {
    padding: 14px 0;
    text-align: center;
    border-left: 3px solid transparent;
}
.sidebar.collapsed .sidebar-menu-container a i {
    margin: 0;
    font-size: 1.3em;
    width: auto;
}
.sidebar.collapsed .sidebar-footer a {
    padding: 10px 0;
    text-align: center;
}
.sidebar.collapsed .sidebar-footer a i {
    margin: 0;
    font-size: 1.2em;
}
.sidebar.collapsed .empresa-title {
    padding: 12px 0;
    font-size: 0;
}
.sidebar.collapsed .empresa-title i {
    font-size: 1.5rem;
    display: block;
}
.sidebar.collapsed .sidebar-menu-container h4 {
    margin: 0;
    padding: 0;
    border: none;
}

/* --- Tooltips en modo colapsado --- */
.sidebar.collapsed .sidebar-menu-container a,
.sidebar.collapsed .sidebar-footer a {
    position: relative;
}
.sidebar.collapsed .sidebar-menu-container a:hover::after,
.sidebar.collapsed .sidebar-footer a:hover::after {
    content: attr(data-title);
    position: fixed;
    left: 65px;
    background: #222;
    color: #fff;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85em;
    white-space: nowrap;
    border: 1px solid #333;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    z-index: 10000;
    pointer-events: none;
}
.sidebar.collapsed .sidebar-menu-container a:hover::before,
.sidebar.collapsed .sidebar-footer a:hover::before {
    content: '';
    position: fixed;
    left: 60px;
    border: 6px solid transparent;
    border-right-color: #222;
    z-index: 10000;
    pointer-events: none;
}

/* --- Header del Sidebar --- */
.empresa-title {
    text-align: center;
    padding: 16px 10px;
    font-size: 1.1em;
    font-weight: bold;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-shrink: 0;
    transition: padding 0.3s;
}
.empresa-title i.fa-bolt {
    color: #FFD700;
    font-size: 1.3em;
}
.empresa-version {
    font-size: 0.55em;
    opacity: 0.4;
    font-weight: normal;
    margin-top: 2px;
    letter-spacing: 1px;
}

/* --- Botón Toggle --- */
.sidebar-toggle {
    position: absolute;
    top: 12px;
    right: -18px;
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border: 2px solid #00e5ff;
    border-radius: 50%;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 901;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}
.sidebar-toggle:hover {
    background: linear-gradient(135deg, #00e5ff, #00bcd4);
    color: #fff;
    transform: scale(1.15);
    box-shadow: 0 6px 16px rgba(0, 188, 212, 0.6);
}
.sidebar.collapsed .sidebar-toggle {
    right: -18px;
    transform: rotate(180deg);
}
.sidebar.collapsed .sidebar-toggle:hover {
    transform: rotate(180deg) scale(1.15);
}

/* --- Badge Desarrollo --- */
.badge-dev {
    background: linear-gradient(90deg, #e74c3c, #c0392b);
    color: white;
    text-align: center;
    font-size: 9px;
    padding: 4px 0;
    font-weight: bold;
    letter-spacing: 1px;
    flex-shrink: 0;
}

/* --- Buscador del Menú --- */
.sidebar-search {
    padding: 10px 12px;
    flex-shrink: 0;
    position: relative;
}
.sidebar-search .search-icon {
    position: absolute;
    left: 24px;
    top: 50%;
    transform: translateY(-50%);
    color: #666;
    font-size: 0.9em;
    pointer-events: none;
    z-index: 1;
}
.sidebar-search input {
    width: 100% !important;
    padding: 10px 12px 10px 38px !important;
    border: 1px solid #333 !important;
    background: #252525 !important;
    color: #f0f0f0 !important;
    border-radius: 8px !important;
    font-size: 0.85em !important;
    outline: none;
    transition: border-color 0.3s, box-shadow 0.3s;
    box-sizing: border-box !important;
    display: block;
    line-height: 1.4;
    position: relative;
    z-index: 0;
    margin-bottom: 0 !important;
}
.sidebar-search input:focus {
    border-color: #00bcd4;
    box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.15);
}
.sidebar-search input::placeholder {
    color: #666;
}

/* --- Contenedor del Menú (scrollable) --- */
.sidebar-menu-container {
    flex-grow: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding-bottom: 10px;
}
.sidebar-menu-container::-webkit-scrollbar { width: 4px; }
.sidebar-menu-container::-webkit-scrollbar-thumb { background: #333; border-radius: 10px; }
.sidebar-menu-container::-webkit-scrollbar-track { background: transparent; }

/* --- Separadores --- */
.sidebar-menu-container hr {
    border: none;
    height: 1px;
    background: linear-gradient(90deg, transparent, #333, transparent);
    margin: 6px 20px;
}

/* --- Títulos de Sección --- */
.sidebar-menu-container h4 {
    color: #888;
    font-size: 0.7em;
    margin: 20px 0 8px 0;
    padding: 0 20px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    overflow: hidden;
}
.sidebar-menu-container h4 i {
    font-size: 0.9em;
    width: 20px;
    text-align: center;
    opacity: 0.5;
}

/* --- Links del Menú --- */
.sidebar-menu-container a {
    color: #ccc !important;
    text-decoration: none;
    display: flex;
    align-items: center;
    padding: 10px 20px;
    margin: 2px 8px;
    border-radius: 8px;
    transition: all 0.25s ease;
    border-left: 3px solid transparent;
    white-space: nowrap;
    overflow: hidden;
    font-size: 0.92em;
    position: relative;
}
.sidebar-menu-container a i {
    display: inline-block;
    width: 25px;
    margin-right: 10px;
    text-align: center;
    transition: transform 0.25s ease;
    flex-shrink: 0;
}
.sidebar-menu-container a span {
    overflow: hidden;
    text-overflow: ellipsis;
}
.sidebar-menu-container a:hover {
    background: linear-gradient(90deg, rgba(0, 188, 212, 0.08), transparent);
    color: #fff !important;
    border-left-color: #00bcd4;
    transform: translateX(3px);
}
.sidebar-menu-container a:hover i {
    color: #00bcd4 !important;
    transform: scale(1.15);
}
/* Link activo - página actual */
.sidebar-menu-container a.active {
    background: linear-gradient(90deg, rgba(0, 188, 212, 0.12), transparent);
    border-left-color: #00bcd4;
    color: #fff !important;
    font-weight: 600;
}
.sidebar-menu-container a.active i {
    color: #00bcd4 !important;
}
.sidebar-menu-container a.active::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 20px;
    background: #00bcd4;
    border-radius: 0 4px 4px 0;
}

/* ============================================================
   COLORES POR CATEGORÍA (Sidebar más colorido)
   ============================================================ */
.sidebar-menu-container .sec-maestros h4,
.sidebar-menu-container .sec-maestros h4 i { color: #4dabf7; }
.sidebar-menu-container .sec-maestros h4 i { opacity: 0.9; }
.sidebar-menu-container .sec-ventas h4,
.sidebar-menu-container .sec-ventas h4 i { color: #51cf66; }
.sidebar-menu-container .sec-ventas h4 i { opacity: 0.9; }
.sidebar-menu-container .sec-compras h4,
.sidebar-menu-container .sec-compras h4 i { color: #ffa94d; }
.sidebar-menu-container .sec-compras h4 i { opacity: 0.9; }
.sidebar-menu-container .sec-caja h4,
.sidebar-menu-container .sec-caja h4 i { color: #b197fc; }
.sidebar-menu-container .sec-caja h4 i { opacity: 0.9; }
.sidebar-menu-container .sec-informes h4,
.sidebar-menu-container .sec-informes h4 i { color: #22d3ee; }
.sidebar-menu-container .sec-informes h4 i { opacity: 0.9; }
.sidebar-menu-container .sec-admin h4,
.sidebar-menu-container .sec-admin h4 i { color: #ff8787; }
.sidebar-menu-container .sec-admin h4 i { opacity: 0.9; }

/* Iconos de los links por categoría */
.sidebar-menu-container .sec-maestros a i { color: #4dabf7; }
.sidebar-menu-container .sec-ventas a i { color: #51cf66; }
.sidebar-menu-container .sec-compras a i { color: #ffa94d; }
.sidebar-menu-container .sec-caja a i { color: #b197fc; }
.sidebar-menu-container .sec-informes a i { color: #22d3ee; }
.sidebar-menu-container .sec-admin a i { color: #ff8787; }

/* Hover por categoría */
.sidebar-menu-container .sec-maestros a:hover { background: linear-gradient(90deg, rgba(77,171,247,0.14), transparent); border-left-color: #4dabf7; }
.sidebar-menu-container .sec-maestros a:hover i { color: #74c0fc !important; }
.sidebar-menu-container .sec-ventas a:hover { background: linear-gradient(90deg, rgba(81,207,102,0.14), transparent); border-left-color: #51cf66; }
.sidebar-menu-container .sec-ventas a:hover i { color: #69db7c !important; }
.sidebar-menu-container .sec-compras a:hover { background: linear-gradient(90deg, rgba(255,169,77,0.14), transparent); border-left-color: #ffa94d; }
.sidebar-menu-container .sec-compras a:hover i { color: #ffc078 !important; }
.sidebar-menu-container .sec-caja a:hover { background: linear-gradient(90deg, rgba(177,151,252,0.14), transparent); border-left-color: #b197fc; }
.sidebar-menu-container .sec-caja a:hover i { color: #d0bfff !important; }
.sidebar-menu-container .sec-informes a:hover { background: linear-gradient(90deg, rgba(34,211,238,0.14), transparent); border-left-color: #22d3ee; }
.sidebar-menu-container .sec-informes a:hover i { color: #67e8f9 !important; }
.sidebar-menu-container .sec-admin a:hover { background: linear-gradient(90deg, rgba(255,135,135,0.14), transparent); border-left-color: #ff8787; }
.sidebar-menu-container .sec-admin a:hover i { color: #ffa8a8 !important; }

/* Activo por categoría */
.sidebar-menu-container .sec-maestros a.active { background: linear-gradient(90deg, rgba(77,171,247,0.20), transparent); border-left-color: #4dabf7; }
.sidebar-menu-container .sec-maestros a.active i { color: #74c0fc !important; }
.sidebar-menu-container .sec-maestros a.active::before { background: #4dabf7; }
.sidebar-menu-container .sec-ventas a.active { background: linear-gradient(90deg, rgba(81,207,102,0.20), transparent); border-left-color: #51cf66; }
.sidebar-menu-container .sec-ventas a.active i { color: #69db7c !important; }
.sidebar-menu-container .sec-ventas a.active::before { background: #51cf66; }
.sidebar-menu-container .sec-compras a.active { background: linear-gradient(90deg, rgba(255,169,77,0.20), transparent); border-left-color: #ffa94d; }
.sidebar-menu-container .sec-compras a.active i { color: #ffc078 !important; }
.sidebar-menu-container .sec-compras a.active::before { background: #ffa94d; }
.sidebar-menu-container .sec-caja a.active { background: linear-gradient(90deg, rgba(177,151,252,0.20), transparent); border-left-color: #b197fc; }
.sidebar-menu-container .sec-caja a.active i { color: #d0bfff !important; }
.sidebar-menu-container .sec-caja a.active::before { background: #b197fc; }
.sidebar-menu-container .sec-informes a.active { background: linear-gradient(90deg, rgba(34,211,238,0.20), transparent); border-left-color: #22d3ee; }
.sidebar-menu-container .sec-informes a.active i { color: #67e8f9 !important; }
.sidebar-menu-container .sec-informes a.active::before { background: #22d3ee; }
.sidebar-menu-container .sec-admin a.active { background: linear-gradient(90deg, rgba(255,135,135,0.20), transparent); border-left-color: #ff8787; }
.sidebar-menu-container .sec-admin a.active i { color: #ffa8a8 !important; }
.sidebar-menu-container .sec-admin a.active::before { background: #ff8787; }

/* --- Ocultar items que no coinciden con la búsqueda --- */
.sidebar-menu-container a.hidden-by-search {
    display: none;
}
.sidebar-menu-container h4.hidden-by-search {
    display: none;
}

/* --- Footer --- */
.sidebar-footer {
    flex-shrink: 0;
    border-top: 1px solid #252525;
    background: rgba(0, 0, 0, 0.2);
    padding: 4px 0;
    backdrop-filter: blur(10px);
}
.sidebar-footer a {
    color: #ccc !important;
    text-decoration: none;
    display: flex;
    align-items: center;
    padding: 8px 20px;
    margin: 2px 8px;
    border-radius: 8px;
    transition: all 0.25s ease;
    white-space: nowrap;
    overflow: hidden;
    font-size: 0.88em;
}
.sidebar-footer a i {
    display: inline-block;
    width: 25px;
    margin-right: 10px;
    text-align: center;
    flex-shrink: 0;
}
.sidebar-footer a span {
    overflow: hidden;
    text-overflow: ellipsis;
}
.sidebar-footer a:hover {
    background: rgba(0, 188, 212, 0.08);
    color: #fff !important;
    transform: translateX(3px);
}
.sidebar-footer a.logout {
    color: #ff6b6b !important;
}
.sidebar-footer a.logout:hover {
    background: rgba(255, 82, 82, 0.1);
    color: #ff5252 !important;
}

/* --- Transición del contenido principal --- */
body .content {
    margin-left: 250px;
    width: calc(100% - 250px);
    transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}
body.sidebar-collapsed .content {
    margin-left: 60px;
    width: calc(100% - 60px);
}
body .topbar {
    left: 250px;
    transition: left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}
body.sidebar-collapsed .topbar {
    left: 60px;
}

/* ============================================================
   MODALES GLOBALES (sin cambios)
   ============================================================ */
#modalConfirmacionGlobal, #modalMensajeGlobal {
    display: none; 
    position: fixed; 
    z-index: 99999999 !important;
    left: 0; 
    top: 0; 
    width: 100%; 
    height: 100vh;
    background-color: rgba(0, 0, 0, 0.6);
    overflow-y: auto;
}
#modalConfirmacionGlobal .modal-content,
#modalMensajeGlobal .modal-content {
    max-width: 400px;
    background: #222;
    border-radius: 12px;
    padding: 25px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
#modalConfirmacionGlobal .modal-content h2,
#modalMensajeGlobal .modal-content h2 {
    margin-top: 0;
    font-size: 1.4rem;
}
#modalConfirmacionGlobal .modal-content p,
#modalMensajeGlobal .modal-content p {
    margin: 20px 0;
    color: #eee;
    line-height: 1.5;
}
</style>

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
        <?php if (tiene_permiso('pages/consulta_precios.php')): ?>
            <a href="<?php echo route_file('pages/consulta_precios.php'); ?>" data-title="Consulta de Precios"><i class="fas fa-tag"></i> <span>Consulta de Precios</span></a>
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
        if (!sidebar.classList.contains('collapsed') && searchInput.value) {
            setTimeout(() => searchInput.focus(), 350);
        }
    });

    // ========== BÚSQUEDA EN EL MENÚ ==========
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
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            this.dispatchEvent(new Event('input'));
            this.blur();
        }
    });

    // ========== ACCESO RÁPIDO: CTRL+K para buscar ==========
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed')) {
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