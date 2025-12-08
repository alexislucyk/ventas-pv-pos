<?php
session_start();
// 1. Verificar Sesión de Seguridad
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Nota: Asumiendo que esta es la ruta correcta desde pages/
require '../config/db_config.php'; // Incluir la conexión PDO

// Inicializar variables
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$producto_editar = array(); 

// ----------------------
// OBTENER LISTA DE PROVEEDORES para el SELECT
// ----------------------
$proveedores_list = array();
$mensaje_proveedor = '';
try {
    // CORRECCIÓN: Usamos 'cod_prov' como identificador y 'razon' para el display
    $stmt_prov = $pdo->query('SELECT cod_prov, razon FROM proveedores ORDER BY razon ASC');
    $proveedores_list = $stmt_prov->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Captura un error si la tabla 'proveedores' no existe.
    $mensaje_proveedor = "⚠️ Error al cargar proveedores: La tabla 'proveedores' no pudo ser consultada. " . $e->getMessage();
}

// ----------------------
// OBTENER LISTA DE RUBROS para el SELECT
// ----------------------
$rubros_list = array();
$mensaje_rubro = '';
try {
    // CORRECCIÓN: Asumiendo que tienes una tabla 'rubros' con una columna 'nombre'
    $stmt_rubro = $pdo->query('SELECT nombre FROM rubros ORDER BY nombre ASC');
    $rubros_list = $stmt_rubro->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Captura un error si la tabla 'rubros' no existe.
    $mensaje_rubro = "⚠️ Error al cargar rubros: La tabla 'rubros' no pudo ser consultada. " . $e->getMessage();
}

## Lógica del Controlador ##

try {
    // ----------------------
    // MANEJO DE FORMULARIO POST (CREAR/ACTUALIZAR)
    // ----------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Obtenemos y limpiamos los datos POST
        $cod_prod = trim($_POST['cod_prod']);
        $descripcion = trim($_POST['descripcion']);
        
        // ==========================================================
        //         ➡️ INICIO DE LAS CORRECCIONES
        // ==========================================================
        
        // 1. Limpiamos la variable POST, reemplazando la coma (,) por el punto (.)
        $p_compra_limpio = str_replace(',', '.', trim($_POST['p_compra']));
        $p_venta_limpio  = str_replace(',', '.', trim($_POST['p_venta']));
        $stock_limpio    = str_replace(',', '.', trim($_POST['stock']));

        // 2. Ahora sí, convertimos a float la cadena limpia (con punto)
        $p_compra = (float)$p_compra_limpio;
        $p_venta  = (float)$p_venta_limpio;
        $stock    = (float)$stock_limpio;
        
        // ==========================================================
        //         ⬅️ FIN DE LAS CORRECCIONES
        // ==========================================================
        $fecha_ult_compra = trim($_POST['fecha_ult_compra']);
        $rubro = trim($_POST['rubro']);
        $proveedor = trim($_POST['proveedor']); // <--- Se obtiene el valor del SELECT
        
        $id_post = isset($_POST['id_producto']) ? $_POST['id_producto'] : null;
        $accion_post = isset($_POST['accion_post']) ? $_POST['accion_post'] : '';

        // Validaciones mínimas
        if (empty($cod_prod) || empty($descripcion)) {
            throw new Exception("El código y la descripción son obligatorios.");
        }
        if (empty($proveedor)) {
            throw new Exception("Debe seleccionar un proveedor.");
        }


        if ($accion_post === 'crear') {
            // INSERT (Alta)
            $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, stock, fecha_ult_compra, rubro, proveedor) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor));
            $mensaje = "✅ Producto '{$descripcion}' creado con éxito.";
            $accion = 'listar'; 
        
        } elseif ($accion_post === 'editar' && $id_post) {
            // UPDATE (Modificación)
            $sql = "UPDATE productos SET cod_prod = ?, descripcion = ?, p_compra = ?, p_venta = ?, stock = ?, fecha_ult_compra = ?, rubro = ?, proveedor = ? 
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor, $id_post));
            $mensaje = "✅ Producto '{$descripcion}' actualizado con éxito.";
            $accion = 'listar'; 
        }
    }

    // ----------------------
    // ELIMINAR REGISTRO
    // ----------------------
    if ($accion === 'eliminar' && $id) {
        $stmt = $pdo->prepare('DELETE FROM productos WHERE id = ?');
        $stmt->execute(array($id));
        $mensaje = "🗑️ Producto ID #{$id} eliminado correctamente.";
        $accion = 'listar';
    }

    // ----------------------
    // CARGAR DATOS PARA EDICIÓN
    // ----------------------
    if ($accion === 'editar' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $stmt->execute(array($id));
        $producto_editar = $stmt->fetch();
        if (!$producto_editar) {
            throw new Exception("Producto no encontrado.");
        }
    }

    // ----------------------
    // LISTAR TODOS LOS PRODUCTOS
    // ----------------------
    $productos = array();
    if ($accion === 'listar') {
        $stmt = $pdo->query('SELECT id, cod_prod, descripcion, p_venta, stock, rubro FROM productos ORDER BY id DESC');
        $productos = $stmt->fetchAll();
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
    <title>ABM Productos | Mi Negocio POS</title>
    <link rel="stylesheet" href="../css/style.css"> 
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 

    <div class="content">
        <h1>Gestión de Productos (ABM)</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($mensaje_proveedor): ?>
            <div class="alert alert-error">
                <?php echo $mensaje_proveedor; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">   
                <h2>Lista de Productos</h2>
                
                <a href="abm_productos.php?accion=crear" class="btn btn-success" style="float: right;">+ Nuevo Producto</a>
                <div style="clear: both;"></div> 
                
                <input type="text" id="filtroProductos" placeholder="🔍 Filtrar productos por Código o Descripción..." style="margin-bottom: 20px;">
                
                <?php if (empty($productos)): ?>
                    <p style="margin-top: 20px;">Aún no hay productos registrados.</p>
                <?php else: ?>
                    <table id="tablaProductos">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th>Rubro</th>
                                <th>Stock</th>
                                <th>P. Venta</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['id']); ?></td>
                                    <td><?php echo htmlspecialchars($p['cod_prod']); ?></td>
                                    <td><?php echo htmlspecialchars($p['descripcion']); ?></td>
                                    <td><?php echo htmlspecialchars($p['rubro']); ?></td>
                                    <td style="text-align: right;"><?php echo htmlspecialchars($p['stock']); ?></td>
                                    <td style="text-align: right;">$<?php echo number_format($p['p_venta'], 2, ',', '.'); ?></td>
                                    <td>
                                        <a href="abm_productos.php?accion=editar&id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                        <a href="abm_productos.php?accion=eliminar&id=<?php echo $p['id']; ?>" 
                                           onclick="return confirm('¿Está seguro de eliminar este producto?')" 
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
                <h2><?php echo ($accion === 'crear') ? 'Crear Nuevo Producto' : 'Editar Producto ID: ' . htmlspecialchars(isset($producto_editar['id']) ? $producto_editar['id'] : $id); ?></h2>
                
                <form method="POST" action="abm_productos.php">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_producto" value="<?php echo htmlspecialchars(isset($producto_editar['id']) ? $producto_editar['id'] : ''); ?>">

                    <?php if ($mensaje_rubro): ?>
                    <div class="alert alert-error">
                        <?php echo $mensaje_rubro; ?>
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 20px;">
                    <div style="flex: 1;">
                        <label for="cod_prod">Código Producto*</label>
                        <input type="text" id="cod_prod" name="cod_prod" required 
                                value="<?php echo htmlspecialchars(isset($producto_editar['cod_prod']) ? $producto_editar['cod_prod'] : ''); ?>">
                    </div>
                    
                    <div style="flex: 1;">
                        <label for="rubro">Rubro</label>
                        <select id="rubro" name="rubro">
                            <option value="">-- Seleccione Rubro --</option>
                            <?php foreach ($rubros_list as $r): 
                                // Usamos el valor guardado en el producto para seleccionar la opción
                                $selected = (isset($producto_editar['rubro']) && $producto_editar['rubro'] === $r['nombre']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($r['nombre']); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($r['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                    <label for="descripcion">Descripción*</label>
                    <input type="text" id="descripcion" name="descripcion" required
                           value="<?php echo htmlspecialchars(isset($producto_editar['descripcion']) ? $producto_editar['descripcion'] : ''); ?>">

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label for="p_compra">Precio Compra ($)</label>
                            <input type="text" step="0.01" id="p_compra" name="p_compra"
                                   value="<?php echo htmlspecialchars(isset($producto_editar['p_compra']) ? $producto_editar['p_compra'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="p_venta">Precio Venta ($)*</label>
                            <input type="text" step="0.01" id="p_venta" name="p_venta" required
                                   value="<?php echo htmlspecialchars(isset($producto_editar['p_venta']) ? $producto_editar['p_venta'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label for="stock">Stock</label>
                            <input type="text" step="0.01" id="stock" name="stock"
                                   value="<?php echo htmlspecialchars(isset($producto_editar['stock']) ? $producto_editar['stock'] : ''); ?>">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 6;">
                            <label for="proveedor">Proveedor</label>
                            <select id="proveedor" name="proveedor" required>
                                <option value="">-- Seleccione Proveedor --</option>
                                <?php foreach ($proveedores_list as $p): 
                                    $selected = (isset($producto_editar['proveedor']) && $producto_editar['proveedor'] === $p['razon']) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($p['razon']); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($p['razon']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                        <div style="flex: 4;">
                            <label for="fecha_ult_compra">Fecha Última Compra</label>
                            <input type="date" id="fecha_ult_compra" name="fecha_ult_compra"
                                   value="<?php echo htmlspecialchars(isset($producto_editar['fecha_ult_compra']) ? $producto_editar['fecha_ult_compra'] : date('Y-m-d')); ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><?php echo ($accion === 'crear') ? 'Guardar Producto' : 'Actualizar Producto'; ?></button>
                    <a href="abm_productos.php" class="btn btn-warning">Cancelar</a>
                </form>
            </div>
        <?php endif; ?>

    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputFiltro = document.getElementById('filtroProductos');
        const tabla = document.getElementById('tablaProductos');
        // Debe verificar que la tabla exista antes de intentar obtener filas
        const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (inputFiltro) {
            inputFiltro.addEventListener('keyup', function() {
                const filtro = inputFiltro.value.toUpperCase(); 

                for (let i = 0; i < filas.length; i++) {
                    let fila = filas[i];
                    // Celdas a buscar: Código (1) y Descripción (2)
                    let celdaCodigo = fila.getElementsByTagName('td')[1];
                    let celdaDescripcion = fila.getElementsByTagName('td')[2];
                    let textoFila = '';

                    if (celdaCodigo) {
                        textoFila += celdaCodigo.textContent || celdaCodigo.innerText;
                    }
                    if (celdaDescripcion) {
                        textoFila += ' ' + (celdaDescripcion.textContent || celdaDescripcion.innerText);
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
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ... (Tu código de filtro de tabla) ...
        
        // Función para limpiar y normalizar el valor del campo
        function normalizarNumero(evento) {
            let valor = this.value;
            
            // 1. Reemplaza el punto por una coma (permitiendo ingreso con punto)
            valor = valor.replace(/\./g, ',');
            
            // 2. Limpia el valor de múltiples comas o caracteres no numéricos
            // (Esta es una limpieza básica, puede que necesites una más compleja
            // si quieres evitar formatos como "10,20,30")
            
            // 3. Establece el valor corregido de vuelta en el input
            this.value = valor;
        }

        // ----------------------------------------------------
        // Asignación de la función a los inputs afectados
        // Usamos 'blur' para corregir el valor una vez que el usuario
        // ha terminado de interactuar con el campo.
        // ----------------------------------------------------
        
        const inputsNumericos = document.querySelectorAll('#p_compra, #p_venta, #stock');

        inputsNumericos.forEach(input => {
            // Utilizamos 'blur' para que la corrección ocurra después
            // de que el usuario haya dejado el foco del campo, preservando
            // la experiencia nativa de 'type="number"'.
            input.addEventListener('blur', normalizarNumero); 
        });

    });
</script>
</html>