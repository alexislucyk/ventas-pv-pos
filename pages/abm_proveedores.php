<?php
session_start();
// 1. Verificar Sesión de Seguridad
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Nota: Asumiendo que esta es la ruta correcta desde pages/
require '../config/db_config.php'; 

// Inicializar variables
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$cod_prov = isset($_GET['cod_prov']) ? $_GET['cod_prov'] : null;
$mensaje = '';
$proveedor_editar = array(); 

## Lógica del Controlador ##

try {
    // ----------------------
    // MANEJO DE FORMULARIO POST (CREAR/ACTUALIZAR)
    // ----------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Obtenemos y limpiamos los datos POST (Solo los 4 campos de la tabla)
        $cod_prov_post = trim($_POST['cod_prov']); 
        $razon = trim($_POST['razon']);
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        
        $accion_post = isset($_POST['accion_post']) ? $_POST['accion_post'] : '';
        $cod_prov_original = isset($_POST['cod_prov_original']) ? $_POST['cod_prov_original'] : $cod_prov_post;

        // Validaciones mínimas
        if (empty($cod_prov_post) || empty($razon)) {
            throw new Exception("El Código de Proveedor y la Razón Social son obligatorios.");
        }


        if ($accion_post === 'crear') {
            // INSERT (Alta) - SOLO 4 CAMPOS
            $sql = "INSERT INTO proveedores (cod_prov, razon, cuit, telefono) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono));
            $mensaje = "✅ Proveedor '{$razon}' creado con éxito.";
            $accion = 'listar'; 
        
        } elseif ($accion_post === 'editar' && $cod_prov_original) {
            // UPDATE (Modificación) - SOLO 4 CAMPOS
            $sql = "UPDATE proveedores SET cod_prov = ?, razon = ?, cuit = ?, telefono = ? 
                    WHERE cod_prov = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono, $cod_prov_original));
            $mensaje = "✅ Proveedor '{$razon}' actualizado con éxito.";
            $accion = 'listar'; 
        }
    }

    // ----------------------
    // ELIMINAR REGISTRO
    // ----------------------
    if ($accion === 'eliminar' && $cod_prov) {
        $stmt = $pdo->prepare('DELETE FROM proveedores WHERE cod_prov = ?');
        $stmt->execute(array($cod_prov));
        $mensaje = "🗑️ Proveedor con Código #{$cod_prov} eliminado correctamente.";
        $accion = 'listar';
    }

    // ----------------------
    // CARGAR DATOS PARA EDICIÓN
    // ----------------------
    if ($accion === 'editar' && $cod_prov) {
        $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE cod_prov = ?');
        $stmt->execute(array($cod_prov));
        $proveedor_editar = $stmt->fetch();
        if (!$proveedor_editar) {
            throw new Exception("Proveedor no encontrado.");
        }
    }

    // ----------------------
    // LISTAR TODOS LOS PROVEEDORES
    // ----------------------
    $proveedores = array();
    if ($accion === 'listar') {
        $stmt = $pdo->query('SELECT cod_prov, razon, cuit, telefono FROM proveedores ORDER BY razon ASC');
        $proveedores = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $mensaje = "❌ Error: " . $e->getMessage();
    $accion = 'listar';
}

## Vista (HTML y Diseño) ##
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ABM Proveedores | Mi Negocio POS</title>
    <link rel="stylesheet" href="../css/style.css"> 
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 

    <div class="content">
        <h1>Gestión de Proveedores (ABM)</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">   
                <h2>Lista de Proveedores</h2>
                
                <div class="header-clientes">
                    <a href="abm_proveedores.php?accion=crear" class="btn btn-success" style="float: right;">+ Nuevo Proveedor</a>
                    <div style="clear: both;"></div> 
                
                    <input type="text" id="filtroProveedores" placeholder="🔍 Filtrar por Razón Social o Código..." style="margin-bottom: 20px;">
                
                </div>
                <?php if (empty($proveedores)): ?>
                    <p style="margin-top: 20px;">Aún no hay proveedores registrados.</p>
                <?php else: ?>
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
                                    <td><?php echo htmlspecialchars($p['cod_prov']); ?></td>
                                    <td><?php echo htmlspecialchars($p['razon']); ?></td>
                                    <td><?php echo htmlspecialchars($p['cuit']); ?></td>
                                    <td><?php echo htmlspecialchars($p['telefono']); ?></td>
                                    <td>
                                        <a href="abm_proveedores.php?accion=editar&cod_prov=<?php echo $p['cod_prov']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                        <a href="abm_proveedores.php?accion=eliminar&cod_prov=<?php echo $p['cod_prov']; ?>" 
                                           onclick="return confirm('¿Está seguro de eliminar este proveedor?')" 
                                           class="btn btn-danger btn-sm">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card">
                <h2><?php echo ($accion === 'crear') ? 'Crear Nuevo Proveedor' : 'Editar Proveedor Código: ' . htmlspecialchars(isset($proveedor_editar['cod_prov']) ? $proveedor_editar['cod_prov'] : $cod_prov); ?></h2>
                
                <form method="POST" action="abm_proveedores.php">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                                        <input type="hidden" name="cod_prov_original" value="<?php echo htmlspecialchars(isset($proveedor_editar['cod_prov']) ? $proveedor_editar['cod_prov'] : ''); ?>">

                    <label for="cod_prov">Código Proveedor*</label>
                    <input type="text" id="cod_prov" name="cod_prov" required class="input-field"
                           value="<?php echo htmlspecialchars(isset($proveedor_editar['cod_prov']) ? $proveedor_editar['cod_prov'] : ''); ?>">

                                        <label for="razon">Razón Social*</label>
                    <input type="text" id="razon" name="razon" required class="input-field"
                           value="<?php echo htmlspecialchars(isset($proveedor_editar['razon']) ? $proveedor_editar['razon'] : ''); ?>">
                           
                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="cuit">CUIT</label>
                            <input type="text" id="cuit" name="cuit" class="input-field"
                                   value="<?php echo htmlspecialchars(isset($proveedor_editar['cuit']) ? $proveedor_editar['cuit'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono" class="input-field"
                                   value="<?php echo htmlspecialchars(isset($proveedor_editar['telefono']) ? $proveedor_editar['telefono'] : ''); ?>">
                        </div>
                    </div>

                    
                    <button type="submit" class="btn btn-primary"><?php echo ($accion === 'crear') ? 'Guardar Proveedor' : 'Actualizar Proveedor'; ?></button>
                    <a href="abm_proveedores.php" class="btn btn-warning">Cancelar</a>
                </form>
            </div>
        <?php endif; ?>

    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputFiltro = document.getElementById('filtroProveedores');
        const tabla = document.getElementById('tablaProveedores');
        const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (inputFiltro) {
            inputFiltro.addEventListener('keyup', function() {
                const filtro = inputFiltro.value.toUpperCase(); 

                for (let i = 0; i < filas.length; i++) {
                    let fila = filas[i];
                    // Celdas a buscar: Código (0) y Razón Social (1)
                    let celdaCodigo = fila.getElementsByTagName('td')[0];
                    let celdaRazon = fila.getElementsByTagName('td')[1];
                    let textoFila = '';

                    if (celdaCodigo) {
                        textoFila += celdaCodigo.textContent || celdaCodigo.innerText;
                    }
                    if (celdaRazon) {
                        textoFila += ' ' + (celdaRazon.textContent || celdaRazon.innerText);
                    }

                    if (textoFila.toUpperCase().indexOf(filtro) > -1) {
                        fila.style.display = "";
                    } else {
                        fila.style.display = "none";
                    }
                }
            });
        }
    });
</script>
</html>