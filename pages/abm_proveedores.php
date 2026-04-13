<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer');
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$cod_prov = isset($_GET['cod_prov']) ? $_GET['cod_prov'] : null;
$mensaje = '';
$proveedor_editar = array(); 

// --- LÓGICA PARA AUTOGENERAR CÓDIGO (Solo si es creación) ---
$nuevo_codigo_sugerido = '';
if ($accion === 'crear') {
    try {
        // Buscamos el valor máximo. Ajusta el nombre de la columna si es necesario.
        // Esta consulta intenta obtener el número más alto incluso si el campo es texto.
        $stmt_cod = $pdo->query("SELECT cod_prov FROM proveedores ORDER BY (cod_prov + 0) DESC LIMIT 1");
        $ultimo = $stmt_cod->fetch();
        
        if ($ultimo) {
            // Si el último código es "10", el siguiente será "11"
            // El (+ 0) en SQL fuerza a tratar el campo como número para la ordenación
            $nuevo_codigo_sugerido = intval($ultimo['cod_prov']) + 1;
        } else {
            // Si la tabla está vacía, empezamos en 1
            $nuevo_codigo_sugerido = 1;
        }
    } catch (Exception $e) {
        $nuevo_codigo_sugerido = ''; // En caso de error, queda vacío para carga manual
    }
}

try {
    // --- CONTROLADOR POST ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cod_prov_post = trim($_POST['cod_prov']); 
        $razon = trim($_POST['razon']);
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        
        $accion_post = isset($_POST['accion_post']) ? $_POST['accion_post'] : '';
        $cod_prov_original = isset($_POST['cod_prov_original']) ? $_POST['cod_prov_original'] : $cod_prov_post;

        if (empty($cod_prov_post) || empty($razon)) {
            throw new Exception("El Código y la Razón Social son obligatorios.");
        }

        if ($accion_post === 'crear') {
            $sql = "INSERT INTO proveedores (cod_prov, razon, cuit, telefono) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono));
            $mensaje = "✅ Proveedor registrado con éxito.";
            $accion = 'listar'; 
        } elseif ($accion_post === 'editar' && $cod_prov_original) {
            $sql = "UPDATE proveedores SET cod_prov = ?, razon = ?, cuit = ?, telefono = ? WHERE cod_prov = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono, $cod_prov_original));
            $mensaje = "✅ Proveedor actualizado con éxito.";
            $accion = 'listar'; 
        }
    }

    if ($accion === 'eliminar' && $cod_prov) {
        $stmt = $pdo->prepare('DELETE FROM proveedores WHERE cod_prov = ?');
        $stmt->execute(array($cod_prov));
        $mensaje = "🗑️ Proveedor eliminado.";
        $accion = 'listar';
    }

    if ($accion === 'editar' && $cod_prov) {
        $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE cod_prov = ?');
        $stmt->execute(array($cod_prov));
        $proveedor_editar = $stmt->fetch();
    }

    $proveedores = array();
    if ($accion === 'listar') {
        $stmt = $pdo->query('SELECT * FROM proveedores ORDER BY (cod_prov + 0) ASC, razon ASC');
        $proveedores = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $mensaje = "❌ Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proveedores | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <style>
        .flex-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 5px; color: #3498db; font-weight: bold; font-size: 0.9em; }
        input { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; }
        .input-auto { border-color: #27ae60 !important; color: #2ecc71 !important; font-weight: bold; }
        #filtro-proveedores { width: 100%; max-width: 450px; margin-bottom: 20px; background: #1a1a1a; border: 1px solid #333; height: 40px; color: white; padding-left: 10px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>🚚 Gestión de Proveedores</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="abm_proveedores.php?accion=crear" class="btn btn-success">+ Nuevo Proveedor</a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo (strpos($mensaje, '❌') !== false) ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">
                <input type="text" id="filtro-proveedores" placeholder="🔍 Buscar proveedor...">
                <table id="tablaProveedores">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Razón Social</th>
                            <th>CUIT</th>
                            <th>Teléfono</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proveedores as $p): ?>
                        <tr>
                            <td><span style="color: #3498db; font-weight: bold;"><?php echo htmlspecialchars($p['cod_prov']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($p['razon']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['cuit'] ? $p['cuit'] : '---'); ?></td>
                            <td><?php echo htmlspecialchars($p['telefono'] ? $p['telefono'] : '---'); ?></td>
                            <td>
                                <a href="abm_proveedores.php?accion=editar&cod_prov=<?php echo $p['cod_prov']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                <a href="abm_proveedores.php?accion=eliminar&cod_prov=<?php echo $p['cod_prov']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar?')">Borrar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 800px; margin: 0 auto;">
                <h2><?php echo ($accion === 'crear') ? 'Nuevo Proveedor' : 'Modificar Proveedor'; ?></h2>
                <form method="POST">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="cod_prov_original" value="<?php echo isset($proveedor_editar['cod_prov']) ? htmlspecialchars($proveedor_editar['cod_prov']) : ''; ?>">

                    <div class="flex-row">
                        <div style="flex: 0.5;">
                            <label>Código*</label>
                            <input type="text" name="cod_prov" required 
                                class="<?php echo ($accion === 'crear') ? 'input-auto' : ''; ?>"
                                value="<?php 
                                    if($accion === 'editar') {
                                        echo htmlspecialchars($proveedor_editar['cod_prov']);
                                    } else {
                                        echo htmlspecialchars($nuevo_codigo_sugerido);
                                    }
                                ?>">
                            <?php if($accion === 'crear'): ?>
                                <small style="color: #27ae60;">Sugerido automáticamente</small>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1.5;">
                            <label>Razón Social*</label>
                            <input type="text" name="razon" required value="<?php echo isset($proveedor_editar['razon']) ? htmlspecialchars($proveedor_editar['razon']) : ''; ?>">
                        </div>
                    </div>

                    <div class="flex-row">
                        <div><label>CUIT</label><input type="text" name="cuit" value="<?php echo isset($proveedor_editar['cuit']) ? htmlspecialchars($proveedor_editar['cuit']) : ''; ?>"></div>
                        <div><label>Teléfono</label><input type="text" name="telefono" value="<?php echo isset($proveedor_editar['telefono']) ? htmlspecialchars($proveedor_editar['telefono']) : ''; ?>"></div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;">💾 Guardar</button>
                        <a href="abm_proveedores.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var inputF = document.getElementById('filtro-proveedores');
        if(inputF) {
            inputF.addEventListener('keyup', function() {
                var v = this.value.toUpperCase();
                var filas = document.querySelectorAll('#tablaProveedores tbody tr');
                for(var i=0; i<filas.length; i++) {
                    filas[i].style.display = (filas[i].innerText.toUpperCase().indexOf(v) > -1) ? "" : "none";
                }
            });
        }
    });
    </script>
</body>
</html>