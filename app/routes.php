<?php
// Bloqueo de acceso directo (compatibilidad Apache/Nginx): si este archivo es el script solicitado por HTTP, responder 404.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit('Not Found'); }

/**
 * app/routes.php - Definición de rutas del sistema POS
 *
 * Mapea URLs amigables (clean URLs) a archivos PHP existentes
 * o a closures. Las rutas están organizadas por sección.
 *
 * NOTA: Los endpoints de ajax/ y api/ se siguen accediendo
 * directamente por su path físico (no se registran aquí) para
 * mantener compatibilidad total con el código existente.
 * Sólo las páginas principales y puntos de entrada se exponen
 * como URLs limpias.
 */

// ── Rutas PÚBLICAS (no requieren autenticación) ──
$router->get('/login', 'login.php', 'login');
$router->post('/login', 'login.php');
$router->get('/logout', 'logout.php', 'logout');
$router->get('/licencia', 'pages/licencia.php', 'licencia');

// ── Rutas PROTEGIDAS (requieren autenticación vía infosesion.php) ──

// Dashboard
$router->get('/', 'pages/dashboard.php', 'dashboard');
$router->get('/index.php', 'pages/dashboard.php', 'dashboard.index');

// Ventas
$router->get('/ventas', 'pages/ventas.php', 'ventas');
$router->get('/venta-rapida', 'pages/ventarapida.php', 'ventas.rapida');
$router->post('/venta-rapida', 'pages/ventarapida.php');
$router->get('/resumen-ventas', 'pages/resumen_ventas.php', 'resumen.ventas');

// Productos
$router->get('/productos', 'pages/abm_productos.php', 'productos');
$router->get('/inventario', 'pages/reportes_inventario.php', 'inventario');

// Clientes
$router->get('/clientes', 'pages/abm_clientes.php', 'clientes');
$router->post('/clientes', 'pages/abm_clientes.php');
$router->get('/cuentas-corrientes', 'pages/cuentas_corrientes.php', 'ctacte');
$router->get('/cuentas-corrientes-detalle', 'pages/cuentas_corrientes_detalle.php', 'ctacte.detalle');
$router->get('/pagos-ctacte', 'pages/pagos_ctacte.php', 'ctacte.pagos');

// Proveedores
$router->get('/proveedores', 'pages/abm_proveedores.php', 'proveedores');
$router->get('/ctacte-proveedores', 'pages/ctacte_proveedores.php', 'ctacte.prov');
$router->get('/proveedores-autorizados', 'pages/abm_proveedores_autorizados.php', 'prov.autorizados');

// Compras
$router->get('/compras', 'pages/compras.php', 'compras');
$router->get('/compras-rapidas', 'pages/compras_rapidas.php', 'compras.rapidas');
$router->get('/historial-compras', 'pages/historial_compras.php', 'compras.historial');

// Presupuestos
$router->get('/presupuestos', 'pages/presupuestos.php', 'presupuestos');
$router->get('/consultar-presupuestos', 'pages/consultar_presupuestos.php', 'presupuestos.consulta');

// Caja
$router->get('/caja-dashboard', 'pages/caja_dashboard.php', 'caja.dashboard');
$router->get('/abrir-caja', 'pages/abrir_caja.php', 'caja.abrir');
$router->get('/cierre-caja', 'pages/cierre_caja.php', 'caja.cierre');
$router->get('/cerrar-cajas-historicas', 'pages/cerrar_cajas_historicas.php', 'caja.historial');
$router->get('/verificar-cajas-historicas', 'pages/verificar_cajas_historicas.php', 'caja.verificar');
$router->get('/reparar-caja-total', 'pages/reparar_caja_total.php', 'caja.reparar');

// Anulaciones y cobros
$router->get('/anulaciones', 'pages/anulaciones.php', 'anulaciones');
$router->get('/cobro-cuotas', 'pages/cobro_cuotas.php', 'cobro.cuotas');
$router->get('/reporte-cuotas', 'pages/reporte_cuotas.php', 'reporte.cuotas');
$router->get('/movimiento-manual', 'pages/movimiento_manual.php', 'mov.manual');

// Reportes
$router->get('/reportes-financieros', 'pages/reportes_financieros.php', 'reportes.fin');
$router->get('/reportes-inventario', 'pages/reportes_inventario.php', 'reportes.inv');
$router->get('/reporte-cierres', 'pages/reporte_cierres.php', 'reporte.cierres');
$router->get('/reporte-movimientos-productos', 'pages/reporte_movimientos_productos.php', 'reporte.movimientos');
$router->get('/consignacion-reporte', 'pages/consignacion_reporte.php', 'consignaciones');

// Consultas
$router->get('/consulta-precios', 'pages/consulta_precios.php', 'precios');
$router->get('/consulta-consignaciones', 'pages/consulta_consignaciones_remota.php', 'consulta.consignaciones');

// Configuración
$router->get('/configuracion', 'pages/configuracion.php', 'configuracion');
$router->get('/configuracion-intereses', 'pages/configuracion_intereses.php', 'config.intereses');
$router->get('/perfil', 'pages/perfil.php', 'perfil');
$router->get('/usuarios', 'pages/usuarios.php', 'usuarios');
$router->get('/abm-permisos-usuarios', 'pages/abm_permisos_usuarios.php', 'permisos.usuarios');
$router->get('/abm-empresa', 'pages/abm_empresa.php', 'abm.empresa');
$router->get('/abm-empresas', 'pages/abm_empresas.php', 'abm.empresas');

// Utilidades
$router->get('/backup', 'pages/backup.php', 'backup');
$router->get('/actualizaciones', 'pages/actualizaciones.php', 'actualizaciones');
$router->get('/actualizaciones.php', 'pages/actualizaciones.php');
$router->post('/actualizaciones.php', 'pages/actualizaciones.php');
$router->get('/facturacion-arca', 'pages/facturacion_arca.php', 'arca');
$router->get('/cron-dolar', 'pages/cron_dolar.php', 'cron.dolar');

// PDFs / Vistas previas
$router->get('/vista-previa-ticket', 'pages/vista_previa_ticket.php', 'ticket.previa');
$router->get('/vista_previa_ticket.php', 'pages/vista_previa_ticket.php', 'ticket.previa');
$router->get('/vista-previa-ticket-cuota', 'pages/vista_previa_ticket_cuota.php', 'ticket.cuota');
$router->get('/vista_previa_ticket_cuota.php', 'pages/vista_previa_ticket_cuota.php', 'ticket.cuota.php');
$router->get('/vista-previa-ticket-devolucion', 'pages/vista_previa_ticket_devolucion.php', 'ticket.devolucion');
$router->get('/vista_previa_ticket_devolucion.php', 'pages/vista_previa_ticket_devolucion.php', 'ticket.devolucion.php');
$router->get('/vista-recibo', 'pages/vista_recibo.php', 'recibo');
$router->get('/imprimir-presupuesto', 'pages/imprimir_presupuesto.php', 'presupuesto.imprimir');
$router->get('/generar_pdf_ticket.php', 'pages/generar_pdf_ticket.php', 'ticket.pdf');
$router->get('/generar_pdf_devolucion.php', 'pages/generar_pdf_devolucion.php', 'ticket.devolucion.pdf');
$router->get('/generar_pdf_presupuesto.php', 'pages/generar_pdf_presupuesto.php', 'presupuesto.pdf');
$router->get('/generar_pdf_recibo.php', 'pages/generar_pdf_recibo.php', 'recibo.pdf');
$router->get('/generar_pdf_cc_seleccion.php', 'pages/generar_pdf_cc_seleccion.php', 'cc.seleccion.pdf');
$router->any('/generar_pdf_cc_seleccion', 'pages/generar_pdf_cc_seleccion.php', 'cc.seleccion');

// Compatibilidad: nombres físicos .php referenciados por redirects/JS
// (header Location relativo y window.location.href a su nombre físico).
$router->get('/ventas.php', 'pages/ventas.php');
$router->get('/anulaciones.php', 'pages/anulaciones.php');
$router->get('/caja_dashboard.php', 'pages/caja_dashboard.php');
$router->get('/abrir_caja.php', 'pages/abrir_caja.php');
$router->get('/cierre_caja.php', 'pages/cierre_caja.php');
$router->get('/pagos_ctacte.php', 'pages/pagos_ctacte.php');
$router->get('/cuentas_corrientes.php', 'pages/cuentas_corrientes.php');
$router->get('/cuentas_corrientes_detalle.php', 'pages/cuentas_corrientes_detalle.php');
$router->get('/vista_recibo.php', 'pages/vista_recibo.php');
$router->get('/licencia.php', 'pages/licencia.php');
$router->get('/cobro_cuotas.php', 'pages/cobro_cuotas.php');
$router->get('/reporte_cuotas.php', 'pages/reporte_cuotas.php');

// Verificar Módulos (accedido desde "Permisos por Usuario").
// Se usa UNICAMENTE la URL amigable /verificar-modulos (sin .php).
$router->get('/verificar-modulos', 'pages/verificar_modulos.php', 'modulos.verificar');
$router->post('/verificar-modulos', 'pages/verificar_modulos.php');

// Rutas con parámetros dinámicos (ejemplo comentado)
// $router->get('/producto/{id}', 'pages/producto_detalle.php');

// --- Rutas POST (formularios self-posting) ---
$router->post('/productos', 'pages/abm_productos.php');
$router->post('/compras', 'pages/compras.php');
$router->post('/presupuestos', 'pages/presupuestos.php');
$router->post('/cierre-caja', 'pages/cierre_caja.php');
$router->post('/abrir-caja', 'pages/abrir_caja.php');
$router->post('/movimiento-manual', 'pages/movimiento_manual.php');
$router->post('/cobro-cuotas', 'pages/cobro_cuotas.php');
