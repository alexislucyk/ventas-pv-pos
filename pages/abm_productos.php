<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
//restringirPagina('developer', 'admin');
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
    
    // Sugerir código para nuevo proveedor (para el modal de alta rápida)
    $stmt_cod_prov = $pdo->query("SELECT cod_prov FROM proveedores ORDER BY (cod_prov + 0) DESC LIMIT 1");
    $ult_prov = $stmt_cod_prov->fetch();
    $nuevo_cod_prov_sugerido = $ult_prov ? (intval($ult_prov['cod_prov']) + 1) : 1;

    // Obtener Ganancia Global de la configuración
    $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
    $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
} catch (Exception $e) {
    $mensaje = "⚠️ Error de configuración: " . $e->getMessage();
}

// --- LÓGICA DEL CONTROLADOR (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion_post = $_POST['accion_post'];

        // --- ACTUALIZACIÓN MASIVA DE PRECIOS ---
        if ($accion_post === 'aumento_masivo') {
            $filtro_rubro = $_POST['masivo_rubro'];
            $filtro_prov  = $_POST['masivo_proveedor'];
            $ids_seleccionados = $_POST['seleccionados_ids'] ?? '';
            $tipo_cambio  = $_POST['masivo_tipo']; // 'porcentaje' o 'fijo'
            $valor_cambio = (float)str_replace(',', '.', $_POST['masivo_valor']);
            $operacion    = $_POST['masivo_operacion']; // 'aumentar' o 'bajar'

            if ($valor_cambio <= 0) throw new Exception("El valor de cambio debe ser mayor a cero.");

            // Construcción de la query dinámica
            $sql_base = "UPDATE productos SET p_venta = ";
            
            if ($operacion === 'aumentar') {
                $sql_base .= ($tipo_cambio === 'porcentaje') ? "p_venta * (1 + ($valor_cambio / 100))" : "p_venta + $valor_cambio";
            } else {
                $sql_base .= ($tipo_cambio === 'porcentaje') ? "p_venta * (1 - ($valor_cambio / 100))" : "p_venta - $valor_cambio";
            }

            $where = [];
            $params = [];

            if (!empty($ids_seleccionados)) {
                $id_array = explode(',', $ids_seleccionados);
                $placeholders = implode(',', array_fill(0, count($id_array), '?'));
                $where[] = "id IN ($placeholders)";
                $params = $id_array;
            } else {
                if (!empty($filtro_rubro)) {
                    $where[] = "rubro = ?";
                    $params[] = $filtro_rubro;
                }
                if (!empty($filtro_prov)) {
                    $where[] = "proveedor = ?";
                    $params[] = $filtro_prov;
                }
            }

            if (!empty($where)) {
                $sql_base .= " WHERE " . implode(" AND ", $where);
            }

            $stmt = $pdo->prepare($sql_base);
            $stmt->execute($params);
            $mensaje = "✅ Precios actualizados en " . $stmt->rowCount() . " productos.";
            $accion = 'listar';
        } else {
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
        #filtroProductos { width: 100%; max-width: 400px; margin-bottom: 20px; background: #1a1a1a url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>') no-repeat 10px center; padding-left: 40px !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>📦 Gestión de Productos</h1>
            <?php if ($accion === 'listar'): ?>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" onclick="abrirModalMasivo()" style="background-color: #6f42c1; color: white;"><i class="fas fa-bolt"></i> Aumento Masivo</button>
                    <a href="abm_productos.php?accion=crear" class="btn btn-success">+ Nuevo Producto</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
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
                                <th style="width: 30px;"><input type="checkbox" id="selectAll" title="Seleccionar todos los visibles"></th>
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
                                <td><input type="checkbox" class="prod-check" value="<?php echo $p['id']; ?>"></td>
                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($p['cod_prod']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($p['descripcion']); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['rubro']); ?></td>
                                <td style="text-align: center;"><?php echo number_format($p['stock'], 2, ',', '.'); ?></td>
                                <td style="text-align: right; color: #2ecc71;">$<?php echo number_format($p['p_venta'], 2, ',', '.'); ?></td>
                                <td>
                                    <a href="abm_productos.php?accion=editar&id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                    <a href="abm_productos.php?accion=eliminar&id=<?php echo $p['id']; ?>" 
                                       class="btn btn-danger btn-sm" 
                                       onclick="event.preventDefault(); const url=this.href; confirmarAccion('Eliminar Producto', '¿Deseas quitar este producto del inventario?', 'ELIMINAR', 'btn-danger', () => window.location.href=url);">
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
                            <div style="display: flex; gap: 5px; align-items: stretch;">
                                <select name="rubro" id="select_rubro" style="flex: 1; margin-bottom: 0 !important;">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($rubros_list as $r): ?>
                                        <option value="<?php echo $r['nombre']; ?>" <?php echo (isset($producto_editar['rubro']) && $producto_editar['rubro'] == $r['nombre']) ? 'selected' : ''; ?>>
                                            <?php echo $r['nombre']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success" onclick="agregarNuevoRubro()" title="Agregar nuevo rubro" style="width: 45px; display: flex; align-items: center; justify-content: center; padding: 0; margin-bottom: 0;">+</button>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label>Descripción del Producto</label>
                        <input type="text" name="descripcion" required value="<?php echo isset($producto_editar['descripcion']) ? $producto_editar['descripcion'] : ''; ?>">
                    </div>

                    <div class="flex-row">
                        <div>
                            <label>Costo (Compra)</label>
                            <input type="text" name="p_compra" id="p_compra_input" class="num-format" value="<?php echo isset($producto_editar['p_compra']) ? $producto_editar['p_compra'] : '0'; ?>" oninput="calcularPrecioVentaSugerido()">
                        </div>
                        <div>
                            <label>Precio de Venta</label>
                            <input type="text" name="p_venta" id="p_venta_input" class="num-format" required value="<?php echo isset($producto_editar['p_venta']) ? $producto_editar['p_venta'] : '0'; ?>">
                        </div>
                        <div>
                            <label>Stock Actual</label>
                            <input type="text" name="stock" class="num-format" value="<?php echo isset($producto_editar['stock']) ? $producto_editar['stock'] : '0'; ?>">
                        </div>
                    </div>

                    <div class="flex-row">
                        <div style="flex: 2;">
                            <label>Proveedor Principal</label>
                            <div style="display: flex; gap: 5px; align-items: stretch;">
                                <select name="proveedor" id="select_proveedor" style="flex: 1; margin-bottom: 0 !important;">
                                    <option value="">-- Seleccionar Proveedor --</option>
                                    <?php foreach ($proveedores_list as $prov): ?>
                                        <option value="<?php echo $prov['razon']; ?>" <?php echo (isset($producto_editar['proveedor']) && $producto_editar['proveedor'] == $prov['razon']) ? 'selected' : ''; ?>>
                                            <?php echo $prov['razon']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success" onclick="agregarNuevoProveedor()" title="Agregar nuevo proveedor" style="width: 45px; display: flex; align-items: center; justify-content: center; padding: 0; margin-bottom: 0;">+</button>
                            </div>
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

        // --- LÓGICA DE SELECCIÓN MÚLTIPLE ---
        const checkAll = document.getElementById('selectAll');
        if (checkAll) {
            checkAll.addEventListener('change', function() {
                const isChecked = this.checked;
                const rows = document.querySelectorAll('#tablaProductos tbody tr');
                rows.forEach(row => {
                    // Solo seleccionar los que están visibles (por si hay un filtro de búsqueda activo)
                    if (row.style.display !== 'none') {
                        const cb = row.querySelector('.prod-check');
                        if (cb) cb.checked = isChecked;
                    }
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

    window.abrirModalMasivo = function() { 
        const selected = Array.from(document.querySelectorAll('.prod-check:checked')).map(cb => cb.value);
        const idsInput = document.getElementById('seleccionados_ids');
        const infoDiv = document.getElementById('infoSeleccionMasiva');
        const filtrosDiv = document.getElementById('filtrosMasivosGrupo');

        if (selected.length > 0) {
            idsInput.value = selected.join(',');
            infoDiv.style.display = 'block';
            infoDiv.innerHTML = `<i class="fas fa-info-circle"></i> Se aplicará el cambio a los <b>${selected.length}</b> productos seleccionados manualmente. Los filtros de Rubro/Proveedor serán ignorados.`;
            filtrosDiv.style.opacity = '0.4';
            filtrosDiv.style.pointerEvents = 'none';
        } else {
            idsInput.value = '';
            infoDiv.style.display = 'none';
            filtrosDiv.style.opacity = '1';
            filtrosDiv.style.pointerEvents = 'auto';
        }

        document.getElementById('modalMasivo').style.display = 'block'; 
    };
    window.cerrarModalMasivo = function() { document.getElementById('modalMasivo').style.display = 'none'; };

    // Función para calcular precio de venta sugerido basado en la configuración global
    window.calcularPrecioVentaSugerido = function() {
        const gananciaRef = <?php echo $ganancia_config; ?>;
        const pCompraInput = document.getElementById('p_compra_input');
        const pVentaInput = document.getElementById('p_venta_input');
        
        // Normalizamos el valor (reemplazando coma por punto para el cálculo)
        let pCompraVal = pCompraInput.value.replace(',', '.');
        const pCompra = parseFloat(pCompraVal) || 0;
        
        if (pCompra > 0) {
            const multiplicador = 1 + (gananciaRef / 100);
            const sugerido = (pCompra * multiplicador).toFixed(2);
            // Mostramos el resultado con coma para mantener la estética del sistema
            pVentaInput.value = sugerido.replace('.', ',');
        }
    };

    // Funciones para el Modal de Nuevo Rubro
    window.agregarNuevoRubro = function() {
        document.getElementById('modalNuevoRubro').style.display = 'block';
        document.getElementById('input_nombre_rubro').focus();
    };

    window.cerrarModalRubro = function() {
        document.getElementById('modalNuevoRubro').style.display = 'none';
        document.getElementById('input_nombre_rubro').value = '';
    };

    window.confirmarNuevoRubro = function() {
        const input = document.getElementById('input_nombre_rubro');
        const nombre = input.value.trim();

        if (nombre === "") {
            mostrarMensaje("Validación", "Debe ingresar un nombre para el nuevo rubro.", "error");
            return;
        }

        const formData = new FormData();
        formData.append('nombre', nombre);

        fetch('../ajax/agregar_rubro_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('select_rubro');
                const option = document.createElement('option');
                option.value = data.nombre;
                option.text = data.nombre;
                option.selected = true;
                select.add(option);
                cerrarModalRubro();
            } else {
                mostrarMensaje("Error", data.error, "error");
            }
        })
        .catch(err => {
            console.error(err);
            mostrarMensaje("Error", "No se pudo procesar la solicitud.", "error");
        });
    };

    // Funciones para el Modal de Nuevo Proveedor Rápido
    window.agregarNuevoProveedor = function() {
        document.getElementById('modalNuevoProveedorRapido').style.display = 'block';
        document.getElementById('input_razon_prov').focus();
    };

    window.cerrarModalProveedorRapido = function() {
        document.getElementById('modalNuevoProveedorRapido').style.display = 'none';
    };

    window.confirmarNuevoProveedorRapido = function() {
        const cod = document.getElementById('input_cod_prov').value.trim();
        const razon = document.getElementById('input_razon_prov').value.trim();
        const cuit = document.getElementById('input_cuit_prov').value.trim();
        const tel = document.getElementById('input_tel_prov').value.trim();

        if (cod === "" || razon === "") {
            mostrarMensaje("Validación", "Código y Razón Social son obligatorios.", "error");
            return;
        }

        const formData = new FormData();
        formData.append('cod_prov', cod);
        formData.append('razon', razon);
        formData.append('cuit', cuit);
        formData.append('telefono', tel);

        fetch('../ajax/agregar_proveedor_rapido.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('select_proveedor');
                const option = document.createElement('option');
                option.value = data.nombre;
                option.text = data.nombre;
                option.selected = true;
                select.add(option);
                cerrarModalProveedorRapido();
            } else {
                mostrarMensaje("Error", data.error, "error");
            }
        })
        .catch(err => {
            console.error(err);
            mostrarMensaje("Error", "No se pudo procesar la solicitud.", "error");
        });
    };
    </script>

    <!-- Modal Personalizado para Nuevo Rubro -->
    <div id="modalNuevoRubro" class="modal">
        <div class="modal-content" style="max-width: 450px; border-top: 4px solid #00bcd4;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #00bcd4;"><i class="fas fa-tags"></i> Crear Nuevo Rubro</h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalRubro()">&times;</span>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="color: #eee; margin-bottom: 10px;">Nombre de la Categoría / Rubro:</label>
                <input type="text" id="input_nombre_rubro" class="input-field" placeholder="Ej: Herramientas, Iluminación..." style="margin-top: 5px;">
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="flex: 2; height: 45px; font-weight: bold;" onclick="confirmarNuevoRubro()">
                    💾 Guardar Rubro
                </button>
                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="cerrarModalRubro()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Personalizado para Nuevo Proveedor Rápido -->
    <div id="modalNuevoProveedorRapido" class="modal">
        <div class="modal-content" style="max-width: 450px; border-top: 4px solid #e67e22;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #e67e22;"><i class="fas fa-truck"></i> Registrar Proveedor</h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalProveedorRapido()">&times;</span>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Código Proveedor*</label>
                <input type="text" id="input_cod_prov" class="input-field" value="<?php echo $nuevo_cod_prov_sugerido; ?>">
                
                <label>Razón Social*</label>
                <input type="text" id="input_razon_prov" class="input-field" placeholder="Nombre de la empresa">
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label>CUIT</label>
                        <input type="text" id="input_cuit_prov" class="input-field" placeholder="00-00000000-0">
                    </div>
                    <div style="flex: 1;">
                        <label>Teléfono</label>
                        <input type="text" id="input_tel_prov" class="input-field">
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="flex: 2; height: 45px; font-weight: bold; background-color: #e67e22;" onclick="confirmarNuevoProveedorRapido()">
                    💾 Guardar Proveedor
                </button>
                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="cerrarModalProveedorRapido()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Aumento Masivo de Precios -->
    <div id="modalMasivo" class="modal">
        <div class="modal-content" style="max-width: 550px; border-top: 4px solid #6f42c1;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #a29bfe;"><i class="fas fa-bolt"></i> Actualización Masiva de Precios</h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalMasivo()">&times;</span>
            </div>
            
            <form method="POST" onsubmit="event.preventDefault(); const form=this; confirmarAccion('Aumento Masivo', '¿Estás seguro de aplicar este cambio de precios a todos los productos seleccionados?', 'APLICAR CAMBIOS', 'btn-primary', () => form.submit());">
                <input type="hidden" name="accion_post" value="aumento_masivo">
                <input type="hidden" name="seleccionados_ids" id="seleccionados_ids">
                
                <div id="infoSeleccionMasiva" class="alert alert-info" style="display:none; margin-bottom: 15px; border-color: #6f42c1; color: #a29bfe; font-size: 0.9em; background: rgba(111, 66, 193, 0.1);"></div>

                <div class="flex-row" id="filtrosMasivosGrupo">
                    <div>
                        <label>Filtrar por Rubro</label>
                        <select name="masivo_rubro">
                            <option value="">-- Todos los Rubros --</option>
                            <?php foreach ($rubros_list as $r): ?>
                                <option value="<?php echo $r['nombre']; ?>"><?php echo $r['nombre']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Filtrar por Proveedor</label>
                        <select name="masivo_proveedor">
                            <option value="">-- Todos los Proveedores --</option>
                            <?php foreach ($proveedores_list as $p): ?>
                                <option value="<?php echo $p['razon']; ?>"><?php echo $p['razon']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex-row">
                    <div>
                        <label>Operación</label>
                        <select name="masivo_operacion">
                            <option value="aumentar">➕ Aumentar Precio</option>
                            <option value="bajar">➖ Bajar Precio</option>
                        </select>
                    </div>
                    <div>
                        <label>Tipo</label>
                        <select name="masivo_tipo">
                            <option value="porcentaje">Porcentaje (%)</option>
                            <option value="fijo">Monto Fijo ($)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label>Valor (Importante: use punto para decimales)</label>
                    <input type="text" name="masivo_valor" class="input-field" placeholder="Ej: 15.5 o 500" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; background-color: #6f42c1; font-weight: bold; padding: 15px;">
                    APLICAR CAMBIOS MASIVOS
                </button>
            </form>
        </div>
    </div>
</body>
</html>