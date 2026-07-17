<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$empresa_editar = array();

if ($accion === 'crear') {
    $nuevo_id_sugerido = 1;
    try {
        $stmt_id = $pdo->query("SELECT id FROM empresas ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id_sugerido = $ultimo ? (intval($ultimo['id']) + 1) : 1;
    } catch (Exception $e) {
        $nuevo_id_sugerido = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre_fantasia = trim($_POST['nombre_fantasia']);
        $razon_social = trim($_POST['razon_social']);
        $cuit = trim($_POST['cuit']);
        $condicion_iva = trim($_POST['condicion_iva']);
        $direccion = trim($_POST['direccion']);
        $localidad = trim($_POST['localidad']);
        $telefono = trim($_POST['telefono']);
        $activa = isset($_POST['activa']) ? 1 : 0;

        $id_post = isset($_POST['id_empresa']) ? $_POST['id_empresa'] : null;
        $accion_post = $_POST['accion_post'];

        if (empty($nombre_fantasia) || empty($direccion) || empty($localidad) || empty($telefono)) {
            throw new Exception("Nombre, dirección, localidad y teléfono son obligatorios.");
        }

        if ($accion_post === 'crear') {
            $sql = "INSERT INTO empresas (id, nombre_fantasia, razon_social, cuit, condicion_iva, direccion, localidad, telefono, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($id_post ?: null, $nombre_fantasia, $razon_social, $cuit ?: null, $condicion_iva, $direccion, $localidad, $telefono, $activa));
            $mensaje = "✅ Empresa registrada con éxito.";
            $accion = 'listar';
        } elseif ($accion_post === 'editar' && $id_post) {
            $sql = "UPDATE empresas SET nombre_fantasia=?, razon_social=?, cuit=?, condicion_iva=?, direccion=?, localidad=?, telefono=?, activa=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($nombre_fantasia, $razon_social, $cuit ?: null, $condicion_iva, $direccion, $localidad, $telefono, $activa, $id_post));
            $mensaje = "✅ Datos de la empresa actualizados.";
            $accion = 'listar';
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

if ($accion === 'eliminar' && $id) {
    try {
        $stmt = $pdo->prepare('DELETE FROM empresas WHERE id = ?');
        $stmt->execute(array($id));
        $mensaje = "🗑️ Empresa eliminada correctamente.";
    } catch (Exception $e) {
        $mensaje = "❌ No se puede eliminar: La empresa tiene registros asociados.";
    }
    $accion = 'listar';
}

if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM empresas WHERE id = ?');
    $stmt->execute(array($id));
    $empresa_editar = $stmt->fetch();
}

$empresas = array();
if ($accion === 'listar') {
    $stmt = $pdo->query('SELECT * FROM empresas ORDER BY id ASC');
    $empresas = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Empresas | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <style>
        .flex-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 5px; color: #3498db; font-weight: bold; font-size: 0.9em; }
        input, select { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; }
        .btn-sm { padding: 5px 10px; font-size: 0.85em; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: rgba(46, 204, 113, 0.2); border: 1px solid #2ecc71; color: #2ecc71; }
        .alert-error { background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; color: #e74c3c; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>🏢 Gestión de Empresas</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="abm_empresas.php?accion=crear" class="btn btn-success">+ Nueva Empresa</a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre Fantasía</th>
                            <th>Razón Social</th>
                            <th>CUIT</th>
                            <th>IVA</th>
                            <th>Localidad</th>
                            <th>Teléfono</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $e): ?>
                        <tr>
                            <td><?php echo $e['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($e['nombre_fantasia']); ?></strong></td>
                            <td><?php echo htmlspecialchars($e['razon_social'] ?: '---'); ?></td>
                            <td><?php echo htmlspecialchars($e['cuit'] ?: '---'); ?></td>
                            <td><?php echo htmlspecialchars($e['condicion_iva'] ?: '---'); ?></td>
                            <td><?php echo htmlspecialchars($e['localidad']); ?></td>
                            <td><?php echo htmlspecialchars($e['telefono']); ?></td>
                            <td>
                                <?php if ($e['activa']): ?>
                                    <span class="badge badge-success">Activa</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactiva</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="abm_empresas.php?accion=editar&id=<?php echo $e['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                <a href="abm_empresas.php?accion=eliminar&id=<?php echo $e['id']; ?>" 
                                   class="btn btn-danger btn-sm" 
                                   onclick="event.preventDefault(); const url=this.href; confirmarAccion('Eliminar Empresa', '¿Estás seguro?', 'ELIMINAR', 'btn-danger', () => window.location.href=url);">
                                   Borrar
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($empresas)) : ?>
                        <tr><td colspan="9" style="text-align:center; padding:30px; color:#666;">No hay empresas registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 700px; margin: 0 auto; padding: 15px 20px;">
                <h2 style="font-size:1.1em; margin:0;"><?php echo ($accion === 'crear') ? 'Nueva Empresa' : 'Modificar Empresa'; ?></h2>
                <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                <form method="POST">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_empresa" value="<?php echo isset($empresa_editar['id']) ? $empresa_editar['id'] : ($nuevo_id_sugerido ?? ''); ?>">

                    <div class="flex-row">
                        <div style="flex: 0.5;">
                            <label>N° Empresa</label>
                            <input type="text" value="<?php echo ($accion === 'crear') ? ($nuevo_id_sugerido ?? '') : $empresa_editar['id']; ?>" readonly>
                        </div>
                        <div style="flex: 0.5;">
                            <label>Estado</label>
                            <select name="activa">
                                <option value="1" <?php echo ($accion === 'editar' && $empresa_editar['activa']) ? 'selected' : 'selected'; ?>>Activa</option>
                                <option value="0" <?php echo ($accion === 'editar' && !$empresa_editar['activa']) ? 'selected' : ''; ?>>Inactiva</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex-row">
                        <div style="flex: 1;">
                            <label>Nombre Fantasía *</label>
                            <input type="text" name="nombre_fantasia" required value="<?php echo isset($empresa_editar['nombre_fantasia']) ? htmlspecialchars($empresa_editar['nombre_fantasia']) : ''; ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Razón Social</label>
                            <input type="text" name="razon_social" value="<?php echo isset($empresa_editar['razon_social']) ? htmlspecialchars($empresa_editar['razon_social']) : ''; ?>">
                        </div>
                    </div>

                    <div class="flex-row">
                        <div style="flex: 1;">
                            <label>CUIT</label>
                            <input type="text" name="cuit" value="<?php echo isset($empresa_editar['cuit']) ? htmlspecialchars($empresa_editar['cuit']) : ''; ?>" placeholder="XX-XXXXXXXX-X">
                        </div>
                        <div style="flex: 1;">
                            <label>Condición IVA</label>
                            <input type="text" name="condicion_iva" value="<?php echo isset($empresa_editar['condicion_iva']) ? htmlspecialchars($empresa_editar['condicion_iva']) : ''; ?>" placeholder="Ej: Responsable Inscripto">
                        </div>
                    </div>

                    <label>Dirección *</label>
                    <input type="text" name="direccion" required value="<?php echo isset($empresa_editar['direccion']) ? htmlspecialchars($empresa_editar['direccion']) : ''; ?>">

                    <div class="flex-row">
                        <div style="flex: 1;">
                            <label>Localidad *</label>
                            <input type="text" name="localidad" required value="<?php echo isset($empresa_editar['localidad']) ? htmlspecialchars($empresa_editar['localidad']) : ''; ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Teléfono *</label>
                            <input type="text" name="telefono" required value="<?php echo isset($empresa_editar['telefono']) ? htmlspecialchars($empresa_editar['telefono']) : ''; ?>">
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;"><i class="fas fa-save"></i> Guardar Empresa</button>
                        <a href="abm_empresas.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
