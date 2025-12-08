<?php
session_start();
// 1. Verificar Sesión de Seguridad
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

require '../config/db_config.php'; // Incluir la conexión PDO

// Inicializar variables
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar'; // 'listar', 'crear', 'editar', 'eliminar'
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$cliente_editar = array(); 

## Lógica del Controlador ##

try {
    // ----------------------
    // MANEJO DE FORMULARIO POST (CREAR/ACTUALIZAR)
    // ----------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        $direccion = trim($_POST['direccion']);
        // Agregamos manejo para los campos adicionales
        $estado = trim($_POST['estado']);
        $habilita_cta = trim($_POST['habilita_cta']); 
        $relacion = trim($_POST['relacion']);
        
        $id_post = isset($_POST['id_cliente']) ? $_POST['id_cliente'] : null;
        $accion_post = isset($_POST['accion_post']) ? $_POST['accion_post'] : '';

        // Validaciones mínimas
        if (empty($nombre) || empty($apellido)) {
            throw new Exception("El nombre y apellido son obligatorios.");
        }

        if ($accion_post === 'crear') {
            // INSERT (Alta) - Usando tus nombres de columna
            $sql = "INSERT INTO clientes (nombre, apellido, cuit, telefono, direccion, estado, habilita_cta, relacion) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($nombre, $apellido, $cuit, $telefono, $direccion, $estado, $habilita_cta, $relacion));
            $mensaje = "✅ Cliente '{$nombre} {$apellido}' creado con éxito.";
            $accion = 'listar'; 
        
        } elseif ($accion_post === 'editar' && $id_post) {
            // UPDATE (Modificación) - Usando tus nombres de columna
            $sql = "UPDATE clientes SET nombre = ?, apellido = ?, cuit = ?, telefono = ?, direccion = ?, estado = ?, habilita_cta = ?, relacion = ? 
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($nombre, $apellido, $cuit, $telefono, $direccion, $estado, $habilita_cta, $relacion, $id_post));
            $mensaje = "✅ Cliente '{$nombre} {$apellido}' actualizado con éxito.";
            $accion = 'listar'; 
        }
    }

    // ----------------------
    // ELIMINAR REGISTRO
    // ----------------------
    if ($accion === 'eliminar' && $id) {
        $stmt = $pdo->prepare('DELETE FROM clientes WHERE id = ?');
        $stmt->execute(array($id));
        $mensaje = "🗑️ Cliente ID #{$id} eliminado correctamente.";
        $accion = 'listar';
    }

    // ----------------------
    // CARGAR DATOS PARA EDICIÓN
    // ----------------------
    if ($accion === 'editar' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $stmt->execute(array($id));
        $cliente_editar = $stmt->fetch();
        if (!$cliente_editar) {
            throw new Exception("Cliente no encontrado.");
        }
    }

    // ----------------------
    // LISTAR TODOS LOS CLIENTES
    // ----------------------
    $clientes = array();
    if ($accion === 'listar') {
        // Seleccionamos los campos que usaremos en la tabla
        $stmt = $pdo->query('SELECT id, nombre, apellido, cuit, telefono FROM clientes ORDER BY id DESC');
        $clientes = $stmt->fetchAll();
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
    <title>ABM Clientes | Mi Negocio POS</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 

    <div class="content">
        <h1>Gestión de Clientes (ABM)</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">   
            <h2>Lista de Clientes</h2>
                <div class="header-clientes">
                    
                    <a href="abm_clientes.php?accion=crear" class="btn btn-success" style="float: right;">+Nuevo</a>
                    <div style="clear: both;"></div> 
                    <input type="text" id="filtro-clientes" placeholder="Filtrar clientes..." class="input-filtro">
                </div>
                                
                <?php if (empty($clientes)): ?>
                    <p style="margin-top: 20px;">Aún no hay clientes registrados.</p>
                <?php else: ?>
                    
                    <table id="tablaClientes">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre y Apellido</th>
                                <th>CUIT</th>
                                <th>Teléfono</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $c): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['id']); ?></td>
                                    <td><?php echo htmlspecialchars($c['nombre'] . ' ' . $c['apellido']); ?></td>
                                    <td><?php echo htmlspecialchars($c['cuit']); ?></td>
                                    <td><?php echo htmlspecialchars($c['telefono']); ?></td>
                                    <td>
                                        <a href="abm_clientes.php?accion=editar&id=<?php echo $c['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                        <a href="abm_clientes.php?accion=eliminar&id=<?php echo $c['id']; ?>" 
                                           onclick="return confirm('¿Está seguro de eliminar a este cliente?')" 
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
                <h2><?php echo ($accion === 'crear') ? 'Crear Nuevo Cliente' : 'Editar Cliente ID: ' . htmlspecialchars(isset($cliente_editar['id']) ? $cliente_editar['id'] : $id); ?></h2>
                
                <form method="POST" action="abm_clientes.php">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_cliente" value="<?php echo htmlspecialchars(isset($cliente_editar['id']) ? $cliente_editar['id'] : ''); ?>">

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="nombre">Nombre*</label>
                            <input type="text" id="nombre" name="nombre" 
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['nombre']) ? $cliente_editar['nombre'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="apellido">Apellido*</label>
                            <input type="text" id="apellido" name="apellido" required 
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['apellido']) ? $cliente_editar['apellido'] : ''); ?>">
                        </div>
                    </div>

                    <label for="direccion">Dirección</label>
                    <input type="text" id="direccion" name="direccion"
                           value="<?php echo htmlspecialchars(isset($cliente_editar['direccion']) ? $cliente_editar['direccion'] : ''); ?>">

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="cuit">CUIT</label>
                            <input type="text" id="cuit" name="cuit"
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['cuit']) ? $cliente_editar['cuit'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono"
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['telefono']) ? $cliente_editar['telefono'] : ''); ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="estado">Estado</label>
                            <input type="text" id="estado" name="estado"
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['estado']) ? $cliente_editar['estado'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="relacion">Relación</label>
                            <input type="text" id="relacion" name="relacion"
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['relacion']) ? $cliente_editar['relacion'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="habilita_cta">Habilita Cta. Cte.</label>
                            <input type="text" id="habilita_cta" name="habilita_cta"
                                   value="<?php echo htmlspecialchars(isset($cliente_editar['habilita_cta']) ? $cliente_editar['habilita_cta'] : ''); ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><?php echo ($accion === 'crear') ? 'Guardar Cliente' : 'Actualizar Cliente'; ?></button>
                    <a href="abm_clientes.php" class="btn btn-warning">Cancelar</a>
                </form>
            </div>
        <?php endif; ?>

    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Obtenemos el campo de entrada de filtro y el cuerpo de la tabla
        const inputFiltro = document.getElementById('filtro-clientes');
        const tabla = document.getElementById('tablaClientes');
        // Seleccionamos todas las filas del cuerpo de la tabla
        const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        // Función que se ejecuta con cada pulsación de tecla
        inputFiltro.addEventListener('keyup', function() {
            const filtro = inputFiltro.value.toUpperCase(); // Convertir a mayúsculas para búsqueda insensible a mayúsculas/minúsculas

            // Iterar sobre cada fila de la tabla
            for (let i = 0; i < filas.length; i++) {
                let fila = filas[i];
                // Obtenemos las celdas de Nombre (índice 1) y CUIT (índice 2)
                let celdaNombre = fila.getElementsByTagName('td')[1];
                let celdaCuit = fila.getElementsByTagName('td')[2];
                let textoFila = '';

                // Concatenamos el texto de las celdas relevantes (Nombre y CUIT)
                if (celdaNombre) {
                    textoFila += celdaNombre.textContent || celdaNombre.innerText;
                }
                if (celdaCuit) {
                    textoFila += ' ' + (celdaCuit.textContent || celdaCuit.innerText);
                }

                // Verificar si el texto de la fila contiene el texto del filtro
                if (textoFila.toUpperCase().indexOf(filtro) > -1) {
                    fila.style.display = ""; // Mostrar la fila
                } else {
                    fila.style.display = "none"; // Ocultar la fila
                }
            }
        });
    });
</script>
</html>