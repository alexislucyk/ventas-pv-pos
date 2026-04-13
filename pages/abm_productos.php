<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer', 'admin');
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

// Inicializar variables
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$producto_editar = array(); 

// --- CARGA DE DATOS PARA SELECTS ---
try {
    $proveedores_list = $pdo->query('SELECT razon FROM proveedores ORDER BY razon ASC')->fetchAll(PDO::FETCH_ASSOC);
    $rubros_list = $pdo->query('SELECT nombre FROM rubros ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mensaje = "⚠️ Error de configuración: " . $e->getMessage();
}

// --- LÓGICA DEL CONTROLADOR (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cod_prod = trim($_POST['cod_prod']);
        $descripcion = trim($_POST['descripcion']);
        
        // Normalización de números (reemplazar coma por punto para la DB)
        $p_compra = (float)str_replace(',', '.', $_POST['p_compra']);
        $p_venta  = (float)str_replace(',', '.', $_POST['p_venta']);
        $stock    = (float)str_replace(',', '.', $_POST['stock']);
        
        $fecha_ult_compra = $_POST['fecha_ult_compra'];
        $rubro = $_POST['rubro'];
        $proveedor = $_POST['proveedor'];
        $id_post = isset($_POST['id_producto']) ? $_POST['id_producto'] : null;
        $accion_post = $_POST['accion_post'];

        if (empty($cod_prod) || empty($descripcion)) throw new Exception("Código y descripción son obligatorios.");

        if ($accion_post === 'crear') {
            $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, stock, fecha_ult_compra, rubro, proveedor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor]);
            $mensaje = "✅ Producto creado correctamente.";
            $accion = 'listar';
        } elseif ($accion_post === 'editar' && $id_post) {
            $sql = "UPDATE productos SET cod_prod=?, descripcion=?, p_compra=?, p_venta=?, stock=?, fecha_ult_compra=?, rubro=?, proveedor=? WHERE id=?";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $stock, $fecha_ult_compra, $rubro, $proveedor, $id_post]);
            $mensaje = "✅ Producto actualizado correctamente.";
            $accion = 'listar';
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// --- ELIMINAR ---
if ($accion === 'eliminar' && $id) {
    $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
    $mensaje = "🗑️ Producto eliminado.";
    $accion = 'listar';
}

// --- CARGAR PARA EDICIÓN ---
if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
    $stmt->execute([$id]);
    $producto_editar = $stmt->fetch();
}

// --- LISTAR ---
$productos = ($accion === 'listar') ? $pdo->query('SELECT * FROM productos ORDER BY id DESC')->fetchAll() : [];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Productos | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <style>
        .flex-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 5px; color: #3498db; font-weight: bold; font-size: 0.9em; }
        input, select { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; }
        input:focus { border-color: #3498db; outline: none; }
        .btn-sm { padding: 5px 10px; font-size: 0.85em; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: rgba(46, 204, 113, 0.2); border: 1px solid #2ecc71; color: #2ecc71; }
        .alert-error { background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; color: #e74c3c; }
        .table-container { overflow-x: auto; }
        #filtroProductos { width: 100%; max-width: 400px; margin-bottom: 20px; background: #1a1a1a url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>') no-repeat 10px center; padding-left: 35px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>📦 Gestión de Productos</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="abm_productos.php?accion=crear" class="btn btn-success">+ Nuevo Producto</a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">
                <input type="text" id="filtroProductos" class="form-control" placeholder="Buscar por código o descripción...">
                
                <div class="table-container">
                    <table id="tablaProductos">
                        <thead>
                            <tr>
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
                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($p['cod_prod']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($p['descripcion']); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['rubro']); ?></td>
                                <td style="text-align: center;"><?php echo number_format($p['stock'], 2, ',', '.'); ?></td>
                                <td style="text-align: right; color: #2ecc71;">$<?php echo number_format($p['p_venta'], 2, ',', '.'); ?></td>
                                <td>
                                    <a href="abm_productos.php?accion=editar&id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                    <a href="abm_productos.php?accion=eliminar&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar producto?')">Borrar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 800px; margin: 0 auto;">
                <h2><?php echo ($accion === 'crear') ? 'Nuevo Registro' : 'Editando: ' . htmlspecialchars($producto_editar['descripcion']); ?></h2>
                <hr style="border: 0; border-top: 1px solid #444; margin-bottom: 20px;">
                
                <form method="POST">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_producto" value="<?php echo isset($producto_editar['id']) ? $producto_editar['id'] : ''; ?>">

                    <div class="flex-row">
                        <div>
                            <label>Código de Barras / Interno</label>
                            <input type="text" name="cod_prod" required value="<?php echo isset($producto_editar['cod_prod']) ? $producto_editar['cod_prod'] : ''; ?>">
                        </div>
                        <div>
                            <label>Rubro / Categoría</label>
                            <select name="rubro">
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($rubros_list as $r): ?>
                                    <option value="<?php echo $r['nombre']; ?>" <?php echo (isset($producto_editar['rubro']) && $producto_editar['rubro'] == $r['nombre']) ? 'selected' : ''; ?>>
                                        <?php echo $r['nombre']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label>Descripción del Producto</label>
                        <input type="text" name="descripcion" required value="<?php echo isset($producto_editar['descripcion']) ? $producto_editar['descripcion'] : ''; ?>">
                    </div>

                    <div class="flex-row">
                        <div>
                            <label>Costo (Compra)</label>
                            <input type="text" name="p_compra" class="num-format" value="<?php echo isset($producto_editar['p_compra']) ? $producto_editar['p_compra'] : '0'; ?>">
                        </div>
                        <div>
                            <label>Precio de Venta</label>
                            <input type="text" name="p_venta" class="num-format" required value="<?php echo isset($producto_editar['p_venta']) ? $producto_editar['p_venta'] : '0'; ?>">
                        </div>
                        <div>
                            <label>Stock Actual</label>
                            <input type="text" name="stock" class="num-format" value="<?php echo isset($producto_editar['stock']) ? $producto_editar['stock'] : '0'; ?>">
                        </div>
                    </div>

                    <div class="flex-row">
                        <div style="flex: 2;">
                            <label>Proveedor Principal</label>
                            <select name="proveedor">
                                <option value="">-- Seleccionar Proveedor --</option>
                                <?php foreach ($proveedores_list as $prov): ?>
                                    <option value="<?php echo $prov['razon']; ?>" <?php echo (isset($producto_editar['proveedor']) && $producto_editar['proveedor'] == $prov['razon']) ? 'selected' : ''; ?>>
                                        <?php echo $prov['razon']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label>Fecha Ult. Compra</label>
                            <input type="date" name="fecha_ult_compra" value="<?php echo isset($producto_editar['fecha_ult_compra']) ? $producto_editar['fecha_ult_compra'] : date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;">💾 Guardar Cambios</button>
                        <a href="abm_productos.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- FILTRO DE TABLA ---
        const inputFiltro = document.getElementById('filtroProductos');
        if (inputFiltro) {
            inputFiltro.addEventListener('keyup', function() {
                const filtro = this.value.toUpperCase();
                const filas = document.querySelectorAll('#tablaProductos tbody tr');
                filas.forEach(fila => {
                    const texto = fila.innerText.toUpperCase();
                    fila.style.display = texto.includes(filtro) ? "" : "none";
                });
            });
        }

        // --- NORMALIZACIÓN DE NÚMEROS (Punto por Coma visualmente) ---
        const inputsNumericos = document.querySelectorAll('.num-format');
        inputsNumericos.forEach(input => {
            input.addEventListener('blur', function() {
                this.value = this.value.replace(/\./g, ',');
            });
            // Al ganar foco, mostramos punto para facilitar edición si es necesario
            input.addEventListener('focus', function() {
                this.value = this.value.replace(/,/g, '.');
            });
        });
    });
    </script>
</body>
</html>