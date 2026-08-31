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
    <link rel="stylesheet" href="<?php echo url('css/pages/usuarios.css'); ?>">
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