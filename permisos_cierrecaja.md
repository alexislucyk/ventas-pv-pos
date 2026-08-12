# Plan de Implementación: Autorización del Módulo "Cierre de Caja" por EMPRESA

> **Fecha:** 08/11/2026
> **Ámbito:** POS (`pos_dev` / `pos_prod`)
> **Objetivo (idea corregida):** autorizar el módulo **"Cierre de Caja" a nivel de empresa**.
> - Empresa **con** el módulo habilitado → opera **con cierres de caja** (flujo actual).
> - Empresa **sin** el módulo habilitado → opera **sin cierres de caja**: no se exige abrir caja, desaparecen los menús/páginas de caja y nadie puede ejecutar un cierre.

---

## 1. Aclaración del alcance

En la iteración anterior el plan se centró en el permiso **por usuario**; la idea real es un **interruptor por empresa** (master switch del módulo). Ambas capas conviven y son complementarias:

| Capa | Pregunta que responde | Mecanismo |
|---|---|---|
| **1. Por EMPRESA** (nueva) | ¿Esta empresa utiliza cierres de caja? | Flag `empresas.modulo_cierre_caja` |
| **2. Por USUARIO** (existente) | Estando habilitado, ¿qué usuarios pueden cerrar? | `modulos.id=13` + `permisos_usuario` / `permisos_rol` |

- La capa 2 **ya funciona** (verificado: módulo 13 "Cierre de Caja", la usuaria `yohana` lo tiene, `ventas` no).
- La capa 1 **no existe hoy**: todas las empresas están obligadas al mismo flujo de caja.

---

## 2. Estado actual verificado (código + BD)

- **Multi-empresa:** `empresas(id, nombre_fantasia, …, activa)`; el usuario pertenece a `usuarios.empresa_id` y la empresa activa se guarda en `$_SESSION['empresa_id']` (login, `ajax/cambiar_empresa.php`, `pages/abm_empresas.php`).
- **Flujo de caja obligatorio para todas las empresas:**
  1. `pages/infosesion.php` define `$paginas_requieren_caja = [ventas.php, compras.php, compras_rapidas.php, cobro_cuotas.php, anulaciones.php, movimiento_manual.php, caja_dashboard.php, cierre_caja.php]` → si la caja no está `ABIERTA` redirige a `abrir_caja.php`.
  2. `estado_caja` registra sesiones `ABIERTA`/`CERRADA` por empresa+sucursal (migración 28).
  3. Cierre: `cierre_caja.php` (formulario) → `procesar_cierre.php` (POST) → `cerrar_caja()` (función en `funciones/funciones_caja.php`). El POST ya está protegido con `require_permiso('pages/cierre_caja.php')`.
  4. Reporte: `reporte_cierres.php` (hoy sin módulo propio).
- **BD `empresas`:** NO existe ninguna columna de módulos/habilitaciones (verificado con `DESCRIBE empresas`).

**Conclusión:** no hay forma hoy de que una empresa opere sin el ciclo de apertura/cierre de caja.

---

## 3. Diseño elegido

Agregar el flag directamente en `empresas`:

```sql
modulo_cierre_caja TINYINT(1) NOT NULL DEFAULT 1
```

- `1` = módulo habilitado (comportamiento actual, valor por defecto).
- `0` = módulo deshabilitado → la empresa opera sin cierres.

**Justificación:**
- Es un único módulo a autorizar por empresa; una columna booleana sobre la PK es la lectura más simple y veloz.
- Se administra desde la pantalla de datos de empresa existente (`abm_empresa.php`) y desde la administración multi-empresa (`abm_empresas.php`).
- Alternativa futura más genérica (tabla `modulos_empresa`) se documenta en la sección 8; no se implementa salvo que se necesiten varios módulos por empresa.
---

## 4. Implementación

### Paso 1 — Migración `migrations/29_modulo_cierre_caja_empresa.sql` ✨ *IMPLEMENTADO — aplicada en pos_dev*

```sql
-- Migración: habilitación del módulo "Cierre de Caja" por empresa
-- 1 = habilitado (default) | 0 = operar sin cierres
-- Idempotente: no falla si la columna ya existe.

SET @existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
      AND COLUMN_NAME  = 'modulo_cierre_caja'
);
SET @sql = IF(@existe = 0,
    'ALTER TABLE `empresas` ADD COLUMN `modulo_cierre_caja` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1=cierre habilitado; 0=operar sin cierres'' AFTER `activa`',
    'SELECT ''La columna modulo_cierre_caja ya existe; no se realiza cambio.'' AS _info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

Las empresas existentes quedan `1` → **sin cambios de comportamiento**. ✅ Validado en `pos_dev`: la columna se creó y `SELECT modulo_cierre_caja FROM empresas WHERE id=1` devuelve `1`.

### Paso 2 — Helper de consulta en `pages/infosesion.php`

Agregar una función global (se ejecuta en todas las páginas porque `infosesion.php` se incluye siempre):

```php
/**
 * ¿La empresa actual tiene habilitado el módulo "Cierre de Caja"?
 * 1 = opera con cierres | 0 = opera sin cierres.
 */
if (!function_exists('empresa_cierre_caja_habilitado')) {
    function empresa_cierre_caja_habilitado() {
        global $pdo;
        $empresa_id = $_SESSION['empresa_id'] ?? null;
        if (!$empresa_id) {
            return true; // sin empresa, comportamiento conservador: flujo actual
        }
        try {
            $st = $pdo->prepare("SELECT modulo_cierre_caja FROM empresas WHERE id = :id");
            $st->execute([':id' => $empresa_id]);
            return (bool)(int)$st->fetchColumn();
        } catch (Exception $e) {
            return true; // ante error de lectura, no bloquear: flujo actual
        }
    }
}
```

> Nota: leer por PK en cada request es despreciable. Si se quiere cachear en sesión, hacerlo **clave por empresa** (`$_SESSION['empresa_cierre_caja_' . $empresa_id]`) y refrescarla al guardar el checkbox o en `ajax/cambiar_empresa.php`.

### Paso 3 — Guardas en `pages/infosesion.php`

Reemplazar el bloque "VALIDACIÓN DE ESTADO DE CAJA" (líneas 74-103) por:

```php
// ============================================
// VALIDACIÓN DE ESTADO DE CAJA (solo si la empresa usa cierres)
// ============================================
$modulo_cierre_caja = empresa_cierre_caja_habilitado();
$pagina_actual = basename($_SERVER['PHP_SELF']);

// 3a. Páginas del MÓDULO "Cierre de Caja": inaccesibles si la empresa NO lo tiene habilitado
$paginas_modulo_cierre_caja = [
    'abrir_caja.php', 'caja_dashboard.php', 'movimiento_manual.php',
    'cierre_caja.php', 'procesar_cierre.php', 'reporte_cierres.php',
    'cerrar_cajas_historicas.php', 'verificar_cajas_historicas.php'
];

if (in_array($pagina_actual, $paginas_modulo_cierre_caja) && !$modulo_cierre_caja) {
    if (strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'post') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error',
            'message' => 'Módulo de cierre de caja deshabilitado para esta empresa.']);
        exit();
    }
    header('Location: ' . URL_BASE . 'index.php');
    exit();
}

// 3b. Exigir caja ABIERTA únicamente si la empresa usa cierres
if ($modulo_cierre_caja && in_array($pagina_actual, $paginas_requieren_caja)) {
    require_once dirname(__FILE__) . '/../funciones/funciones_caja.php';
    $empresa_id = $_SESSION['empresa_id'] ?? null;
    $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
    if ($empresa_id && !caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
        $_SESSION['error_caja'] = 'La caja está cerrada. Debe abrirla antes de continuar.';
        header('Location: ' . URL_BASE . 'pages/abrir_caja.php');
        exit();
    }
}
```

### Paso 4 — Menú lateral `pages/sidebar.php`

En la sección "Facturación y Caja" (líneas 581-599), dejar **AFIP siempre visible** y ocultar los ítems de caja cuando la empresa no usa cierres:

```php
<?php if (tiene_permiso('pages/facturacion_arca.php')): ?>
    <a href=".../facturacion_arca.php" ...>Comprobantes AFIP</a>
<?php endif; ?>

<?php if (empresa_cierre_caja_habilitado()): ?>
    <?php if (tiene_permiso('pages/caja_dashboard.php')): ?>
        <a href=".../caja_dashboard.php" ...>Panel de Caja</a>
    <?php endif; ?>
    <?php if (tiene_permiso('pages/movimiento_manual.php')): ?>
        <a href=".../movimiento_manual.php" ...>Movimiento Manual</a>
    <?php endif; ?>
    <?php if (tiene_permiso('pages/cierre_caja.php')): ?>
        <a href=".../cierre_caja.php" ...>Cierre de Caja</a>
    <?php endif; ?>
    <?php if (tiene_permiso('pages/reporte_cierres.php')): ?>
        <a href=".../reporte_cierres.php" ...>Reporte de Cierres</a>
    <?php endif; ?>
<?php endif; ?>
```
### Paso 5 — Toggle en "Datos de Empresa" `pages/abm_empresa.php`

**Guardado (backend POST):** agregar el campo al `UPDATE empresas` (líneas 39-49) y capturarlo:

```php
$modulo_cierre_caja = isset($_POST['modulo_cierre_caja']) ? 1 : 0;

// (dentro del UPDATE)
//   ... vias = ...,
//   modulo_cierre_caja = :modulo_cierre_caja
//   WHERE id = :empresa_id
// (y agregar ':modulo_cierre_caja' => $modulo_cierre_caja al array de execute)
```

**Formulario (HTML):** agregar un bloque en la ficha de datos de la empresa:

```html
<div class="card">
  <label>
    <input type="checkbox" name="modulo_cierre_caja" value="1"
           <?php echo ($empresa['modulo_cierre_caja'] ?? 1) ? 'checked' : ''; ?>>
    Módulo "Cierre de Caja" habilitado para esta empresa
  </label>
  <p class="help-text">
    Si está habilitado, la empresa opera con cierres de caja (apertura y cierre).
    Si NO, opera sin cierres: no se exige abrir caja y las opciones de caja se ocultan.
  </p>
</div>
```

> `abm_empresa.php` ya lee la empresa con `SELECT *` (línea 197), así que el valor del checkbox estará disponible automáticamente.

### Paso 6 — Toggle en administración multi-empresa `pages/abm_empresas.php`

- **Crear empresa:** agregar columna `modulo_cierre_caja` al `INSERT INTO empresas` (líneas 105-112) con el valor del POST (`isset($_POST['modulo_cierre_caja']) ? 1 : 0`).
- **Editar empresa:** agregar columna al `UPDATE empresas` (líneas 127-136).
- **Formulario crear/editar:** mismo checkbox del Paso 5.
- **Listado:** columna "Cierres de Caja" (badge HABILITADO/DESHABILITADO) en la grilla para visibilidad de un vistazo.

### Paso 7 — Refrescar flag al cambiar de empresa

`ajax/cambiar_empresa.php` y `pages/abm_empresas.php` (cambio vía GET, líneas 28-40) solo setean `$_SESSION['empresa_id']`. Como el helper **lee de BD en cada request**, no requieren cambios obligatorios. Si se implementara cache por sesión, deben limpiar/actualizar la clave `empresa_cierre_caja_<id>` al conmutar de empresa.

### Paso 8 — (IMPLEMENTADO) Endurecimiento por usuario cuando el módulo está habilitado

- `pages/cierre_caja.php`: se agregó `require_permiso('pages/cierre_caja.php');` al inicio (reemplazó al `restringirPagina('developer')` comentado), de modo que usuarios sin el módulo "Cierre de Caja" no acceden siquiera al formulario por URL directa. ✅
- `pages/procesar_cierre.php`: ya exigía `require_permiso('pages/cierre_caja.php')` (sin cambios). ✅
- `pages/sidebar.php`: mantiene `tiene_permiso('pages/cierre_caja.php')` para el ítem de menú (además del bloqueo por empresa del Paso 4). ✅
- Cuando el módulo está **deshabilitado** por empresa, el Paso 3 **bloquea** todo el módulo antes, de modo que la capa por usuario no es lo único que protege. ✅

---

## 5. Comportamiento resultante

| Aspecto | Módulo HABILITADO | Módulo DESHABILITADO |
|---|---|---|
| Ventas, compras, anulaciones, cuotas | Exigen caja ABIERTA (actual) | Funcionan **sin** exigir apertura |
| Menú "Panel de Caja, Mov. Manual, Cierre, Reporte de Cierres" | Visible según permiso de usuario | **Oculto** |
| URL directa `cierre_caja.php`, `caja_dashboard.php`, etc. | Según permiso de usuario | **Redirige a `index.php`** |
| POST `procesar_cierre.php` | Según permiso de usuario | **403 JSON** |
| `estado_caja` / `cierres_caja` | Se usan con normalidad | No se generan (quedan los históricos) |
---

## 6. Manejo de datos al deshabilitar (empresa con caja abierta)

Si se deshabilita el módulo para una empresa que tiene una sesión de caja **ABIERTA** o movimientos `cerrado = 0` pendientes, los registros quedan "abiertos" para siempre (no se usan más). Recomendado ejecutar antes del cambio un cierre contable de regularización:

```sql
-- Cerrar la sesión de caja abierta de la empresa
UPDATE estado_caja
SET estado = 'CERRADA', fecha_cierre = NOW(), usuario_cierre = 'sistema (deshabilitación módulo)'
WHERE empresa_id = :empresa_id AND estado = 'ABIERTA';

-- Marcar como cerrados los movimientos pendientes de la empresa
UPDATE movimientos
SET cerrado = 1
WHERE empresa_id = :empresa_id AND cerrado = 0;

-- Generar un registro de cierre de regularización (opcional)
INSERT INTO cierres_caja (empresa_id, sucursal_id, fecha_cierre, usuario, ...)
SELECT empresa_id, sucursal_id, NOW(), 'sistema (deshabilitación)', ...
FROM movimientos WHERE empresa_id = :empresa_id GROUP BY empresa_id, sucursal_id;
```

> Si la empresa **nunca** usó cierres (recién creada con el módulo apagado), no hay datos que regularizar.

---

## 7. Plan de pruebas

| # | Escenario | Resultado esperado |
|---|---|---|
| 1 | Empresa A con `modulo_cierre_caja = 1` | Comportamiento actual sin cambios |
| 2 | Empresa A: caja cerrada → entrar a `ventas.php` | Redirige a `abrir_caja.php` (como hoy) |
| 3 | Empresa B con `modulo_cierre_caja = 0`, caja cerrada → `ventas.php`, `compras.php` | **Operan normalmente**, sin redirección a abrir caja |
| 4 | Empresa B → sidebar | No aparece Panel de Caja / Mov. Manual / Cierre / Reporte de Cierres; AFIP sí sigue |
| 5 | Empresa B → URL directa `pages/cierre_caja.php` (GET) | Redirige a `index.php` |
| 6 | Empresa B → POST a `pages/procesar_cierre.php` | **403 JSON** "módulo deshabilitado" |
| 7 | Empresa B → URL directa `pages/caja_dashboard.php`, `abrir_caja.php`, `movimiento_manual.php` | Redirige a `index.php` |
| 8 | Empresa B → `pages/abm_empresa.php` | Checkbox "Cierre de Caja" desmarcado y persistente |
| 9 | Conmutar empresa con el selector (B ↔ A) | Los menús/páginas cambian según el flag de cada una (sin recargar sesión) |
| 10 | Rehabilitar empresa B (`modulo_cierre_caja = 1`) | Vuelve a exigir apertura y mostrar opciones de caja |
| 11 | `developer` | Mantiene acceso total (bypass por usuario); el flag por empresa aplica igualmente a nivel de empresa |

#### Validación ejecutada en `pos_dev`

Se simuló el guard con `modulo_cierre_caja` de la empresa 1 en `0` y en `1` (el helper lee de BD por cada request). Resultados obtidos:

```
flag = 0 → empresa_cierre_caja_habilitado() => false
  GET  cierre_caja.php        => redirige index          ✅
  POST procesar_cierre.php    => 403 JSON                ✅
  GET  caja_dashboard.php     => redirige index          ✅
  GET  abrir_caja.php         => redirige index          ✅
  GET  movimiento_manual.php  => redirige index          ✅
  GET  reporte_cierres.php    => redirige index          ✅
  GET  ventas.php             => acceso OK (sin exigir apertura) ✅

flag = 1 → empresa_cierre_caja_habilitado() => true
  GET  ventas.php             => redirige a abrir_caja (como hoy) ✅
  GET  cierre_caja.php        => redirige a abrir_caja (como hoy) ✅
  GET  abrir_caja.php         => acceso OK ✅
```

`php -l` OK en: `infosesion.php`, `sidebar.php`, `cierre_caja.php`, `caja_dashboard.php`, `abm_empresa.php`, `abm_empresas.php`, `procesar_cierre.php`, `abrir_caja.php`, `movimiento_manual.php`, `reporte_cierres.php`.

---

## 8. Opcional a futuro

1. **Tabla genérica de módulos por empresa:** si más adelante se quieren autorizar otros módulos (p. ej. ARCA/AFIP, compras), reemplazar la columna por `modulos_empresa(empresa_id, modulo_id)` con la misma lógica de guarda. La columna actual es deliberadamente simple porque hoy solo hay un módulo a autorizar.
2. **Registro de auditoría del toggle:** guardar quién y cuándo habilitó/deshabilitó (por ejemplo en `cierres_caja_audit` o una tabla `auditoria_config`).
3. **Endurecimiento por usuario** (independiente de este plan): `require_permiso('pages/cierre_caja.php')` en `pages/cierre_caja.php` y botón condicional en `caja_dashboard.php`.

---

## 9. Resumen de cambios por archivo

| Archivo | Cambio |
|---|---|
| `migrations/29_modulo_cierre_caja_empresa.sql` | ➕ columna `modulo_cierre_caja` en `empresas` (default 1) |
| `pages/infosesion.php` | ➕ helper `empresa_cierre_caja_habilitado()`; guarda de caja condicional; bloqueo de páginas del módulo |
| `pages/sidebar.php` | ➕ ocultar ítems de caja cuando el módulo está deshabilitado |
| `pages/abm_empresa.php` | ➕ checkbox + UPDATE del flag |
| `pages/abm_empresas.php` | ➕ checkbox en crear/editar + columna "Cierres de Caja" en el listado + INSERT/UPDATE |
| `pages/cierre_caja.php` | ➕ `require_permiso('pages/cierre_caja.php')` por usuario (IMPLEMENTADO) |
| Tablas `estado_caja`, `cierres_caja`, `movimientos` | ✅ Sin cambios estructurales |

**Estado:** ✅ **IMPLEMENTADO** en el código y validado en `pos_dev` (lint OK en todos los archivos PHP; migración aplicada; simulación de guards con `modulo_cierre_caja = 0` y `= 1` confirma los resultados esperados — ver sección 7).
**Total: 1 migración + 5 archivos PHP.**
**Riesgo:** bajo. El `DEFAULT 1` preserva el comportamiento existente; nada cambia hasta que se marque una empresa con `0`.
**Aplicar en prod:** correr `migrations/29_modulo_cierre_caja_empresa.sql` (idempotente) y, si alguna empresa con caja abierta se deshabilita, aplicar el SQL de regularización (sección 6).
**Rollback:** `ALTER TABLE empresas DROP COLUMN modulo_cierre_caja;` y revertir los snippets PHP.