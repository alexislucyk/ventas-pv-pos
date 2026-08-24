<?php
// funciones/funcion_actualizaciones.php - Lógica de consulta y aplicación de
// actualizaciones desde un repositorio de GitHub (orientada a PRODUCCIÓN).
//
// Requiere: config/db_config.php y config/actualizaciones.php incluidos antes.

if (!function_exists('actualizaciones_git_disponible')) {

    /**
     * Indica si Git está instalado y los comandos de shell están habilitados.
     */
    function actualizaciones_git_disponible() {
        if (!function_exists('shell_exec')) return false;
        $out = @shell_exec('git --version 2>&1');
        return !empty($out) && stripos((string)$out, 'git version') !== false;
    }

    /**
     * Rafz real del repositorio de la aplicación.
     * Use forward slashes (compatible con git en Windows).
     */
    function actualizaciones_git_root() {
        return str_replace('\\', '/', dirname(__DIR__));
    }

    /**
     * Ejecuta un comando git dentro de la raíz de la aplicación.
     *
     * Se antepone `-c safe.directory=<raiz>` para evitar el error
     * "dubious ownership" que Git lanza cuando el .git fue creado por otro
     * usuario (muy común en produccion al correr Apache como un usuario distinto).
     *
     * @param string $cmd Comandos a ejecutar después de "git" (ej. "rev-parse --short HEAD").
     * @return array{code:int, out:string}
     */
    function actualizaciones_ejecutar_git($cmd) {
        $root  = actualizaciones_git_root();
        $safe  = '"' . addcslashes($root, '"\\') . '"';
        $line  = 'git -c safe.directory=' . $safe . ' ' . $cmd . ' 2>&1';
        $prevDir = getcwd();
        if (is_dir($root)) chdir($root);

        $output = @shell_exec($line);
        $code   = ($output === null) ? 1 : 0;

        if ($prevDir !== false) chdir($prevDir);

        $out = trim((string)$output);
        // Git escribe errores a stderr (unificada con 2>&1) usando palabras clave.
        $fallo = ($out === '')
              || stripos($out, 'fatal') !== false
              || stripos($out, 'error:') !== false
              || stripos($out, 'authentication failed') !== false;
        $code = $fallo ? 1 : 0;
        return ['code' => $code, 'out' => $out];
    }

    /**
     * Registra (best-effort) la raíz como directorio seguro en la config global
     * de git para que también funcionen comandos git fuera de esta librería.
     * Es lo que recomienda el propio mensaje de error de git.
     */
    function actualizaciones_asegurar_safe_dir() {
        $root  = actualizaciones_git_root();
        $safe  = '"' . addcslashes($root, '"\\') . '"';
        @shell_exec('git config --global --add safe.directory ' . $safe . ' 2>&1');
    }

    /**
     * Normaliza una versión (quita "v", espacios, sufijos).
     */
    function actualizaciones_normalizar_version($v) {
        $v = trim((string)$v);
        $v = ltrim($v, 'vV ');
        $v = preg_replace('/[^0-9.].*$/', '', $v);
        return trim($v, '.');
    }

    /**
     * Consulta la última versión publicada en GitHub usando la API pública.
     * Prioriza el último release; si no hay releases, usa la última tag.
     *
     * @return array{version:?string, raw:?string, method:string, error:?string}
     */
    function actualizaciones_consulta_github() {
        $owner = defined('GIT_OWNER') ? GIT_OWNER : '';
        $repo  = defined('GIT_REPO')  ? GIT_REPO  : '';
        $timeout = defined('GITHUB_TIMEOUT') ? GITHUB_TIMEOUT : 8;

        $urls = [
            "https://api.github.com/repos/$owner/$repo/releases/latest",
            "https://api.github.com/repos/$owner/$repo/tags",
        ];

        // Indicador de si al menos una petición llegó a la API (aunque responda 404/llena).
        $conectado   = false;
        $hay_version = false;

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'LS-POS-PRO/Updater');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/vnd.github+json']);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            // Error de red: no llegó la petición
            if ($cerr) {
                continue;
            }

            // Respuesta HTTP válida de GitHub (incluye 404=sin releases, 200=ok)
            $conectado = true;

            // Hemos visto la respuesta; solo nos sirve si es 200
            if ($http !== 200) {
                continue;
            }

            $json = json_decode($resp, true);
            $tag  = null;

            if (isset($json['tag_name'])) {
                $tag = $json['tag_name'];
            } elseif (is_array($json) && isset($json[0]['name'])) {
                $tag = $json[0]['name'];
            }

            if (!empty($tag)) {
                $hay_version = true;
                return [
                    'version' => actualizaciones_normalizar_version($tag),
                    'raw'     => $tag,
                    'method'  => isset($json['tag_name']) ? 'release' : 'tag',
                    'error'   => null,
                ];
            }
        }

        if (!$conectado) {
            // Ninguna petición llegó a la API: problema real de conexión
            return ['version' => null, 'raw' => null, 'method' => 'none', 'error' => 'No se pudo contactar con la API de GitHub. Verifique la conexión a Internet.'];
        }

        // La API respondió pero el repositorio no publica releases/tags:
        // no es un error de conexión, simplemente no hay versión con la que comparar.
        return ['version' => null, 'raw' => null, 'method' => 'none', 'error' => null];
    }

    /**
     * Obtiene los datos locales de git: rama actual y último commit.
     */
    function actualizaciones_git_local() {
        $branch = actualizaciones_ejecutar_git('rev-parse --abbrev-ref HEAD')['out'];
        $commit = actualizaciones_ejecutar_git('rev-parse --short HEAD')['out'];
        return ['branch' => $branch, 'commit' => $commit];
    }

    /**
     * Obtiene el SHA remoto de la rama objetivo (via ls-remote). Null si falla.
     */
    function actualizaciones_sha_remoto() {
        $branch = defined('GIT_BRANCH_TARGET') ? GIT_BRANCH_TARGET : 'main';
        $out = actualizaciones_ejecutar_git('ls-remote ' . GIT_REMOTE_NAME . ' refs/heads/' . $branch)['out'];
        $first = strtok($out, "\n");
        if ($first) {
            $parts = preg_split('/\s+/', trim($first));
            return $parts[0] ?? null;
        }
        return null;
    }

    /**
     * Datos de migraciones pendientes: archivos .sql con número > último aplicado.
     *
     * Si todavía no hay un contador registrado (`ultima_migracion_aplicada`),
     * se asume que el esquema ya está al día y se toma como "última aplicada" la
     * migración de mayor número existente en la carpeta. Esto evita re-ejecutar
     * en producción migraciones ya aplicadas (ALTER TABLE, etc.) y que el panel
     * muestre un número falso de "pendientes".
     */
    function actualizaciones_migraciones_pendientes($pdo) {
        $dir = rtrim(defined('PATH_BASE') ? PATH_BASE : '', '/\\') . '/migrations';
        if (!is_dir($dir)) return [];

        // Máximo número de migración presente en disco
        $max_disco = 0;
        foreach ((array)glob($dir . '\\*.sql') as $archivo) {
            if (preg_match('/^(\d+)_/', basename($archivo), $m)) {
                $max_disco = max($max_disco, (int)$m[1]);
            }
        }

        $ultima = 0;
        try {
            $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'ultima_migracion_aplicada' LIMIT 1");
            $stmt->execute();
            $ultima = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $ultima = 0;
        }

        // Nunca se registró un contador: en un sistema ya desplegado asumimos
        // que el esquema está al día hasta la migración de mayor número.
        if ($ultima <= 0) {
            $ultima = $max_disco;
        }

        $pendientes = [];
        foreach ((array)glob($dir . '\\*.sql') as $archivo) {
            if (preg_match('/^(\d+)_/', basename($archivo), $m)) {
                $num = (int)$m[1];
                if ($num > $ultima) {
                    $pendientes[$num] = $archivo;
                }
            }
        }
        ksort($pendientes);
        return $pendientes;
    }

    /**
     * Verifica si una tabla existe en la base de datos actual.
     */
    function actualizaciones_verificar_tabla($pdo, $tabla) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$tabla]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Verifica si una columna existe en una tabla dentro de la BD actual.
     */
    function actualizaciones_verificar_columna($pdo, $tabla, $columna) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$tabla, $columna]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Analiza un script SQL y extrae las operaciones de estructura (DDL) para
     * poder verificar contra el esquema real si ya fueron aplicadas manualmente.
     *
     * @return array<int, array{tipo:string, tabla:string, nombre?:string}>
     */
    function actualizaciones_analizar_sql($sql) {
        $ops = [];

        // 1) Índices / claves: ADD [UNIQUE] KEY|INDEX
        if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $ops[] = ['tipo' => 'indice', 'tabla' => $r[1], 'nombre' => strtolower($r[2])];
            }
        }

        // 2) Columnas: ADD [COLUMN] `col`
        //    Excluye que tras ADD siga KEY/INDEX/CONSTRAINT/PRIMARY/FOREIGN/DEFAULT
        if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?(?!\s*(?:UNIQUE\s+)?(?:KEY|INDEX|CONSTRAINT|PRIMARY|FOREIGN|DEFAULT)\b)`?(\w+)`?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $ops[] = ['tipo' => 'columna', 'tabla' => $r[1], 'nombre' => strtolower($r[2])];
            }
        }

        // 3) Tablas: CREATE TABLE [IF NOT EXISTS] `tabla`
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $ops[] = ['tipo' => 'tabla', 'tabla' => $r[1]];
            }
        }

        return $ops;
    }

    /**
     * Determina si una migración ya fue aplicada manualmente.
     *
     * Compara las operaciones DDL del script contra el esquema real
     * (information_schema). Si TODAS las estructuras ya existen, se considera
     * que la migración ya fue implementada y no debe re-ejecutarse.
     *
     * @return bool
     */
    function actualizaciones_migracion_ya_aplicada($pdo, $sql) {
        $ops = actualizaciones_analizar_sql($sql);
        if (empty($ops)) {
            // Sin operaciones DDL detectadas (solo INSERT/UPDATE/DELETE):
            // no se puede verificar por estructura. Se asume que NO está aplicada
            // (suele tratarse de datos y es seguro dejarla para ejecución).
            return false;
        }

        foreach ($ops as $op) {
            if ($op['tipo'] === 'columna') {
                if (!actualizaciones_verificar_columna($pdo, $op['tabla'], $op['nombre'])) {
                    return false;
                }
            } else {
                // tabla e índices: verificamos que la tabla exista
                if (!actualizaciones_verificar_tabla($pdo, $op['tabla'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Aplica una migración SQL, verificando antes que no haya sido aplicada
     * manualmente.
     *
     * @return array{0:string, 1:string} Estado ('aplicada'|'omitida'|'error') y mensaje.
     */
    function actualizaciones_aplicar_migracion($pdo, $archivo) {
        $sql = file_get_contents($archivo);
        if ($sql === false || trim($sql) === '') {
            return ['error', 'No se pudo leer la migración ' . basename($archivo)];
        }

        // ANTES de ejecutar: chequear que no haya sido implementada manualmente
        if (actualizaciones_migracion_ya_aplicada($pdo, $sql)) {
            return ['omitida', 'Ya aplicada (estructura existente en la BD)'];
        }

        try {
            $pdo->exec($sql);
            return ['aplicada', 'OK'];
        } catch (Exception $e) {
            $msg = $e->getMessage();

            // Migraciones con DELIMITER / CREATE FUNCTION no son ejecutables con
            // la API de PDO (solo la consola mysql). Se omiten con aviso.
            if (stripos($msg, 'delimiter') !== false
                || (stripos($msg, 'sql syntax') !== false && stripos($sql, 'delimiter') !== false)) {
                return ['omitida', 'No ejecutable automáticamente (usa DELIMITER/CREATE FUNCTION). Aplíquela manualmente: ' . $msg];
            }

            // Si falla por duplicado (p. ej. columna ya existente), tratarla como omitida.
            if (stripos($msg, 'duplicate column') !== false
                || stripos($msg, 'already exists') !== false
                || stripos($msg, 'duplicate') !== false) {
                return ['omitida', 'Ya aplicada: ' . $msg];
            }
            return ['error', $msg];
        }
    }

    /**
     * Fallback de versión local desde la BD.
     */
    function actualizaciones_version_local_bd($pdo) {
        try {
            $stmt = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'app_version' LIMIT 1");
            $v = $stmt->fetchColumn();
            return $v ?: '0.0.0';
        } catch (Exception $e) {
            return '0.0.0';
        }
    }

    /**
     * Ruta del archivo de caché del estado de actualizaciones.
     * Usa __DIR__ (raíz real de la app, independiente de PATH_BASE) para
     * funcionar tanto en el navegador como en scripts CLI.
     */
    function actualizaciones_ruta_cache() {
        $dir = dirname(__DIR__) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/actualizaciones_estado.json';
    }

    /**
     * Guarda el estado en un archivo de caché para evitar consultas de red
     * repetidas en cada carga de página.
     */
    function actualizaciones_guardar_cache(array $estado) {
        $archivo = actualizaciones_ruta_cache();
        $contenido = json_encode(['ts' => time(), 'estado' => $estado]);
        @file_put_contents($archivo, $contenido);
    }

    /**
     * Lee el estado desde la caché si aún no expiró. null si no hay caché válida.
     */
    function actualizaciones_leer_cache() {
        $archivo = actualizaciones_ruta_cache();
        if (!is_file($archivo)) return null;
        $json = json_decode(@file_get_contents($archivo), true);
        if (!isset($json['ts']) || !isset($json['estado'])) return null;

        $ttl = defined('ACTUALIZACIONES_CACHE_TTL') ? ACTUALIZACIONES_CACHE_TTL : 300;
        if (time() - (int)$json['ts'] > $ttl) return null;

        return $json['estado'];
    }

    /**
     * Arma el estado completo del módulo de actualizaciones.
     *
     * El resultado se cachea en disco unos minutos para no golpear a la API de
     * GitHub ni al remoto git en cada impresión de la página. Con $forzar=true
     * se ignora la caché y se recalculan las consultas de red (botón "Comprobar").
     *
     * @param PDO   $pdo
     * @param bool  $forzar  Recalcular ignorando la caché.
     * @return array
     */
    function consultar_actualizaciones($pdo, $forzar = false) {
        // Usar caché salvo que se pida forzar
        if (!$forzar) {
            $cache = actualizaciones_leer_cache();
            if ($cache !== null) return $cache;
        }

        $version_local = defined('APP_VERSION') ? APP_VERSION : actualizaciones_version_local_bd($pdo);

        $github    = actualizaciones_consulta_github();
        // Best-effort: registrar la raíz como safe.directory en la config global de git
        actualizaciones_asegurar_safe_dir();
        $git_ok    = actualizaciones_git_disponible();
        $git_local = $git_ok ? actualizaciones_git_local() : ['branch' => null, 'commit' => null];
        $sha_remoto = $git_ok ? actualizaciones_sha_remoto() : null;

        // Detección por versión (releases/tags) cuando hay tag disponible
        $hay_actualizacion = false;
        $modo_deteccion = 'ninguno';
        if (!empty($version_local) && !empty($github['version'])) {
            $hay_actualizacion = version_compare($version_local, $github['version'], '<');
            $modo_deteccion = 'version';
        } elseif ($git_ok && !empty($sha_remoto) && !empty($git_local['commit'])) {
            // Sin tags publicados: comparar el HEAD local contra la rama remota (origin/main).
            // Si el commit local difiere del remoto, hay cambios por aplicar.
            $hay_actualizacion = ! (strncmp($git_local['commit'], $sha_remoto, 7) === 0);
            $modo_deteccion = 'commit';
        }

        // Texto a mostrar en la card "Disponible en GitHub".
        // Si hay release/tag se muestra la versión; si la detección es por commit
        // (repo sin tags), se muestra el commit remoto para indicar la actualización.
        $version_display = '-';
        if (!empty($github['version'])) {
            $version_display = 'v' . $github['version'];
        } elseif ($modo_deteccion === 'commit' && !empty($sha_remoto)) {
            $version_display = 'commit ' . substr($sha_remoto, 0, 7);
        }

        $estado = [
            'entorno'                => es_entorno_produccion() ? 'produccion' : 'desarrollo',
            'git_disponible'         => $git_ok,
            'branch_local'           => $git_local['branch'],
            'commit_local'           => $git_local['commit'],
            'sha_remoto'             => $sha_remoto,
            'version_local'          => $version_local,
            'version_remota'         => $github['version'],
            'version_display'        => $version_display,
            'raw_tag'                => $github['raw'],
            'metodo_github'          => $github['method'],
            'error_github'           => $github['error'],
            'modo_deteccion'         => $modo_deteccion,
            'hay_actualizacion'      => $hay_actualizacion,
            'migraciones_pendientes' => actualizaciones_migraciones_pendientes($pdo),
        ];

        // Guardar en caché para las próximas cargas
        actualizaciones_guardar_cache($estado);

        return $estado;
    }
}

if (!function_exists('aplicar_actualizacion')) {

    /**
     * Aplica la actualización: backup de BD + git fetch/reset + migraciones.
     *
     * @param PDO $pdo
     * @return array{success:bool, log:array}
     */
    function aplicar_actualizacion($pdo) {
        $log    = [];
        $branch = defined('GIT_BRANCH_TARGET') ? GIT_BRANCH_TARGET : 'main';
        $remote = defined('GIT_REMOTE_NAME')    ? GIT_REMOTE_NAME    : 'origin';

        $log[] = '[PASO] Verificando Git...';
        if (!actualizaciones_git_disponible()) {
            $log[] = '[ERROR] Git no está disponible en este servidor.';
            return ['success' => false, 'log' => $log];
        }

        // 1) Backup de la base de datos
        $log[] = '[PASO] Generando backup de la base de datos antes de actualizar...';
        if (!actualizaciones_hacer_backup($pdo, $log)) {
            $log[] = '[ERROR] No se pudo garantizar un backup. Se aborta la actualización.';
            return ['success' => false, 'log' => $log];
        }

        // 2) Descargar cambios del remoto
        $log[] = "[PASO] Descargando últimos cambios desde GitHub ({$remote}/{$branch})...";
        $res = actualizaciones_ejecutar_git('fetch ' . $remote . ' ' . $branch);
        $log[] = $res['out'] ?: '(sin salida)';
        if ($res['code'] !== 0) {
            $log[] = '[ERROR] Falló el fetch de git: ' . $res['out'];
            return ['success' => false, 'log' => $log];
        }

        // 3) Aplicar los cambios de la rama objetivo
        $log[] = "[PASO] Aplicando cambios de {$remote}/{$branch}...";
        $res = actualizaciones_ejecutar_git('reset --hard ' . $remote . '/' . $branch);
        $log[] = $res['out'] ?: '(sin salida)';
        if ($res['code'] !== 0) {
            $log[] = '[ERROR] Falló al aplicar los cambios: ' . $res['out'];
            return ['success' => false, 'log' => $log];
        }

        // 4) Ejecutar migraciones pendientes
        $pendientes = actualizaciones_migraciones_pendientes($pdo);
        if (empty($pendientes)) {
            $log[] = '[OK] No hay migraciones SQL pendientes.';
        } else {
            $max = 0;
            foreach ($pendientes as $num => $archivo) {
                $log[] = "[PASO] Aplicando migración #{$num}: " . basename($archivo);
                list($estado_mig, $msg) = actualizaciones_aplicar_migracion($pdo, $archivo);

                if ($estado_mig === 'error') {
                    $log[] = "[ERROR] Migración #{$num} falló: {$msg}";
                    return ['success' => false, 'log' => $log];
                }
                if ($estado_mig === 'omitida') {
                    $log[] = "[SKIP] Migración #{$num} omitida: {$msg}";
                } else {
                    $log[] = "[OK] Migración #{$num} aplicada.";
                }
                $max = max($max, $num);
            }
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
                );
                $stmt->execute([(string)$max]);
                $log[] = "[OK] Última migración registrada: #{$max}.";
            } catch (Exception $e) {
                $log[] = '[AVISO] No se pudo registrar última migración: ' . $e->getMessage();
            }
        }

        // 5) Actualizar la versión en BD según la última tag de GitHub
        $github = actualizaciones_consulta_github();
        if (!empty($github['version'])) {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO configuracion (clave, valor) VALUES ('app_version', ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
                );
                $stmt->execute([$github['version']]);
                $log[] = "[OK] Versión en BD actualizada a {$github['version']}.";
            } catch (Exception $e) {
                $log[] = '[AVISO] No se pudo actualizar versión en BD: ' . $e->getMessage();
            }
        }

        $log[] = '[OK] Actualización completada exitosamente.';
        return ['success' => true, 'log' => $log];
    }

    /**
     * Ejecuta el backup de la BD reutilizando procesos/backup_database.php.
     *
     * @return bool true si el backup se generó (o no arrojó error fatal).
     */
    function actualizaciones_hacer_backup($pdo, &$log) {
        try {
            // Habilitar temporalmente el backup
            $stmt = $pdo->prepare(
                "INSERT INTO configuracion (clave, valor) VALUES ('backup_habilitado', '1')
                 ON DUPLICATE KEY UPDATE valor = '1'"
            );
            $stmt->execute();

            // Ejecutar el script de backup capturando su salida
            ob_start();
            include PATH_BASE . 'procesos/backup_database.php';
            $output = ob_get_clean();

            // Restaurar configuración original salvo que la tuviera activa
            $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'backup_habilitado'");
            $stmt->execute();
            $estado_original = $stmt->fetchColumn();
            if ($estado_original === false || $estado_original === '0') {
                $stmt = $pdo->prepare("UPDATE configuracion SET valor = '0' WHERE clave = 'backup_habilitado'");
                $stmt->execute();
            }

            $log[] = trim($output) ?: '(backup generado)';
            return true;
        } catch (Exception $e) {
            $log[] = '[AVISO] Error en el backup: ' . $e->getMessage();
            return false;
        }
    }
}