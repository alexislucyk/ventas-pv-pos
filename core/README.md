# Router del Sistema POS

Sistema de enrutamiento ligero (sin dependencias externas) para el proyecto POS.

## Estructura

```
core/
├── Router.php        # Clase Router (registro, matching, dispatch)
├── helpers.php       # Funciones helper (route(), redirect(), auth(), url(), csrf)
app/
└── routes.php        # Definición de todas las rutas
.htaccess             # Reescritura de URLs (solo URLs limpias → index.php)
index.php             # Front controller (controlador principal)
pages/dashboard.php   # Dashboard extraído de index.php
pages/404.php         # Página de error 404 personalizada
```

## Cómo funciona

1. **`.htaccess`** reescribe todas las URLs que **no** coinciden con un archivo/directorio real a `index.php`
2. **`index.php`** (front controller) carga: config → helpers → Router → routes → dispatch
3. El **Router** normaliza la URI (saca el prefijo `URL_BASE`), busca una ruta coincidente y ejecuta el handler:
   - **Archivo PHP existente** → se incluye con `chdir()` al directorio del archivo (para que los `include` relativos funcionen)
   - **Closure** → se ejecuta directamente (ámbito global)

### Compatibilidad hacia atrás

- Las URLs existentes siguen funcionando: `pages/ventas.php`, `ajax/xxx.php`, `api/xxx.php`, `css/xxx.css`, etc.
- El `.htaccess` usa `!-f` y `!-d` para servir archivos reales directamente
- Solo las URLs limpias (ej. `/ventas`, `/productos`) pasan por el router

## Uso

### Registrar una ruta

En `app/routes.php`:

```php
// Ruta simple (archivo existente)
$router->get('/ventas', 'pages/ventas.php', 'ventas');

// Ruta POST
$router->post('/api/webhook', 'procesos/webhook.php');

// Ruta con parámetro dinámico
$router->get('/producto/{id}', 'pages/producto_detalle.php', 'producto');

// Closure (ejecúta lógica personalizada)
$router->get('/test', function($params) {
    echo "¡Hola!";
}, 'test');

// Todos los métodos
$router->any('/utilidad', 'pages/utilidad.php', 'utilidad');
```

### Generar URLs en vistas

> **Nota sobre URLs:** el prefijo de la URL (folder) se detecta **dinámicamente**
> según el directorio donde corre la app (no está hardcodeado). Si la app corre
> en `/ventas_dev/`, `route()` genera `/ventas_dev/...`; si corre en `/pos_prod/`,
> genera `/pos_prod/...`. A continuación se usan `<folder>` como placeholder.

```php
// URL limpia basada en nombre de ruta
echo route('ventas');                    // → <folder>/ventas
echo route('producto', ['id' => 123]);     // → <folder>/producto/123

// URL para assets
echo url('css/style.css');                // → <folder>/css/style.css
```

### Redirecciones

```php
redirect('ventas');        // → redirige a la ruta nombrada
redirect('<folder>/login'); // → redirige a URL absoluta
```

### Autenticación

```php
require_login();           // redirige a /login si no está autenticado
require_permiso('pages/ventas.php'); // 403 si no tiene permiso
```

### CSRF

```php
// En formulario:
echo csrf_field();  // → <input type="hidden" name="_csrf_token" value="...">

// En controlador:
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}
```

## Migración progresiva

Para migrar una página existente a URL limpia:

1. Agregar la ruta en `app/routes.php`:
   ```php
   $router->get('/clientes', 'pages/abm_clientes.php', 'clientes');
   ```

2. Usar `route()` en los enlaces:
   ```php
   <a href="<?php echo route('clientes'); ?>">Clientes</a>
   ```

3. La URL antigua (`pages/abm_clientes.php`) **continúa funcionando** — la migración es progresiva y no rompe nada.
