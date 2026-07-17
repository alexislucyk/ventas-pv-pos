# Estado del Sistema — POS Electricidad Lucyk (pos_dev)

> **Versión del sistema:** `2.0.0` (app_version en BD)
> **Última actualización del informe:** 17/07/2026
> **Entorno activo:** Desarrollo (`/pos_dev/`)
> **Base de datos:** `pos_dev` (desarrollo) / `pos_prod` (producción)
> **Servidor BD:** `192.168.7.45:3306`
> **Framework:** PHP monolítico (sin framework MVC) + MySQL + JavaScript vanilla

---

## 1. VISIÓN GENERAL DE LA APLICACIÓN

### 1.1 ¿Qué es POS Lucyk?

Sistema de Punto de Venta (POS) diseñado para un comercio minorista/mayorista del rubro eléctrico. Implementa:

- **Frontend:** HTML/CSS/JavaScript vanilla, renderizado desde PHP (sin SPA ni framework frontend).
- **Backend:** PHP puro con arquitectura monolítica (cada página es un archivo PHP que incluye seguridad, lógica y vista).
- **Base de datos:** MySQL con PDO como capa de abstracción.
- **Diseño:** Interfaz oscura (dark mode), sidebar colapsable, diseño responsive básico.

### 1.2 Arquitectura General

```
pos_dev/
├── index.php              → Dashboard principal
├── login.php              → Autenticación
├── logout.php             → Cierre de sesión
├── config/                → Configuración BD, permisos, licencias
├── pages/                 → Vistas PHP (controladores + UI)
│   ├── infosesion.php     → Guardia de seguridad global
│   ├── sidebar.php        → Menú de navegación
│   ├── topbar.php         → Barra superior
│   ├── components/        → Componentes reutilizables
│   ├── abm_*.php          → CRUDs (clientes, productos, proveedores)
│   ├── ventas.php         → Módulo de ventas (core)
│   ├── compras.php        → Módulo de compras
│   ├── *.php              → Resto de módulos
├── ajax/                  → Endpoints asincrónicos (JSON)
├── procesos/              → Lógica batch/backend
├── funciones/             → Generación de tickets, configuración
├── fpdf/                  → Librería de generación PDF
├── libs/phpqrcode/        → Generación de códigos QR
├── js/                    → JavaScript del frontend
├── css/                   → Hojas de estilo
├── afip_res/              → Certificados AFIP/ARCA
├── migrations/            → Migraciones SQL
└── cache/                 → Caché (ej. dólar)
```

---

## 2. MÓDULOS DEL SISTEMA

### 2.1 Módulos por Categoría

| Categoría | Módulo | Archivo | Estado |
|-----------|--------|---------|--------|
| **Maestros** | Productos (ABM) | `pages/abm_productos.php` | ✅ Completo |
| | Clientes (ABM) | `pages/abm_clientes.php` | ✅ Completo |
| | Proveedores (ABM) | `pages/abm_proveedores.php` | ✅ Completo |
| | Consulta de Precios | `pages/consulta_precios.php` | ✅ Completo |
| **Ventas** | Nueva Venta | `pages/ventas.php` | ✅ Completo (Core) |
| | Presupuestos | `pages/presupuestos.php` | ✅ Completo |
| | Anulaciones | `pages/anulaciones.php` | ✅ Completo |
| | Cobro de Cuotas | `pages/cobro_cuotas.php` | ✅ Completo |
| | Pagos Cta. Cte. | `pages/pagos_ctacte.php` | ✅ Completo |
| **Compras** | Compras | `pages/compras.php` | ✅ Completo |
| | Compra Rápida | `pages/compras_rapidas.php` | ✅ Completo |
| **Facturación y Caja** | Comprobantes AFIP/ARCA | `pages/facturacion_arca.php` | ✅ Completo |
| | Panel de Caja | `pages/caja_dashboard.php` | ✅ Completo |
| | Movimiento Manual | `pages/movimiento_manual.php` | ✅ Completo |
| | Cierre de Caja | `pages/cierre_caja.php` | ✅ Completo |
| **Informes** | Resumen de Ventas | `pages/resumen_ventas.php` | ✅ Completo |
| | Cuentas a Cobrar | `pages/reporte_cuotas.php` | ✅ Completo |
| | Cta. Cte. Clientes | `pages/cuentas_corrientes.php` | ✅ Completo |
| | Cta. Cte. Proveedores | `pages/ctacte_proveedores.php` | ✅ Completo |
| | Inventario | `pages/reportes_inventario.php` | ✅ Completo |
| | Financieros | `pages/reportes_financieros.php` | ✅ Completo |
| **Administración** | Usuarios | `pages/usuarios.php` | ✅ Completo |
| | Permisos por Usuario | `pages/abm_permisos_usuarios.php` | ✅ Completo |
| | Backup | `pages/backup.php` | ✅ Completo |
| | Datos de Empresa | `pages/abm_empresa.php` | ✅ Completo |
| | Configuración | `pages/configuracion.php` | ✅ Completo |

### 2.2 Funcionalidades Transversales

| Funcionalidad | Estado | Detalle |
|---------------|--------|---------|
| Autenticación por sesión | ✅ | `login.php` con `password_verify()` |
| Control de permisos (rol) | ✅ | Roles: vendedor, cajero, supervisor, admin, developer |
| Control de permisos (individual) | ✅ | Permisos granulares por usuario/módulo |
| Sidebar colapsable con buscador | ✅ | Búsqueda en vivo de módulos |
| Dashboard con gráficos | ✅ | Chart.js, cards con indicadores |
| Multi-empresa | ✅ | Tabla `empresas`, sesión por empresa |
| Multi-sucursal | 🟡 Parcial | Existe `sucursal_id` en sesión, pero no hay selector de sucursal funcional ni menú de gestión de sucursales |
| Facturación electrónica AFIP/ARCA | ✅ | SDK `afipsdk/afip.php`, emisión de CAE |
| Cotización dólar | ✅ | Cache automático, actualización desde API externa |
| Tickets PDF | ✅ | FPDF + QR + vista previa |
| Backup de BD | ✅ | Exportación SQL + explorador de archivos |
| Consignaciones | ✅ | `pages/consignacion_reporte.php` |
| Envío WhatsApp | 🟡 Parcial | Endpoint `ajax/enviar_whatsapp_nodered.php` (requiere Node-RED externo) |

---

## 3. FLUJO DE AUTENTICACIÓN Y PERMISOS

### 3.1 Proceso de Login

```
Usuario → login.php → validar credenciales → verificar estado='ACTIVO' 
→ cargar permisos (rol + individuales) → $_SESSION['permisos'] → redirect index.php
```

- **Hash de contraseñas:** `password_hash()` / `password_verify()` (bcrypt).
- **Sesión:** PHP nativa, sin JWT ni tokens.
- **Validación de permisos:** 
  - `tiene_permiso($archivo)`: chequea rol 'developer' (acceso total) o si el archivo está en `$_SESSION['permisos']`.
  - `require_permiso($archivo)`: igual pero termina con 403 si no tiene acceso.
  - `restringirPagina($rolMinimo)`: control por jerarquía numérica de roles.
- **Excepción global:** `set_exception_handler()` captura errores fatales y muestra pantalla amigable (con detalles técnicos solo para developer).

### 3.2 Roles y Jerarquía

| Rol | Nivel | Acceso |
|-----|-------|--------|
| vendedor | 1 | Ventas, consultas |
| cajero | 2 | Caja, movimientos |
| supervisor | 3 | Supervisión, reportes |
| admin | 4 | Administración, ABMs |
| developer | 99 | Acceso total |

---

## 4. MÓDULO DE VENTAS (CORE)

### 4.1 Flujo de Venta

1. Usuario selecciona productos (búsqueda por código/descripción)
2. Define cantidades, descuentos (unitario y global)
3. Selecciona cliente (opcional), condición de pago
4. Si es Cuenta Corriente: opción de financiación en cuotas
5. Aplica descuento global (% o fijo)
6. Procesa pago (efectivo, transferencia, mixto)
7. Finaliza → descuenta stock → registra en BD → genera ticket PDF
8. Si aplica: guarda pendiente para facturación electrónica ARCA

### 4.2 Características de Venta

| Característica | Estado | Descripción |
|---------------|--------|-------------|
| Productos en múltiples monedas | ✅ | Pesos y dólares con conversión automática |
| Descuento por producto | ✅ | Descuento unitario por línea |
| Descuento global | ✅ | Porcentaje o monto fijo sobre el total |
| Financiación en cuotas | ✅ | Cuotas con interés configurable |
| Condiciones de pago | ✅ | CONTADO, TRANSFERENCIA, CUENTA CORRIENTE |
| Pago mixto | ✅ | Efectivo + Transferencia en una misma venta |
| Ventas pendientes | ✅ | Guardar como "Pendiente" y retomar después |
| Anulación de ventas | ✅ | Con control de stock |
| Devoluciones | ✅ | Con reintegro |

### 4.3 Cálculos Financieros

```
Subtotal = Σ (precio_unitario × cantidad)
Descuento global = (porcentaje → subtotal × %) / (fijo → monto)
Total = subtotal - descuento_global
Si aplica cuotas: Total_cuotas = Total × (1 + interés%/100)
```

---

## 5. INTEGRACIÓN ARCA/AFIP

### 5.1 Dependencias

```
composer.json → "afipsdk/afip.php": "^1.2"
```

### 5.2 Flujo de Facturación

1. Venta finalizada → registrada en `ventas` y `ventas_detalle`
2. Desde `pages/facturacion_arca.php` se procesa la venta
3. Se envía a ARCA/AFIP → se obtiene CAE
4. Se registra en `ventas_afip` (id_venta, CAE, vencimiento, etc.)
5. Se puede reimprimir el comprobante fiscal

### 5.3 Archivos Relacionados

- `pages/facturacion_arca.php` → Listado de comprobantes con CAE
- `pages/procesar_factura_arca.php` → Lógica de emisión
- `afip_res/` → Certificados (`.crt` y `.key`)
- `config/check_arca_requirements.php` → Validación pre-emisión
- `arca.md` → Documentación de errores ARCA

---

## 6. CONTABILIDAD Y FINANZAS

### 6.1 Cuentas Corrientes

| Entidad | Tabla | Descripción |
|---------|-------|-------------|
| Clientes | `ctacte` | Debe/Haber por cliente, saldo actual |
| Proveedores | `ctacte_proveedores` | Debe/Haber por proveedor |
| Pagos | `pagos_ctacte` | Registro de pagos recibidos |
| Cuotas | `cuotas_ventas` | Financiación en cuotas |

### 6.2 Caja

| Componente | Archivo | Descripción |
|------------|---------|-------------|
| Dashboard | `pages/caja_dashboard.php` | Resumen del día (ingresos/egresos) |
| Cierre | `pages/cierre_caja.php` | Cierre de caja diario |
| Procesar cierre | `pages/procesar_cierre.php` | Lógica de cierre |
| Mov. Manual | `pages/movimiento_manual.php` | Registrar ingresos/egresos manuales |
| Reparar total | `pages/reparar_caja_total.php` | Utilidad de corrección |

### 6.3 Reportes Financieros

- `pages/resumen_ventas.php`: Ventas por período, método de pago, ganancias estimadas.
- `pages/reportes_financieros.php`: Reportes financieros generales.
- `pages/reporte_cuotas.php`: Cuentas a cobrar (cuotas pendientes).

---

## 7. BASE DE DATOS

### 7.1 Conexión

- **Host:** `192.168.7.45`
- **Usuario:** `root`
- **Password:** `isidoro9`
- **Charset:** `utf8mb4` con `utf8mb4_unicode_ci`
- **Entornos:** Detección automática por URL (`/pos_dev/` → DB `pos_dev`, caso contrario → DB `pos_prod`)

### 7.2 Tablas Principales Identificadas

| Tabla | Propósito |
|-------|-----------|
| `usuarios` | Autenticación, roles, permisos |
| `empresas` | Multi-empresa |
| `clientes` | Datos de clientes (dni, CUIT, tipo IVA, etc.) |
| `proveedores` | Datos de proveedores |
| `productos` | Catálogo con stock, precios, moneda |
| `rubros` | Categorías de productos |
| `ventas` | Cabecera de ventas (total, condición, estado) |
| `ventas_detalle` | Líneas de venta (producto, cantidad, precio, descuento) |
| `ventas_afip` | Vinculación CAE con ventas |
| `compras` | Cabecera de compras |
| `presupuestos` | Presupuestos pendientes |
| `movimientos` | Movimientos de caja |
| `ctacte` | Cuenta corriente de clientes (debe/haber) |
| `ctacte_proveedores` | Cuenta corriente de proveedores |
| `cuotas_ventas` | Financiación en cuotas |
| `devoluciones` | Devoluciones de productos |
| `modulos` | Registro de módulos para permisos |
| `permisos_rol` | Permisos por rol |
| `permisos_usuario` | Permisos individuales |
| `configuracion` | Configuraciones clave/valor (ganancia, versión, etc.) |

### 7.3 Migraciones

- Directorio `migrations/` contiene scripts SQL evolutivos.
- `manifesto.sql` funciona como bitácora histórica de DDL.
- Existe `procesos/ejecutar_migracion_20.php` para migraciones específicas.

---

## 8. ENDPOINTS AJAX

### 8.1 Catálogo y Búsquedas

| Endpoint | Función |
|----------|---------|
| `ajax/agregar_cliente_rapido.php` | Alta rápida de clientes |
| `ajax/agregar_producto_rapido.php` | Alta rápida de productos |
| `ajax/agregar_proveedor_rapido.php` | Alta rápida de proveedores |
| `ajax/agregar_rubro_ajax.php` | Alta de rubros |
| `ajax/cargar_multiples_productos.php` | Carga múltiple de productos a venta |
| `ajax/importar_catalogo_csv.php` | Importación de CSV |
| `ajax/obtener_precios_proveedor.php` | Precios por proveedor |
| `ajax/obtener_catalogo_proveedor.php` | Catálogo de proveedor |

### 8.2 Ventas y Operaciones

| Endpoint | Función |
|----------|---------|
| `ajax/obtener_detalle_venta.php` | Detalle de venta |
| `ajax/obtener_detalle_presupuesto.php` | Detalle de presupuesto |
| `ajax/obtener_detalle_devolucion.php` | Detalle de devolución |
| `ajax/obtener_venta_anulacion.php` | Datos para anulación |
| `ajax/cargar_venta_pendiente_ajax.php` | Cargar venta pendiente |
| `ajax/ventas_pendientes_ajax.php` | Listar ventas pendientes |
| `ajax/buscar_ventas_cliente_ajax.php` | Ventas por cliente |

### 8.3 Pagos y Cuentas Corrientes

| Endpoint | Función |
|----------|---------|
| `ajax/obtener_cuotas_pago.php` | Cuotas pendientes de pago |
| `ajax/procesar_pago_cuota.php` | Procesar pago de cuota |
| `ajax/anular_pago_cuota.php` | Anular pago de cuota |
| `ajax/obtener_detalle_pago.php` | Detalle de pago |
| `ajax/obtener_movimientos_cc.php` | Historial de movimientos CC |
| `ajax/obtener_clientes_cc.php` | Clientes con CC |
| `ajax/registrar_pago_ctacte_ajax.php` | Registrar pago en CC |
| `ajax/registrar_pago_proveedor_ajax.php` | Pago a proveedor |
| `ajax/cargar_ctacte_proveedor_ajax.php` | Cargar CC proveedor |
| `ajax/reimputar_excedente_proveedor_ajax.php` | Reimputar excedente |
| `ajax/marcar_compra_pagada_ajax.php` | Marcar compra como pagada |

### 8.4 Tickets y Notificaciones

| Endpoint | Función |
|----------|---------|
| `ajax/generar_ticket.php` | Generar ticket de venta |
| `ajax/generar_ticket_cuota.php` | Ticket de cuota |
| `ajax/temp_generar_ticket.php` | Ticket temporal |
| `ajax/enviar_whatsapp_nodered.php` | Envío WhatsApp vía Node-RED |

### 8.5 Sistema

| Endpoint | Función |
|----------|---------|
| `ajax/cambiar_empresa.php` | Cambio de empresa |
| `ajax/cambiar_sucursal.php` | Cambio de sucursal (parcialmente implementado) |
| `ajax/explorador_archivos_backup.php` | Explorar backups |
| `ajax/update_licencia_ip.php` | Actualizar licencia por IP |

---

## 9. GENERACIÓN DE TICKETS Y PDFs

### 9.1 Archivos de Generación

| Archivo | Propósito |
|---------|-----------|
| `funciones/ticket_generator.php` | Generador principal de tickets |
| `funciones/temp_ticket_generator.php` | Versión temporal/alternativa |
| `funciones/ult_ticket_generator.php` | Versión reciente del generador |
| `funciones/funciona_ticket_generator.php` | Versión de prueba/legacy |
| `pages/generar_pdf_ticket.php` | PDF de ticket de venta |
| `pages/generar_pdf_recibo.php` | PDF de recibo |
| `pages/generar_pdf_presupuesto.php` | PDF de presupuesto |
| `pages/generar_pdf_devolucion.php` | PDF de devolución |
| `pages/generar_pdf_consignacion.php` | PDF de consignación |
| `pages/vista_previa_ticket.php` | Vista previa en HTML |
| `pages/vista_previa_ticket_cuota.php` | Vista previa cuota |
| `pages/vista_previa_ticket_devolucion.php` | Vista previa devolución |

### 9.2 Estilos de Impresión

- `css/ticket_print.css` - Estilos para ticket térmico
- `css/temptocketprint.css` - Estilos temporales
- `css/cee_temp.css` - Estilos complementarios

### 9.3 QR en Tickets

- Biblioteca: `libs/phpqrcode/`
- QR placeholder: `img/qr_paceholder.png`
- QR del comercio: `img/shop.png`

---

## 10. SEGURIDAD

### 10.1 Capas de Seguridad

| Capa | Descripción |
|------|-------------|
| Autenticación | `login.php` con password_hash/verify |
| Sesión | PHP nativa con session_start() |
| Guardia de sesión | `pages/infosesion.php` - redirige a login si no hay sesión |
| Permisos por rol | Jerarquía numérica (vendedor=1 a developer=99) |
| Permisos individuales | Asignación por usuario a módulos específicos |
| Validación en páginas | `tiene_permiso()` / `require_permiso()` en cada página |
| Validación en AJAX | Misma función de permisos en endpoints |
| Manejo de errores | `set_exception_handler()` con pantalla segura |
| SQL Injection | Prepared statements (PDO) en toda la app |
| XSS | `htmlspecialchars()` en salidas |
| Licenciamiento | Validación por IP (config/licencia_manager.php) |

### 10.2 Puntos Débiles Identificados

1. **Contraseña en texto plano en `db_config.php`**: La contraseña `isidoro9` y el usuario `root` están hardcodeados.
2. **Acceso root a BD**: Usuario root con todos los privilegios.
3. **Compartir/configuracion en repositorio**: `config/db_config.php` con credenciales en el repo.
4. **No hay HTTPS forzado**: Depende del servidor web.
5. **Algunas páginas usan `restringirPagina()` y otras `tiene_permiso()`**: Inconsistencia en el método de control de acceso.
6. **`db_config copy.php`**: Archivo duplicado con credenciales, riesgo de exposición.

---

## 11. INTERFAZ DE USUARIO

### 11.1 Diseño

- **Tema:** Dark mode completo (#121212 fondo, #1e1e1e tarjetas, #00bcd4 acento)
- **Sidebar:** Colapsable con animación, categorías coloreadas, buscador en vivo
- **Dashboard:** Grid responsive con cards, tabla de top productos, gráfico Chart.js
- **Tipografía:** Segoe UI, Tahoma, Geneva, sans-serif

### 11.2 Dependencias Frontend

| Librería | CDN/Local |
|----------|-----------|
| Font Awesome 6 | CDN (`cdnjs.cloudflare.com`) |
| Chart.js | CDN (`cdn.jsdelivr.net/npm/chart.js`) |
| FPDF | Local (`fpdf/`) |
| phpqrcode | Local (`libs/phpqrcode/`) |

---

## 12. CONFIGURACIÓN DEL SISTEMA

### 12.1 Variables de Configuración en BD (tabla `configuracion`)

| Clave | Descripción |
|-------|-------------|
| `ganancia_global` | Porcentaje de ganancia default (ej: 60%) |
| `app_version` | Versión del sistema (ej: "2.0.0") |
| `nombre_empresa` | Nombre de la empresa (fallback) |
| Otras | Configuraciones diversas |

### 12.2 Constantes del Sistema (definidas en `config/db_config.php`)

| Constante | Valor |
|-----------|-------|
| `URL_BASE` | `/pos_dev/` o `/pos_prod/` según entorno |
| `PATH_BASE` | Directorio raíz absoluto |

---

## 13. SISTEMA DE MULTI-EMPRESA

### 13.1 Estado Actual

- **Soporte multi-empresa:** ✅ Implementado
  - Tabla `empresas` con datos de cada empresa
  - Sesión con `empresa_id`
  - Todas las consultas filtran por `empresa_id`
  - Selector de empresa en sidebar (`components/selector_empresa.php`)
  - Endpoint `ajax/cambiar_empresa.php` para cambio en caliente
  - Página `pages/abm_empresas.php` para gestión

- **Soporte multi-sucursal:** 🟡 Parcial
  - Existe `sucursal_id` en sesión
  - Algunas consultas lo incluyen (ej: caja)
  - Endpoint `ajax/cambiar_sucursal.php` creado
  - **No hay:** selector de sucursal en UI, gestión de sucursales, stock por sucursal, reportes filtrados por sucursal

---

## 14. ARCHIVOS Y DOCUMENTACIÓN

### 14.1 Documentación Existente

| Archivo | Contenido |
|---------|-----------|
| `manifiesto.md` | Arquitectura, flujos, estructura del sistema |
| `arca.md` | Manual de errores ARCA/AFIP |
| `ANALISIS_SISTEMA_POS.md` | Análisis general del sistema |
| `informe_sistema_permisos.md` | Informe de permisos |
| `implementacion_arca.md` | Detalles de implementación ARCA |
| `TODO.md` | Tareas pendientes actuales |
| `docs/BACKUP_SISTEMA.md` | Documentación de backups |
| `docs/VERSION_MANAGEMENT.md` | Gestión de versiones |

### 14.2 Archivos de Utilidad

| Archivo | Función |
|---------|---------|
| `hash.php` | Generador de hashes para contraseñas |
| `test_agregar.php` | Pruebas de inserción |
| `test_trigger.php` | Pruebas de triggers SQL |

---

## 15. ESTADO DE TAREAS PENDIENTES (TODO.md)

```
- [x] Crear migración SQL para stock con DEFAULT 0
- [ ] Ejecutar migración en BD y probar:
  - [ ] ajax/agregar_producto_rapido.php
  - [ ] ajax/cargar_multiples_productos.php
- [ ] Actualizar INSERTs de productos.stock si es necesario
```

---

## 16. PROCESOS DEL SISTEMA

### 16.1 Procesos Batch/Programados

| Archivo | Propósito |
|---------|-----------|
| `pages/cron_dolar.php` | Actualización programada del dólar |
| `procesos/backup_database.php` | Backup automático de BD |
| `procesos/verificar_backups.php` | Verificación de integridad de backups |
| `procesos/verificar_backup_simple.php` | Verificación simplificada |
| `procesos/verificar_ruta_backup.php` | Validación de ruta de backups |
| `procesos/probar_backup.php` | Test de backup |
| `procesos/probar_explorador.php` | Test de explorador de archivos |
| `procesos/ejecutar_migracion_20.php` | Ejecutor de migraciones |

---

## 17. RESUMEN DE MÉTRICAS

| Métrica | Valor |
|---------|-------|
| Archivos PHP totales | ~80 |
| Páginas (pages/) | ~35 |
| Endpoints AJAX | ~28 |
| Procesos backend | ~10 |
| Funciones auxiliares | ~7 |
| Archivos CSS | 5 |
| Archivos JS | 2 |
| Documentos .md | 10 |
| Tablas BD estimadas | ~25 |
| Versión actual | 2.0.0 |

---

## 18. CONCLUSIONES Y RECOMENDACIONES

### 18.1 Fortalezas

1. **Arquitectura sólida** para un sistema monolítico PHP con buena separación de responsabilidades.
2. **Cobertura funcional completa** para un POS: ventas, compras, CC, caja, AFIP, reportes.
3. **Multi-empresa implementado** correctamente con filtrado por empresa en todas las consultas.
4. **Sistema de permisos flexible** (rol + individual) con fácil extensibilidad.
5. **Prepared statements** en toda la aplicación (buena práctica de seguridad).
6. **Manejo global de excepciones** con interfaz amigable.
7. **Control de versiones** con migraciones.

### 18.2 Debilidades

1. **Credenciales de BD** hardcodeadas en `config/db_config.php`.
2. **Usuario root** en lugar de un usuario de BD con permisos limitados.
3. **Inconsistencia en validación de permisos**: algunos archivos usan `restringirPagina()` y otros `tiene_permiso()`.
4. **Multi-sucursal incompleta**: falta selector UI, gestión y stock por sucursal.
5. **Código duplicado** en múltiples generadores de tickets (varias versiones).
6. **Archivos legacy** como `db_config copy.php` y versiones antiguas de ticket generators.
7. **Sin tests automatizados** (no se detectaron archivos de test unitarios).
8. **Dependencia de CDN** para librerías críticas (Chart.js, Font Awesome) sin fallback local.
9. **Sin API REST** - todo el frontend se renderiza desde PHP, no hay backend desacoplado.

### 18.3 Recomendaciones Prioritarias

1. **🔴 Crítica:** Mover credenciales de BD a variables de entorno o archivo `.env` fuera del repo.
2. **🔴 Crítica:** Crear usuario de BD específico para la app (no root).
3. **🟡 Media:** Completar implementación multi-sucursal (selector, stock por sucursal, reportes).
4. **🟡 Media:** Unificar los generadores de tickets en una sola versión estable.
5. **🟢 Baja:** Agregar tests básicos para flujos críticos (login, ventas, pagos).
6. **🟢 Baja:** Migrar dependencias CDN a archivos locales.
7. **🟢 Baja:** Limpiar archivos legacy y duplicados.

---

*Documento generado el 17/07/2026 basado en el análisis del código fuente del repositorio `pos_dev`.*