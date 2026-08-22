<?php
// config/actualizaciones.php - Configuración del módulo de Actualizaciones (GitHub)

/**
 * ENTORNOS SOPORTADOS
 * Esta funcionalidad está pensada para PRODUCCIÓN (carpeta sin sufijo `_dev`).
 * En desarrollo se muestra pero se advierte que no se recomienda aplicarla.
 */

// Repositorio remoto objetivo (debe coincidir con `git remote -v`)
define('GIT_REMOTE_NAME', 'origin');
define('GIT_REMOTE_URL', 'https://github.com/alexislucyk/ventas-pv-pos.git');
define('GIT_BRANCH_TARGET', 'main');
define('GIT_OWNER', 'alexislucyk');
define('GIT_REPO', 'ventas-pv-pos');

// Timeout (seg) para las peticiones a GitHub
if (!defined('GITHUB_TIMEOUT')) {
    define('GITHUB_TIMEOUT', 5);
}

// TTL de la caché del estado de actualizaciones (segundos).
// Mientras la caché no expire, la página NO consulta GitHub ni el remoto git,
// por lo que carga casi al instante. Se refresca con el botón "Comprobar de nuevo".
if (!defined('ACTUALIZACIONES_CACHE_TTL')) {
    define('ACTUALIZACIONES_CACHE_TTL', 300); // 5 minutos
}

// Determinar si estamos en producción (carpeta sin sufijo `_dev`)
function es_entorno_produccion() {
    if (!defined('PATH_BASE')) return false;
    return substr(basename(rtrim(PATH_BASE, '/\\')), -4) !== '_dev';
}