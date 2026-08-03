# 📋 Plan de Implementación: Arquitectura Modular POS

> **Documento:** `PLAN_IMPLEMENTACION_MODULAR.md`
> **Proyecto:** `pos_dev` — Sistema de Punto de Venta
> **Fecha:** 30/7/2026
> **Objetivo:** Migrar la arquitectura actual (estructura plana) a una arquitectura modular, manteniendo la funcionalidad existente y permitiendo el crecimiento sostenible del sistema.

---

## 1. Análisis del Estado Actual

### 1.1 Estructura Actual (Plana)

```
pos_dev/
├── config/
│   ├── db_config.php          ← Conexión PDO + constantes URL_BASE/PATH_BASE
│   ├── validar_permisos.php   ← Jerarquía de roles
│   └── licencia_manager.php
├── pages/                     ← 40+ archivos PHP (lógica + presentación mezcladas)
│   ├── infosesion.php         ← Guardia de sesión global
│   ├── sidebar.php            ← Menú lateral con permisos
│   ├── topbar.php
│   ├── abm_clientes.php       ← CRUD completo en un solo archivo
│   ├── abm_productos.php
│   ├── ventas.php
│   ├── compras.php
│   ├── ...
│   └── components/
├── ajax/                      ← 30+ endpoints AJAX sueltos
├── funciones/                 ← Helpers sueltos
│   ├── funciones_configuracion.php
│   ├── obtener_dolar.php
│   └── ticket_generator.php
├── css/                       ← Estilos globales
├── js/                        ← Scripts globales
├── img/
├── libs/
├── migrations/
├── procesos/                  ← Scripts de mantenimiento
├── fpdf/
├── docs/
├── index.php                  ← Dashboard principal
├── login.php
└── logout.php
```

### 1.2 Problemas Identificados

| # | Problema | Impacto |
|---|----------|---------|
| 1 | **Lógica y presentación mezcladas** | Difícil de mantener; alta acoplamiento |
| 2 | **CRUD monolítico** | Cada `abm_*.php` contiene listado, formulario, guardado y eliminación en un solo archivo (979 líneas en `abm_clientes.php`) |
| 3 | **AJAX dispersos** | 30+ archivos en `ajax/` sin organización por dominio |
| 4 | **Helpers sueltos** | Funciones en `funciones/` sin espacio de nombres ni autoloading |
| 5 | **Rutas hardcodeadas** | Enlaces como `pages/abm_clientes.php` en el sidebar |
| 6 | **Sin separación de responsabilidades** | No hay Modelo-Vista-Controlador |
| 7 | **Duplicidad de includes** | Cada página incluye `infosesion.php`, `db_config.php`, `validar_permisos.php` manualmente |

### 1.3 Fortalezas a Preservar

- ✅ Sistema de permisos basado en roles (`tiene_permiso()`, `require_permiso()`)
- ✅ Conexión PDO con prepared statements
- ✅ Constantes `URL_BASE` y `PATH_BASE` para multi-entorno
- ✅ Manejador global de excepciones
- ✅ Cache de nombre de empresa en sesión
- ✅ Sidebar colapsable con búsqueda y tooltips

---

## 2. Estructura Objetivo (Modular)

```
pos_dev/
│
├── config/
│   ├── database.php          ← Configuración de conexión (PDO factory)
│   └── config.php            ← Configuración global (constantes, timezone, etc.)
│
├── core/
│   ├── Conexion.php          ← Singleton de conexión PDO
│   ├── Auth.php              ← Gestión de autenticación y sesiones
│   ├── Funciones.php         ← Helpers globales (formato, validación, etc.)
│   ├── ControladorBase.php   ← Clase base para controladores
│   ├── ModeloBase.php        ← Clase base para modelos
│   └── App.php               ← Enrutador/front-controller
│
├── public/
│   ├── css/
│   │   ├── style.css
│   │   ├── style_login.css
│   │   └── ticket_print.css
│   ├── js/
│   │   ├── app.js
│   │   ├── ventas.js
│   │   └── presupuestos.js
│   ├── img/
│   └── uploads/              ← Archivos subidos por usuarios
│
├── templates/
│   ├── header.php            ← `<!DOCTYPE>`, `<head>`, apertura de `<body>`
│   ├── menu.php              ← Sidebar colapsable con permisos
│   ├── footer.php            ← Cierre de `</body></html>` + scripts globales
│   └── topbar.php            ← Barra superior de navegación
│
├── modulos/
│   ├── clientes/
│   │   ├── index.php         ← Punto de entrada del módulo
│   │   ├── listado.php       ← Vista: lista de clientes
│   │   ├── formulario.php    ← Vista: formulario crear/editar
│   │   ├── guardar.php       ← Controlador: procesa POST (crear/editar)
│   │   ├── eliminar.php      ← Controlador: elimina registro
│   │   ├── ajax.php          ← Endpoint AJAX del módulo
│   │   └── modelo.php        ← Modelo: operaciones DB de clientes
│   │
│   ├── productos/
│   │   ├── index.php
│   │   ├── listado.php
│   │   ├── formulario.php
│   │   ├── guardar.php
│   │   ├── eliminar.php
│   │   ├── ajax.php
│   │   └── modelo.php
│   │
│   ├── ventas/
│   │   ├── index.php
│   │   ├── listado.php
│   │   ├── formulario.php
│   │   ├── guardar.php
│   │   ├── eliminar.php
│   │   ├── ajax.php
│   │   └── modelo.php
│   │
│   ├── compras/
│   │   └── ... (misma estructura)
│   │
│   ├── proveedores/
│   │   └── ...
│   │
│   ├── caja/
│   │   └── ...
│   │
│   ├── usuarios/
│   │   └── ...
│   │
│   ├── reportes/
│   │   └── ...
│   │
│   └── configuracion/
│       └── ...
│
├── vendor/                   ← Dependencias Composer (afip.php, phpqrcode)
├── migrations/               ← Scripts de migración de DB
├── cache/                    ← Cache de archivos
├── docs/                     ← Documentación
├── index.php                 ← Front-controller principal
├── login.php                 ← Página de login
└── logout.php                ← Cierre de sesión
```

---

## 3. Fases de Implementación

### Fase 0: Preparación del Entorno (Semana 1)

#### 3.0.1. Crear la infraestructura base

| Tarea | Detalle | Archivo(s) |
|-------|---------|------------|
| ✅ Crear `config/config.php` | Consolidar constantes, timezone, locale, manejo de errores | `config/config.php` |
| ✅ Crear `core/Conexion.php` | Singleton PDO reutilizable | `core/Conexion.php` |
| ✅ Crear `core/Auth.php` | Clase para gestión de sesión, login, logout, permisos | `core/Auth.php` |
| ✅ Crear `core/Funciones.php` | Helpers globales (formato de moneda, fechas, validaciones) | `core/Funciones.php` |
| ✅ Crear `core/ControladorBase.php` | Métodos comunes: render, redirect, jsonResponse | `core/ControladorBase.php` |
| ✅ Crear `core/ModeloBase.php` | Métodos CRUD genéricos: find, findAll, save, delete | `core/ModeloBase.php` |
| ✅ Crear `core/App.php` | Enrutador simple (front-controller) | `core/App.php` |
| ✅ Crear `templates/header.php` | Plantilla de apertura HTML | `templates/header.php` |
| ✅ Crear `templates/menu.php` | Migrar sidebar existente | `templates/menu.php` |
| ✅ Crear `templates/footer.php` | Plantilla de cierre HTML + scripts | `templates/footer.php` |
| ✅ Crear `templates/topbar.php` | Migrar topbar existente | `templates/topbar.php` |
| ✅ Mover assets a `public/` | CSS, JS, IMG, uploads | `public/` |

#### 3.0.2. Autoloading y bootstrap

```php
// config/config.php — Bootstrap global
<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
setlocale(LC_NUMERIC, 'C');

// Constantes de entorno
define('ENTORNO', strpos($_SERVER['SCRIPT_NAME'], '/pos_dev/') !== false ? 'desarrollo' : 'produccion');
define('URL_BASE', ENTORNO === 'desarrollo' ? '/pos_dev/' : '/pos_prod/');
define('PATH_BASE', $_SERVER['DOCUMENT_ROOT'] . URL_BASE);
define('PATH_CORE', PATH_BASE . 'core/');
define('PATH_MODULOS', PATH_BASE . 'modulos/');
define('PATH_TEMPLATES', PATH_BASE . 'templates/');
define('PATH_PUBLIC', PATH_BASE . 'public/');

// Autoloader simple
spl_autoload_register(function ($clase) {
    $paths = [PATH_CORE, PATH_MODULOS];
    foreach ($paths as $path) {
        $file = $path . $clase . '.php';
        if (file_exists($file)) require_once $file;
    }
});

// Manejador global de excepciones (migrado desde infosesion.php)
set_exception_handler(function (Throwable $e) {
    error_log("POS FATAL: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    // ... renderizar error page
});
```

---

### Fase 1: Núcleo Modular (Semana 2)

#### 3.1.1. `core/Conexion.php` — Singleton PDO

```php
<?php
class Conexion {
    private static $instancia = null;
    private $pdo;

    private function __construct() {
        $host = '192.168.7.45';
        $db   = ENTORNO === 'desarrollo' ? 'pos_dev' : 'pos_prod';
        $user = 'root';
        $pass = 'isidoro9';
        $dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        $this->pdo = new PDO($dsn, $user, $pass, $options);
    }

    public static function getInstancia() {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    public function getPDO() {
        return $this->pdo;
    }
}
```

#### 3.1.2. `core/Auth.php` — Gestión de autenticación

```php
<?php
class Auth {
    public static function estaLogueado(): bool {
        return isset($_SESSION['usuario_id']);
    }

    public static function requerirLogin(): void {
        if (!self::estaLogueado()) {
            header('Location: ' . URL_BASE . 'login.php');
            exit();
        }
    }

    public static function getUsuarioId(): ?int {
        return $_SESSION['usuario_id'] ?? null;
    }

    public static function getRol(): string {
        return $_SESSION['usuario_rol'] ?? 'invitado';
    }

    public static function getEmpresaId(): ?int {
        return $_SESSION['empresa_id'] ?? null;
    }

    public static function tienePermiso(string $archivo): bool {
        if (self::getRol() === 'developer') return true;
        return in_array($archivo, $_SESSION['permisos'] ?? []);
    }

    public static function requirePermiso(string $archivo): void {
        if (!self::tienePermiso($archivo)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado']);
            exit();
        }
    }

    public static function login(string $usuario, string $password): bool {
        // ... lógica migrada desde login.php
    }

    public static function logout(): void {
        session_destroy();
        header('Location: ' . URL_BASE . 'login.php');
        exit();
    }
}
```

#### 3.1.3. `core/ModeloBase.php` — CRUD genérico

```php
<?php
abstract class ModeloBase {
    protected $pdo;
    protected $tabla;
    protected $empresaId;

    public function __construct() {
        $this->pdo = Conexion::getInstancia()->getPDO();
        $this->empresaId = Auth::getEmpresaId();
    }

    public function findAll(array $condiciones = []): array {
        $sql = "SELECT * FROM {$this->tabla} WHERE empresa_id = ?";
        $params = [$this->empresaId];

        foreach ($condiciones as $campo => $valor) {
            $sql .= " AND {$campo} = ?";
            $params[] = $valor;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tabla} WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$id, $this->empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function save(array $datos): bool {
        if (isset($datos['id']) && $datos['id']) {
            return $this->update($datos);
        }
        return $this->insert($datos);
    }

    protected function insert(array $datos): bool {
        $columnas = array_keys($datos);
        $placeholders = array_map(fn($c) => ":{$c}", $columnas);
        $sql = "INSERT INTO {$this->tabla} (" . implode(',', $columnas) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($datos);
    }

    protected function update(array $datos): bool {
        $id = $datos['id'];
        unset($datos['id']);
        $set = [];
        foreach ($datos as $col => $val) {
            $set[] = "{$col} = :{$col}";
        }
        $sql = "UPDATE {$this->tabla} SET " . implode(',', $set) . " WHERE id = :id AND empresa_id = :empresa_id";
        $datos['id'] = $id;
        $datos['empresa_id'] = $this->empresaId;
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($datos);
    }

    public function delete(int $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tabla} WHERE id = ? AND empresa_id = ?");
        return $stmt->execute([$id, $this->empresaId]);
    }
}
```

#### 3.1.4. `core/ControladorBase.php`

```php
<?php
abstract class ControladorBase {
    protected $modelo;

    protected function render(string $vista, array $datos = []): void {
        extract($datos);
        require_once PATH_TEMPLATES . 'header.php';
        require_once PATH_TEMPLATES . 'menu.php';
        require_once PATH_TEMPLATES . 'topbar.php';
        require_once $vista;
        require_once PATH_TEMPLATES . 'footer.php';
    }

    protected function redirect(string $url): void {
        header('Location: ' . URL_BASE . $url);
        exit();
    }

    protected function json(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit();
    }
}
```

---

### Fase 2: Primer Módulo — Clientes (Semana 3)

#### 3.2.1. Estructura del módulo

```
modulos/clientes/
├── index.php         ← Front-controller del módulo
├── listado.php       ← Vista HTML (tabla de clientes)
├── formulario.php    ← Vista HTML (formulario)
├── guardar.php       ← Controlador: valida y guarda
├── eliminar.php      ← Controlador: elimina
├── ajax.php          ← Endpoint AJAX (búsqueda, autocompletado)
└── modelo.php        ← ModeloCliente extends ModeloBase
```

#### 3.2.2. `modulos/clientes/modelo.php`

```php
<?php
class ModeloCliente extends ModeloBase {
    protected $tabla = 'clientes';

    public function buscarPorDni(string $dni): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM clientes WHERE dni = ? AND empresa_id = ? LIMIT 1"
        );
        $stmt->execute([$dni, $this->empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorNombre(string $term): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, nombre, apellido, dni FROM clientes 
             WHERE (nombre LIKE ? OR apellido LIKE ?) AND empresa_id = ? 
             ORDER BY apellido LIMIT 10"
        );
        $like = "%{$term}%";
        $stmt->execute([$like, $like, $this->empresaId]);
        return $stmt->fetchAll();
    }
}
```

#### 3.2.3. `modulos/clientes/index.php`

```php
<?php
require_once PATH_BASE . 'config/config.php';
Auth::requerirLogin();

$accion = $_GET['accion'] ?? 'listar';
$id = $_GET['id'] ?? null;

$modelo = new ModeloCliente();

switch ($accion) {
    case 'listar':
        $clientes = $modelo->findAll();
        require_once __DIR__ . '/listado.php';
        break;

    case 'editar':
        $cliente = $modelo->findById((int)$id);
        require_once __DIR__ . '/formulario.php';
        break;

    case 'crear':
        require_once __DIR__ . '/formulario.php';
        break;
}
```

#### 3.2.4. `modulos/clientes/guardar.php`

```php
<?php
require_once PATH_BASE . 'config/config.php';
Auth::requerirLogin();
Auth::requirePermiso('modulos/clientes/index.php');

$modelo = new ModeloCliente();

$datos = [
    'nombre'   => trim($_POST['nombre'] ?? ''),
    'apellido' => trim($_POST['apellido'] ?? ''),
    'dni'      => trim($_POST['dni'] ?? ''),
    'email'    => trim($_POST['email'] ?? ''),
    'telefono' => trim($_POST['telefono'] ?? ''),
    'direccion'=> trim($_POST['direccion'] ?? ''),
    'empresa_id' => Auth::getEmpresaId(),
];

if (isset($_POST['id']) && $_POST['id']) {
    $datos['id'] = (int)$_POST['id'];
}

if ($modelo->save($datos)) {
    header('Location: ' . URL_BASE . 'modulos/clientes/index.php?msg=ok');
} else {
    header('Location: ' . URL_BASE . 'modulos/clientes/index.php?msg=error');
}
exit();
```

#### 3.2.5. `modulos/clientes/ajax.php`

```php
<?php
require_once PATH_BASE . 'config/config.php';
Auth::requerirLogin();

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';
$modelo = new ModeloCliente();

switch ($accion) {
    case 'buscar_dni':
        $dni = trim($_GET['dni'] ?? '');
        $cliente = $modelo->buscarPorDni($dni);
        echo json_encode($cliente ?: ['status' => 'not_found']);
        break;

    case 'autocomplete':
        $term = trim($_GET['term'] ?? '');
        $resultados = $modelo->buscarPorNombre($term);
        echo json_encode($resultados);
        break;
}
exit();
```

#### 3.2.6. `modulos/clientes/eliminar.php`

```php
<?php
require_once PATH_BASE . 'config/config.php';
Auth::requerirLogin();
Auth::requirePermiso('modulos/clientes/index.php');

$id = (int)($_GET['id'] ?? 0);
$modelo = new ModeloCliente();

if ($modelo->delete($id)) {
    header('Location: ' . URL_BASE . 'modulos/clientes/index.php?msg=eliminado');
} else {
    header('Location: ' . URL_BASE . 'modulos/clientes/index.php?msg=error');
}
exit();
```

#### 3.2.7. `modulos/clientes/listado.php` (Vista)

```php
<?php
// Vista: listado de clientes
// Variables disponibles: $clientes
?>
<div class="content">
    <div class="welcome-banner">
        <h1><i class="fas fa-users"></i> Gestión de Clientes</h1>
        <p>Administre la lista de clientes del sistema</p>
    </div>

    <div class="stat-card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Apellido</th>
                    <th>DNI</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= htmlspecialchars($c['nombre']) ?></td>
                    <td><?= htmlspecialchars($c['apellido']) ?></td>
                    <td><?= htmlspecialchars($c['dni']) ?></td>
                    <td><?= htmlspecialchars($c['email']) ?></td>
                    <td><?= htmlspecialchars($c['telefono']) ?></td>
                    <td>
                        <a href="<?= URL_BASE ?>modulos/clientes/index.php?accion=editar&id=<?= $c['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="<?= URL_BASE ?>modulos/clientes/eliminar.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-danger"
                           onclick="return confirmarAccion('¿Está seguro?', 'Esta acción no se puede deshacer', 'Eliminar', 'btn-danger', () => { window.location.href = this.href; })">
                            <i class="fas fa-trash"></i> Eliminar
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
```

#### 3.2.8. `modulos/clientes/formulario.php` (Vista)

```php
<?php
// Vista: formulario de cliente
// Variables disponibles: $cliente (null si es crear)
$es_edicion = !empty($cliente);
?>
<div class="content">
    <div class="welcome-banner">
        <h1><i class="fas fa-user-plus"></i> <?= $es_edicion ? 'Editar' : 'Nuevo' ?> Cliente</h1>
    </div>

    <div class="stat-card">
        <form method="POST" action="<?= URL_BASE ?>modulos/clientes/guardar.php">
            <?php if ($es_edicion): ?>
                <input type="hidden" name="id" value="<?= $cliente['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['nombre']) : '' ?>" required>
                </div>
                <div class="form-group">
                    <label>Apellido *</label>
                    <input type="text" name="apellido" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['apellido']) : '' ?>" required>
                </div>
                <div class="form-group">
                    <label>DNI</label>
                    <input type="text" name="dni" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['dni']) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['email']) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['telefono']) : '' ?>">
                </div>
                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" name="direccion" class="form-control"
                           value="<?= $es_edicion ? htmlspecialchars($cliente['direccion']) : '' ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Guardar
            </button>
            <a href="<?= URL_BASE ?>modulos/clientes/index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Cancelar
            </a>
        </form>
    </div>
</div>
```

#### 3.2.9. Actualización del menú (`templates/menu.php`)

```php
<!-- Antes -->
<a href="<?= URL_BASE ?>pages/abm_clientes.php">Clientes</a>

<!-- Después -->
<a href="<?= URL_BASE ?>modulos/clientes/index.php">Clientes</a>
```

---

### Fase 3: Migración de Módulos Existentes (Semanas 4-8)

#### 3.3.1. Mapeo de módulos

| Módulo | Archivo origen (`pages/`) | Archivo origen (`ajax/`) | Estado |
|--------|--------------------------|--------------------------|--------|
| **Clientes** | `abm_clientes.php` | `ajax/agregar_cliente_rapido.php` | ✅ Fase 2 |
| **Productos** | `abm_productos.php` | `ajax/agregar_producto_rapido.php` | 🔄 Fase 3 |
| **Ventas** | `ventas.php` | `ajax/cargar_multiples_productos.php`, `ajax/obtener_venta_detalle_ajax.php`, `ajax/obtener_detalle_venta.php`, `ajax/obtener_detalle_presupuesto.php`, `ajax/obtener_detalle_devolucion.php`, `ajax/obtener_detalle_pago.php`, `ajax/obtener_detalle_cuotas_venta.php`, `ajax/anular_pago_cuota.php`, `ajax/procesar_pago_cuota.php`, `ajax/generar_ticket.php`, `ajax/temp_generar_ticket.php`, `ajax/ventas_pendientes_ajax.php`, `ajax/buscar_ventas_cliente_ajax.php` | 🔄 Fase 3 |
| **Compras** | `compras.php`, `compras_rapidas.php` | `ajax/marcar_compra_pagada_ajax.php`, `ajax/obtener_precios_proveedor.php`, `ajax/obtener_catalogo_proveedor.php`, `ajax/cargar_ctacte_proveedor_ajax.php`, `ajax/registrar_pago_proveedor_ajax.php`, `ajax/reimputar_excedente_proveedor_ajax.php` | 🔄 Fase 4 |
| **Proveedores** | `abm_proveedores.php` | `ajax/agregar_proveedor_rapido.php` | 🔄 Fase 4 |
| **Caja** | `caja_dashboard.php`, `cierre_caja.php`, `movimiento_manual.php` | `ajax/generar_ticket_cuota.php`, `ajax/vista_previa_ticket_cuota.php`, `ajax/vista_previa_ticket_devolucion.php` | 🔄 Fase 5 |
| **Usuarios** | `usuarios.php`, `abm_permisos_usuarios.php` | — | 🔄 Fase 5 |
| **Reportes** | `resumen_ventas.php`, `reporte_cuotas.php`, `reportes_inventario.php`, `reporte_movimientos_productos.php`, `reportes_financieros.php`, `cuentas_corrientes.php`, `ctacte_proveedores.php`, `cuentas_corrientes_detalle.php`, `consignacion_reporte.php`, `consulta_precios.php`, `anulaciones.php`, `presupuestos.php`, `consultar_presupuestos.php` | — | 🔄 Fase 6 |
| **Configuración** | `configuracion.php`, `abm_empresa.php`, `abm_empresas.php`, `licencia.php`, `backup.php` | `ajax/cambiar_empresa.php`, `ajax/cambiar_sucursal.php`, `ajax/importar_catalogo_csv.php`, `ajax/explorador_archivos_backup.php`, `ajax/update_licencia_ip.php` | 🔄 Fase 6 |

#### 3.3.2. Estrategia de migración por módulo

Para cada módulo, seguir este patrón:

1. **Crear `modelo.php`** — Extraer todas las consultas SQL del archivo original a métodos del modelo
2. **Crear `index.php`** — Router interno con `switch` sobre `$_GET['accion']`
3. **Crear `listado.php`** — Extraer la vista de listado (HTML + CSS)
4. **Crear `formulario.php`** — Extraer la vista de formulario
5. **Crear `guardar.php`** — Extraer la lógica de POST (validaciones + guardado)
6. **Crear `eliminar.php`** — Extraer la lógica de borrado
7. **Crear `ajax.php`** — Consolidar todos los endpoints AJAX del módulo en uno solo
8. **Actualizar `templates/menu.php`** — Apuntar los enlaces al nuevo módulo
9. **Eliminar archivos originales** — Una vez verificado que todo funciona

#### 3.3.3. Ejemplo: Migración de Productos

```php
// modulos/productos/modelo.php
class ModeloProducto extends ModeloBase {
    protected $tabla = 'productos';

    public function buscarPorCodigo(string $codigo): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM productos WHERE cod_prod = ? AND empresa_id = ? LIMIT 1"
        );
        $stmt->execute([$codigo, $this->empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorNombre(string $term): array {
        $stmt = $this->pdo->prepare(
            "SELECT cod_prod, descripcion, precio_venta, stock FROM productos 
             WHERE descripcion LIKE ? AND empresa_id = ? ORDER BY descripcion LIMIT 10"
        );
        $stmt->execute(["%{$term}%", $this->empresaId]);
        return $stmt->fetchAll();
    }

    public function getRubros(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM rubros WHERE empresa_id = ?");
        $stmt->execute([$this->empresaId]);
        return $stmt->fetchAll();
    }
}
```

---

### Fase 4: Consolidación y Limpieza (Semana 9)

#### 3.4.1. Eliminar archivos obsoletos

| Archivo/Directorio | Acción |
|---------------------|--------|
| `pages/abm_clientes.php` | ✅ Ya migrado |
| `pages/abm_productos.php` | 🔄 Eliminar tras migración |
| `pages/ventas.php` | 🔄 Eliminar tras migración |
| `pages/compras.php` | 🔄 Eliminar tras migración |
| `pages/abm_proveedores.php` | 🔄 Eliminar tras migración |
| `pages/caja_dashboard.php` | 🔄 Eliminar tras migración |
| `pages/usuarios.php` | 🔄 Eliminar tras migración |
| `pages/configuracion.php` | 🔄 Eliminar tras migración |
| `pages/abm_empresa.php` | 🔄 Eliminar tras migración |
| `pages/abm_empresas.php` | 🔄 Eliminar tras migración |
| `pages/abm_permisos_usuarios.php` | 🔄 Eliminar tras migración |
| `pages/backup.php` | 🔄 Eliminar tras migración |
| `pages/consulta_precios.php` | 🔄 Eliminar tras migración |
| `pages/anulaciones.php` | 🔄 Eliminar tras migración |
| `pages/presupuestos.php` | 🔄 Eliminar tras migración |
| `pages/consultar_presupuestos.php` | 🔄 Eliminar tras migración |
| `pages/cuentas_corrientes.php` | 🔄 Eliminar tras migración |
| `pages/ctacte_proveedores.php` | 🔄 Eliminar tras migración |
| `pages/cuentas_corrientes_detalle.php` | 🔄 Eliminar tras migración |
| `pages/consignacion_reporte.php` | 🔄 Eliminar tras migración |
| `pages/reporte_cuotas.php` | 🔄 Eliminar tras migración |
| `pages/reportes_inventario.php` | 🔄 Eliminar tras migración |
| `pages/reporte_movimientos_productos.php` | 🔄 Eliminar tras migración |
| `pages/reportes_financieros.php` | 🔄 Eliminar tras migración |
| `pages/resumen_ventas.php` | 🔄 Eliminar tras migración |
| `pages/cobro_cuotas.php` | 🔄 Eliminar tras migración |
| `pages/pagos_ctacte.php` | 🔄 Eliminar tras migración |
| `pages/movimiento_manual.php` | 🔄 Eliminar tras migración |
| `pages/cierre_caja.php` | 🔄 Eliminar tras migración |
| `pages/facturacion_arca.php` | 🔄 Eliminar tras migración |
| `pages/licencia.php` | 🔄 Eliminar tras migración |
| `pages/perfil.php` | 🔄 Eliminar tras migración |
| `pages/infosesion.php` | ✅ Reemplazado por `core/Auth.php` + `config/config.php` |
| `pages/sidebar.php` | ✅ Reemplazado por `templates/menu.php` |
| `pages/topbar.php` | ✅ Reemplazado por `templates/topbar.php` |
| `pages/components/` | 🔄 Migrar componentes reutilizables |
| `ajax/*.php` (30 archivos) | ✅ Consolidados en `modulos/*/ajax.php` |
| `funciones/*.php` | ✅ Migrados a `core/Funciones.php` o modelos específicos |
| `config/db_config.php` | ✅ Reemplazado por `config/config.php` + `core/Conexion.php` |
| `config/validar_permisos.php` | ✅ Reemplazado por `core/Auth.php` |

#### 3.4.2. Actualizar `index.php` (Dashboard)

```php
<?php
require_once 'config/config.php';
Auth::requerirLogin();

// El dashboard puede quedar en la raíz o moverse a modulos/dashboard/
// Se recomienda mantener en raíz como punto de entrada principal
require_once PATH_TEMPLATES . 'header.php';
require_once PATH_TEMPLATES . 'menu.php';
require_once PATH_TEMPLATES . 'topbar.php';
// ... contenido del dashboard ...
require_once PATH_TEMPLATES . 'footer.php';
```

#### 3.4.3. Actualizar `login.php` y `logout.php`

```php
// login.php
<?php
require_once 'config/config.php';

if (Auth::estaLogueado()) {
    header('Location: ' . URL_BASE . 'index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::login($_POST['usuario'], $_POST['password'])) {
        header('Location: ' . URL_BASE . 'index.php');
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
// ... renderizar formulario ...
```

```php
// logout.php
<?php
require_once 'config/config.php';
Auth::logout();
```

---

## 4. Cronograma Resumido

| Semana | Fase | Actividades | Módulos |
|--------|------|-------------|---------|
| 1 | Fase 0 | Infraestructura base, core, templates, assets | — |
| 2 | Fase 1 | Núcleo modular, autoloading, enrutador | — |
| 3 | Fase 2 | Primer módulo completo (Clientes) | Clientes |
| 4 | Fase 3 | Migración de Productos y Ventas | Productos, Ventas |
| 5 | Fase 3 | Migración de Compras | Compras |
| 6 | Fase 4 | Migración de Proveedores y Caja | Proveedores, Caja |
| 7 | Fase 5 | Migración de Usuarios | Usuarios |
| 8 | Fase 6 | Migración de Reportes y Configuración | Reportes, Configuración |
| 9 | Fase 4 | Limpieza, testing, documentación | Todos |

---

## 5. Consideraciones Técnicas

### 5.1. Compatibilidad con PHP

- El proyecto actual soporta **PHP 5** (ver `config/db_config.php` — comentario "Opciones PDO para PHP 5")
- La nueva arquitectura usará **sintaxis compatible con PHP 7.4+** (tipos, `??`, arrow functions)
- Se recomienda actualizar el requisito mínimo a **PHP 7.4** o **PHP 8.0+**

### 5.2. Rutas y Enlaces

- Todos los enlaces usarán `URL_BASE` como prefijo
- Los assets se servirán desde `public/`
- Se actualizarán las referencias en CSS/JS de `css/` → `public/css/`, `js/` → `public/js/`

### 5.3. Seguridad

- Todas las consultas usan **prepared statements** (heredado del patrón actual)
- Los controladores de escritura (`guardar.php`, `eliminar.php`) validan permisos con `Auth::requirePermiso()`
- Los endpoints AJAX validan sesión y permisos
- Se mantiene el manejo global de excepciones

### 5.4. Testing

- **Testing manual:** Navegar cada módulo y verificar CRUD completo
- **Testing de permisos:** Verificar que usuarios sin permisos reciban 403
- **Testing de AJAX:** Verificar respuestas JSON correctas
- **Testing de migración:** Comparar datos antes/después de cada migración

### 5.5. Rollback

- Cada módulo migrado se mantiene funcional antes de eliminar el original
- Se usa un **sistema de feature flags** opcional: si `$_GET['legacy']` está presente, se carga la versión antigua
- Los backups de base de datos se realizan antes de cada fase

---

## 6. Convenciones de Código

### 6.1. Nombres de archivos y clases

| Elemento | Convención | Ejemplo |
|----------|-----------|---------|
| Modelos | `Modelo{Entidad}.php` | `ModeloCliente.php` |
| Controladores | `{Entidad}.php` (en raíz del módulo) | `clientes/index.php` |
| Vistas | snake_case | `listado.php`, `formulario.php` |
| AJAX | `ajax.php` (único por módulo) | `clientes/ajax.php` |

### 6.2. Estructura de un módulo típico

```
modulos/{entidad}/
├── index.php         ← Router interno (switch sobre acción)
├── listado.php       ← Vista: tabla/lista
├── formulario.php    ← Vista: formulario
├── guardar.php       ← Controlador: POST create/update
├── eliminar.php      ← Controlador: DELETE
├── ajax.php          ← Endpoint AJAX unificado
└── modelo.php        ← Modelo de datos
```

### 6.3. Patrón de enrutamiento interno

```php
// modulos/{entidad}/index.php
$accion = $_GET['accion'] ?? 'listar';
$id = $_GET['id'] ?? null;
$modelo = new Modelo{Entidad}();

switch ($accion) {
    case 'listar':
        $items = $modelo->findAll();
        require_once __DIR__ . '/listado.php';
        break;
    case 'crear':
        require_once __DIR__ . '/formulario.php';
        break;
    case 'editar':
        $item = $modelo->findById((int)$id);
        require_once __DIR__ . '/formulario.php';
        break;
}
```

---

## 7. Checklist de Verificación

### Fase 0 — Infraestructura
- [ ] `config/config.php` creado con constantes y autoloading
- [ ] `core/Conexion.php` implementado (Singleton PDO)
- [ ] `core/Auth.php` implementado (login, logout, permisos)
- [ ] `core/Funciones.php` creado con helpers globales
- [ ] `core/ControladorBase.php` creado
- [ ] `core/ModeloBase.php` creado
- [ ] `templates/header.php` creado
- [ ] `templates/menu.php` creado (migrado desde sidebar)
- [ ] `templates/footer.php` creado
- [ ] `templates/topbar.php` creado
- [ ] Assets movidos a `public/`

### Fase 1 — Núcleo
- [ ] Autoloading funcionando
- [ ] Manejador de excepciones global
- [ ] Login/logout funcionando con `Auth`
- [ ] Permisos funcionando con `Auth::tienePermiso()`

### Fase 2 — Módulo Clientes
- [ ] `modelo.php` con todas las consultas
- [ ] `index.php` con router interno
- [ ] `listado.php` funcional
- [ ] `formulario.php` funcional (crear + editar)
- [ ] `guardar.php` funcional
- [ ] `eliminar.php` funcional
- [ ] `ajax.php` con endpoints consolidados
- [ ] Menú actualizado
- [ ] Archivo original `pages/abm_clientes.php` eliminado

### Fase 3-6 — Módulos restantes
- [ ] Productos migrado y verificado
- [ ] Ventas migrado y verificado
- [ ] Compras migrado y verificado
- [ ] Proveedores migrado y verificado
- [ ] Caja migrado y verificado
- [ ] Usuarios migrado y verificado
- [ ] Reportes migrado y verificado
- [ ] Configuración migrado y verificado

### Fase 4 — Consolidación
- [ ] Todos los archivos `pages/` obsoletos eliminados
- [ ] Todos los archivos `ajax/` obsoletos eliminados
- [ ] Todos los archivos `funciones/` obsoletos eliminados
- [ ] `index.php` actualizado
- [ ] `login.php` y `logout.php` actualizados
- [ ] Testing completo de todos los módulos
- [ ] Documentación actualizada

---

## 8. Riesgos y Mitigaciones

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| **Pérdida de funcionalidad durante migración** | Alto | Migrar módulo por módulo; mantener archivos originales hasta verificación |
| **Incompatibilidad de versiones de PHP** | Medio | Verificar versión mínima; usar sintaxis compatible |
| **Romper enlaces existentes** | Alto | Usar redirecciones 301 desde URLs antiguas a nuevas |
| **Duplicación de lógica de negocio** | Medio | Extraer toda la lógica a modelos antes de crear vistas |
| **Problemas de permisos** | Alto | Mantener el sistema de permisos existente; migrar `tiene_permiso()` a `Auth` |

---

## 9. Conclusión

Este plan transforma el sistema POS actual de una arquitectura plana y monolítica a una arquitectura modular basada en el patrón **MVC simplificado**, donde cada módulo es independiente, reutilizable y fácil de mantener. La migración se realiza de forma incremental, minimizando el riesgo de interrumpir el funcionamiento del sistema en producción.

La estructura propuesta mantiene la filosofía del sistema actual (PDO, sesiones, permisos por roles) mientras introduce una organización clara que facilita:

- **Mantenimiento:** Cada módulo es un contenedor autocontenido
- **Escalabilidad:** Nuevos módulos se añaden siguiendo el mismo patrón
- **Testing:** Modelos y controladores son independientes y testeables
- **Colaboración:** Múltiples desarrolladores pueden trabajar en módulos distintos sin conflictos

---

*Documento generado como parte del proceso de refactorización del sistema POS `pos_dev`.*