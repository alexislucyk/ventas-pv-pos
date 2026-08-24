<?php
// pages/actualizaciones.php - Módulo de Actualizaciones desde GitHub (PRODUCCIÓN)
include 'infosesion.php';
require '../config/db_config.php';
require PATH_BASE . 'config/actualizaciones.php';
require_once PATH_BASE . 'funciones/funcion_actualizaciones.php';

// Acceso: solo 'developer' (o permiso del módulo si ya se migró)
if ($_SESSION['usuario_rol'] !== 'developer' && !tiene_permiso('pages/actualizaciones.php')) {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

$mensaje   = '';
$tipo_msj  = '';
$logado    = [];

// Procesar solicitud de actualización (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_actualizacion'])) {
    if ($_SESSION['usuario_rol'] !== 'developer') {
        $mensaje  = 'Solo el usuario developer puede aplicar actualizaciones.';
        $tipo_msj = 'error';
    } else {
        $resultado = aplicar_actualizacion($pdo);
        $logado = $resultado['log'] ?? [];

        if ($resultado['success']) {
            $mensaje  = 'Sistema actualizado correctamente.';
            $tipo_msj = 'success';
        } else {
            $mensaje  = 'La actualización no se completó. Revise el registro.';
            $tipo_msj = 'error';
        }
    }
}

// Estado actual (usa caché salvo que se fuerce la comprobación con ?forzar=1
// o que se acabe de aplicar una actualización)
$forzar = !empty($_GET['forzar'])
       || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_actualizacion']));
$estado    = consultar_actualizaciones($pdo, $forzar);
$pendientes = $estado['migraciones_pendientes'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Sistema | <?php echo htmlspecialchars($nombre_empresa_sistema); ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .up-container { max-width: 1100px; margin: 0 auto; }
        .up-card { background: #1e1e1e; border: 1px solid #333; border-radius: 12px; padding: 25px 30px; margin-bottom: 22px; }
        .up-card h3 { color: #00bcd4; margin-top: 0; text-transform: uppercase; font-size: 1rem; letter-spacing: .5px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; }
        .stat-card { background: #252525; border: 1px solid #333; border-radius: 8px; padding: 18px; text-align: center; }
        .stat-card i { font-size: 1.8rem; margin-bottom: 8px; display: block; }
        .stat-card .stat-value { font-size: 1.4rem; font-weight: bold; color: #f0f0f0; }
        .stat-card .stat-label { font-size: .78em; color: #888; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
        .upd-display { border-left: 5px solid #4caf50; background: rgba(76,175,80,.08); padding: 18px 22px; border-radius: 8px; margin-bottom: 20px; }
        .upd-none { border-left: 5px solid #00bcd4; background: rgba(0,188,212,.06); padding: 18px 22px; border-radius: 8px; margin-bottom: 20px; }
        .upd-error { border-left: 5px solid #f39c12; background: rgba(243,156,18,.08); padding: 18px 22px; border-radius: 8px; margin-bottom: 20px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: .95rem; transition: .25s; text-decoration: none; }
        .btn-update { background: linear-gradient(135deg, #4caf50, #2e7d32); color: #fff; }
        .btn-update:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(76,175,80,.35); }
        .btn-update:disabled { background: #333; color: #888; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-check { background: linear-gradient(135deg, #00bcd4, #008ba3); color: #fff !important; }
        .btn-check:hover { transform: translateY(-2px); }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .alert-success { background: rgba(76,175,80,.1); border: 1px solid #4caf50; color: #4caf50; }
        .alert-error { background: rgba(244,67,54,.1); border: 1px solid #f44336; color: #f44336; }
        .log-box { background: #0d0d0d; color: #9ce9a1; font-family: Consolas, monospace; font-size: .82em; padding: 16px; border-radius: 8px; white-space: pre-wrap; max-height: 360px; overflow-y: auto; border: 1px solid #2a2a2a; }
        .var-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .var-table td { padding: 8px 12px; border-bottom: 1px solid #2a2a2a; font-size: .9em; }
        .var-table td:first-child { color: #888; width: 40%; }
        .var-table td:last-child { color: #eee; }
        .buena { color: #4caf50; } .mala { color: #f44336; } .nula { color: #f1c40f; }
        .badge-env { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: .75em; font-weight: bold; text-transform: uppercase; margin-bottom: 16px; }
        .badge-prod { background: rgba(244,67,54,.15); color: #f44336; border: 1px solid #f44336; }
        .badge-dev { background: rgba(243,156,18,.15); color: #f1c40f; border: 1px solid #f1c40f; }
        /* --- Overlay y barra de progreso de la consulta --- */
        #overlayConsulta {
            position: fixed; inset: 0; z-index: 1200;
            background: rgba(0,0,0,.72);
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }
        #overlayConsulta .prog-box {
            width: 420px; max-width: 90%;
            background: #1e1e1e; border: 1px solid #333;
            border-radius: 14px; padding: 28px 30px; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.6);
        }
        #overlayConsulta .prog-title {
            font-size: 1.05rem; font-weight: 700; color: #f0f0f0; margin-bottom: 4px;
        }
        #overlayConsulta .prog-fase {
            font-size: .85rem; color: #00bcd4; margin-bottom: 18px; min-height: 1.2em;
        }
        #overlayConsulta .prog-track {
            height: 10px; background: #2a2a2a; border-radius: 20px; overflow: hidden;
        }
        #overlayConsulta .prog-fill {
            height: 100%; width: 35%; border-radius: 20px;
            background: linear-gradient(90deg, #00bcd4, #008ba3);
            animation: prog-avance 1.1s ease-in-out infinite;
        }
        @keyframes prog-avance {
            0%   { transform: translateX(-110%); }
            100% { transform: translateX(320%); }
        }
        #overlayConsulta .prog-spin {
            display: inline-block; margin-right: 8px;
            animation: fa-spin 1.1s infinite linear;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>

        <div class="up-container">
            <h1><i class="fas fa-sync-alt"></i> Actualizar Sistema</h1>
            <p style="color:#888;">Consulta el repositorio de GitHub en busca de actualizaciones y aplícalas desde aquí (pensado para producción).</p>

            <?php if ($estado['entorno'] === 'produccion'): ?>
                <span class="badge-env badge-prod">Entorno: PRODUCCIÓN</span>
            <?php else: ?>
                <span class="badge-env badge-dev">Entorno: DESARROLLO</span>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_msj; ?>">
                    <i class="fas fa-<?php echo $tipo_msj === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($logado)): ?>
                <div class="up-card">
                    <h3>Registro de la operación</h3>
                    <div class="log-box"><?php echo htmlspecialchars(implode("\n", $logado)); ?></div>
                </div>
            <?php endif; ?>

            <!-- Estado de versiones -->
            <div class="up-card">
                <h3>Versiones</h3>
                <div class="grid" style="margin-top:15px;">
                    <div class="stat-card">
                        <i class="fas fa-code-branch" style="color:#00bcd4;"></i>
                        <div class="stat-value">v<?php echo htmlspecialchars($estado['version_local'] ?: '-'); ?></div>
                        <div class="stat-label">Instalada</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-cloud-download-alt" style="color:#4caf50;"></i>
                        <div class="stat-value"><?php echo htmlspecialchars($estado['version_display'] ?? '-'); ?></div>
                        <div class="stat-label">Disponible en GitHub</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-tasks" style="color:#f1c40f;"></i>
                        <div class="stat-value"><?php echo count($pendientes); ?></div>
                        <div class="stat-label">Migraciones pendientes</div>
                    </div>
                </div>

                <table class="var-table">
                    <tr><td>Rama local</td><td><?php echo htmlspecialchars($estado['branch_local'] ?: '-'); ?></td></tr>
                    <tr><td>Último commit local</td><td><code><?php echo htmlspecialchars($estado['commit_local'] ?: '-'); ?></code></td></tr>
                    <tr><td>Tag detectado en GitHub</td><td><?php echo htmlspecialchars($estado['raw_tag'] ?: '-'); ?> (<?php echo htmlspecialchars($estado['metodo_github'] ?: '-'); ?>)</td></tr>
                    <tr>
                        <td>Git disponible en servidor</td>
                        <td>
                            <?php echo $estado['git_disponible']
                                ? '<span class="buena"><i class="fas fa-check-circle"></i> Sí</span>'
                                : '<span class="mala"><i class="fas fa-times-circle"></i> No</span>'; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Actualización disponible</td>
                        <td>
                            <?php if (!empty($estado['error_github'])): ?>
                                <span class="nula"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($estado['error_github']); ?></span>
                            <?php elseif ($estado['hay_actualizacion']): ?>
                                <span class="buena"><i class="fas fa-arrow-circle-up"></i> Sí</span>
                            <?php else: ?>
                                <span class="mala"><i class="fas fa-check-circle"></i> No</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Banner según estado -->
            <?php if (!empty($estado['error_github'])): ?>
                <div class="upd-error">
                    <strong><i class="fas fa-exclamation-triangle"></i> No se pudo contactar a GitHub.</strong>
                    <p style="margin:6px 0 0;color:#bbb;"><?php echo htmlspecialchars($estado['error_github']); ?> Verifique la conexión a Internet.</p>
                </div>
            <?php elseif ($estado['hay_actualizacion']): ?>
                <div class="upd-display">
                    <strong style="font-size:1.05rem;">Hay una nueva versión disponible.</strong>
                    <p style="margin:6px 0 0;color:#bbb;">
                        Se ejecutará un <strong>respaldo de la base de datos</strong> automático, se descargarán los cambios
                        desde GitHub, se aplicarán las migraciones SQL pendientes y se actualizará la versión del sistema.
                    </p>
                </div>
            <?php else: ?>
                <div class="upd-none">
                    <strong><i class="fas fa-check-circle"></i> El sistema está actualizado.</strong>
                    <p style="margin:6px 0 0;color:#bbb;">No hay actualizaciones disponibles en este momento.</p>
                </div>
            <?php endif; ?>

            <div style="display:flex; gap:12px; margin-top:6px; flex-wrap:wrap;">
                <button type="button" id="btnComprobar" class="btn btn-check">
                    <i class="fas fa-sync"></i> <span>Comprobar de nuevo</span>
                </button>

                <?php if ($estado['hay_actualizacion']): ?>
                    <button type="button" class="btn btn-update" id="btnAplicar">
                        <i class="fas fa-download"></i> Actualizar ahora
                    </button>
                <?php endif; ?>
            </div>

            <form method="post" id="formActualizar" style="display:none;">
                <input type="hidden" name="aplicar_actualizacion" value="1">
            </form>

            <p style="margin-top:18px;font-size:.82em;color:#666;">
                <i class="fas fa-info-circle"></i> La actualización sobrescribe el código con la rama
                <code><?php echo htmlspecialchars(GIT_BRANCH_TARGET); ?></code> del repositorio
                <code><?php echo htmlspecialchars(GIT_REMOTE_URL); ?></code>. Antes de ejecutarla se genera un respaldo de la base de datos.
            </p>
        </div>
    </div>

    <!-- Overlay de progreso al comprobar actualizaciones -->
    <div id="overlayConsulta" style="display:none;">
        <div class="prog-box">
            <div class="prog-title"><i class="fas fa-sync prog-spin"></i>Consultando GitHub…</div>
            <div class="prog-fase" id="progFase">Contactando repositorio…</div>
            <div class="prog-track"><div class="prog-fill"></div></div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var urlConsulta = "<?php echo URL_BASE; ?>ajax/consultar_actualizaciones.php";

        // ---- Comprobar de nuevo (con barra de progreso) ----
        var btnComprobar = document.getElementById('btnComprobar');
        if (btnComprobar) {
            btnComprobar.addEventListener('click', function () {
                var spun = this.querySelector('i');
                if (spun) spun.classList.add('fa-spin');
                var overlay = document.getElementById('overlayConsulta');
                var faseEl = document.getElementById('progFase');
                overlay.style.display = 'flex';

                var fases = [
                    'Contactando repositorio…',
                    'Verificando rama y commits…',
                    'Comparando versiones…',
                    'Finalizando consulta…'
                ];
                var i = 0;
                var timer = setInterval(function () {
                    i = (i + 1) % fases.length;
                    if (faseEl) faseEl.textContent = fases[i];
                }, 1200);

                // Consulta real forzando la actualización de la caché
                fetch(urlConsulta, { method: 'GET', cache: 'no-store' })
                    .then(function (r) { return r.json().catch(function () { return {}; }); })
                    .then(function () {
                        clearInterval(timer);
                        if (faseEl) faseEl.textContent = '¡Listo! Recargando…';
                        setTimeout(function () { location.reload(); }, 600);
                    })
                    .catch(function () {
                        clearInterval(timer);
                        if (faseEl) faseEl.textContent = 'Error de conexión. Recargando…';
                        setTimeout(function () { location.reload(); }, 800);
                    });
            });
        }

        // ---- Actualizar ahora (confirmación) ----
        var btn = document.getElementById('btnAplicar');
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (window.confirmarAccion) {
                    window.confirmarAccion(
                        '¿Actualizar el sistema?',
                        'Se generará un respaldo de la base de datos y se sobrescribirá el código con la última versión de GitHub. Esta operación puede tardar unos segundos.',
                        'SÍ, ACTUALIZAR',
                        'btn-success',
                        function () { document.getElementById('formActualizar').submit(); }
                    );
                } else if (window.confirm('¿Seguro que desea actualizar el sistema?')) {
                    document.getElementById('formActualizar').submit();
                }
            });
        }
    });
    </script>
</body>
</html>