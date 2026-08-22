<?php
// pages/abm_permisos_usuario.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer');
require '../config/db_config.php';

if ($_SESSION['usuario_rol'] !== 'developer') {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

$id_seleccionado = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$usuario_nombre_edicion = "";

// --- LÓGICA DE REGISTRO DE NUEVO MÓDULO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_modulo'])) {
    $n_nom = trim($_POST['nuevo_nombre']);
    $n_arc = trim($_POST['nuevo_archivo']);
    $n_ico = trim($_POST['nuevo_icono']);
    $n_sec = $_POST['nueva_seccion'];
    
    if (!empty($n_nom) && !empty($n_arc)) {
        $stmt_mod = $pdo->prepare("INSERT INTO modulos (nombre, archivo, icono, seccion) VALUES (?, ?, ?, ?)");
        $stmt_mod->execute(array($n_nom, $n_arc, $n_ico, $n_sec));
        $mensaje = "✅ Módulo '$n_nom' registrado correctamente.";
    }
}

// --- Guardar cambios de permisos ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id_seleccionado > 0 && isset($_POST['guardar_permisos'])) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM permisos_usuario WHERE usuario_id = ?")->execute(array($id_seleccionado));

        // Obtenemos el empresa_id del usuario editado (la tabla permisos_usuario exige empresa_id NOT NULL)
        $stmt_emp = $pdo->prepare("SELECT empresa_id FROM usuarios WHERE id = ?");
        $stmt_emp->execute(array($id_seleccionado));
        $empresa_id_usuario = $stmt_emp->fetchColumn();
        if (empty($empresa_id_usuario)) {
            $empresa_id_usuario = $_SESSION['empresa_id'] ?? 1;
        }

        if (isset($_POST['modulos'])) {
            $ins = $pdo->prepare("INSERT INTO permisos_usuario (usuario_id, modulo_id, empresa_id) VALUES (?, ?, ?)");
            foreach ($_POST['modulos'] as $mod_id) {
                $ins->execute(array($id_seleccionado, (int)$mod_id, $empresa_id_usuario));
            }
        }
        $pdo->commit();
        $mensaje = "✅ Permisos actualizados. El usuario deberá cerrar y volver a iniciar sesión.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// -- CARGAR DATOS
$usuarios = $pdo->query("SELECT id, usuario, rol FROM usuarios WHERE estado = 'ACTIVO' AND rol != 'developer' ORDER BY usuario")->fetchAll();

// Filtro por tipo (pagina | funcion | todos)
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
if (!in_array($filtro_tipo, ['pagina', 'funcion'])) {
    $filtro_tipo = 'todos';
}

$sql_modulos = "SELECT * FROM modulos";
if ($filtro_tipo !== 'todos') {
    $sql_modulos .= " WHERE tipo = '" . $filtro_tipo . "'";
}
$sql_modulos .= " ORDER BY seccion, nombre";
$modulos = $pdo->query($sql_modulos)->fetchAll();

$permisos_actuales = array();
if ($id_seleccionado > 0) {
    $stmt_u = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $stmt_u->execute(array($id_seleccionado));
    $usuario_nombre_edicion = $stmt_u->fetchColumn();

    $res = $pdo->prepare("SELECT modulo_id FROM permisos_usuario WHERE usuario_id = ?");
    $res->execute(array($id_seleccionado));
    $permisos_actuales = $res->fetchAll(PDO::FETCH_COLUMN, 0);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permisos por Usuario | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --accent: #00bcd4;
            --bg-card: #1e1e1e;
            --border: #333;
        }

        .perms-container { padding: 20px; max-width: 1400px; margin: 0 auto; }
        
        /* Cards */
        .card-admin {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr) auto;
            gap: 10px;
            align-items: flex-end;
        }

        .input-dark {
            padding: 8px;
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 4px;
            color: white;
            font-size: 0.9rem;
            min-width: 180px;
            box-sizing: border-box;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
            padding: 6px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85rem;
            transition: 0.3s;
            height: 34px;
            align-self: flex-end;
        }
        .btn-primary:hover { background: #0097a7; }

        .btn-registrar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            color: white;
            border: none;
            padding: 0 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            height: 34px;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
            transition: all 0.25s ease;
            text-decoration: none;
        }
        .btn-registrar:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            box-shadow: 0 4px 14px rgba(0, 188, 212, 0.45);
            transform: translateY(-1px);
        }
        .btn-registrar:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(0, 188, 212, 0.35);
        }

        /* Layout flex para usuarios y permisos */
        .flex { display: flex; gap: 30px; }

        /* Lista de usuarios */
        .user-list {
            width: 300px;
            background: var(--bg-card);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border);
            height: fit-content;
            max-height: 70vh;
            overflow-y: auto;
        }
        .user-list h3 { color: var(--accent); margin-top: 0; }
        .user-item {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            display: block;
            color: #ccc;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s, color 0.2s;
        }
        .user-item:hover { background: #252525; color: #fff; }
        .user-item.active { 
            color: var(--accent); 
            font-weight: bold; 
            background: #252525;
            border-left: 4px solid var(--accent);
        }
        .user-item small {
            display: block;
            color: #666;
            font-size: 0.85rem;
            margin-top: 4px;
        }

        /* Matrix de permisos */
        .matrix {
            flex-grow: 1;
            background: var(--bg-card);
            padding: 25px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        
        .card {
            background: #2a2a2a;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #444;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s;
        }
        .card:hover { background: #333; }
        .card.checked {
            border-left-color: var(--accent);
            background: #333;
        }
        .card input[type="checkbox"] {
            margin-top: 2px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .card strong {
            color: #fff;
            font-size: 0.95rem;
        }
        .card small {
            display: block;
            color: #666;
            font-size: 0.75rem;
            margin-top: 4px;
        }
        .card i {
            width: 24px;
            color: var(--accent);
            flex-shrink: 0;
        }

        .seccion-titulo {
            grid-column: 1 / -1;
            background: #333;
            padding: 8px 15px;
            color: var(--accent);
            border-radius: 6px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 10px;
        }

        /* Alert */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .success { background: rgba(46, 184, 113, 0.15); color: #2ecc71; border: 1px solid #2ecc71; }
        .danger { background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid #e74c3c; }

        /* Toast */
        .toast-notificacion {
            position: fixed; top: 20px; right: 20px; background: #2ecc71; color: white;
            padding: 15px 25px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.5);
            z-index: 10000; display: flex; align-items: center; gap: 10px; font-weight: bold;
            animation: slideInToast 0.3s ease-out forwards;
        }
        @keyframes slideInToast {
            from { transform: translateX(120%); }
            to { transform: translateX(0); }
        }
        .toast-fade-out {
            animation: fadeOutToast 0.5s ease-out forwards;
        }
        @keyframes fadeOutToast {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        /* Empty state */
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
        <div class="perms-container">
            <h1 style="color: var(--accent); margin-bottom: 30px;">
                <i class="fas fa-user-shield"></i> Gestión de Permisos por Usuario
            </h1>

            <?php if (isset($mensaje)): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = "<?php echo str_replace(['✅', '❌'], '', $mensaje); ?>";
                        mostrarToast(msg, "<?php echo str_contains($mensaje, '❌') ? 'error' : 'success'; ?>");
                    });
                </script>
            <?php endif; ?>

            <div class="card-admin">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                    <h3 style="margin-top: 0; color: var(--accent); margin-bottom: 0;">
                        <i class="fas fa-plus-circle"></i> Registrar Nueva Página / Módulo
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span style="color: #888; font-size: 0.85rem;">Filtrar:</span>
                        <a href="?tipo=todos<?php echo $id_seleccionado ? '&u=' . $id_seleccionado : ''; ?>" class="btn-secondary <?php echo $filtro_tipo === 'todos' ? 'btn-primary' : ''; ?>" style="padding: 6px 12px; font-size: 0.8rem; text-decoration: none;">
                            Todos
                        </a>
                        <a href="?tipo=pagina<?php echo $id_seleccionado ? '&u=' . $id_seleccionado : ''; ?>" class="btn-secondary <?php echo $filtro_tipo === 'pagina' ? 'btn-primary' : ''; ?>" style="padding: 6px 12px; font-size: 0.8rem; text-decoration: none;">
                            <i class="fas fa-file-alt"></i> Páginas
                        </a>
                        <a href="?tipo=funcion<?php echo $id_seleccionado ? '&u=' . $id_seleccionado : ''; ?>" class="btn-secondary <?php echo $filtro_tipo === 'funcion' ? 'btn-primary' : ''; ?>" style="padding: 6px 12px; font-size: 0.8rem; text-decoration: none;">
                            <i class="fas fa-cogs"></i> Funciones
                        </a>
                        <a href="<?php echo route('modulos.verificar'); ?>" class="btn-primary" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem;">
                            <i class="fas fa-clipboard-check"></i> Verificar Módulos
                        </a>
                    </div>
                </div>
                <form method="POST" style="display: flex; gap: 10px; align-items: stretch;">
                    <input type="text" name="nuevo_nombre" placeholder="Nombre (Ej: Stock)" required class="input-dark">
                    <input type="text" name="nuevo_archivo" placeholder="Ruta (Ej: pages/stock.php)" required class="input-dark">
                    <input type="text" name="nuevo_icono" placeholder="Icono (fas fa-boxes)" class="input-dark">
                    <select name="nueva_seccion" class="input-dark">
                        <option value="Maestros">Maestros</option>
                        <option value="Transacciones">Transacciones</option>
                        <option value="Facturación">Facturación</option>
                        <option value="Gestión de Caja">Gestión de Caja</option>
                        <option value="Informes">Informes</option>
                        <option value="Seguridad">Seguridad</option>
                    </select>
                    <button type="submit" name="crear_modulo" class="btn-registrar">Registrar</button>
                </form>
            </div>

            <div class="flex">
                <div class="user-list">
                    <h3><i class="fas fa-users"></i> Usuarios</h3>
                    <?php foreach($usuarios as $u): ?>
                        <a href="?u=<?php echo $u['id']; ?>" class="user-item <?php echo ($id_seleccionado == $u['id']) ? 'active' : ''; ?>">
                            <strong><?php echo htmlspecialchars($u['usuario']); ?></strong>
                            <small><?php echo htmlspecialchars($u['rol']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="matrix">
                    <?php if($id_seleccionado > 0): ?>
                        <form method="POST">
                            <h3 style="margin-top: 0;">
                                Permisos para: 
                                <span style="color: var(--accent);"><?php echo htmlspecialchars($usuario_nombre_edicion); ?></span>
                            </h3>
                            <div class="grid">
                                <?php 
                                $last_sec = "";
                                foreach($modulos as $m): 
                                    if($m['seccion'] != $last_sec): 
                                        $last_sec = $m['seccion'];
                                        echo "<div class='seccion-titulo'><i class='fas fa-folder'></i> $last_sec</div>";
                                    endif;
                                ?>
                                    <label class="card <?php echo in_array($m['id'], $permisos_actuales) ? 'checked' : ''; ?>">
                                        <input type="checkbox" name="modulos[]" value="<?php echo $m['id']; ?>" 
                                        <?php echo in_array($m['id'], $permisos_actuales) ? 'checked' : ''; ?>>
                                        <div style="flex:1;">
                                            <strong>
                                                <i class="<?php echo htmlspecialchars($m['icono']); ?>"></i>
                                                <?php echo htmlspecialchars($m['nombre']); ?>
                                            </strong>
                                            <small>Ruta: <?php echo htmlspecialchars($m['archivo']); ?></small>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <br>
                            <button type="submit" name="guardar_permisos" class="btn-primary">
                                <i class="fas fa-save"></i> Guardar Permisos
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-arrow-left"></i>
                            <p>Selecciona un usuario de la lista para gestionar sus permisos.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <br>
            <a href="<?php echo URL_BASE; ?>" style="color: #aaa; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Volver al inicio
            </a>
        </div>
    </div>

    <script>
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

    // Marcar card como checked al hacer click
    document.querySelectorAll('.card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('input[type="checkbox"]');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('checked', checkbox.checked);
            }
        });
    });
    </script>
</body>
</html>