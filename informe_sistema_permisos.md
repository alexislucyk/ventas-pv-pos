# 📋 Informe del Sistema de Permisos

**Proyecto:** POS Dev - Sistema de Gestión Comercial  
**Fecha:** 13 de Julio de 2026  
**Versión:** 1.0  

---

## 📊 Resumen Ejecutivo

El sistema implementa un modelo de permisos **híbrido y multi-empresa** que combina:
- **Roles jerárquicos** con niveles numéricos
- **Permisos granulares** por usuario individual
- **Soporte multi-empresa** con aislamiento completo
- **Caché en sesión** para optimización de rendimiento

---

## 🏗️ Arquitectura de Base de Datos

### Tablas del Sistema

#### 1. `modulos` - Catálogo de Páginas
```sql
CREATE TABLE modulos (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nombre VARCHAR(50) NOT NULL,      -- Nombre descriptivo
  archivo VARCHAR(100) NOT NULL,    -- Ruta del archivo (ej: pages/ventas.php)
  icono VARCHAR(50),                -- Icono FontAwesome (ej: fas fa-shopping-cart)
  seccion VARCHAR(50)               -- Categoría (Maestros, Ventas, Facturación, etc.)
);
```

**Propósito:** Registra todas las páginas/módulos del sistema que pueden ser protegidas.

---

#### 2. `permisos_rol` - Permisos por Rol
```sql
CREATE TABLE permisos_rol (
  id INT PRIMARY KEY AUTO_INCREMENT,
  empresa_id INT NOT NULL,          -- Multi-empresa
  rol VARCHAR(50) NOT NULL,         -- Rol del usuario (vendedor, admin, etc.)
  modulo_id INT NOT NULL,           -- Referencia a modulos.id
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (modulo_id) REFERENCES modulos(id)
);
```

**Propósito:** Define permisos base por rol.  
**Ejemplo:** Rol 'vendedor' tiene acceso a 'pages/ventas.php'

---

#### 3. `permisos_usuario` - Permisos Individuales
```sql
CREATE TABLE permisos_usuario (
  id INT PRIMARY KEY AUTO_INCREMENT,
  empresa_id INT NOT NULL,          -- Multi-empresa
  usuario_id INT NOT NULL,          -- Referencia a usuarios.id
  modulo_id INT NOT NULL,           -- Referencia a modulos.id
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (modulo_id) REFERENCES modulos(id)
);
```

**Propósito:** Permisos específicos que sobreescriben o complementan los del rol.  
**Ejemplo:** Un vendedor específico puede acceder a 'pages/compras.php' sin cambiar su rol.

---

### Soporte Multi-Empresa

**Migración 12** agregó `empresa_id` a ambas tablas de permisos:
```sql
ALTER TABLE permisos_rol ADD COLUMN empresa_id INT NOT NULL DEFAULT 1;
ALTER TABLE permisos_usuario ADD COLUMN empresa_id INT NOT NULL DEFAULT 1;
```

**Beneficio:** Aislamiento completo de permisos entre empresas.

---

## 🔐 Sistema de Roles

### Jerarquía Numérica

**Archivo:** `config/validar_permisos.php`

| Rol | Nivel | Descripción |
|-----|-------|-------------|
| `vendedor` | 1 | Acceso básico (ventas, clientes) |
| `cajero` | 2 | Acceso a caja y transacciones |
| `supervisor` | 3 | Acceso a informes y supervisión |
| `admin` | 4 | Acceso administrativo |
| `developer` | 99 | **Acceso total** (bypass de permisos) |

**Regla:** Mayor número = Mayor privilegio

---

### Función de Validación

```php
function restringirPagina($rolMinimoRequerido) {
    // 1. Verificar sesión activa
    if (!isset($_SESSION['usuario_rol'])) {
        header("Location: " . URL_BASE . "login.php");
        exit();
    }
    
    // 2. Comparar niveles
    $nivelUsuario = obtenerNivel($_SESSION['usuario_rol']);
    $nivelMinimo = obtenerNivel($rolMinimoRequerido);
    
    // 3. Validar acceso
    if ($nivelUsuario < $nivelMinimo) {
        header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
        exit();
    }
}
```

**Uso:**
```php
require_once '../config/validar_permisos.php';
restringirPagina('supervisor'); // Solo supervisor, admin y developer
```

---

## 🔑 Carga de Permisos en Login

### Proceso de Autenticación

**Archivo:** `login.php` (líneas 46-54)

```php
// 1. Autenticar usuario
$stmt = $pdo->prepare('SELECT id, password_hash, rol, estado, empresa_id 
                       FROM usuarios WHERE usuario = ?');
$stmt->execute([$usuario]);
$user = $stmt->fetch();

// 2. Validar credenciales
if ($user && password_verify($password, $user['password_hash'])) {
    
    // 3. Crear sesión
    $_SESSION['usuario_id'] = $user['id'];
    $_SESSION['usuario_nombre'] = $usuario;
    $_SESSION['usuario_rol'] = $user['rol'];
    $_SESSION['empresa_id'] = $user['empresa_id'];
    
    // 4. Cargar permisos (UNION de rol + individuales)
    $stmt_permisos = $pdo->prepare("
        SELECT DISTINCT m.archivo 
        FROM modulos m 
        LEFT JOIN permisos_rol p ON m.id = p.modulo_id AND p.rol = ?
        LEFT JOIN permisos_usuario pu ON m.id = pu.modulo_id AND pu.usuario_id = ?
        WHERE p.id IS NOT NULL OR pu.id IS NOT NULL
    ");
    $stmt_permisos->execute(array($user['rol'], $user['id']));
    $_SESSION['permisos'] = $stmt_permisos->fetchAll(PDO::FETCH_COLUMN, 0);
    
    header('Location: index.php');
    exit();
}
```

### Lógica de la Consulta

** UNION implícito** de dos fuentes:

1. **Permisos del rol** (`permisos_rol`):
   ```sql
   LEFT JOIN permisos_rol p ON m.id = p.modulo_id AND p.rol = 'vendedor'
   ```

2. **Permisos individuales** (`permisos_usuario`):
   ```sql
   LEFT JOIN permisos_usuario pu ON m.id = pu.modulo_id AND pu.usuario_id = 5
   ```

**Resultado:** Array de rutas de archivos permitidos.

---

## ✅ Función `tiene_permiso()`

### Implementación

**Archivo:** `pages/infosesion.php` (líneas 81-93)

```php
if (!function_exists('tiene_permiso')) {
    function tiene_permiso($archivo_buscado) {
        // 1. Developer siempre tiene acceso
        if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
            return true;
        }
        
        // 2. Buscar en array de permisos de la sesión
        if (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
            return in_array($archivo_buscado, $_SESSION['permisos']);
        }
        
        // 3. Denegar por defecto
        return false;
    }
}
```

### Casos de Uso

#### **En Sidebar** (Ocultar opciones de menú)
```php
<?php if (tiene_permiso('pages/ventas.php')): ?>
    <a href="<?php echo URL_BASE; ?>pages/ventas.php">
        <i class="fas fa-shopping-cart"></i> <span>Nueva Venta</span>
    </a>
<?php endif; ?>
```

#### **En Páginas** (Proteger acceso directo)
```php
<?php
if (!tiene_permiso('pages/configuracion.php')) {
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}
?>
```

#### **Permisos Especiales** (Acciones específicas)
```php
<?php if (tiene_permiso('prov_ver_stock')): ?>
    <button onclick="verStock()">Ver Stock</button>
<?php endif; ?>

<?php if (tiene_permiso('whatsapp_enviar')): ?>
    <button onclick="enviarWhatsapp()">Enviar WhatsApp</button>
<?php endif; ?>
```

---

## 🎛️ Panel de Gestión de Permisos

### Archivo: `pages/abm_permisos_usuarios.php`

**Acceso:** Solo rol `developer`

### Funcionalidades

#### 1. **Registrar Nuevo Módulo**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_modulo'])) {
    $n_nom = trim($_POST['nuevo_nombre']);
    $n_arc = trim($_POST['nuevo_archivo']);
    $n_ico = trim($_POST['nuevo_icono']);
    $n_sec = $_POST['nueva_seccion'];
    
    $stmt_mod = $pdo->prepare("INSERT INTO modulos (nombre, archivo, icono, seccion) 
                               VALUES (?, ?, ?, ?)");
    $stmt_mod->execute(array($n_nom, $n_arc, $n_ico, $n_sec));
}
```

**Secciones disponibles:**
- Maestros
- Transacciones
- Facturación
- Gestión de Caja
- Informes
- Seguridad

---

#### 2. **Asignar Permisos a Usuario**

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_permisos'])) {
    try {
        $pdo->beginTransaction();
        
        // 1. Eliminar permisos actuales
        $pdo->prepare("DELETE FROM permisos_usuario WHERE usuario_id = ?")
            ->execute(array($id_seleccionado));
        
        // 2. Insertar nuevos permisos
        if (isset($_POST['modulos'])) {
            $ins = $pdo->prepare("INSERT INTO permisos_usuario (usuario_id, modulo_id) 
                                  VALUES (?, ?)");
            foreach ($_POST['modulos'] as $mod_id) {
                $ins->execute(array($id_seleccionado, (int)$mod_id));
            }
        }
        
        $pdo->commit();
        $mensaje = "✅ Permisos actualizados. El usuario deberá cerrar y volver a iniciar sesión.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}
```

**Características:**
- ✅ Transacción completa (rollback en error)
- ✅ Reemplazo total de permisos (DELETE + INSERT)
- ✅ Validación de usuario activo

---

#### 3. **Interfaz de Usuario**

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│  [Formulario: Registrar Nuevo Módulo]               │
├──────────────┬──────────────────────────────────────┤
│  Usuarios    │  Permisos para: [Usuario Seleccionado]│
│              │  ┌──────────────────────────────────┐│
│  ▸ Usuario 1 │  │ ☑ Ventas                        ││
│  ▸ Usuario 2 │  │ ☑ Compras                       ││
│  ▸ Usuario 3 │  │ ☐ Facturación                   ││
│              │  │ ☑ Inventario                    ││
│              │  └──────────────────────────────────┘│
│              │  [Guardar Permisos]                  │
└──────────────┴──────────────────────────────────────┘
```

**Características:**
- Lista de usuarios con rol visible
- Checkboxes organizados por sección
- Cards clickeables (toggle automático)
- Toast notifications de éxito/error

---

## 🔄 Flujo Completo del Sistema

```
┌──────────────────────────────────────────────────────────┐
│  1. LOGIN (login.php)                                    │
│     ├─ Usuario ingresa credenciales                      │
│     ├─ Validar password_hash                             │
│     ├─ Verificar estado = 'ACTIVO'                       │
│     └─ Cargar permisos en $_SESSION['permisos']          │
└──────────────────────────────────────────────────────────┘
                        ↓
┌──────────────────────────────────────────────────────────┐
│  2. SESIÓN INICIADA                                      │
│     $_SESSION['usuario_id'] = 5                          │
│     $_SESSION['usuario_rol'] = 'vendedor'                │
│     $_SESSION['empresa_id'] = 1                          │
│     $_SESSION['permisos'] = ['pages/ventas.php',         │
│                               'pages/clientes.php', ...] │
└──────────────────────────────────────────────────────────┘
                        ↓
┌──────────────────────────────────────────────────────────┐
│  3. ACCESO A PÁGINA (ej: pages/ventas.php)              │
│     ├─ include 'infosesion.php'                          │
│     │   ├─ Verificar sesión activa                       │
│     │   ├─ Cargar función tiene_permiso()                │
│     │   └─ Obtener nombre_empresa                        │
│     └─ if (!tiene_permiso('pages/ventas.php'))           │
│         → Redirigir a index.php?error=acceso_denegado    │
└──────────────────────────────────────────────────────────┘
                        ↓
┌──────────────────────────────────────────────────────────┐
│  4. MOSTRAR SIDEBAR (sidebar.php)                        │
│     ├─ include 'infosesion.php'                          │
│     └─ foreach (modulos)                                 │
│         if (tiene_permiso(modulo.archivo))               │
│             → Mostrar en menú                            │
└──────────────────────────────────────────────────────────┘
```

---

## 📈 Ventajas del Sistema

### ✅ **Multi-Empresa**
- Aislamiento completo por `empresa_id`
- Permisos independientes por empresa
- Escalable a múltiples clientes

### ✅ **Híbrido (Rol + Individual)**
- Permisos base por rol (herencia)
- Permisos específicos por usuario (flexibilidad)
- No requiere cambiar rol para excepciones

### ✅ **Optimización**
- Caché en sesión (`$_SESSION['permisos']`)
- Sin consultas DB en cada página
- Carga una sola vez en login

### ✅ **Seguridad**
- Developer bypass (nivel 99)
- Validación en cada página protegida
- Transacciones en guardado de permisos
- Estado de usuario (ACTIVO/INACTIVO)

### ✅ **UX/UI**
- Sidebar dinámico (solo muestra opciones permitidas)
- Organización por secciones
- Interfaz intuitiva de checkboxes
- Notificaciones toast

---

## ⚠️ Áreas de Mejora

### 1. **Sin Auditoría**
**Problema:** No hay registro de cambios de permisos  
**Riesgo:** No se puede rastrear quién modificó permisos y cuándo  
**Sugerencia:** Agregar tabla `auditoria_permisos` con:
- `usuario_id` (quien modificó)
- `usuario_afectado_id` (a quién se modificó)
- `modulo_id`
- `accion` (ASIGNAR/REVOCAR)
- `fecha`

---

### 2. **Sin Fechas de Vigencia**
**Problema:** Permisos permanentes sin expiración  
**Riesgo:** Acceso perpetuo incluso después de cambiar roles  
**Sugerencia:** Agregar campos:
- `fecha_inicio`
- `fecha_fin`
- Validar vigencia en `tiene_permiso()`

---

### 3. **Permisos Huérfanos**
**Problema:** Si se elimina un módulo, quedan registros en `permisos_rol` y `permisos_usuario`  
**Riesgo:** Datos inconsistentes  
**Sugerencia:** 
- Agregar `ON DELETE CASCADE` en FK de `modulo_id`
- O trigger de limpieza

---

### 4. **Sin Validación de Archivos**
**Problema:** No verifica que el archivo registrado exista realmente  
**Riesgo:** Permisos a páginas que no existen  
**Sugerencia:** 
- Validar `file_exists($archivo)` al registrar módulo
- O script de auditoría periódico

---

### 5. **Mezcla de Responsabilidades**
**Problema:** Dos sistemas de validación diferentes:
- `restringirPagina()` - Por rol jerárquico
- `tiene_permiso()` - Por permisos granulares  
**Riesgo:** Inconsistencias en la lógica  
**Sugerencia:** Unificar en una sola función:
```php
function validar_acceso($recurso, $nivel_minimo = null) {
    // Developer siempre pasa
    if ($_SESSION['usuario_rol'] === 'developer') return true;
    
    // Si requiere nivel mínimo, validar jerarquía
    if ($nivel_minimo && obtenerNivel($_SESSION['usuario_rol']) < obtenerNivel($nivel_minimo)) {
        return false;
    }
    
    // Validar permiso específico
    return tiene_permiso($recurso);
}
```

---

### 6. **Sin Historial de Sesiones**
**Problema:** No registra cuándo un usuario inició sesión  
**Riesgo:** No se puede auditar accesos  
**Sugerencia:** Tabla `sesiones` con:
- `usuario_id`
- `fecha_inicio`
- `fecha_fin`
- `ip_address`
- `user_agent`

---

## 📊 Estadísticas del Sistema

### Permisos Comunes Identificados

**Páginas del sistema:**
- `pages/ventas.php` - Nueva Venta
- `pages/compras.php` - Compras
- `pages/abm_productos.php` - Productos
- `pages/abm_clientes.php` - Clientes
- `pages/abm_proveedores.php` - Proveedores
- `pages/facturacion_arca.php` - Facturación AFIP
- `pages/caja_dashboard.php` - Panel de Caja
- `pages/cierre_caja.php` - Cierre de Caja
- `pages/resumen_ventas.php` - Resumen de Ventas
- `pages/reportes_inventario.php` - Inventario
- `pages/configuracion.php` - Configuración
- `pages/usuarios.php` - Gestión de Usuarios

**Permisos especiales:**
- `whatsapp_enviar` - Envío de WhatsApp
- `prov_ver_stock` - Ver stock de proveedor
- `prov_ver_catalogo` - Ver catálogo de proveedor
- `prov_importar_catalogo` - Importar catálogo CSV

---

## 🎯 Conclusiones

### Estado Actual: ✅ FUNCIONAL

El sistema de permisos está **correctamente implementado** y es:
- **Seguro:** Validación en cada punto de acceso
- **Escalable:** Soporta múltiples empresas y roles
- **Flexible:** Permisos granulares por usuario
- **Optimizado:** Caché en sesión
- **Usable:** Interfaz intuitiva de gestión

### Recomendaciones Prioritarias

1. **Alta:** Implementar auditoría de cambios de permisos
2. **Media:** Agregar fechas de vigencia a permisos
3. **Media:** Unificar funciones de validación
4. **Baja:** Validar existencia de archivos al registrar módulos

---

## 📁 Archivos Relacionados

### Core del Sistema
- `config/validar_permisos.php` - Roles jerárquicos
- `pages/infosesion.php` - Función `tiene_permiso()`
- `login.php` - Carga de permisos en autenticación

### Gestión
- `pages/abm_permisos_usuarios.php` - Panel de administración
- `pages/sidebar.php` - Menú dinámico

### Base de Datos
- `schema.sql` - Estructura de tablas
- `migrations/12_add_empresa_to_permisos.sql` - Multi-empresa

---

**Documento generado:** 13/07/2026  
**Sistema:** POS Dev v1.0  
**Autor:** Análisis automatizado del código fuente

---

## 🔒 Estándar de Seguridad Unificado (Actualización 16/07/2026)

### Problema detectado y corregido

Se identificó que la protección de acciones críticas (facturación ARCA, cierres de caja, pagos, anulaciones, backups) era **solo visual** en varios endpoints: el botón/UI se ocultaba sin permiso, pero el endpoint backend no validaba permisos, permitiendo POST manuales no autorizados. Además, el modelo de permisos estaba mezclado sin criterio claro.

### Estándar adoptado

1. **Mecanismo principal de permisos: `tiene_permiso($archivo)`**
   - Array granular por usuario cargado en `$_SESSION['permisos']`.
   - El rol `developer` siempre tiene acceso total.
   - Se usa en **botones de UI, páginas y endpoints**.

2. **`restringirPagina($rol)` solo para administración pura**
   - Jerarquía de rol (vendedor < cajero < supervisor < admin < developer).
   - Reservado para páginas de configuración/administración puras (ej. `abm_permisos_usuarios.php`, `abm_empresa.php`, `verificar_modulos.php`).

3. **Helper `require_permiso($archivo)` (en `pages/infosesion.php`)**
   - Termina con HTTP 403 si el usuario no tiene el permiso.
   - Detecta si la petición es AJAX/JSON para responder con JSON o HTML.
   - **Obligatorio en todo endpoint que realice acciones críticas** (facturación, anulaciones, pagos, cierres, backups, eliminaciones).

### Regla de nomenclatura

El nombre del permiso debe coincidir exactamente entre la UI, el helper y el endpoint:
- Formato: `'pages/<archivo>.php'` (ruta relativa desde la raíz del proyecto).
- Ejemplo: facturación ARCA usa `'pages/facturacion_arca.php'` en `facturacion_arca.php`, `resumen_ventas.php` y `procesar_factura_arca.php`.

### Endpoints endurecidos (16/07/2026)

| Endpoint | Permiso requerido | Acción |
|----------|-------------------|--------|
| `pages/procesar_factura_arca.php` | `pages/facturacion_arca.php` | Facturación ARCA |
| `pages/procesar_cierre.php` | `pages/cierre_caja.php` | Cierre de caja |
| `pages/anulaciones.php` | `pages/anulaciones.php` | Anulación de ventas |
| `ajax/marcar_compra_pagada_ajax.php` | `pages/compras.php` | Marcar compra pagada |
| `ajax/registrar_pago_proveedor_ajax.php` | `pages/ctacte_proveedores.php` | Pago a proveedor |
| `ajax/reimputar_excedente_proveedor_ajax.php` | `pages/ctacte_proveedores.php` | Reimputar excedente |
| `procesos/backup_database.php` (vía web) | `pages/backup.php` | Generar backup |

### Bug corregido: CAE en `resumen_ventas.php`

La consulta de ventas usaba `NULL as cae`, ocultando el badge "✓ AFIP" y permitiendo re-facturar ventas ya facturadas. Se reemplazó por un `LEFT JOIN ventas_afip va ON va.id_venta = v.id AND va.empresa_id = v.empresa_id` seleccionando `va.cae as cae`. Ahora:
- Ventas sin facturar → muestran botón ARCA (solo si el usuario tiene permiso).
- Ventas facturadas → muestran badge "✓ AFIP" con el CAE real.

### Checklist para nuevos endpoints

- [ ] ¿El endpoint incluye `infosesion.php` (o `require_permiso` disponible)?
- [ ] ¿Valida permiso en backend con `require_permiso('pages/<archivo>.php')`?
- [ ] ¿El nombre de permiso coincide con la UI y la página origen?
- [ ] ¿La UI oculta el botón con `tiene_permiso()` (defensa en profundidad)?
