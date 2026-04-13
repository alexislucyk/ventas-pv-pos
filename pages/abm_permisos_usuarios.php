<?php
// pages/abm_permisos_usuario.php
require_once '../config/db_config.php';
include 'infosesion.php';

if ($_SESSION['usuario_rol'] !== 'developer') {
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}

$id_seleccionado = isset($_GET['u']) ? (int)$_GET['u'] : 0;

// --- LÓGICA DE REGISTRO DE NUEVO MÓDULO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_modulo'])) {
    $n_nom = trim($_POST['nuevo_nombre']);
    $n_arc = trim($_POST['nuevo_archivo']);
    $n_ico = trim($_POST['nuevo_icono']);
    $n_sec = $_POST['nueva_seccion'];
    
    if (!empty($n_nom) && !empty($n_arc)) {
        $stmt_mod = $pdo->prepare("INSERT INTO modulos (nombre, archivo, icono, seccion) VALUES (?, ?, ?, ?)");
        $stmt_mod->execute(array($n_nom, $n_arc, $n_ico, $n_sec));
        $mensaje = "Nuevo módulo '$n_nom' registrado correctamente.";
    }
}

// 1. Guardar cambios de permisos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id_seleccionado > 0 && isset($_POST['guardar_permisos'])) {
    $pdo->prepare("DELETE FROM permisos_usuario WHERE usuario_id = ?")->execute(array($id_seleccionado));
    if (isset($_POST['modulos'])) {
        $ins = $pdo->prepare("INSERT INTO permisos_usuario (usuario_id, modulo_id) VALUES (?, ?)");
        foreach ($_POST['modulos'] as $mod_id) {
            $ins->execute(array($id_seleccionado, $mod_id));
        }
    }
    $mensaje = "Permisos de usuario actualizados.";
}

// 2. Cargar Datos
$usuarios = $pdo->query("SELECT id, usuario, rol FROM usuarios WHERE estado = 'ACTIVO' AND rol != 'developer' ORDER BY usuario")->fetchAll();
$modulos = $pdo->query("SELECT * FROM modulos ORDER BY seccion, nombre")->fetchAll();

$permisos_actuales = array();
if ($id_seleccionado > 0) {
    $res = $pdo->prepare("SELECT modulo_id FROM permisos_usuario WHERE usuario_id = ?");
    $res->execute(array($id_seleccionado));
    $permisos_actuales = $res->fetchAll(PDO::FETCH_COLUMN, 0);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permisos por Usuario | Modo Developer</title>
    <style>
        body { background: #121212; color: #eee; font-family: sans-serif; padding: 20px; }
        .flex { display: flex; gap: 30px; }
        .user-list { width: 300px; background: #1e1e1e; padding: 15px; border-radius: 8px; height: fit-content; }
        .user-item { padding: 10px; border-bottom: 1px solid #333; display: block; color: #aaa; text-decoration: none; }
        .user-item.active { color: #00bcd4; font-weight: bold; background: #252525; }
        .matrix { flex-grow: 1; background: #1e1e1e; padding: 20px; border-radius: 8px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .card { background: #2a2a2a; padding: 10px; border-radius: 4px; border-left: 4px solid #444; cursor: pointer; display: flex; align-items: center; gap: 10px; }
        .card.checked { border-left-color: #00bcd4; background: #333; }
        .btn { background: #00bcd4; border: none; padding: 10px 20px; color: #000; font-weight: bold; cursor: pointer; border-radius: 4px; }
        
        /* Estilos Formulario Nuevo Módulo */
        .form-nuevo { background: #1e1e1e; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #333; }
        .form-nuevo input, .form-nuevo select { 
            background: #2a2a2a; border: 1px solid #444; color: white; padding: 8px; border-radius: 4px; margin-right: 5px; margin-bottom: 10px;
        }
        .form-nuevo h3 { margin-top: 0; color: #00bcd4; font-size: 1.1em; }
    </style>
</head>
<body>
    <h2>Gestión de Accesos Individuales</h2>
    
    <?php if(isset($mensaje)): ?>
        <p style="color: #2ecc71; background: rgba(46, 204, 113, 0.1); padding: 10px; border-radius: 5px;"><?php echo $mensaje; ?></p>
    <?php endif; ?>

    <div class="form-nuevo">
        <h3><i class="fas fa-plus-circle"></i> Registrar Nueva Página / Módulo</h3>
        <form method="POST">
            <input type="text" name="nuevo_nombre" placeholder="Nombre (Ej: Stock)" required>
            <input type="text" name="nuevo_archivo" placeholder="Ruta (Ej: pages/stock.php)" required>
            <input type="text" name="nuevo_icono" placeholder="Icono (Ej: fas fa-boxes)">
            <select name="nueva_seccion">
                <option value="Maestros">Maestros</option>
                <option value="Transacciones">Transacciones</option>
                <option value="Gestión de Caja">Gestión de Caja</option>
                <option value="Informes">Informes</option>
                <option value="Seguridad">Seguridad</option>
            </select>
            <button type="submit" name="crear_modulo" class="btn" style="background: #27ae60; color: white;">Registrar</button>
        </form>
    </div>

    <div class="flex">
        <div class="user-list">
            <h3>Usuarios</h3>
            <?php foreach($usuarios as $u): ?>
                <a href="?u=<?php echo $u['id']; ?>" class="user-item <?php echo ($id_seleccionado == $u['id']) ? 'active' : ''; ?>">
                    <strong><?php echo $u['usuario']; ?></strong>
                    <small style="display:block; color:#666;"><?php echo $u['rol']; ?></small>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="matrix">
            <?php if($id_seleccionado > 0): ?>
                <form method="POST">
                    <h3>Permisos para: <span style="color: #00bcd4;"><?php echo $id_seleccionado; ?></span></h3>
                    <div class="grid">
                        <?php foreach($modulos as $m): ?>
                            <label class="card <?php echo in_array($m['id'], $permisos_actuales) ? 'checked' : ''; ?>">
                                <input type="checkbox" name="modulos[]" value="<?php echo $m['id']; ?>" 
                                <?php echo in_array($m['id'], $permisos_actuales) ? 'checked' : ''; ?>>
                                <span>
                                    <i class="<?php echo $m['icono']; ?>" style="width: 20px; color: #888;"></i>
                                    <?php echo $m['nombre']; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <br>
                    <button type="submit" name="guardar_permisos" class="btn">Guardar Permisos Especiales</button>
                </form>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <i class="fas fa-arrow-left" style="font-size: 2em;"></i>
                    <p>Selecciona un usuario de la lista para gestionar sus accesos.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <br>
    <a href="<?php echo URL_BASE; ?>index.php" style="color: #aaa; text-decoration: none;">← Volver al inicio</a>
</body>
</html>