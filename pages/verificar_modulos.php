<?php
// pages/verificar_modulos.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer');
require '../config/db_config.php';

if ($_SESSION['usuario_rol'] !== 'developer') {
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}

// --- ELIMINAR MÓDULO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_modulo'])) {
    $id_eliminar = (int)$_POST['modulo_id'];
    try {
        $pdo->beginTransaction();
        // Eliminar permisos asociados al módulo
        $pdo->prepare("DELETE FROM permisos_usuario WHERE modulo_id = ?")->execute(array($id_eliminar));
        // Eliminar el módulo
        $stmt_del = $pdo->prepare("DELETE FROM modulos WHERE id = ?");
        $stmt_del->execute(array($id_eliminar));
        $pdo->commit();
        $mensaje = "✅ Módulo eliminado correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error al eliminar: " . $e->getMessage();
    }
}

// --- CARGAR MÓDULOS ---
$modulos = $pdo->query("SELECT * FROM modulos ORDER BY seccion, nombre")->fetchAll();

// --- DETECTAR DUPLICADOS (por archivo y por nombre) ---
$conteo_archivo = array();
$conteo_nombre  = array();
foreach ($modulos as $m) {
    $arch = strtolower(trim($m['archivo']));
    $nom  = strtolower(trim($m['nombre']));
    $conteo_archivo[$arch] = ($conteo_archivo[$arch] ?? 0) + 1;
    $conteo_nombre[$nom]   = ($conteo_nombre[$nom]   ?? 0) + 1;
}

// --- VERIFICAR CADA MÓDULO ---
$resultados = array();
$total = 0;
$existentes = 0;
$inexistentes = 0;
$duplicados = 0;

foreach ($modulos as $m) {
    $total++;
    $ruta_relativa = ltrim($m['archivo'], '/');
    $ruta_absoluta = PATH_BASE . $ruta_relativa;
    $existe = file_exists($ruta_absoluta);
    $legible = $existe && is_readable($ruta_absoluta);

    if ($existe) {
        $existentes++;
    } else {
        $inexistentes++;
    }

    $dup_arch = $conteo_archivo[strtolower(trim($m['archivo']))] > 1;
    $dup_nom  = $conteo_nombre[strtolower(trim($m['nombre']))]  > 1;
    $es_dup = $dup_arch || $dup_nom;
    if ($es_dup) {
        $duplicados++;
    }

    $resultados[] = array(
        'modulo'        => $m,
        'ruta'          => $ruta_relativa,
        'existe'        => $existe,
        'legible'       => $legible,
        'tamano'        => $existe ? filesize($ruta_absoluta) : 0,
        'dup_archivo'   => $dup_arch,
        'dup_nombre'    => $dup_nom
    );
}

$porcentaje = $total > 0 ? round(($existentes / $total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificación de Módulos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --accent: #00bcd4;
            --bg-card: #1e1e1e;
            --border: #333;
        }

        .verify-container { padding: 20px; max-width: 1400px; margin: 0 auto; }

        .card-admin {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        /* Resumen de estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: #2a2a2a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            text-align: center;
            border-left: 4px solid var(--accent);
        }
        .stat-box .num {
            font-size: 2rem;
            font-weight: bold;
            color: #fff;
        }
        .stat-box .label {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }
        .stat-box.ok { border-left-color: #2ecc71; }
        .stat-box.ok .num { color: #2ecc71; }
        .stat-box.bad { border-left-color: #e74c3c; }
        .stat-box.bad .num { color: #e74c3c; }

        /* Barra de progreso */
        .progress-wrap {
            background: #2a2a2a;
            border-radius: 8px;
            height: 22px;
            overflow: hidden;
            border: 1px solid var(--border);
            margin-bottom: 25px;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #00bcd4, #2ecc71);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-weight: bold;
            font-size: 0.8rem;
            transition: width 0.5s ease;
        }

        /* Tabla de resultados */
        .table-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #252525;
            color: var(--accent);
            text-align: left;
            padding: 14px 16px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid var(--border);
        }
        tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #2a2a2a;
            color: #ccc;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        tbody tr:hover { background: #252525; }
        tbody tr:last-child td { border-bottom: none; }

        .mod-name { color: #fff; font-weight: 600; }
        .mod-route { font-family: monospace; color: #888; font-size: 0.82rem; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: bold;
        }
        .badge-ok {
            background: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
            border: 1px solid #2ecc71;
        }
        .badge-bad {
            background: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }

        .seccion-tag {
            display: inline-block;
            background: #333;
            color: var(--accent);
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9rem;
            transition: 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary:hover { background: #0097a7; }

        .btn-secondary {
            background: #333;
            color: #ccc;
            border: 1px solid #444;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9rem;
            transition: 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary:hover { background: #444; color: #fff; }

        .btn-delete {
            background: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
            border: 1px solid #e74c3c;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9rem;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-delete:hover { background: #e74c3c; color: #fff; }

        .actions-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state i {
            font-size: 3em;
            display: block;
            margin-bottom: 15px;
            opacity: 0.3;
            color: var(--accent);
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div class="verify-container">
            <h1 style="color: var(--accent); margin-bottom: 30px;">
                <i class="fas fa-clipboard-check"></i> Verificación de Módulos
            </h1>

            <div class="card-admin">
                <h3 style="margin-top: 0; color: var(--accent); margin-bottom: 20px;">
                    <i class="fas fa-chart-pie"></i> Resumen
                </h3>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="num"><?php echo $total; ?></div>
                        <div class="label">Total Módulos</div>
                    </div>
                    <div class="stat-box ok">
                        <div class="num"><?php echo $existentes; ?></div>
                        <div class="label">Archivos Existentes</div>
                    </div>
                    <div class="stat-box bad">
                        <div class="num"><?php echo $inexistentes; ?></div>
                        <div class="label">Archivos Faltantes</div>
                    </div>
                    <div class="stat-box">
                        <div class="num"><?php echo $porcentaje; ?>%</div>
                        <div class="label">Integridad</div>
                    </div>
                    <div class="stat-box bad">
                        <div class="num"><?php echo $duplicados; ?></div>
                        <div class="label">Duplicados</div>
                    </div>
                </div>
                <div class="progress-wrap">
                    <div class="progress-bar" style="width: <?php echo $porcentaje; ?>%;">
                        <?php echo $porcentaje; ?>%
                    </div>
                </div>
                <?php if ($duplicados > 0): ?>
                    <div class="alert danger" style="margin-top: 0;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Se detectaron <strong><?php echo $duplicados; ?></strong> módulo(s) duplicados (misma ruta de archivo o mismo nombre). Revísalos en la columna "Duplicado" y elimina los repetidos desde la gestión de permisos.
                    </div>
                <?php endif; ?>
            </div>

            <div class="actions-bar">
                <a href="abm_permisos_usuarios.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver a Permisos
                </a>
                <a href="verificar_modulos.php" class="btn-primary">
                    <i class="fas fa-sync-alt"></i> Re-verificar
                </a>
            </div>

            <div class="table-wrap">
                <?php if (count($resultados) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Módulo</th>
                                <th>Sección</th>
                                <th>Ruta del Archivo</th>
                                <th>Tamaño</th>
                                <th>Estado</th>
                                <th>Duplicado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $r): ?>
                                <tr>
                                    <td>
                                        <span class="mod-name">
                                            <i class="<?php echo htmlspecialchars($r['modulo']['icono']); ?>" style="color: var(--accent);"></i>
                                            <?php echo htmlspecialchars($r['modulo']['nombre']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="seccion-tag"><?php echo htmlspecialchars($r['modulo']['seccion']); ?></span>
                                    </td>
                                    <td>
                                        <span class="mod-route"><?php echo htmlspecialchars($r['ruta']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo $r['existe'] ? number_format($r['tamano']) . ' B' : '—'; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['existe']): ?>
                                            <span class="badge badge-ok">
                                                <i class="fas fa-check-circle"></i> Existe
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-bad">
                                                <i class="fas fa-times-circle"></i> Faltante
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['dup_archivo'] || $r['dup_nombre']): ?>
                                            <span class="badge badge-bad" title="<?php echo $r['dup_archivo'] ? 'Ruta de archivo repetida' : 'Nombre repetido'; ?>">
                                                <i class="fas fa-copy"></i> Sí
                                                <?php if ($r['dup_archivo']): ?><small> (archivo)</small><?php endif; ?>
                                                <?php if ($r['dup_nombre']): ?><small> (nombre)</small><?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-ok">
                                                <i class="fas fa-check"></i> No
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <?php if ($r['existe']): ?>
                                                <a href="<?php echo URL_BASE . htmlspecialchars($r['ruta']); ?>" target="_blank" class="btn-secondary" style="padding: 6px 12px; font-size: 0.8rem;">
                                                    <i class="fas fa-external-link-alt"></i> Abrir
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #e74c3c; font-size: 0.8rem;">
                                                    <i class="fas fa-exclamation-triangle"></i> No disponible
                                                </span>
                                            <?php endif; ?>
                                            <button type="button" class="btn-delete" style="padding: 6px 12px; font-size: 0.8rem;"
                                                    onclick="confirmarEliminar(<?php echo (int)$r['modulo']['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['modulo']['nombre'])); ?>')">
                                                <i class="fas fa-trash-alt"></i> Borrar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No hay módulos registrados en el sistema.</p>
                    </div>
                <?php endif; ?>
            </div>

            <br>
            <a href="<?php echo URL_BASE; ?>index.php" style="color: #aaa; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Volver al inicio
            </a>
        </div>
    </div>

    <?php if (isset($mensaje)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const msg = "<?php echo str_replace(['✅', '❌'], '', $mensaje); ?>";
                mostrarToast(msg, "<?php echo str_contains($mensaje, '❌') ? 'error' : 'success'; ?>");
            });
        </script>
    <?php endif; ?>

    <script>
    function confirmarEliminar(id, nombre) {
        confirmarAccion(
            'Eliminar Módulo',
            '¿Estás seguro de eliminar el módulo "' + nombre + '"? Se borrarán también los permisos asociados. Esta acción no se puede deshacer.',
            'ELIMINAR',
            'btn-danger',
            function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'verificar_modulos.php';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'eliminar_modulo';
                input.value = '1';
                form.appendChild(input);
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'modulo_id';
                inputId.value = id;
                form.appendChild(inputId);
                document.body.appendChild(form);
                form.submit();
            }
        );
    }

    function mostrarToast(mensaje, tipo = 'success') {
        const toast = document.createElement('div');
        toast.className = 'toast-notificacion';
        if (tipo === 'error') toast.style.background = '#e74c3c';
        toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }
    </script>
</body>
</html>