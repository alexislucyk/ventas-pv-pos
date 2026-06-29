<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

// Inicializar variables - PHP 5 compatible
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$cliente_editar = array(); 

// Mapeo de tipos de IVA para ARCA/AFIP
$tipos_iva = [
    99 => 'Consumidor Final',
    1  => 'Responsable Inscripto',
    6  => 'Monotributo',
    4  => 'Exento'
];

// --- LÓGICA PARA AUTOGENERAR ID (Solo si es creación) ---
$nuevo_id_sugerido = '';
if ($accion === 'crear') {
    try {
        // Buscamos el ID más alto
        $stmt_id = $pdo->query("SELECT id FROM clientes ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id_sugerido = $ultimo ? (intval($ultimo['id']) + 1) : 1;
    } catch (Exception $e) {
        $nuevo_id_sugerido = ''; 
    }
}

// --- LÓGICA DEL CONTROLADOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre']); 
        $apellido = trim($_POST['apellido']); 
        $dni = trim($_POST['dni']);
        $id_tipo_iva = isset($_POST['id_tipo_iva']) ? intval($_POST['id_tipo_iva']) : 99;
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        $direccion = trim($_POST['direccion']);
        $estado = isset($_POST['estado']) ? $_POST['estado'] : 'Activo';
        $habilita_cta = isset($_POST['habilita_cta']) ? $_POST['habilita_cta'] : 'No';
        $relacion = trim($_POST['relacion']);
        
        $id_post = isset($_POST['id_cliente']) ? $_POST['id_cliente'] : null;
        $accion_post = $_POST['accion_post'];

        if (empty($apellido)) {
            throw new Exception("El Apellido es obligatorio.");
        }

        if ($accion_post === 'crear') {
            // Usamos el ID autogenerado en el INSERT
            $id_a_insertar = $_POST['id_visual']; 
            $sql = "INSERT INTO clientes (id, nombre, apellido, dni, id_tipo_iva, cuit, telefono, direccion, estado, habilita_cta, relacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($id_a_insertar, $nombre, $apellido, $dni, $id_tipo_iva, $cuit, $telefono, $direccion, $estado, $habilita_cta, $relacion));
            $mensaje = "✅ Cliente #$id_a_insertar registrado con éxito.";
            $accion = 'listar'; 
        } elseif ($accion_post === 'editar' && $id_post) {
            $sql = "UPDATE clientes SET nombre=?, apellido=?, dni=?, id_tipo_iva=?, cuit=?, telefono=?, direccion=?, estado=?, habilita_cta=?, relacion=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($nombre, $apellido, $dni, $id_tipo_iva, $cuit, $telefono, $direccion, $estado, $habilita_cta, $relacion, $id_post));
            $mensaje = "✅ Datos del cliente actualizados.";
            $accion = 'listar'; 
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// --- ELIMINAR ---
if ($accion === 'eliminar' && $id) {
    try {
        $stmt = $pdo->prepare('DELETE FROM clientes WHERE id = ?');
        $stmt->execute(array($id));
        $mensaje = "🗑️ Cliente eliminado correctamente.";
    } catch (Exception $e) {
        $mensaje = "❌ No se puede eliminar: El cliente tiene registros asociados.";
    }
    $accion = 'listar';
}

// --- CARGAR DATOS PARA EDICIÓN ---
if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $stmt->execute(array($id));
    $cliente_editar = $stmt->fetch();
}

// --- LISTAR CLIENTES ---
$clientes = array();
if ($accion === 'listar') {
    $stmt = $pdo->query('SELECT * FROM clientes ORDER BY id DESC'); // Ordenados por el más reciente
    $clientes = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Clientes | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <style>
        .flex-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 5px; color: #3498db; font-weight: bold; font-size: 0.9em; }
        input, select { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; }
        .input-readonly { background: #1a1a1a; color: #2ecc71; font-weight: bold; border: 1px dashed #27ae60; }
        .badge-cta { background: #f1c40f; color: #000; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        #filtro-clientes { width: 100%; max-width: 450px; margin-bottom: 20px; background: #1a1a1a; border: 1px solid #333; height: 40px; color: white; padding-left: 10px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>👥 Gestión de Clientes</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="abm_clientes.php?accion=crear" class="btn btn-success">+ Nuevo Cliente</a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">
                <input type="text" id="filtro-clientes" placeholder="🔍 Buscar por apellido, nombre, ID o CUIT...">
                <div style="overflow-x: auto;">
                    <table id="tablaClientes">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Apellido y Nombre</th>
                                <th>CUIT/DNI</th>
                                <th>IVA</th>
                                <th>Teléfono</th>
                                <th>Cta. Cte.</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $c): ?>
                            <tr>
                                <td><span style="color: #666;">#<?php echo $c['id']; ?></span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['apellido']); if(!empty($c['nombre'])) echo ', ' . htmlspecialchars($c['nombre']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($c['cuit'] ?: ($c['dni'] ?: '---')); ?></td>
                                <td><small><?php echo $tipos_iva[$c['id_tipo_iva']] ?? 'CF'; ?></small></td>
                                <td><?php echo htmlspecialchars($c['telefono'] ? $c['telefono'] : '---'); ?></td>
                                <td>
                                    <?php if(strtoupper(trim($c['habilita_cta'])) === 'SI'): ?>
                                        <span class="badge-cta">Habilitada</span>
                                    <?php else: ?>
                                        <span style="color: #666;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="abm_clientes.php?accion=editar&id=<?php echo $c['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                    <a href="abm_clientes.php?accion=eliminar&id=<?php echo $c['id']; ?>" 
                                       class="btn btn-danger btn-sm" 
                                       onclick="event.preventDefault(); const url=this.href; confirmarAccion('Eliminar Cliente', '¿Estás seguro de eliminar a este cliente? Se perderán sus datos de contacto.', 'ELIMINAR', 'btn-danger', () => window.location.href=url);">
                                       Borrar
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 850px; margin: 0 auto;">
                <h2><?php echo ($accion === 'crear') ? 'Registrar Nuevo Cliente' : 'Modificar Cliente'; ?></h2>
                <hr style="border: 0; border-top: 1px solid #444; margin: 20px 0;">
                
                <form method="POST">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_cliente" value="<?php echo isset($cliente_editar['id']) ? $cliente_editar['id'] : ''; ?>">

                    <div class="flex-row">
                        <div style="flex: 0.3;">
                            <label>N° Cliente</label>
                            <input type="text" name="id_visual" readonly class="input-readonly" 
                                value="<?php echo ($accion === 'crear') ? $nuevo_id_sugerido : $cliente_editar['id']; ?>">
                        </div>
                        <div style="flex: 0.8;">
                            <label>Apellido*</label>
                            <input type="text" name="apellido" required value="<?php echo isset($cliente_editar['apellido']) ? htmlspecialchars($cliente_editar['apellido']) : ''; ?>">
                        </div>
                        <div style="flex: 0.8;">
                            <label>Nombre</label>
                            <input type="text" name="nombre" value="<?php echo isset($cliente_editar['nombre']) ? htmlspecialchars($cliente_editar['nombre']) : ''; ?>">
                        </div>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label>Dirección</label>
                        <input type="text" name="direccion" value="<?php echo isset($cliente_editar['direccion']) ? htmlspecialchars($cliente_editar['direccion']) : ''; ?>">
                    </div>

                    <div class="flex-row">
                        <div style="flex: 0.6;">
                            <label>DNI</label>
                            <input type="text" name="dni" value="<?php echo isset($cliente_editar['dni']) ? htmlspecialchars($cliente_editar['dni']) : ''; ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Condición IVA (ARCA)</label>
                            <select name="id_tipo_iva">
                                <?php foreach ($tipos_iva as $id_iva => $label_iva): ?>
                                    <option value="<?php echo $id_iva; ?>" <?php echo (isset($cliente_editar['id_tipo_iva']) && $cliente_editar['id_tipo_iva'] == $id_iva) ? 'selected' : ($id_iva == 99 ? 'selected' : ''); ?>>
                                        <?php echo $label_iva; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="flex-row">
                        <div><label>CUIT (Empresas)</label><input type="text" name="cuit" value="<?php echo isset($cliente_editar['cuit']) ? htmlspecialchars($cliente_editar['cuit']) : ''; ?>"></div>
                        <div><label>Teléfono</label><input type="text" name="telefono" value="<?php echo isset($cliente_editar['telefono']) ? htmlspecialchars($cliente_editar['telefono']) : ''; ?>"></div>
                    </div>

                    <div class="flex-row">
                        <div>
                            <label>Estado</label>
                            <select name="estado">
                                <option value="Activo" <?php echo (isset($cliente_editar['estado']) && $cliente_editar['estado'] == 'Activo') ? 'selected' : ''; ?>>Activo</option>
                                <option value="Inactivo" <?php echo (isset($cliente_editar['estado']) && $cliente_editar['estado'] == 'Inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                        <div>
                            <label>Cuenta Corriente</label>
                            <select name="habilita_cta" style="border-color: #f1c40f;">
                                <option value="No" <?php echo (isset($cliente_editar['habilita_cta']) && strtoupper($cliente_editar['habilita_cta']) == 'NO') ? 'selected' : ''; ?>>Deshabilitada</option>
                                <option value="Si" <?php echo (isset($cliente_editar['habilita_cta']) && strtoupper($cliente_editar['habilita_cta']) == 'SI') ? 'selected' : ''; ?>>Habilitada</option>
                            </select>
                        </div>
                        <div><label>Relación / Nota</label><input type="text" name="relacion" value="<?php echo isset($cliente_editar['relacion']) ? htmlspecialchars($cliente_editar['relacion']) : ''; ?>"></div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;">💾 Guardar Cliente</button>
                        <a href="abm_clientes.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var inputF = document.getElementById('filtro-clientes');
        if (inputF) {
            inputF.addEventListener('keyup', function() {
                var v = this.value.toUpperCase();
                var filas = document.querySelectorAll('#tablaClientes tbody tr');
                for (var i = 0; i < filas.length; i++) {
                    filas[i].style.display = (filas[i].innerText.toUpperCase().indexOf(v) > -1) ? "" : "none";
                }
            });
        }
    });
    </script>
</body>
</html>