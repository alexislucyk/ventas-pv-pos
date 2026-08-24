# 📊 Análisis Completo del Sistema POS — Resumen Ejecutivo

> Documento generado: 24/08/2026 · Proyecto: `c:\laragon\www\pos_dev`

---

## Contexto General

El sistema es un **Punto de Venta (POS)** desarrollado en **PHP puro 8.3** (sin framework), orientado a comercios minoristas argentinos, con arquitectura multi-empresa y multi-sucursal. Corre sobre **Apache vía Laragon (Windows)**, con **MySQL 8.4** como base de datos. Recientemente se implementó un **router propio** (`core/Router.php`) para URLs limpias.

**Estructura**: 314 archivos PHP, 38 migraciones SQL, 465 archivos en `libs/phpqrcode`. Git con tags: `v2.7.0`, `v2.7.1`.

---

## ✅ Fortalezas del Sistema

| Área | Fortalezas |
|---|---|
| **Arquitectura** | Patrón Front Controller con router propio (`core/Router.php`), compatibilidad hacia atrás. |
| **Base de datos** | Prepared statements PDO en la mayoría de módulos. |
| **Multi-empresa** | `empresa_id` en todas las tablas (31 migraciones). |
| **Permisos** | Jerarquía de roles + permisos granulares por módulo. |
| **Errores** | Manejador global de excepciones con modo dev/prod. |
| **Caja** | Apertura/cierre, cierres históricos, estado por sesiones, rango de fechas. |
| **ARCA/AFIP** | PDFs con CAE, QR ARCA, facturación electrónica parcial. |
| **Backup** | Sistema mysqldump configurable. |
| **Frontend** | JavaScript vanilla moderno (fetch API), Chart.js, dark mode. |
| **Docs** | `docs/` con guías de cierres, ARCA, versionado. |

---

## 🔴 Mejoras Prioritarias (Alta)

### 1. 🔐 Secretos expuestos en código fuente
- Credenciales DB hardcodeadas en `config/db_config.php` (host `192.168.7.45`, root, pass `isidoro9`).
- Tokens de API hardcodeados en `api/consignaciones.php` y `api/proveedores.php` (`'consignaciones_remote_2024_vpn'`), con `Access-Control-Allow-Origin: *`.
- Sin archivo `.env`. Sin `schema.sql`.
- **Recomendación**: Mover a variables de entorno via `.env`; restringir CORS; crear `.env.example`.

### 2. 📋 Inconsistencia de versión
- `APP_VERSION` = `'2.5.0'` en `config/licencia_manager.php`, pero tags Git = `v2.7.0`/`v2.7.1`.
- Afecta validación de licencia y detección de actualizaciones.
- **Recomendación**: Sincronizar con el tag actual.

### 3. 🐛 Hardcodeo de `empresa_id = 1` en sidebar
- `pages/sidebar.php:9` usa `WHERE id = 1` en lugar de `$_SESSION['empresa_id']`.

### 4. ⚠️ Sin tests ni CI/CD
- Un único `procesos/test_backup_directo.php`. `.gitignore` referencia archivos de test inexistentes.

### 5. 🔓 CORS `*` + token hardcodeado en APIs públicas.

---

## 🟡 Mejoras Prioritarias (Media)

### 6. 🔫 `die()` en lugar de manejo de errores (dashboard.php, ventas.php, abm_productos.php).
### 7. 🛡️ Sin `session_regenerate_id()` post-login (vulnerabilidad session fixation).
### 8. 📁 Archivos duplicados (`funciona_ticket_generator.php` vs `ticket_generator.php`, `resumen_ventas_viejo.php`).
### 9. 🌐 HTML estáticos `list_filt.html` (91KB) / `list_nofilt.html` (306KB) con paths `/pos_dev/` hardcodeados.
### 10. 📦 `libs/phpqrcode` con 465 archivos (máscaras cache); añadir al `.gitignore`.
### 11. 🔧 Sin migrador con rollback; migraciones ejecutadas una a una via `ejecutar_migracion_XX.php`.
### 12. 🎨 Sin build system, sin SRI en CDNs, CSS inline de 580 líneas en dashboard.php.

---

## 🟢 Mejoras Prioritarias (Baja)

### 13. 📄 `INFORME_SEGURIDAD_POS.pdf` sin fuente editable.
### 14. 🗃️ `afip_res/` carpeta vacía (0 archivos).
### 15. 🧬 Sin PSR-12 / autoloading PSR-4 para app code.
### 16. 🔧 `config/check_arca_requirements.php` vacío (0 bytes).
### 17. 🧮 Query no preparada con cast `(int)` en `abm_productos.php:20`.
### 18. 🕐 Sin configuración global de errores PHP.

---

## Matriz de Prioridades

| # | Problema | Prioridad | Impacto | Complejidad |
|---|---|---|---|---|
| 1 | Secretos hardcodeados (DB, API tokens) | **Alta** | Crítico | Baja |
| 2 | Inconsistencia de versión | **Alta** | Alto | Muy baja |
| 3 | `empresa_id = 1` en sidebar | **Alta** | Funcional | Muy baja |
| 4 | Sin tests / CI-CD | **Alta** | Alto | Alta |
| 5 | CORS `*` + token hardcodeado | **Alta** | Alto | Media |
| 6-18 | Ver detalle anterior | Media-Baja | Medio-Bajo | Variable |