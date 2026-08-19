<?php
/**
 * index.php - Controlador Frontal (Front Controller)
 * ────────────────────────────────────────────────
 * Punto de entrada único de la aplicación. Carga la configuración,
 * el Router y las rutas, y despacha la petición al manejador
 * correspondiente (archivo PHP o closure).
 *
 * URLs limpias (ej. /ventas, /productos) → Router → archivo correspondiente
 * URLs directas (ej. ajax/xxx.php, api/xxx.php, css/xxx.css) → sirve directamente vía .htaccess
 *
 * El router mantiene total compatibilidad con el acceso directo a archivos:
 * el .htaccess solo reescribe cuando la URL NO coincide con un archivo/directorio real.
 */

// 1. Configuración de base de datos y constantes (URL_BASE, PATH_BASE, $pdo)
require_once __DIR__ . '/config/db_config.php';

// 2. Definir BASE_PATH (path absoluto del proyecto en el filesystem)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// 3. Cargar helpers globales (route(), redirect(), auth(), url(), csrf_*())
require_once __DIR__ . '/core/helpers.php';

// 4. Cargar el Router
require_once __DIR__ . '/core/Router.php';

// 5. Instanciar el Router y registrar las rutas
//    $router se define como variable global para que los helpers puedan acceder
global $router;
$router = new Router();

//    Cargar definiciones de rutas (el archivo recibe $router por ámbito)
require __DIR__ . '/app/routes.php';

// 6. Despachar la petición actual
$router->dispatch();

