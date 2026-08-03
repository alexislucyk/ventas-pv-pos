# Manifiesto — POS Electricidad Lucyk (pos_dev)

> Documento de estado y arquitectura del repositorio **c:/laragon/www/pos_dev**.
>
> Objetivo: dejar una descripción **estrictamente detallada** sobre el **funcionamiento** y la **estructura** del sistema, incluyendo cómo se conectan UI, endpoints, procesos backend, base de datos e integración ARCA/AFIP.

---

## 1) Qué es `pos_dev`

`pos_dev` es un sistema de **Punto de Venta (POS)** implementado en **PHP** (estilo monolito/plantillas), con:

- **UI** renderizada desde archivos PHP bajo `pages/`.
- **End points AJAX** en `ajax/` para operaciones asincrónicas.
- **Procesos backend** en `procesos/` para acciones (persistencia/transformación de datos).
- **Integración AFIP/ARCA** (facturación electrónica) con:
  - certificados/artefactos en `afip_res/`
  - SDK en `composer.json` (dependencia `afipsdk/afip.php`)
  - flujo de emisión (ver relación con `arca.md` y el código que procesa la facturación en archivos “procesar_*arca*”).
- **Generación de tickets / PDF** usando **FPDF** (carpeta `fpdf/`) y QR con `libs/phpqrcode/`.
- Módulo de **control de permisos** por rol y permisos individuales.
- Autenticación mediante **session** y hashes en la tabla `usuarios`.
- Licenciamiento/validación por IP (carpetas `config/` con archivos dedicados).

---

## 2) Alcance del manifiesto

Este documento describe el estado actual observado del repositorio (estructura y piezas principales), y cómo encaja el flujo general:

- Inicio de sesión
- Control de acceso por permisos
- Dashboard (panel) y UI
- Operaciones (ventas, cobros, compras, cuentas corrientes)
- Estructura de datos (tablas) según `manifesto.sql`
- Integración ARCA/AFIP y manejo de errores
- Tickets/PDF

---

## 3) Estructura del repositorio

### 3.1 Archivos raíz

En la raíz del repo se observan:

- `index.php`
  - Punto de entrada del **dashboard**.
- `login.php`
  - Página de inicio de sesión.
- `logout.php`
  - Cierra sesión y redirige.
- `composer.json` y `composer.lock`
  - Dependencias PHP, incluyendo AFIP/ARCA.
- `manifesto.sql`
  - Registro de cambios/DDL histórico: tablas y ALTER TABLE.
- `arca.md`
  - Manual de errores ARCA/AFIP (códigos y acciones sugeridas).
- `hash.php`
  - Utilidad local para generar hashes (ej. `password_hash`) para `usuarios`.

### 3.2 Carpetas principales

#### `pages/` (UI / controladores de vista)

Contiene páginas PHP que renderizan la interfaz:

- Panel y navegación
  - `sidebar.php` (menú/lateral)
- Gestión de catálogos
  - `abm_clientes.php`
  - `abm_productos.php`
  - `abm_proveedores.php`
  - `abm_empresa.php`
  - `abm_permisos_usuarios.php`
- Operaciones
  - `ventas.php`
  - `compras.php`
  - `caja_dashboard.php`, `cierre_caja.php`, `procesar_cierre.php`
  - `presupuestos.php`, `consultar_presupuestos.php`, etc.
- Cuentas corrientes / pagos
  - `cuentas_corrientes.php`
  - `ctacte_proveedores.php`
  - `pagos_ctacte.php`
- Tickets / vistas previas / impresión
  - `vista_previa_ticket.php`, `vista_recibo.php`
  - `generar_pdf_*.php`

Estas páginas suelen:
- incluir `pages/infosesion.php` (patrón observado en `index.php`, y típico en otras páginas)
- usar `$pdo` (PDO) definido en `config/db_config.php`
- respetar permisos (dependiendo del módulo/archivo)

#### `ajax/` (endpoints asincrónicos)

Contiene scripts PHP que atienden llamados AJAX desde la UI:

- Agregados rápidos
  - `agregar_cliente_rapido.php`
  - `agregar_producto_rapido.php`
  - `agregar_proveedor_rapido.php`
- Búsquedas/detalles
  - `buscar_ventas_cliente_ajax.php`
  - `obtener_detalle_venta.php`
  - `obtener_venta_detalle_ajax.php` (y variantes)
- Cuentas corrientes
  - `cargar_ctacte_proveedor_ajax.php`
  - `obtener_movimientos_cc.php`
  - `obtener_clientes_cc.php`
- Cobros / cuotas
  - `obtener_cuotas_pago.php`
  - `procesar_pago_cuota.php`
  - `anular_pago_cuota.php`
  - `registrar_pago_ctacte_ajax.php`
  - `registrar_pago_proveedor_ajax.php`
- Integración ARCA/WhatsApp/Nodered
  - `enviar_whatsapp_nodered.php`

Típicamente:
- reciben parámetros por POST/GET
- validan permiso (usando helpers en `config/validar_permisos.php` u otro método)
- consultan/actualizan BD
- devuelven JSON o HTML fragment

#### `procesos/` (acciones backend)

Ejemplo observado:
- `procesos/registrar_pago_cc.php`

Suele separar parcialmente la persistencia/transformación del flujo UI.

#### `config/` (infra: BD, permisos, licencias)

- `db_config.php`: configura DSN y crea `$pdo`.
- `validar_permisos.php`: guard para chequear acceso según sesión/permisos.
- `licencia_manager.php`, `check_arca_requirements.php`, `config/.licencia_*`: licenciamiento.
- `config/db_config copy.php`: duplicado/legacy.

#### `funciones/`

Generadores de tickets/PDF:
- `ticket_generator.php`
- `temp_ticket_generator.php`
- `ult_ticket_generator.php`
- `funciona_ticket_generator.php`

Normalmente:
- preparan datos del comprobante
- producen contenido formateado para impresión/PDF

#### `fpdf/`

Biblioteca FPDF y recursos.

#### `libs/phpqrcode/`

Generación de QR.

#### `css/` y `js/`

- CSS: estilos de UI y de impresión (p.ej. `ticket_print.css`).
- JS: lógica del lado cliente (p.ej. `ventas.js`, `presupuestos.js`).

#### `afip_res/`

Artefactos ARCA/AFIP (certificados/llaves).

---

## 4) Flujo principal de ejecución

### 4.1 Login (`login.php`)

1. `session_start()`.
2. Si `$_SESSION['usuario_id']` existe: redirige a `index.php`.
3. Incluye `config/db_config.php` para `$pdo`.
4. Si hay POST:
   - valida `usuario` y `password`
5. Consulta del usuario:
   - `SELECT id, password_hash, rol, estado FROM usuarios WHERE usuario = ?`
6. Autentica con:
   - `password_verify($password, $user['password_hash'])`
7. Valida `estado === 'ACTIVO'`.
8. Crea sesiones:
   - `usuario_id`, `usuario_nombre`, `usuario_rol`
9. Carga permisos desde BD:
   - módulos: `modulos` (archivo asociado)
   - permisos por rol: `permisos_rol`
   - permisos por usuario: `permisos_usuario`
   - guarda `$_SESSION['permisos']` como lista de `m.archivo`
10. Redirige a `index.php`.

### 4.2 Logout (`logout.php`)

1. Borra `$_SESSION`.
2. Elimina cookie de sesión si `ini_get("session.use_cookies")`.
3. `session_destroy()`.
4. Redirige a `URL_BASE . 'login.php'`.

### 4.3 Dashboard (`index.php`)

- Incluye `pages/infosesion.php`.
- Ejecuta queries agregadas (ventas del día por `cond_pago`, stock crítico, presupuestos pendientes, top productos, últimas ventas, gráfico últimos 7 días).
- Renderiza HTML con cards, tablas y gráfico Chart.js.

---

## 5) Autorización y permisos

- La lista de archivos habilitados se calcula en `login.php` y se guarda en `$_SESSION['permisos']`.
- La seguridad real depende de que cada `pages/*` y `ajax/*` aplique el guard en `config/validar_permisos.php` (o equivalente).

---

## 6) Integración ARCA/AFIP

### 6.1 Dependencias

- `composer.json`: `afipsdk/afip.php:^1.2`.

### 6.2 Certificados

- `afip_res/`: ubicación de `.crt` y `.key` (ver `arca.md`).

### 6.3 Manejo de errores

- `arca.md` documenta:
  - errores de validación (CUIT receptor, tipo de comprobante, punto de venta, etc.)
  - errores de negocio (condición de IVA, tipo de receptor)
  - errores técnicos (server ARCA caído, errores de BD)
  - errores de conexión (timeout)

---

## 7) Tickets / PDF / QR

- PDF: `fpdf/`.
- QR: `libs/phpqrcode/`.
- Vistas previas y generación: `pages/vista_previa_ticket*.php`, `pages/generar_pdf_*.php`, y utilidades en `funciones/`.

---

## 8) Estructura de datos (BD) — `manifesto.sql`

`manifesto.sql` actúa como bitácora histórica (CREATE/ALTER/INSERT) del esquema.

Tablas y columnas observadas:

- `devoluciones` y `devoluciones_detalle`
- `ventas_afip` (vinculación CAE)
- `clientes`: `dni`, `id_tipo_iva`
- `ventas`: `descuento_global`, `tipo_descuento_global`
- `ventas_detalle`: `descuento_unitario`
- `compras`: `usuario_id`, `fecha_vencimiento`, `observaciones`, `es_sin_detalle`
- `modulos`: habilita recursos (ej. `pages/compras_rapidas.php`)
- `ctacte_proveedores`: `usuario_id`, `compra_id`

---

## 9) Soporte conceptual para “otra sucursal” (qué falta)

Para implementar sucursales en un POS como este, en alto nivel normalmente hace falta:

1) **BD**: agregar `sucursales` y propagar `sucursal_id` a entidades transaccionales.
   - mínimo: `ventas`, `compras`, caja/movimientos (si existen)
2) **Permisos**: actualizar el guard (`validar_permisos.php`) para limitar por sucursal.
3) **UI / Reportes**: agregar selector de sucursal y filtrar consultas por `sucursal_id`.
4) **AFIP/ARCA (si aplica)**: separar punto de venta/correlativos por sucursal si el negocio lo requiere.
5) **Stock (crítico)**: si el stock es por sucursal, reemplazar `productos.stock` por una tabla tipo `stocks(sucursal_id, cod_prod, stock_actual)`.
6) **Tickets/PDF**: mostrar sucursal en el encabezado/comprobante.

---

## 10) Estado del documento

Este manifiesto quedó reescrito para quedar legible como texto UTF-8.

---

**Fin del Manifiesto — POS Electricidad Lucyk (`pos_dev`)**

