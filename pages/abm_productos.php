<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
//restringirPagina('developer', 'admin');
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
$producto_editar = array(); 

try {
    $proveedores_list = $pdo->query('SELECT razon FROM proveedores WHERE empresa_id = ' . (int)$empresa_id . ' ORDER BY razon ASC')->fetchAll(PDO::FETCH_ASSOC);
    $rubros_list = $pdo->query('SELECT nombre FROM rubros ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_cod_prov = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE empresa_id = :empresa_id ORDER BY (cod_prov + 0) DESC LIMIT 1");
    $stmt_cod_prov->execute([':empresa_id' => $empresa_id]);
    $ult_prov = $stmt_cod_prov->fetch();
    $nuevo_cod_prov_sugerido = $ult_prov ? (intval($ult_prov['cod_prov']) + 1) : 1;

    $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
    $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
} catch (Exception $e) {
    $mensaje = "⚠️ Error de configuración: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion_post = $_POST['accion_post'];

        if ($accion_post === 'aumento_masivo') {
            $filtro_rubro = $_POST['masivo_rubro'];
            $filtro_prov  = $_POST['masivo_proveedor'];
            $ids_seleccionados = $_POST['seleccionados_ids'] ?? '';
            $tipo_cambio  = $_POST['masivo_tipo']; // 'porcentaje' o 'fijo'
            $valor_cambio = (float)str_replace(',', '.', $_POST['masivo_valor']);
            $operacion    = $_POST['masivo_operacion']; // 'aumentar' o 'bajar'

            if ($valor_cambio <= 0) throw new Exception("El valor de cambio debe ser mayor a cero.");

            $sql_base = "UPDATE productos SET p_venta = ";
            
            if ($operacion === 'aumentar') {
                $sql_base .= ($tipo_cambio === 'porcentaje')
                    ? "p_venta * (1 + (:valor_cambio / 100.0))"
                    : "p_venta + :valor_cambio";
            } else {
                $sql_base .= ($tipo_cambio === 'porcentaje')
                    ? "p_venta * (1 - (:valor_cambio / 100.0))"
                    : "p_venta - :valor_cambio";
            }

            $where = [];
            $params = [':valor_cambio' => $valor_cambio, ':empresa_id' => $empresa_id];

            if (!empty($ids_seleccionados)) {
                $id_array = explode(',', $ids_seleccionados);
                $placeholders = [];
                foreach ($id_array as $idx => $id_val) {
                    $ph = ':id_' . $idx;
                    $placeholders[] = $ph;
                    $params[$ph] = (int)$id_val;
                }
                $where[] = 'id IN (' . implode(',', $placeholders) . ')';
            } else {
                if (!empty($filtro_rubro)) {
                    $where[] = "rubro = :filtro_rubro";
                    $params[':filtro_rubro'] = $filtro_rubro;
                }
                if (!empty($filtro_prov)) {
                    $where[] = "proveedor = :filtro_prov";
                    $params[':filtro_prov'] = $filtro_prov;
                }
            }

            $where[] = "empresa_id = :empresa_id";

            if (!empty($where)) {
                $sql_base .= " WHERE " . implode(" AND ", $where);
            }

            $stmt = $pdo->prepare($sql_base);
            $stmt->execute($params);
            $mensaje = "✅ Precios actualizados en " . $stmt->rowCount() . " productos.";
            $accion = 'listar';
        } else {
        $cod_prod = trim((string)($_POST['cod_prod'] ?? ''));
        // Normaliza cod_prod para evitar diferencias por espacios (ej: "AAA " vs "AAA")
        // No eliminar espacios internos: solo quitar espacios externos
        $cod_prod = trim($cod_prod);
        $descripcion = trim($_POST['descripcion']);
        
        $p_compra = (float)str_replace(',', '.', $_POST['p_compra']);
        $p_venta  = (float)str_replace(',', '.', $_POST['p_venta']);

        // Normalización robusta de stock (soporta 1.234,56 y 1,5 y 1234.56 y números redondos)
        $stock_raw = trim((string)($_POST['stock'] ?? '0'));
        $stock_raw = str_replace([' '], '', $stock_raw);
        // Si tiene coma decimal (ej: 1.234,56) => quitar puntos de miles y cambiar coma por punto
        if (strpos($stock_raw, ',') !== false) {
            $stock_raw = str_replace('.', '', $stock_raw);
            $stock_raw = str_replace(',', '.', $stock_raw);
        }
        // Si no tiene coma, el valor está en formato punto decimal (ej: 10.5) o es entero (ej: 10)
        // NO eliminar puntos porque serían el separador decimal, no de miles.
        $stock = (float)$stock_raw;

        
        $fecha_ult_compra = $_POST['fecha_ult_compra'];
        $rubro = $_POST['rubro'];
        $proveedor = $_POST['proveedor'];
        $id_post = isset($_POST['id_producto']) ? $_POST['id_producto'] : null;

        if (empty($cod_prod) || empty($descripcion)) throw new Exception("Código y descripción son obligatorios.");

        if ($accion_post === 'crear') {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE empresa_id = ? AND cod_prod = ?");
            $stmt_check->execute([$empresa_id, $cod_prod]);
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Ya existe un producto con ese código en esta empresa.");
            }
            
            // Insertar producto (stock se maneja en tabla stocks, pero la tabla productos requiere el campo)
            $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, fecha_ult_compra, rubro, proveedor, moneda, empresa_id, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $fecha_ult_compra, $rubro, $proveedor, $_POST['moneda'] ?? 'pesos', $empresa_id]);
            
            // Guardar stock en tabla stocks (por sucursal)
            $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)";
            $pdo->prepare($sql_stock)->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);
            
            $mensaje = "✅ Producto creado correctamente.";
            $accion = 'listar';
        } elseif ($accion_post === 'editar' && $id_post) {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE empresa_id = ? AND cod_prod = ? AND id != ?");
            $stmt_check->execute([$empresa_id, $cod_prod, $id_post]);
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Ya existe otro producto con ese código en esta empresa.");
            }
            
            // Actualizar producto SIN el campo stock (se maneja en tabla stocks)
            $sql = "UPDATE productos SET cod_prod=?, descripcion=?, p_compra=?, p_venta=?, fecha_ult_compra=?, rubro=?, proveedor=?, moneda=? WHERE id=? AND empresa_id=?";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $fecha_ult_compra, $rubro, $proveedor, $_POST['moneda'] ?? 'pesos', $id_post, $empresa_id]);
            
            // Guardar stock en tabla stocks (por sucursal)
            $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)";
            $pdo->prepare($sql_stock)->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);
            
            $mensaje = "✅ Producto actualizado correctamente.";
            $accion = 'listar';
        }
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

if ($accion === 'eliminar' && $id) {
    $pdo->prepare('DELETE FROM productos WHERE id = ? AND empresa_id = ?')->execute([$id, $empresa_id]);
    $mensaje = "🗑️ Producto eliminado.";
    $accion = 'listar';
}

if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare('SELECT p.*, COALESCE(s.stock_actual, 0) AS stock FROM productos p LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.empresa_id = :empresa_id_stock AND s.sucursal_id = :sucursal_id WHERE p.id = :id AND p.empresa_id = :empresa_id_producto');
    $stmt->execute([':empresa_id_stock' => $empresa_id, ':sucursal_id' => $sucursal_id, ':id' => $id, ':empresa_id_producto' => $empresa_id]);
    $producto_editar = $stmt->fetch();
}

$productos = ($accion === 'listar') ? $pdo->query("SELECT p.*, COALESCE(s.stock_actual, 0) AS stock FROM productos p LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.empresa_id = " . (int)$empresa_id . " AND s.sucursal_id = " . (int)$sucursal_id . " WHERE p.empresa_id = " . (int)$empresa_id . " ORDER BY p.id DESC")->fetchAll() : [];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Productos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css?v=' . time()); ?>">
<style>
     :root { --accent: #00bcd4; --success: #2ecc71; --warning: #f1c40f; --danger: #e74c3c; }
     
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
     
     /* Ocultar spinners de inputs number */
     #modalMultiples input[type=number]::-webkit-inner-spin-button,
     #modalMultiples input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
     #modalMultiples input[type=number] { -moz-appearance: textfield; }
     
     /* Estilos de tabla alineados con reporte_movimientos_productos.php */
     .card { background: #1e1e1e; border-radius: 12px; border: 1px solid #333; padding: 20px; }
     table { border-collapse: separate; border-spacing: 0 6px; width: 100%; }
     table thead th { color: var(--accent); text-transform: uppercase; font-size: 0.75em; letter-spacing: 1px; padding: 10px 8px; text-align: left; white-space: nowrap; font-weight: bold; }
     table tbody tr { background: #252525; transition: 0.3s; }
     table tbody tr:hover { background: #2a2a2a; }
     table tbody td { padding: 10px 8px; border-top: 1px solid #333; border-bottom: 1px solid #333; color: #ccc; font-size: 0.9em; }
     table tbody td:first-child { border-left: 1px solid #333; border-radius: 8px 0 0 8px; }
     table tbody td:last-child { border-right: 1px solid #333; border-radius: 0 8px 8px 0; }
     
     .text-right { text-align: right; }
     .text-bold { font-weight: bold; color: #fff; }
     .text-success { color: var(--success); }
     .text-danger { color: var(--danger); }
     .text-warning { color: var(--warning); }
     
     .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.75em; font-weight: bold; display: inline-block; }
     .badge-warning { background: rgba(241, 196, 15, 0.15); color: var(--warning); border: 1px solid var(--warning); }
 </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>📦 Gestión de Productos</h1>
            <?php if ($accion === 'listar'): ?>
<div style="display: flex; gap: 10px;">
                     <button type="button" class="btn" onclick="abrirModalMasivo()" style="background-color: #6f42c1; color: white;"><i class="fas fa-bolt"></i> Aumento Masivo</button>
                     <button type="button" class="btn" onclick="abrirModalMultiples()" style="background-color: #ff9800; color: white;"><i class="fas fa-layer-group"></i> Carga Múltiple</button>
                     <a href="<?php echo URL_BASE; ?>productos?accion=crear" class="btn btn-success">+ Nuevo Producto</a>
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
                                <th>Moneda</th>
                                <th class="text-right">Stock</th>
                                <th class="text-right">P. Venta</th>
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
                                <td><?php echo $p['moneda'] == 'dolar' ? 'U$S' : '$'; ?></td>
                                <td class="text-right"><?php echo number_format($p['stock'], 2, ',', '.'); ?></td>
                                <td class="text-right text-bold text-success"><?php echo $p['moneda'] == 'dolar' ? 'U$S' : '$'; ?><?php echo number_format($p['p_venta'], 2, ',', '.'); ?></td>
                                <td>
                                    <a href="<?php echo URL_BASE; ?>productos?accion=editar&id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                    <a href="<?php echo URL_BASE; ?>productos?accion=eliminar&id=<?php echo $p['id']; ?>" 
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
                            <label>Moneda</label>
                            <select name="moneda">
                                <option value="pesos" <?php echo (!isset($producto_editar['moneda']) || $producto_editar['moneda'] == 'pesos') ? 'selected' : ''; ?>>Pesos ($)</option>
                                <option value="dolar" <?php echo (isset($producto_editar['moneda']) && $producto_editar['moneda'] == 'dolar') ? 'selected' : ''; ?>>Dólar (U$S)</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label>Fecha Ult. Compra</label>
                            <input type="date" name="fecha_ult_compra" value="<?php echo isset($producto_editar['fecha_ult_compra']) ? $producto_editar['fecha_ult_compra'] : date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;">💾 Guardar Cambios</button>
                        <a href="<?php echo URL_BASE; ?>productos" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
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

        fetch('<?php echo URL_BASE; ?>ajax/agregar_rubro_ajax.php', {
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

        fetch('<?php echo URL_BASE; ?>ajax/agregar_proveedor_rapido.php', {
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

     <!-- Modal Carga Múltiple de Productos (nuevo diseño) -->
     <div id="modalMultiples" class="modal" style="display: none;">
         <div class="modal-content" style="max-width: 920px; border-top: 4px solid #ff9800;">
             <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                 <h3 style="margin: 0; color: #ff9800;"><i class="fas fa-layer-group"></i> Carga Múltiple de Productos</h3>
                 <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalMultiples()">&times;</span>
             </div>

             <form id="formMultiples" method="POST" action="<?php echo URL_BASE; ?>productos">
                 <input type="hidden" name="accion_post" value="carga_multiple">

                 <!-- Fila 1 -->
                 <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                     <div style="flex: 1;">
                         <label>Proveedor</label>
                         <select name="prov_multiple" id="prov_multiple" required>
                             <option value="">-- Seleccionar Proveedor --</option>
                             <?php foreach ($proveedores_list as $prov): ?>
                                 <option value="<?php echo $prov['razon']; ?>"><?php echo $prov['razon']; ?></option>
                             <?php endforeach; ?>
                         </select>
                     </div>

                     <div style="flex: 1;">
                         <label>Rubro / Categoría</label>
                         <select name="rubro_multiple" id="rubro_multiple" required>
                             <option value="">-- Seleccionar Rubro --</option>
                             <?php foreach ($rubros_list as $r): ?>
                                 <option value="<?php echo $r['nombre']; ?>"><?php echo $r['nombre']; ?></option>
                             <?php endforeach; ?>
                         </select>
                     </div>

                     <div style="flex: 1;">
                         <label>Moneda</label>
                         <select name="moneda_multiple" id="moneda_multiple" required>
                             <option value="pesos">Pesos ($)</option>
                             <option value="dolar">Dólar (U$S)</option>
                         </select>
                     </div>
                 </div>

                 <!-- Fila 2: porcentaje al lado derecho de la misma columna de moneda -->
                 <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                     <div style="flex: 1;"></div>
                     <div style="flex: 1;"></div>
                     <div style="flex: 1; margin-left: auto;">
                         <label>Porcentaje (%)</label>
                         <input type="number" step="0.01" name="porcentaje_multiple" id="porcentaje_multiple" value="60" style="width: 100%; padding: 6px;">
                         <small style="color: #888;">Se aplicará a compra para calcular venta</small>
                     </div>
                 </div>

                 <!-- Acrílla dinámica -->
                 <div style="margin-bottom: 15px;">
                     <label>Productos</label>
                     <div style="background: #222; border: 1px solid #444; border-radius: 4px; padding: 10px;">
                         <table id="tablaMultiples" style="width: 100%; border-collapse: collapse;">
                             <thead>
                                 <tr style="border-bottom: 1px solid #444;">
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 160px;">Código</th>
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 420px;">Descripción</th>
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 180px;">Compra</th>
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 180px;">Venta</th>
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 160px;">Stock</th>
                                     <th style="padding: 8px; color: #aaa; font-size: 0.85em; width: 68px;">Acciones</th>
                                 </tr>
                             </thead>
                             <tbody id="cuerpoMultiples">
                                 <tr>
                                     <td style="padding: 5px; width: 160px;"><input type="text" class="prod-cod" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-descrip"></td>
                                     <td style="padding: 5px; width: 420px;"><input type="text" class="prod-descrip" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-compra"></td>
                                     <td style="padding: 5px; width: 180px;"><input type="number" class="prod-compra" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" onchange="calcularVenta(this)" data-enter-next="prod-venta"></td>
                                     <td style="padding: 5px; width: 180px;"><input type="number" class="prod-venta" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-stock"></td>
                                     <td style="padding: 5px; width: 160px;"><input type="number" class="prod-stock" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" value="0" data-enter-next="__nueva_fila__"></td>
                                     <td style="padding: 5px; text-align: center; width: 68px;"><button type="button" class="btn btn-success btn-sm" onclick="agregarFila()" title="Agregar fila"><i class="fas fa-plus"></i></button></td>
                                 </tr>
                             </tbody>
                         </table>
                     </div>
                 </div>

                 <div style="margin-top: 20px; text-align: right;">
                     <button type="button" class="btn btn-success" onclick="guardarMultiples(); return false;"><i class="fas fa-save"></i> Guardar Productos</button>
                     <button type="button" class="btn btn-secondary" onclick="cerrarModalMultiples(); return false;">Cancelar</button>
                 </div>
             </form>
         </div>
     </div>


     <script>
     document.addEventListener('keydown', function(event) {
          if (event.key !== 'Enter') return;
          const target = event.target;
          if (!(target instanceof HTMLInputElement)) return;
          const modal = document.getElementById('modalMultiples');
          if (!modal || modal.style.display === 'none') return;
          if (!target.closest('#cuerpoMultiples')) return;

          // Solo mover si el input trae data-enter-next
          const next = target.getAttribute('data-enter-next');
          if (!next) return;
          event.preventDefault();

              if (next === '__nueva_fila__') {
              // Evitar doble-carga: el form/inputs a veces disparan más de un Enter.
              if (window.__enter_creando_fila) return;
              window.__enter_creando_fila = true;

              agregarFila();

              // Llevar el foco al campo Código de la nueva fila
              const filas = document.querySelectorAll('#cuerpoMultiples tr');
              const ultima = filas[filas.length - 1];
              const codigoNueva = ultima ? ultima.querySelector('input.prod-cod') : null;
              if (codigoNueva) codigoNueva.focus();

              // Reset del flag en el próximo tick
              setTimeout(() => { window.__enter_creando_fila = false; }, 0);
              return;
          }



          const fila = target.closest('tr');
          const siguiente = fila ? fila.querySelector('input.' + next) : null;
          if (siguiente) siguiente.focus();
      });

     function abrirModalMultiples() {
         document.getElementById('modalMultiples').style.display = 'block';
     }
     
function cerrarModalMultiples() {
          document.getElementById('modalMultiples').style.display = 'none';
          document.getElementById('formMultiples').reset();
          document.getElementById('cuerpoMultiples').innerHTML = '<tr>' + 
              '<td style="padding: 5px; width: 160px;"><input type="text" class="prod-cod" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;"></td>' +
              '<td style="padding: 5px; width: 420px;"><input type="text" class="prod-descrip" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;"></td>' +
              '<td style="padding: 5px; width: 180px;"><input type="number" class="prod-compra" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" onchange="calcularVenta(this)"></td>' +
              '<td style="padding: 5px; width: 180px;"><input type="number" class="prod-venta" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;"></td>' +
              '<td style="padding: 5px; width: 160px;"><input type="number" class="prod-stock" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" value="0"></td>' +
              '<td style="padding: 5px; text-align: center; width: 68px;"><button type="button" class="btn btn-success btn-sm" onclick="agregarFila()" title="Agregar fila"><i class="fas fa-plus"></i></button></td>' +
          '</tr>'; 
      }
      
      function agregarFila() {
          const tbody = document.getElementById('cuerpoMultiples');
          // Evitar doble creación si por cualquier motivo se dispara dos veces.
          if (window.__agregarFila_en_curso) return;
          window.__agregarFila_en_curso = true;
          setTimeout(() => { window.__agregarFila_en_curso = false; }, 0);

          const tr = document.createElement('tr');


          tr.innerHTML = '<td style="padding: 5px; width: 160px;"><input type="text" class="prod-cod" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-descrip"></td>' +
              '<td style="padding: 5px; width: 420px;"><input type="text" class="prod-descrip" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-compra"></td>' +
              '<td style="padding: 5px; width: 180px;"><input type="number" class="prod-compra" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" onchange="calcularVenta(this)" data-enter-next="prod-venta"></td>' +
              '<td style="padding: 5px; width: 180px;"><input type="number" class="prod-venta" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" data-enter-next="prod-stock"></td>' +
              '<td style="padding: 5px; width: 160px;"><input type="number" class="prod-stock" style="width: 100%; padding: 6px; background: #1a1a1a; color: #fff; border: 1px solid #444;" value="0" data-enter-next="__nueva_fila__"></td>' +
              '<td style="padding: 5px; text-align: center; width: 68px; white-space: nowrap;">'
                + '<div style="display:flex; gap:6px; justify-content:center; align-items:center;">'
                + '<button type="button" class="btn btn-success btn-sm" onclick="agregarFila()" title="Agregar fila"><i class="fas fa-plus"></i></button>'
                + '<button type="button" class="btn btn-danger btn-sm" onclick="eliminarFila(this)" title="Eliminar fila"><i class="fas fa-times"></i></button>'
                + '</div>'
              + '</td>';

          // Set listeners de Enter (se usa la clase .prod-*)
          tr.addEventListener('keydown', function(e) {
              if (e.key !== 'Enter') return;
              e.preventDefault();

              const el = e.target;
              if (!(el instanceof HTMLInputElement)) return;

              const next = el.getAttribute('data-enter-next');
              const fila = el.closest('tr');
              if (!fila || !next) return;

              if (next === '__nueva_fila__') {
                  agregarFila();
                  return;
              }

              // Buscar el siguiente input por clase (prod-descrip, prod-compra, etc.)
              const siguiente = fila.querySelector('input.' + next);
              if (siguiente) {
                  siguiente.focus();
              }
          });



          tbody.appendChild(tr);
      }
      
      function calcularVenta(input) {
          const fila = input.closest('tr');
          const compra = parseFloat(fila.querySelector('.prod-compra').value) || 0;
          const porcentaje = parseFloat(document.getElementById('porcentaje_multiple').value) || 0;
          const venta = compra * (1 + porcentaje / 100);

          fila.querySelector('.prod-venta').value = venta.toFixed(2);
      }
      
     function eliminarFila(btn) {
         btn.closest('tr').remove();
     }

     
     function guardarMultiples() {
         const proveedor = document.getElementById('prov_multiple').value;
         const rubro = document.getElementById('rubro_multiple').value;
         const moneda = document.getElementById('moneda_multiple').value;

         if (!proveedor || !rubro) {
             alert('Complete proveedor y rubro');
             return;
         }

         const filas = document.querySelectorAll('#cuerpoMultiples tr');
         const productos = [];

         filas.forEach(fila => {
             const cod = fila.querySelector('.prod-cod').value.trim();
             const desc = fila.querySelector('.prod-descrip').value.trim();
             const compra = fila.querySelector('.prod-compra').value;
             const venta = fila.querySelector('.prod-venta').value;
             const stock = fila.querySelector('.prod-stock').value || '0';

             if (cod && desc && venta) {
                 productos.push({cod, desc, compra, venta, stock});
             }
         });

         if (productos.length === 0) {
             alert('Ingrese al menos un producto válido');
             return;
         }

         fetch('<?php echo URL_BASE; ?>ajax/cargar_multiples_productos.php', {
             method: 'POST',
             headers: {'Content-Type': 'application/json'},
             body: JSON.stringify({proveedor, rubro, moneda, productos})
         })
         .then(res => res.json())
         .then(data => {
             if (data.success) {
                 alert('✅ ' + data.message);
                 cerrarModalMultiples();
                 location.reload();
             } else {
                 alert('❌ ' + data.error);
             }
         })
         .catch(err => {
             console.error(err);
             alert('❌ Error al guardar productos');
         });
     }
     </script>
</body>
</html>