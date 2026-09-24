# 🗺️ Roadmap — Extracción y Modularización de Estilos CSS

> **Proyecto:** Sistema POS - Punto de Venta  
> **Objetivo:** Eliminar todos los estilos embebidos (`<style>` inline y atributos `style=`) de los archivos PHP y consolidarlos en módulos CSS ordenados dentro de `css/`.  
> **Fecha de inicio estimada:** 2026-08-25

---

## 📊 Diagnóstico Actual

### Estado del directorio `css/` (estructura de carpetas YA aplicada)

```
css/
├── style.css                  ← Master stylesheet (solo @import) ✅
├── cee_temp.css               ← Temporal CEE (pendiente eliminar/unificar, FASE 6)
├── base/
│   └── variables.css          ← [HECHO] CSS custom properties (:root) — importado en style.css
├── layout/
│   ├── base.css
│   ├── layout_components.css
│   └── responsive.css
├── components/
│   ├── forms_buttons.css
│   ├── autocomplete.css
│   ├── tables.css
│   ├── modals_alerts.css
│   ├── sidebar.css
│   ├── topbar.css
│   └── utilities.css
├── pages/
│   ├── dashboard.css           ← [HECHO] Extraído de dashboard.php (2 bloques) — cargo en dashboard.php
│   ├── ventas.css              ← [HECHO] Extraído de ventas.php — cargo en ventas.php
│   ├── abm_clientes.css        ← [HECHO] Extraído de abm_clientes.php (FASE 2)
│   ├── abm_base.css            ← [creado] helper ABM (fuera del roadmap)
│   ├── compras.css
│   └── ctacte_proveedores.css  ← [HECHO] Extraído de ctacte_proveedores.php (FASE 2)
├── print/
│   ├── ticket_print.css
│   └── temptocketprint.css     ← (pendiente renombrar / temporal)
└── login/
    └── style_login.css
```

> 📌 *Snapshot del diagnóstico inicial (2026-08-25). Al 2026-09-23, `css/pages/` tiene 28 módulos y `css/print/` 4 — todos enlazados desde su PHP (cobertura de `<link>` verificada).*

### Problema detectado

> 📊 **Re-meditado el 2026-09-23** (169 PHP analizados, fuera de `vendor/`, `libs/`, `fpdf/`, `cache/` y `backups/`). Reemplaza al diagnóstico inicial del 2026-08-25.

**Bloques `<style>` embebidos: solo quedan 7 archivos** (al inicio eran 48 archivos con `<style>` y/o `style=`):

| Archivo | Motivo | Estado |
|---|---|---|
| `pages/vista_previa_ticket_cuota.php` | ancho de papel calculado por PHP | ✅ intencional (dinámico) |
| `pages/vista_previa_ticket_devolucion.php` | ancho de papel calculado por PHP | ✅ intencional (dinámico) |
| `pages/consulta_consignaciones_remota.php` | padding por sesión PHP | ✅ intencional (dinámico) |
| `pages/vista_recibo.php` | 2 reglas con valores PHP dinámicos | ✅ intencional (dinámico) |
| `procesos/verificar_cajas_historicas.php` | `echo "<style>"` dinámico | ✅ intencional (dinámico) |
| `core/Router.php` (línea 382) | página de error del router | ⚠️ **nuevo — no contemplado** (scopear con clase en `body`) |
| `pages/components/selector_empresa.php` (línea 28) | componente nuevo | ⚠️ **nuevo — no contemplado** |

**Atributos `style=` inline: 1.433 en 75 archivos PHP** (desglose por directorio y top de deudas en la **FASE 5**) **+ 76 en 4 archivos JS** (no contemplados en el diagnóstico original).

---

## 🏗️ Arquitectura CSS Objetivo

```
css/
├── style.css                   ← Master stylesheet (solo @imports)
│
├── base/
│   ├── variables.css           ← [NUEVO] CSS custom properties (:root)
│   ├── reset.css               ← [NUEVO] Extraído de base.css
│   └── typography.css          ← [NUEVO] Extraído de base.css
│
├── layout/
│   ├── base.css                ← (renombrado desde base.css)
│   ├── layout_components.css   ← (existente, revisado)
│   └── responsive.css          ← (existente, revisado)
│
├── components/
│   ├── forms_buttons.css       ← (existente, revisado)
│   ├── tables.css              ← (existente, revisado)
│   ├── modals_alerts.css       ← (existente, revisado)
│   ├── autocomplete.css        ← (existente)
│   ├── utilities.css           ← (existente)
│   ├── sidebar.css             ← (existente)
│   └── topbar.css              ← (existente)
│
├── pages/
│   ├── dashboard.css           ← [NUEVO] Extraído de dashboard.php
│   ├── ventas.css              ← [NUEVO] Extraído de ventas.php
│   ├── ventarapida.css         ← [NUEVO] Extraído de ventarapida.php
│   ├── compras.css             ← (existente + revisado con compras.php)
│   ├── compras_rapidas.css     ← [NUEVO] Extraído de compras_rapidas.php
│   ├── abm_clientes.css        ← [NUEVO] Extraído de abm_clientes.php
│   ├── abm_productos.css       ← [NUEVO] Extraído de abm_productos.php
│   ├── abm_empresas.css        ← [NUEVO] Extraído de abm_empresas.php + abm_empresa.php
│   ├── abm_proveedores.css     ← [NUEVO] Extraído de abm_proveedores.php
│   ├── cuentas_corrientes.css  ← [NUEVO] Extraído de cuentas_corrientes*.php
│   ├── caja_dashboard.css      ← [NUEVO] Extraído de caja_dashboard.php
│   ├── cierre_caja.css         ← [NUEVO] Extraído de cierre_caja.php + cerrar_cajas_historicas.php
│   ├── reportes.css            ← [NUEVO] Extraído de reportes_*.php + resumen_ventas.php
│   ├── cobro_cuotas.css        ← [NUEVO] Extraído de cobro_cuotas.php
│   ├── configuracion.css       ← [NUEVO] Extraído de configuracion*.php
│   ├── presupuestos.css        ← [NUEVO] Extraído de presupuestos.php
│   ├── usuarios.css            ← [NUEVO] Extraído de usuarios.php
│   ├── licencia.css            ← [NUEVO] Extraído de licencia.php
│   ├── backup.css              ← [NUEVO] Extraído de backup.php
│   ├── anulaciones.css         ← [NUEVO] Extraído de anulaciones.php
│   ├── consignaciones.css      ← [NUEVO] Extraído de consignacion_reporte.php + consulta_consignaciones_remota.php
│   ├── ctacte_proveedores.css  ← [NUEVO] Extraído de ctacte_proveedores.php
│   ├── verificar_modulos.css   ← [NUEVO] Extraído de verificar_modulos.php + verificar_cajas_historicas.php
│   └── misc.css                ← [NUEVO] Páginas menores (perfil, infosesion, 404, etc.)
│
├── print/
│   ├── ticket_print.css        ← (existente)
│   ├── temptocketprint.css     ← (existente, pendiente renombrar)
│   ├── imprimir_presupuesto.css← [NUEVO] Extraído de imprimir_presupuesto.php
│   └── vista_recibo.css        ← [NUEVO] Extraído de vista_recibo.php + vistas ticket
│
└── login/
    └── style_login.css         ← (existente)
```

---

## 📋 Fases de Implementación

### ✅ FASE 0 — Preparación (Prerequisitos)
> Duración estimada: **1 hora** — ✅ **COMPLETADA**

- [x] Hacer commit/backup del estado actual del repositorio
- [x] Crear la estructura de carpetas dentro de `css/`:
  - `css/base/`, `css/layout/`, `css/components/`, `css/pages/`, `css/print/`, `css/login/`
- [x] Mover archivos existentes a las subcarpetas correspondientes (sin modificar contenido)
- [x] Actualizar `style.css` con los nuevos paths de `@import` para los archivos movidos
- [x] Verificar que el sitio carga correctamente tras la reorganización

---

### 🔵 FASE 1 — Extracción de Variables CSS Globales
> Duración estimada: **2 horas** — 🟡 **EN PROGRESO (parcial)** (2026-09-23: `variables.css` con 52 tokens y **410 usos `var(--)`** activos en `css/`; **no existe commit de revert** en el historial git — la migración NO se revirtió, quedó a medias. Pendiente completar la migración hex→`var()` con validación visual, ver también FASE 6)

**Problema:** Cada archivo PHP repite las mismas variables de color/tema oscuro (ej: `#121212`, `#1e1e1e`, `#00bcd4`, `#e0e0e0`).

**Tareas:**
- [x] Crear `css/base/variables.css` con todas las CSS custom properties
- [x] Reemplazar colores hardcodeados en archivos CSS existentes por las variables  🟡 Parcial — 29/08/2026: ~558 reemplazos en 33 archivos CSS (hex→`var()` solo para tonos con token exacto). Medición 23/09/2026: **410 usos `var(--)` activos** y **~1.558 ocurrencias hex fuera de `variables.css`** (1.610 hex totales − 52 del propio archivo de tokens), en su mayoría arrastrados por los módulos `pages/` extraídos con los estilos originales — candidatos a nuevos tokens en FASE 6
- [x] Importar `variables.css` como primera línea en `style.css`
- [x] Documentar cada variable con un comentario de propósito

**Resultado esperado:**
```css
/* css/base/variables.css */
:root {
    --bg-primary:    #121212;
    --bg-secondary:  #1a1a1a;
    --bg-card:       #1e1e1e;
    --accent:        #00bcd4;
    --accent-hover:  #0097a7;
    --text-primary:  #e0e0e0;
    --text-muted:    #888888;
    --border-color:  #333333;
    --success:       #4caf50;
    --warning:       #ff9800;
    --danger:        #f44336;
    --info:          #2196f3;
}
```

---

### 🔵 FASE 2 — Extracción de Páginas de Alta Densidad
> Duración estimada: **1–2 días** — ✅ **COMPLETADA** (2026-08-29 — 6/6 páginas; ver Registro de avances. Cabecera corregida el 2026-09-23: decía "EN PROGRESO (2/6)")

Archivos con más de 50 usos de `style=` o bloques `<style>` grandes:

#### 2.1 `pages/ventas.php` → `css/pages/ventas.css` ✅ HECHO
- Bloque `<style>` en línea 377 (~150 líneas): extraído a `css/pages/ventas.css`
- 86 atributos `style=` inline: ⏳ aún pendiente (FASE 5)
- `<link>` a `css/pages/ventas.css` agregado en `ventas.php`
- Clases objetivo: `.venta-grid`, `.total-box`, `.vuelto-box`, `.table-full`, `.input-field` (dark)

#### 2.2 `pages/abm_productos.php` → `css/pages/abm_productos.css` ✅ HECHO
- Bloque `<style>` en línea 193
- **120 atributos `style=` inline** (máxima densidad del proyecto)
- Crear clases utilitarias: `.text-right`, `.badge-stock`, `.col-precio`, etc.

#### 2.3 `pages/cuentas_corrientes.php` + `cuentas_corrientes_detalle.php` → `css/pages/cuentas_corrientes.css` ✅ HECHO
- Combinar ambos (comparten contexto)
- 62 + 53 = 115 atributos `style=` inline

#### 2.4 `pages/abm_clientes.php` → `css/pages/abm_clientes.css` ✅ HECHO
- Bloque `<style>` en línea 223
- 53 atributos `style=` inline

#### 2.5 `pages/abm_proveedores.php` → `css/pages/abm_proveedores.css` ✅ HECHO
- Bloque `<style>` en línea 131
- 54 atributos `style=` inline

#### 2.6 `pages/ctacte_proveedores.php` → `css/pages/ctacte_proveedores.css` ✅ HECHO
- Bloque `<style>` en línea 41
- 51 atributos `style=` inline

---

### 🔵 FASE 3 — Extracción de Páginas de Media Densidad
> Duración estimada: **2–3 días** — ✅ **COMPLETADA** (2026-08-29 — ver registro de avances)

| Archivo fuente | CSS destino | `style=` count | Estado |
|---|---|---|---|
| `dashboard.php` (¡2 bloques!) | `css/pages/dashboard.css` | 40 | ✅ HECHO (2 bloques extraídos + `<link>` en dashboard.php) |
| `ventarapida.php` | `css/pages/ventarapida.css` | 14 | ✅ HECHO |
| `backup.php` | `css/pages/backup.css` | 46 |
| `cobro_cuotas.php` | `css/pages/cobro_cuotas.css` | 37 |
| `anulaciones.php` | `css/pages/anulaciones.css` | 37 |
| `presupuestos.php` | `css/pages/presupuestos.css` | 36 |
| `caja_dashboard.php` | `css/pages/caja_dashboard.css` | 33 |
| `resumen_ventas.php` | `css/pages/reportes.css` | 27 |
| `reporte_movimientos_productos.php` | `css/pages/reportes.css` | 25 |
| `abm_empresas.php` + `abm_empresa.php` | `css/pages/abm_empresas.css` | 19 + 38 |
| `usuarios.php` | `css/pages/usuarios.css` | 18 |
| `reportes_inventario.php` | `css/pages/reportes.css` | 18 |
| `abm_permisos_usuarios.php` | `css/pages/usuarios.css` | 15 |
| `pagos_ctacte.php` | `css/pages/cuentas_corrientes.css` | 15 |
| `reporte_cuotas.php` | `css/pages/reportes.css` | 14 |
| `cerrar_cajas_historicas.php` | `css/pages/cierre_caja.css` | 16 |
| `actualizaciones.php` | `css/pages/misc.css` | 14 |
| `cierre_caja.php` | `css/pages/cierre_caja.css` | 13 |
| `vista_recibo.php` | `css/print/vista_recibo.css` | 12 |
| `sidebar.php` | `css/components/sidebar.css` | 12 |
| `licencia.php` | `css/pages/licencia.css` | 11 |
| `abm_proveedores_autorizados.php` | `css/pages/abm_proveedores.css` | 11 |
| `imprimir_presupuesto.php` | `css/print/imprimir_presupuesto.css` | 10 |
| `facturacion_arca.php` | `css/pages/misc.css` | 10 |
| `verificar_cajas_historicas.php` | `css/pages/verificar_modulos.css` | 10 |

---

### 🔵 FASE 4 — Extracción de Páginas de Baja Densidad
> Duración estimada: **1 día** — ✅ **COMPLETADA** (2026-08-29 — ver registro de avances)

| Archivo fuente | CSS destino | `style=` count |
|---|---|---|
| `configuracion_intereses.php` | `css/pages/configuracion.css` | 7 |
| `compras_rapidas.php` | `css/pages/compras_rapidas.css` | 6 |
| `historial_compras.php` | `css/pages/compras.css` | 6 |
| `reportes_financieros.php` | `css/pages/reportes.css` | 6 |
| `topbar.php` | `css/components/topbar.css` | 5 |
| `perfil.php` | `css/pages/misc.css` | 4 |
| `vista_previa_ticket_devolucion.php` | `css/print/vista_recibo.css` | 4 |
| `reparar_caja_total.php` | `css/pages/misc.css` | 3 |
| `configuracion.php` | `css/pages/configuracion.css` | 3 |
| `vista_previa_ticket.php` | `css/print/vista_recibo.css` | 3 |
| `vista_previa_ticket_cuota.php` | `css/print/vista_recibo.css` | 3 |
| `infosesion.php` | `css/pages/misc.css` | 2 |
| `movimiento_manual.php` | `css/pages/misc.css` | 2 |
| `abrir_caja.php` | `css/pages/misc.css` | 1 |
| `404.php` | `css/pages/misc.css` | 1 |

---

### 🔵 FASE 5 — Eliminación de Atributos `style=` Inline
> Duración estimada: **3–4 días** — ⬜ **Pendiente** (medición real 2026-09-23)

**Alcance real (re-meditado sobre el código):**

| Directorio | Archivos | `style=` |
|---|---|---|
| `pages/` | 57 | 1.306 |
| `ajax/` | 11 | 96 |
| `funciones/` | 2 | 25 |
| `core/` | 2 | 2 |
| `procesos/` | 2 | 3 |
| **Total PHP** | **75** | **1.433** |
| **JavaScript** (`ventas.js` 40, `ventarapida.js` 16, `presupuestos.js` 11, `compras.js` 9) | 4 | **76** |

**Top de deudas:** `abm_productos.php` (169), `ventas.php` (90), `cuentas_corrientes.php` (62), `cuentas_corrientes_detalle.php` (53), `abm_proveedores.php` (52), `ctacte_proveedores.php` (51), `abm_clientes.php` (50), `backup.php` (47), `dashboard.php` (45), `consignaciones_ingreso.php` (42), `abm_empresa.php` (42).

> ⚠️ El conteo original del roadmap (53 PHP / 1.189 `style=` en `pages/`) quedó superado: entraron páginas nuevas después del diagnóstico (`consignaciones_ingreso.php` 42, `consultar_presupuestos.php` 19, `reparar_cierres_fondo_inicial.php` 17, `comprobantes_externos.php` 5) y ahora también aplican `ajax/`, `funciones/`, `core/`, `procesos/` y los `style=` generados desde JavaScript al renderizar filas/estados.

Esta es la fase más laboriosa. Para cada atributo `style=` inline:

1. **Identificar el patrón** — ¿Es un estilo único o se repite en el mismo archivo?
2. **Crear una clase** en el módulo CSS correspondiente
3. **Reemplazar** el atributo `style="..."` por `class="nombre-clase"`
4. Si el valor es **dinámico** (ej: `style="width: <?= $porcentaje ?>%"`) → mantener solo esa propiedad dinámica y extraer el resto

**Estrategia para valores dinámicos:**
```html
<!-- ANTES -->
<div style="width: <?= $pct ?>%; background: #1e1e1e; border-radius: 8px;">

<!-- DESPUÉS -->
<div class="progress-bar-inner" style="width: <?= $pct ?>%">
```

**Clases utilitarias a crear:**
- `display: none` → `.d-none`
- `display: block` → `.d-block`
- `text-align: right` → `.text-right`
- `text-align: center` → `.text-center`
- `color: #4caf50` → `.text-success`
- `color: #f44336` → `.text-danger`
- `font-weight: bold` → `.fw-bold`

---

### 🔵 FASE 6 — Revisión y Consolidación de CSS Existentes
> Duración estimada: **1 día**

- [ ] Revisar `compras.css` (5.7KB) — posibles duplicaciones con lo extraído en fases anteriores
- [ ] Revisar `sidebar.css` (14.9KB) y `topbar.css` (7.1KB) — extraer colores hardcodeados a variables (medido 23/09: `sidebar.css` 84 hex / 0 `var()`; `topbar.css` 50 hex / 0 `var()`)
- [ ] Eliminar o unificar `cee_temp.css` y `temptocketprint.css` si son archivos temporales/obsoletos
  - `cee_temp.css` — verificado 2026-09-23: **0 referencias** en todo el proyecto → seguro para eliminar
  - `temptocketprint.css` — verificado 2026-09-23: aún lo enlazan `vista_previa_ticket.php`, `vista_previa_ticket_cuota.php`, `vista_previa_ticket_devolucion.php` y `vista_recibo.php` → migrar esos `<link>` a `ticket_print.css` / `vista_recibo.css` antes de eliminar
- [ ] Completar migración hex→`var()` pendiente de FASE 1 (~1.558 ocurrencias hex fuera de `variables.css`)
- [ ] Unificar reglas duplicadas entre módulos usando las variables de FASE 1
- [ ] Verificar que `login.php` referencia correctamente `style_login.css`

---

### 🔵 FASE 7 — Carga Condicional por Página
> Duración estimada: **4–6 horas**

En lugar de cargar `style.css` (todos los módulos) en cada página, implementar carga condicional:

```php
// En core/helpers.php
function page_css(array $extras = []): string {
    $base = '<link rel="stylesheet" href="' . url('/css/style.css') . '">';
    foreach ($extras as $file) {
        $base .= '<link rel="stylesheet" href="' . url("/css/$file") . '">';
    }
    return $base;
}
```

```php
// En ventas.php — reemplazar el <link> actual
<?= page_css(['pages/ventas.css']) ?>
```

- [ ] Implementar helper `page_css()` en `core/helpers.php` — verificado 2026-09-23: **no existe todavía** (solo está esta propuesta en el roadmap)
- [ ] Actualizar cada archivo PHP para usar carga condicional
- [ ] Verificar que los archivos de impresión (`print/`) se cargan correctamente
- [ ] Unificar cache-busting — verificado 2026-09-23: solo **6 PHP** usan `style.css?v=filemtime` y **42 no** (riesgo de CSS cacheado tras deploy)
- [ ] Pages sin módulo enlazado — `consultar_presupuestos.php` (19 `style=`, carga `style.css` pero **no** `pages/presupuestos.css`), `reparar_caja_total.php` (3 `style=`, no enlaza `style.css` ni módulo propio), `sidebar.php` (12) y `topbar.php` (3) → destino a definir en FASE 5
- [ ] Validar visualmente `404.php` e `infosesion.php`: cargan **solo** `pages/misc.css`, sin el bundle global `style.css`

---

### 🔵 FASE 8 — Auditoría Final y Documentación
> Duración estimada: **2–3 horas**

- [ ] Ejecutar búsqueda global de `<style>` — debe retornar 0 resultados en `pages/`
- [ ] Ejecutar búsqueda de `style=` dinámicos restantes — documentar cada caso como intencional
- [ ] Actualizar comentario de índice en `style.css` con la nueva estructura de carpetas
- [ ] Crear `css/README.md` documentando cada módulo y su propósito
- [ ] Verificar visualmente todas las páginas (especialmente las de impresión)
- [ ] Commit final con tag `v-css-modular`

---

## 📁 Archivos PHP Sin Estilos (No requieren acción)

- `buscar_cliente_ajax.php`
- `buscar_producto_ajax.php`
- `buscar_producto_codigo_ajax.php`
- `cron_dolar.php`
- `generar_pdf_*.php` (todos los generadores de PDF)
- `guardar_presupuesto_backend.php`
- `obtener_venta_detalle_ajax.php`
- `procesar_cierre.php`
- `procesar_factura_arca.php`

---

## ⚠️ Consideraciones y Riesgos

### Atributos `style=` dinámicos (PHP)
Algunos `style=` contienen valores calculados por PHP:
```html
<div style="width: <?= $porcentaje ?>%">
<span style="color: <?= $color_semaforo ?>">
```
Estos **no pueden** eliminarse completamente. La estrategia es extraer las propiedades estáticas y conservar solo la propiedad dinámica.

### Reglas globales (`body`, `*`) en módulos de páginas
Al importar los módulos de `pages/` en el bundle global (`style.css`), las reglas dirigidas al `body` o al selector universal de una página específica (ej. `404.php`, error de sesión) afectan a **todas** las páginas. Solución aplicada en FASE 4: scopear con clase en el `<body>` (`body.sys-error`, `body.page-404`). **Regla para FASE 5/6:** ninguna página debe aportar selectores `body`, `html` o `*` sin scopear al bundle global.

### `!important` en exceso
Varios bloques `<style>` usan `!important` para sobreescribir Bootstrap/libs. Al extraer a CSS modular, revisar si siguen siendo necesarios o si el orden de carga CSS puede resolverlo.

### Duplicación de clases
Clases como `.card`, `.btn`, `.table-full` aparecen redefinidas en múltiples archivos. En la fase 5, consolidarlas en el módulo global apropiado y eliminar duplicados.

---

## 📈 Métricas de Progreso

> ⏰ **Última actualización:** 2026-09-23 — Auditoría de consistencia: todos los números re-meditidos sobre el código real (ver Registro de avances).

| Fase | Estado | Archivos impactados |
|---|---|---|
| FASE 1 — Variables CSS | 🟡 En progreso (parcial) | `variables.css` (52 tokens) + 410 usos `var(--)` activos; **sin commit de revert** en el historial git; ~1.558 ocurrencias hex fuera de `variables.css` — migración a completar con validación visual (ver FASE 6) |
| FASE 2 — Alta densidad | ✅ Completada | 6/6 archivos refactorizados |
| FASE 3 — Media densidad | ✅ Completada | 22/22 archivos refactorizados |
| FASE 4 — Baja densidad | ✅ Completada | 15/15 archivos (12 lote + 3 con PHP dinámico; 3 sin bloque `<style>`) |
| `<style>` embebidos | ✅ Casi completo | Quedan 7: 5 dinámicos intencionales + 2 nuevos sin migrar (`core/Router.php`, `pages/components/selector_empresa.php`) |
| FASE 5 — Inline `style=` | 🟡 En progreso | **Piloto `abm_productos.php` COMPLETADO (2026-09-23):169 →1 `style=`** (queda1 dinámico/JS-intencional). Total: **74 PHP / 1.265 `style=`** (`pages/` 1.138, `ajax/` 96, `funciones/` 25, `core/` 2, `procesos/` 2) + **76 en 4 JS**. Nuevas utilitarias globales en `utilities.css` |
| FASE 6 — Consolidación | ⬜ Pendiente | 16 CSS existentes (`cee_temp.css` verificado sin referencias → eliminar) |
| FASE 7 — Carga condicional | 🟡 En progreso | `@import` de `pages/*` eliminados y cada PHP enlaza su módulo (31/08/2026); 32/32 módulos con cobertura verificada; falta `page_css()` y unificar `?v=filemtime` (6 con / 42 sin) |
| FASE 8 — Auditoría | ⬜ Pendiente | Proyecto completo |

**Total estimado:** ~10–12 días de trabajo (refactoring puro, sin regresiones)

---

## ✅ Registro de avances
### 2026-09-23 — FASE 5: piloto `abm_productos.php` completado (169 → 1 `style=`)
- **Resultado:** `pages/abm_productos.php` pasó de **169 atributos `style=` a1** (el único restante es `style="display: none;"` en `#row_comision_multiple`, controlado por JS vía `style.display = '' / 'none'` — dinámico intencional). `php -l` sin errores; verificado:0 tags con doble `class=`, todas las clases usadas existen en el CSS que carga la página.
- **Utilitarias globales nuevas** en `css/components/utilities.css` (todas verificadas sin colisiones): `.d-none`, `.d-flex`, `.flex-1/2`, `.gap-8/10/15`, `.mb-10/15/20`, `.mt-5/15/20/30`, `.ml-auto`, `.text-center`, `.ta-right`, `.text-nowrap`, `.text-muted`, `.text-faint`, `.text-accent`, `.text-orange`, `.text-violet`, `.text-amber`, `.fw-bold`, `.w-30/68/160/180/420`, `.is-disabled`.
- **Clases de página nuevas** en `css/pages/abm_productos.css` (~45): modales (`.modal-head`, `.modal-x`, `.modal-label`, `.modal-actions-end`, variantes `.modal-content` scopeadas por `#id`), botones (`.btn-violet/amber/cyan/wide/compact/plus/tall/orange/gold/violet-block/lg-ghost/lg-accent`), form (`.select-row`, `.select-grow`, `.card-center`, `.hr-line`, `.flex-row-end`, `.chk-label/box`, `.hint`, `.form-footer`), tablas (`.th-cell`, `.td-cell`, `.table-frame`, `#tablaMultiples`, `.tr-line`, `.cell-actions`, `.input-cell`, `.inp-sm`), badges (`.tag-warn/ok`), empty state, radios PDF, `.alert-violet`, `.filtro-condicional`.
- **Casos dinámicos PHP (FASE 5 methodology):** paginación — los4 `style` condicionales de «Primero/Ant./Sig./Último» se convirtieron en clase condicional `is-disabled` inyectada en el `class` existente; el `style` de la página activa (`background-color:#00bcd4...`) se eliminó por **redundante** con `.pagination-nav .btn-info` (reglas idénticas ya presentes en el módulo CSS).
- **Overrides de especificidad documentados:** `.flex-row > .flex-2` (gana a `.flex-row > div`), `.alert.mb-15` (gana a `.alert{mb:20px}`).
- **Eliminación segura de `display: none`:** en los3 modales (redundante con `.modal`) y en `.filtro-condicional` (nueva regla CSS; JS usa `style.display='block'/'none'` explícitos).
- **Nota:** el script de reemplazo reescribió el archivo sin BOM UTF-8 (antes tenía `﻿` antes de `<?php`; su eliminación es inocua/beneficiosa — no afecta `php -l` ni la salida).
- **Pendiente FASE 5:** verificación visual manual de la página (recomendada antes de escalar a los6 archivos de alta densidad restantes: `ventas`, `cuentas_corrientes`, `cuentas_corrientes_detalle`, `abm_proveedores`, `ctacte_proveedores`, `abm_clientes`).

### 2026-09-23 — Auditoría de consistencia del roadmap (discrepancias corregidas)
- Todos los números re-meditidos sobre el código (169 PHP analizados, fuera de `vendor/`, `libs/`, `fpdf/`, `cache/` y `backups/`); árbol de diagnóstico, métricas y cabeceras de fase actualizados.
- **`<style>` embebidos:** de los 48 archivos originales quedan **7**: 5 intencionales dinámicos (`vista_previa_ticket_cuota`, `vista_previa_ticket_devolucion`, `consulta_consignaciones_remota`, `vista_recibo`, `procesos/verificar_cajas_historicas`) + **2 nuevos no contemplados**: `core/Router.php` (página de error, línea 382) y `pages/components/selector_empresa.php` (componente, línea 28).
- **`style=` inline:** medición real **1.433 en 75 PHP** (no 1.189 en 53): `pages/` 1.306/57, `ajax/` 96/11, `funciones/` 25/2, `core/` 2/2, `procesos/` 3/2. Además **76 `style=` en 4 JS** (`ventas.js` 40, `ventarapida.js` 16, `presupuestos.js` 11, `compras.js` 9) — incorporados al alcance de FASE 5 (no estaban contemplados).
- **FASE 1:** eliminada la fila contradictoria de métricas. Estado real: **410 `var(--)` activos** y **no hay commit de revert** en el historial git (la migración no se revirtió, quedó parcial); ~1.558 ocurrencias hex fuera de `variables.css`.
- **FASE 2:** cabecera desactualizada "EN PROGRESO (2/6)" corregida a **COMPLETADA** (el registro de avances y los commits ya la daban por hecha); subsecciones 2.2–2.6 marcadas ✅.
- **FASE 3:** `ventarapida.php` marcado ✅ (estaba ⏳ pero fue extraído en FASE 3).
- **Cobertura de módulos verificada:** los 32 CSS de `css/pages/` y `css/print/` están enlazados desde al menos un PHP — 0 huérfanos.
- **FASE 6 verificada:** `cee_temp.css` con 0 referencias en todo el proyecto (listo para eliminar); `temptocketprint.css` sigue enlazado por las 4 vistas de ticket/recibo (migrar antes de eliminar).
- **FASE 7 verificada:** helper `page_css()` inexistente; cache-busting inconsistente (6 PHP con `?v=filemtime` / 42 sin); `consultar_presupuestos.php` no enlaza `pages/presupuestos.css`; `reparar_caja_total.php` sin `style.css` ni módulo; `404.php` e `infosesion.php` cargan solo `misc.css`.
- **FASE 4:** `compras.php` removido de "Archivos PHP Sin Estilos" (tiene 5 `style=` → FASE 5).

### 2026-08-31 — Corrección de regresión visual + FASE 7 (carga condicional)
- **Problema reportado:** la mayoría de las páginas quedaron con elementos desordenados/desalineados tras la extracción de estilos (FASES 2-4).
- **Causa raíz:** los 25 módulos de `css/pages/` quedaron importados **globalmente** en `style.css` vía `@import`. Al convivir todos los módulos en cada página, clases duplicadas entre páginas (`.filtros-bar` definida distinta en `abm_clientes`, `abm_empresas`, `cuentas_corrientes` y `resumen_ventas`; también `.card`, `.table-full`, etc.) y reglas `body`/`*` sin scopear (`abm_proveedores.css`, `consignaciones.css`, `abm_empresas.css`, `presupuestos.css`) pisaban los estilos de las demás páginas. Este riesgo ya estaba documentado en "⚠️ Consideraciones y Riesgos".
- **Corrección aplicada (FASE 7):**
  - Eliminados los `@import url('pages/*.css')` de `css/style.css`; cada PHP ya enlaza su módulo con `<link>` después del bundle global (se verificó cobertura: todos los módulos tienen su `<link>`).
  - `abm_clientes.php`: agregado `<link>` a `css/pages/abm_base.css` (sus reglas base de tabla/inputs/labels viven ahí y ningún módulo las incluía).
  - `compras.php` e `historial_compras.php`: link roto `css/compras.css` → `css/pages/compras.css` (regresión de v2.9).
  - `abm_base.css` NO se enlaza en `abm_empresas`/`abm_empresa`/`abm_permisos_usuarios` (sus bloques originales no contenían esas reglas de tabla; se verificaría contra HEAD).
- Validado: `php -l` sin errores en los 3 PHP editados; script de verificación sin links CSS rotos en `pages/`.
- Pendiente de FASE 7: helper `page_css()` opcional (los `<link>` directos ya resuelven la carga condicional).


### 2026-08-29 — FASE 4 completada
- 12 archivos extraídos en lote:
  - `configuracion_intereses.php` + `configuracion.php` → `css/pages/configuracion.css` ✅
  - `compras_rapidas.php` → `css/pages/compras_rapidas.css` ✅
  - `reportes_financieros.php` + `reporte_cierres.php` → `css/pages/reportes.css` (append) ✅
  - `perfil.php`, `infosesion.php`, `movimiento_manual.php`, `abrir_caja.php`, `404.php` → `css/pages/misc.css` (append) ✅
  - `consignacion_reporte.php` → `css/pages/consignaciones.css` ✅
  - `vista_previa_ticket.php` → `css/print/vista_recibo.css` (append) ✅
- 3 archivos con valores dinámicos PHP en el `<style>` (extracción parcial, regla dinámica conservada inline):
  - `vista_previa_ticket_devolucion.php` ✅
  - `vista_previa_ticket_cuota.php` → estático a `css/print/vista_recibo.css` ✅
  - `consulta_consignaciones_remota.php` → estático a `css/pages/consignaciones.css` ✅
- Sin bloque `<style>` (no requieren acción; `style=` → FASE 5): `historial_compras.php`, `topbar.php`, `reparar_caja_total.php`.
- `@import` agregados: `pages/configuracion.css`, `pages/compras_rapidas.css`, `pages/consignaciones.css`.
- Sintaxis PHP verificada con `php -l` (sin errores). Solo quedan 4 `<style>` inline intencionales (dinámicos) en `pages/`.

### 2026-08-29 — FASE 3 completada
- 22 archivos de media densidad refactorizados en lote:
  - `ventarapida.php` → `css/pages/ventarapida.css` ✅
  - `backup.php` → `css/pages/backup.css` ✅
  - `anulaciones.php` → `css/pages/anulaciones.css` ✅
  - `caja_dashboard.php` → `css/pages/caja_dashboard.css` ✅
  - `reporte_movimientos_productos.php`, `reportes_inventario.php`, `reporte_cuotas.php` → `css/pages/reportes.css` ✅
  - `abm_empresas.php` + `abm_empresa.php` → `css/pages/abm_empresas.css` ✅
  - `usuarios.php` + `abm_permisos_usuarios.php` → `css/pages/usuarios.css` ✅
  - `pagos_ctacte.php` → `css/pages/cuentas_corrientes.css` (append) ✅
  - `cierre_caja.php` + `cerrar_cajas_historicas.php` → `css/pages/cierre_caja.css` ✅
  - `verificar_modulos.php` + `verificar_cajas_historicas.php` → `css/pages/verificar_modulos.css` ✅
  - `actualizaciones.php` + `facturacion_arca.php` → `css/pages/misc.css` ✅
  - `licencia.php` → `css/pages/licencia.css` ✅
  - `abm_proveedores_autorizados.php` → `css/pages/abm_proveedores.css` (append) ✅
  - `imprimir_presupuesto.php` → `css/print/imprimir_presupuesto.css` ✅
  - `vista_recibo.php` → `css/print/vista_recibo.css` ✅ (2 reglas con valores dinámicos PHP se conservan inline)
- `sidebar.php`: no tiene bloque `<style>` (sus `style=` corresponden a FASE 5).
- `@import` agregados en `style.css` para los nuevos módulos de `pages/`.
- Sintaxis PHP verificada con `php -l` (sin errores).
### 2026-08-29 — Fase 2 completada (registro previo)
- **FASE 2:** Los 6 archivos de alta densidad fueron refactorizados:
  - `abm_productos.php` → `css/pages/abm_productos.css` ✅
  - `abm_proveedores.php` → `css/pages/abm_proveedores.css` ✅
  - `abm_clientes.php` → `css/pages/abm_clientes.css` ✅
  - `cuentas_corrientes.php` → `css/pages/cuentas_corrientes.css` ✅
  - `cuentas_corrientes_detalle.php` → `css/pages/cuentas_corrientes_detalle.css` ✅
  - `ctacte_proveedores.php` → `css/pages/ctacte_proveedores.css` ✅
- **FASE 3 (primeros 4):** `cobro_cuotas.php`, `presupuestos.php`, `resumen_ventas.php` refactorizados (completada luego, ver registro superior).
  - `cobro_cuotas.php` → `css/pages/cobro_cuotas.css` ✅
  - `presupuestos.php` → `css/pages/presupuestos.css` ✅
  - `resumen_ventas.php` → `css/pages/resumen_ventas.css` ✅
- **CSS imports agregados** en `style.css` para todos los nuevos módulos de pages/.
- **Nota:** Los atributos `style=` inline siguen pendientes (FASE 5).
