# Estado Actual del Sistema POS

> Documento generado automáticamente como resumen de la aplicación.
> **Fecha:** 18/08/2026 · **Proyecto:** `c:\laragon\www\pos_dev`

---

## 1. Resumen Ejecutivo

El proyecto es un **sistema de Punto de Venta (POS)** desarrollado en **PHP puro** (sin framework), con MySQL como base de datos y HTML/CSS/JS (jQuery) en el frontend. Está diseñado para **multi-empresa (SaaS)** y multi-sucursal, orientado a comercios minoristas argentinos, con soporte de facturación electrónica ARCA/AFIP, venta en dólares, cuentas corrientes (clientes y proveedores), presupuestos, compras, cierres de caja, consignaciones y módulo de licenciamiento propio conectado a un "App Engine".

La app corre sobre **Laragon (Windows)** con Apache. Recientemente se incorporó un **enrutador ligero propio (`core/Router.php`)** para URLs limpias, manteniendo compatibilidad total con el acceso directo a archivos.

**Versión reportada:** `2.5.0` (`APP_VERSION` en `config/licencia_manager.php`).

---

## 2. Stack Tecnológico

| Componente | Tecnología |
|---|---|
| Lenguaje backend | PHP 8.3 (migrado desde PHP 5) |
| Framework | Ninguno (PHP puro + Router propio) |
| Base de datos | MySQL 8.4 (`pos_dev` / `pos_prod`) |
| Frontend | HTML5 + CSS + JavaScript (jQuery) + FontAwesome |
| Generación PDF | FPDF (`fpdf/`) |
| QR | `libs/phpqrcode/` |
| AFIP/ARCA | SDK Composer `afipsdk/afip.php ^1.2` |
| Servidor | Apache vía Laragon (Windows) |
| Control de versiones | Git (rama `main`) |

---

## 3. Arquitectura

**Patrón:** Front Controller con router propio (sin dependencias externas).

```
.htaccess  →  reescribe URLs limpias a index.php (no afecta archivos reales)
index.php  →  carga config → helpers → Router → rutas → dispatch
```

- **Entrada principal:** `index.php` (front controller).
- **Enrutador:** `core/Router.php` — registra rutas GET/POST/PUT/DELETE/any, soporta parámetros `{id}`, genera URLs (`route()`, `url()`), redirige (`redirect()`), maneja 404.
- **Rutas:** `app/routes.php` — mapea URLs limpias a archivos físicos.
- **Helpers:** `core/helpers.php` — `route()`, `url()`, `redirect()`, `auth()`, `require_login()`, `require_permiso()`, `csrf_token/field/verify()`.
- **Compatibilidad hacia atrás:** las URLs directas (`pages/xxx.php`, `ajax/xxx.php`, `api/xxx.php`) siguen funcionando; el router sólo captura lo que no es un archivo/directorio real.

**Detección de entorno:** `config/db_config.php` detecta el entorno por el **sufijo del directorio**: `*_dev` → BD `pos_dev` (desarrollo); cualquier otro → BD `pos_prod` (producción). `URL_BASE` y `PATH_BASE` se calculan dinámicamente.

---

## 4. Estructura del Proyecto

| Carpeta | Contenido | Nº archivos |
|---|---|---|
| `ajax/` | Endpoints AJAX de operaciones (ventas, caja, ctacte, cuotas, proveedores) | 39 |
| `api/` | Endpoints REST públicos (consignaciones, proveedores) | 2 |
| `app/` | Definición de rutas (`routes.php`) | 1 |
| `cache/` | Caché (dolar, backups) | 2 |
| `config/` | Configuración DB, permisos, licencia, ARCA | 7 |
| `core/` | Router + helpers + README | 3 |
| `css/` | Hojas de estilo | 5 |
| `docs/` | Documentación (caja, cierres, seguridad, estado) | 3 |
| `fpdf/` | Librería FPDF | 115 |
| `funciones/` | Funciones de dominio (caja, ticket, dolar, intereses, config) | 9 |
| `img/` | Imágenes (logo, QR placeholder) | 2 |
| `js/` | JavaScript de módulos (ventas, ventarapida, presupuestos) | 4 |
| `libs/` | Librerías (phpqrcode) | 465 |
| `migrations/` | Migraciones SQL (versión 01 a 30) | 31 |
| `pages/` | Vistas/páginas del sistema | 71 |
| `procesos/` | Procesos/scripts (backup, migraciones, verificaciones) | 15 |
| `vendor/` | Dependencias Composer (AFIP SDK) | 84 |
| `backups/` | Respaldo de BD (vacía/ignorada en git) | 0 |

---

## 5. Base de Datos

### Tablas principales (`schema.sql` — 29 tablas)

| Tabla | Propósito |
|---|---|
| `empresas` | Empresas (multi-tenant) |
| `sucursales` | Sucursales por empresa |
| `usuarios` | Usuarios (rol, empresa) |
| `clientes` | Clientes (DNI/CUIT, IVA, habilita cta. cte.) |
| `proveedores` | Proveedores |
| `productos` / `rubros` | Productos y rubros |
| `stocks` | Stock por empresa y sucursal |
| `ventas` / `ventas_detalle` | Ventas y su detalle |
| `ventas_afip` | Facturas electrónicas con CAE (ARCA) |
| `ventas_financiacion` | Financiación / desglose de ventas mixtas |
| `compras` / `compras_detalle` | Compras y detalle |
| `ctacte` | Cuenta corriente de clientes |
| `ctacte_proveedores` | Cuenta corriente de proveedores |
| `cuotas_pagos` / `cuotas_seguimiento` | Cobro de cuotas y seguimiento |
| `presupuestos` / `presupuestos_detalle` | Presupuestos |
| `devoluciones` / `devoluciones_detalle` | Devoluciones/anulaciones |
| `cierres_caja` | Cierres de caja (con rango de fechas) |
| `movimientos` | Movimientos de stock/caja |
| `configuracion` | Configuración general |
| `modulos` / `permisos_rol` / `permisos_usuario` | Permisos por módulo |
| `proveedores_catalogos` | Catálogos de proveedores |
| `proveedores_autorizados` | Proveedores autorizados globales (consignación remota) |

### Evolución (migraciones `migrations/`)

El proyecto mantiene **31 migraciones SQL** que evidencian su evolución:
- **01–19:** Multi-empresa (`empresa_id` en todas las tablas), stocks por sucursal, versión de app.
- **20:** Sistema de backups.
- **21:** Fecha de vencimiento en cta. cte.
- **22:** Estado de caja.
- **23:** Separación de permisos por páginas y funciones.
- **24:** Desglose de ventas mixtas.
- **25:** Proveedores autorizados globales (consignación remota).
- **26–27:** Cierre de caja con rango de fechas y métodos de pago.
- **28:** Estado de caja por sesiones.
- **29:** Módulo de cierre de caja por empresa (activación).
- **30:** Observaciones en ventas.

> ⚠️ **Nota:** el archivo `schema.sql` es un dump de referencia y puede no reflejar todas las migraciones posteriores a la 30 (p. ej. tabla `proveedores_autorizados` está en migraciones pero no en el dump de tablas listado).

---

## 6. Módulos Funcionales

### Ventas
- **`pages/ventas.php`** (1100 líneas): venta completa con cliente, condiciones de pago (Contado, Transferencia, Cuenta Corriente), pago mixto, descuentos, observaciones, ventas pendientes.
- **`pages/ventarapida.php`** (924 líneas) + **`js/ventarapida.js`** (nuevos, sin commitear): venta rápida estilo supermarket, con desglose de métodos de pago (efectivo/transferencia), detalle JSON de productos y control de stock por sucursal.
- **`pages/resumen_ventas.php`** y **`resumen_ventas_viejo.php`** (versión antigua conservada).
- **`pages/anulaciones.php`**, **`pages/cobro_cuotas.php`**, **`pages/reporte_cuotas.php`**: anulaciones y cuotas.
- **`js/ventas.js`** (669 líneas): lógica AJAX de ventas.

### Productos / Inventario
- `pages/abm_productos.php` (953 líneas): ABM con rubros, precios, stock, moneda.
- `pages/reportes_inventario.php`, `pages/reporte_movimientos_productos.php`.
- Stock por empresa y sucursal (`stocks`), con COLLATE normalizado en los JOIN.

### Clientes / Cuentas Corrientes
- `pages/abm_clientes.php`, `pages/cuentas_corrientes.php` (761), `cuentas_corrientes_detalle.php`, `pagos_ctacte.php`.
- Intereses en cta. cte. (módulo `configuracion_intereses.php`, `funciones/funciones_intereses.php`).

### Proveedores / Compras
- `pages/abm_proveedores.php`, `abm_proveedores_autorizados.php`, `ctacte_proveedores.php`, `compras.php` (835), `compras_rapidas.php`.
- Catálogo de proveedores, importación CSV, precios de proveedor.

### Presupuestos
- `pages/presupuestos.php`, `consultar_presupuestos.php`, `imprimir_presupuesto.php`, `guardar_presupuesto_backend.php`, `js/presupuestos.js`, `ajax/obtener_detalle_presupuesto*.php`.
- Generación de PDF de presupuesto (`generar_pdf_presupuesto.php`).

### Caja
- `pages/abrir_caja.php`, `cierre_caja.php` (619, con rango de fechas), `caja_dashboard.php`, `reporte_cierres.php`, `cerrar_cajas_historicas.php`, `verificar_cajas_historicas.php`, `reparar_caja_total.php`, `movimiento_manual.php`.
- **Cierre por rango de fechas** (días múltiples) según `docs/CIERRES_MULTIPLES_DIAS.md`.
- **Módulo de cierre activable por empresa** (migración 29).
- Funciones en `funciones/funciones_caja.php` (449 líneas).

### Facturación Electrónica (ARCA/AFIP)
- `pages/facturacion_arca.php`, `pages/procesar_factura_arca.php`, `config/check_arca_requirements.php`.
- Tabla `ventas_afip` con CAE. SDK `afipsdk/afip.php ^1.2` en Composer.
- **Estado:** listado de comprobantes con CAE; **no es un emisor completo** (no genera CAE automáticamente desde venta; se procesa aparte).

### Consignaciones
- `api/consignaciones.php` y `api/proveedores.php` (endpoints públicos con token).
- `pages/consulta_consignaciones_remota.php`, `consignacion_reporte.php`, `ajax/obtener_catalogo_proveedor.php`.
- Proveedores autorizados **globales** (migración 25).

### Empresas / Multitenant / Usuarios
- `pages/abm_empresa.php`, `abm_empresas.php`, `components/selector_empresa.php`, `ajax/cambiar_empresa.php`, `ajax/cambiar_sucursal.php`.
- `pages/usuarios.php`, `abm_permisos_usuarios.php`, `perfil.php`.

### Reportes / Utilidades
- `reportes_financieros.php`, `reportes_inventario.php`, `resumen_ventas.php`.
- `pages/backup.php`, `procesos/backup_database.php` (sistema de respaldo).
- `pages/configuracion.php`, `pages/cron_dolar.php` (cotización dólar).

### Otros
- `pages/dashboard.php` (KPIs: ventas del día, top productos, stock crítico, vencimientos a proveedores, ventas por semana).
- `pages/topbar.php` + `pages/sidebar.php` (UI con sidebar colapsable y buscador).
- `pages/404.php`, `pages/licencia.php`, `pages/infosesion.php`.
- Generación de PDFs: ticket, ticket cuota, devolución, recibo, lista de precios, consignación, cta. cte. seleccionada.

---

## 7. Roles y Permisos

**Jerarquía de roles** (`config/validar_permisos.php`):
| Rol | Nivel |
|---|---|
| vendedor | 1 |
| cajero | 2 |
| supervisor | 3 |
| admin | 4 |
| developer | 99 (acceso total) |

- Se respetan **permisos individuales por módulo** (`permisos_usuario` / `permisos_rol`) además del rol.
- `restringirPagina()` valida por sesión + `permisos_paginas` (o `permisos` como fallback).
- `require_permiso()` en helpers devuelve 403 en formato HTML o JSON según el contexto.
- `pages/infosesion.php` actúa como **guardia de sesión** y manejador global de errores.

---

## 8. Integraciones Externas

1. **ARCA/AFIP** — Facturación electrónica mediante SDK Composer (`afipsdk/afip.php`).
2. **Dólar** — Cotización oficial vía `https://dolarapi.com/v1/dolares/oficial`, con caché en `cache/dolar_cache.json` y cron (`pages/cron_dolar.php`).
3. **Licenciamiento** — `config/licencia_manager.php` valida licencia contra un **App Engine propio** (URL dinámica en `config/.licencia_ip.conf`), con hardware ID (WMIC UUID), periodo de gracia de 20 días y caché por sesión de 24 h.
4. **Consignaciones remotas** — Endpoints públicos con token (`api/consignaciones.php`) para consulta vía VPN Radmin.
5. **WhatsApp** — `ajax/enviar_whatsapp_nodered.php` (integración con Node-RED).

---

## 9. Estado del Desarrollo

### Git — situación de la rama `main`
- El working tree tiene **muchos cambios sin commitear** (≈70 archivos modificados + varios nuevos).
- **Archivos nuevos sin trackear:** `app/`, `core/`, `pages/dashboard.php`, `pages/ventarapida.php`, `pages/404.php`, `js/ventarapida.js`, `ajax/obtener_detalle_presupuesto_json.php`, `pages/buscar_producto_codigo_ajax.php`, `pages/generar_pdf_cc_seleccion.php`, `.htaccess`.
- **Archivos eliminados (no commiteados):** `.kilo/kilo.jsonc`, `diagnostico_cierre.php`, `diagnostico_cierre_sistema.php`, `ejecutar_migraciones.php`, `hash.php`.
- Último commit: `c80ce2d` "Modulo de caja por empresa, fix de consulta consignaciones remotas".

### Funcionalidades recientes (por historial git)
- Módulo de caja por empresa, fix consulta consignaciones remotas.
- Fix de cierres de caja (rango de fechas, cajas históricas).
- Fix de Cta. Cte. habilitada en ventas.
- Fix de stock + intereses en cta. cte. de clientes.
- **Multiempresa** y **selector de empresas**.
- Precios en dólar, "dólar a topar", descuentos.
- Topbar/sidebar en módulos de ABM.

---

## 10. Seguridad — Fortalezas y Riesgos

### Fortalezas
- Consultas parametrizadas (PDO prepared statements) en la mayoría de los módulos.
- Protección CSRF disponible (`csrf_*` en helpers), aunque su uso no es uniforme en todas las páginas.
- `.htaccess` bloquea acceso directo a `app|config|core|migrations|vendor|backups|docs` y a archivos sensibles (`.sql`, `.log`, `.env`, backups).
- Guardia de sesión centralizada (`infosesion.php`) + jerarquía de permisos.
- Manejador global de excepciones con modo desarrollo/producción.

### Riesgos / Deuda técnica
- **Credenciales de BD hardcodeadas** en `config/db_config.php` (host `192.168.7.45`, user `root`, pass en claro) — aunque `db_config.php` está bloqueado por `.htaccess`, sigue siendo un riesgo en producción.
- **Endpoints API públicos** con **token hardcodeado** en el código (`api/consignaciones.php`: `consignaciones_remote_2024_vpn`) y `Access-Control-Allow-Origin: *`.
- **URLs absolutas/mixtas**: hay enlaces con `window.location` y `header Location` a nombres físicos y a URLs limpias (se manejaron con rutas de compatibilidad en `routes.php`, pero conviene unificar).
- **Archivos de funciones duplicados/legado** en `funciones/` (varias versiones de `ticket_generator`, `*_ticket_generator.php`), probablemente sin uso. Lo mismo con `pages/resumen_ventas_viejo.php`.
- **Código sensible a collation** en JOINs (se usan `COLLATE` explícitos; frágil ante cambios de charset).
- **`session_start()`** repetido en múltiples puntos (mitigado con `PHP_SESSION_NONE`).

---

## 11. Problemas Detectados y Deuda Técnica

1. **Work tree sucio:** gran cantidad de cambios sin commitear → riesgo de pérdida de trabajo; conviene committear con urgencia.
2. **Secretos en el código:** credenciales de BD y token de API en texto plano.
3. **Código duplicado/legado:** múltiples `*_ticket_generator.php`, `resumen_ventas_viejo.php`, `temp_*`, `ult_*`, `procesos/test_*`, `probar_*` — candidatos a limpieza.
4. **ARCA incompleto:** módulo lista comprobantes con CAE pero no parece emitir facturas automáticamente desde el flujo de venta.
5. **Inconsistencia URL** entre enlaces con nombres físicos y URLs limpias.
6. **Dependencia de `phpqrcode`** en `libs/` con muchos archivos cacheados (mask_*) que inflan el repo.
7. **`docs/INFORME_SEGURIDAD_POS.pdf`** existe en PDF, no en MD (no hay fuente editable).

---

## 12. Recomendaciones

- **Prioridad alta:** commitear el working tree actual y documentar el estado de los archivos eliminados.
- **Prioridad alta:** mover credenciales y tokens a variables de entorno o archivos fuera del webroot, no versionados.
- **Media:** unificar la generación de tickets en una única función y eliminar versiones duplicadas.
- **Media:** estandarizar el uso de CSRF en todos los formularios y AJAX.
- **Media:** centralizar `session_start()` y el arranque de sesión en un único bootstrap.
- **Baja/media:** revisar la deuda de collation en las consultas que dependen de `COLLATE`.
- **Media:** completar o documentar el alcance del módulo ARCA (emisión automática vs. manual).
- **Baja:** limpiar `libs/phpqrcode/cache` y archivos temporales; añadirlos al `.gitignore`.

---

## 13. Datos Técnicos Clave

| Parámetro | Valor |
|---|---|
| Nombre de la app | `LS-POS-PRO` |
| Versión | `2.5.0` |
| BD desarrollo | `pos_dev` (host `192.168.7.45`) |
| BD producción | `pos_prod` |
| Zona horaria | `America/Argentina/Buenos_Aires` |
| Separador decimal forzado | punto (`setlocale(LC_NUMERIC, 'C')`) |
| Servidor de licencias | dinámico (`.licencia_ip.conf`, default `190.100.100.50`) |
