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
    $stmt_prov = $pdo->prepare("SELECT razon FROM proveedores WHERE empresa_id = ? ORDER BY razon ASC");
    $stmt_prov->execute([$empresa_id]);
    $proveedores_list = $stmt_prov->fetchAll(PDO::FETCH_ASSOC);
    $rubros_list = $pdo->query("SELECT nombre FROM rubros ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_cod_prov = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE empresa_id = :empresa_id ORDER BY (cod_prov + 0) DESC LIMIT 1");
    $stmt_cod_prov->execute([':empresa_id' => $empresa_id]);
    $ult_prov = $stmt_cod_prov->fetch();
    $nuevo_cod_prov_sugerido = $ult_prov ? (intval($ult_prov['cod_prov']) + 1) : 1;

    $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
    $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
    
    // Proveedores para filtro PDF
    $proveedores_pdf_list = $pdo->prepare("SELECT DISTINCT TRIM(proveedor) as proveedor FROM productos WHERE empresa_id = :empresa_id AND proveedor IS NOT NULL AND TRIM(proveedor) != '' ORDER BY proveedor ASC");
    $proveedores_pdf_list->execute([':empresa_id' => $empresa_id]);
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
        $es_consignacion = isset($_POST['es_consignacion']) ? 1 : 0;
        $comision_proveedor = null;
        if ($es_consignacion && isset($_POST['comision_proveedor']) && $_POST['comision_proveedor'] !== '') {
            $comision_proveedor = (float)str_replace(',', '.', $_POST['comision_proveedor']);
            if ($comision_proveedor <= 0 || $comision_proveedor >= 100) {
                throw new Exception("La comisión del proveedor debe estar entre 1 y 99 (porcentaje).");
            }
        }

        if (empty($cod_prod) || empty($descripcion)) throw new Exception("Código y descripción son obligatorios.");

        if ($accion_post === 'crear') {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE empresa_id = ? AND cod_prod = ?");
            $stmt_check->execute([$empresa_id, $cod_prod]);
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Ya existe un producto con ese código en esta empresa.");
            }
            
            // Insertar producto (stock se maneja en tabla stocks, pero la tabla productos requiere el campo)
            $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, fecha_ult_compra, rubro, proveedor, moneda, empresa_id, stock, unidad_medida, es_consignacion, comision_proveedor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $fecha_ult_compra, $rubro, $proveedor, $_POST['moneda'] ?? 'pesos', $empresa_id, $_POST['unidad_medida'] ?? 'Unidad', $es_consignacion, $comision_proveedor]);
            
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
            $sql = "UPDATE productos SET cod_prod=?, descripcion=?, p_compra=?, p_venta=?, fecha_ult_compra=?, rubro=?, proveedor=?, moneda=?, unidad_medida=?, es_consignacion=?, comision_proveedor=? WHERE id=? AND empresa_id=?";
            $pdo->prepare($sql)->execute([$cod_prod, $descripcion, $p_compra, $p_venta, $fecha_ult_compra, $rubro, $proveedor, $_POST['moneda'] ?? 'pesos', $_POST['unidad_medida'] ?? 'Unidad', $es_consignacion, $comision_proveedor, $id_post, $empresa_id]);
            
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

// --- Paginación y Búsqueda de Productos ---
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$registros_por_pagina = (int)($_GET['registros'] ?? 20);
$busqueda = trim((string)($_GET['q'] ?? ''));

$opciones_registros = [10, 20, 50, 100];
if (!in_array($registros_por_pagina, $opciones_registros)) {
    $registros_por_pagina = 20;
}

// Cláusula WHERE base + condición de búsqueda (server-side)
$where_consulta = "p.empresa_id = " . (int)$empresa_id;
$params_consulta = [];
if ($busqueda !== '') {
    $where_consulta .= " AND (p.cod_prod LIKE :q1 OR p.descripcion LIKE :q2 OR p.rubro LIKE :q3 OR p.proveedor LIKE :q4)";
    $params_consulta[':q1'] = '%' . $busqueda . '%';
    $params_consulta[':q2'] = '%' . $busqueda . '%';
    $params_consulta[':q3'] = '%' . $busqueda . '%';
    $params_consulta[':q4'] = '%' . $busqueda . '%';
}

// Contar total de productos (para cálculo de páginas)
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM productos p WHERE " . $where_consulta);
$stmt_count->execute($params_consulta);
$total_productos = (int)$stmt_count->fetchColumn();

$total_paginas = (int)ceil($total_productos / $registros_por_pagina);
$pagina_actual = min($pagina, max(1, $total_paginas));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Query principal con paginación (LIMIT / OFFSET)
$sql_listado = "SELECT p.*, COALESCE(s.stock_actual, 0) AS stock FROM productos p LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.empresa_id = " . (int)$empresa_id . " AND s.sucursal_id = " . (int)$sucursal_id . " WHERE " . $where_consulta . " ORDER BY p.id DESC LIMIT " . (int)$registros_por_pagina . " OFFSET " . (int)$offset;

$productos = [];
if ($accion === 'listar') {
    $stmt_listado = $pdo->prepare($sql_listado);
    $stmt_listado->execute($params_consulta);
    $productos = $stmt_listado->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Productos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css?v=' . (file_exists(__DIR__ . '/../css/style.css') ? filemtime(__DIR__ . '/../css/style.css') : '1')); ?>">
<link rel="stylesheet" href="<?php echo url('css/pages/abm_productos.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content content-topbar">
        <?php include 'topbar.php'; ?>
        <div class="page-header">
            <h1>📦 Gestión de Productos</h1>
<?php if ($accion === 'listar'): ?>
            <div class="page-actions">
                      <button type="button" class="btn btn-violet" onclick="abrirModalMasivo()"><i class="fas fa-bolt"></i> Aumento Masivo</button>
                      <button type="button" class="btn btn-amber" onclick="abrirModalMultiples()"><i class="fas fa-layer-group"></i> Carga Múltiple</button>
                      <button type="button" class="btn btn-cyan" onclick="abrirModalPdfPrecios()"><i class="fas fa-file-pdf"></i> Listado PDF</button>
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
                <!-- Barra de búsqueda y controles -->
                <div class="pagination-bar">
                    <div class="search-box">
                        <input type="text" id="filtroProductos" class="form-control" placeholder="Buscar por código, descripción, rubro o proveedor..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="button" onclick="buscarProductos()" class="btn btn-primary btn-wide">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <?php if ($busqueda !== ''): ?>
                            <a href="<?php echo URL_BASE; ?>productos" class="btn btn-secondary btn-compact">
                                <i class="fas fa-times"></i> Limpiar
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="per-page-selector">
                        <select id="registrosPorPagina" class="form-control" onchange="cambiarRegistros()">
                            <?php foreach ($opciones_registros as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $registros_por_pagina === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> / página</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="tabsPosesion" class="d-flex gap-8 mb-10">
                    <button type="button" class="btn btn-sm tab-pos tab-pos-activa" data-tab="todos" onclick="filtrarPosesion(this)">Todos</button>
                    <button type="button" class="btn btn-sm tab-pos tab-pos-ok" data-tab="propios" onclick="filtrarPosesion(this)">Propios (<span id="cantPropios">0</span>)</button>
                    <button type="button" class="btn btn-sm tab-pos tab-pos-warn" data-tab="consignacion" onclick="filtrarPosesion(this)">🤝 Consignación (<span id="cantConsignacion">0</span>)</button>
                </div>
                
                <div class="table-container">
                    <table id="tablaProductos">
                        <thead>
                            <tr>
                                <th class="w-30"><input type="checkbox" id="selectAll" title="Seleccionar todos los visibles"></th>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th>Rubro</th>
                                <th>Moneda</th>
                                <th>Unidad</th>
                                <th id="thPosesion">Posesión</th>
                                <th class="text-right">Stock</th>
                                <th class="text-right">P. Venta</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $p): ?>
                            <tr class="fila-prod" data-pos="<?php echo !empty($p['es_consignacion']) ? 'consignacion' : 'propio'; ?>">
                                <td><input type="checkbox" class="prod-check" value="<?php echo $p['id']; ?>"></td>
                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($p['cod_prod']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($p['descripcion']); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['rubro']); ?></td>
                                <td><?php echo $p['moneda'] == 'dolar' ? 'U$S' : '$'; ?></td>
                                <td><?php echo htmlspecialchars($p['unidad_medida'] ?? 'Unidad'); ?></td>
                                <td>
                                    <?php if (!empty($p['es_consignacion'])): ?>
                                        <span class="tag-warn">🤝 Consignación<?php echo $p['comision_proveedor'] !== null ? ' ' . number_format($p['comision_proveedor'], 0) . '%' : ''; ?></span>
                                    <?php else: ?>
                                        <span class="tag-ok">Propio</span>
                                    <?php endif; ?>
                                </td>
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

                <!-- Mensaje cuando no hay resultados -->
                <?php if (empty($productos)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open empty-icon"></i>
                    <p>No se encontraron productos<?php echo $busqueda !== '' ? ' para: <strong>' . htmlspecialchars($busqueda) . '</strong>' : ''; ?>.</p>
                </div>
                <?php endif; ?>

                <!-- Resumen y Controles de Paginación -->
                <div class="pagination-footer">
                    <div class="pagination-summary">
                        <?php
                        $inicio = ($total_productos > 0) ? ($offset + 1) : 0;
                        $fin = min($offset + $registros_por_pagina, $total_productos);
                        echo "Mostrando <strong>$inicio</strong> - <strong>$fin</strong> de <strong>$total_productos</strong> producto" . ($total_productos != 1 ? 's' : '');
                        ?>
                    </div>
                    <?php if ($total_paginas > 1): ?>
                    <div class="pagination-nav">
                        <?php
                        // Helper: construir URL preservando q y registros
                        $buildPageUrl = function($p) use ($busqueda, $registros_por_pagina) {
                            $params = [];
                            if ($busqueda !== '') $params['q'] = $busqueda;
                            if ($registros_por_pagina != 20) $params['registros'] = $registros_por_pagina;
                            if ($p > 1) $params['pagina'] = $p;
                            return URL_BASE . 'productos?' . http_build_query($params);
                        };

                        $rango = 2;
                        $inicio_rango = max(1, $pagina_actual - $rango);
                        $fin_rango = min($total_paginas, $pagina_actual + $rango);
                        ?>
                        <a href="<?php echo $buildPageUrl(1); ?>" class="btn btn-sm btn-secondary<?php echo $pagina_actual <= 1 ? ' is-disabled' : ''; ?>">« Primero</a>
                        <a href="<?php echo $buildPageUrl(max(1, $pagina_actual - 1)); ?>" class="btn btn-sm btn-secondary<?php echo $pagina_actual <= 1 ? ' is-disabled' : ''; ?>">‹ Ant.</a>

                        <?php for ($i = $inicio_rango; $i <= $fin_rango; $i++): ?>
                            <a href="<?php echo $buildPageUrl($i); ?>" class="btn btn-sm <?php echo $i == $pagina_actual ? 'btn-info' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <a href="<?php echo $buildPageUrl(min($total_paginas, $pagina_actual + 1)); ?>" class="btn btn-sm btn-secondary<?php echo $pagina_actual >= $total_paginas ? ' is-disabled' : ''; ?>">Sig. ›</a>
                        <a href="<?php echo $buildPageUrl($total_paginas); ?>" class="btn btn-sm btn-secondary<?php echo $pagina_actual >= $total_paginas ? ' is-disabled' : ''; ?>">Último »</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card card-center">
                <h2><?php echo ($accion === 'crear') ? 'Nuevo Registro' : 'Editando: ' . htmlspecialchars($producto_editar['descripcion']); ?></h2>
                <hr class="hr-line">
                
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
                            <div class="select-row">
                                <select name="rubro" id="select_rubro" class="select-grow">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($rubros_list as $r): ?>
                                        <option value="<?php echo $r['nombre']; ?>" <?php echo (isset($producto_editar['rubro']) && $producto_editar['rubro'] == $r['nombre']) ? 'selected' : ''; ?>>
                                            <?php echo $r['nombre']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success btn-plus" onclick="abrirModalRubro()" title="Agregar nuevo rubro">+</button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-15">
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
                        <div class="flex-2">
                            <label>Proveedor Principal</label>
                            <div class="select-row">
                                <select name="proveedor" id="select_proveedor" class="select-grow">
                                    <option value="">-- Seleccionar Proveedor --</option>
                                    <?php foreach ($proveedores_list as $prov): ?>
                                        <option value="<?php echo $prov['razon']; ?>" <?php echo (isset($producto_editar['proveedor']) && $producto_editar['proveedor'] == $prov['razon']) ? 'selected' : ''; ?>>
                                            <?php echo $prov['razon']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success btn-plus" onclick="agregarNuevoProveedor()" title="Agregar nuevo proveedor">+</button>
                            </div>
                        </div>
                        <div class="flex-1">
                            <label>Unidad de Medida</label>
                            <select name="unidad_medida">
                                <?php
                                $unidades = ['Unidad', 'Kilogramo', 'Metro', 'Litro'];
                                $unidad_actual = $producto_editar['unidad_medida'] ?? 'Unidad';
                                foreach ($unidades as $u): ?>
                                    <option value="<?php echo $u; ?>" <?php echo ($unidad_actual == $u) ? 'selected' : ''; ?>><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label>Moneda</label>
                            <select name="moneda">
                                <option value="pesos" <?php echo (!isset($producto_editar['moneda']) || $producto_editar['moneda'] == 'pesos') ? 'selected' : ''; ?>>Pesos ($)</option>
                                <option value="dolar" <?php echo (isset($producto_editar['moneda']) && $producto_editar['moneda'] == 'dolar') ? 'selected' : ''; ?>>Dólar (U$S)</option>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label>Fecha Ult. Compra</label>
                            <input type="date" name="fecha_ult_compra" value="<?php echo isset($producto_editar['fecha_ult_compra']) ? $producto_editar['fecha_ult_compra'] : date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="flex-row flex-row-end">
                        <div class="flex-1">
                            <label>Posesión de la Mercadería</label>
                            <label class="chk-label">
                                <input type="checkbox" name="es_consignacion" id="chk_consignacion" value="1" class="chk-box"
                                    <?php echo (!empty($producto_editar['es_consignacion'])) ? 'checked' : ''; ?>>
                                <span id="chk_consignacion_txt">🤝 En Consignación (es del proveedor)</span>
                            </label>
                            <small class="hint">Los productos en consignación no son de tu propiedad: se liquidan al proveedor según se venden.</small>
                        </div>
                        <div class="flex-1" id="row_comision_proveedor">
                            <label>Comisión del Proveedor (%)</label>
                            <input type="number" name="comision_proveedor" id="input_comision_proveedor" min="1" max="99" step="0.01"
                                   value="<?php echo isset($producto_editar['comision_proveedor']) && $producto_editar['comision_proveedor'] !== null ? $producto_editar['comision_proveedor'] : '50'; ?>"
                                   placeholder="50 = reparto 50/50">
                            <small class="hint">% de la ganancia que se lleva el proveedor. Ej: 50 = 50/50.</small>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary flex-2">💾 Guardar Cambios</button>
                        <a href="<?php echo URL_BASE; ?>productos" class="btn btn-secondary flex-1 text-center">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- BÚSQUEDA SERVER-SIDE ---
        // buscarProductos() y cambiarRegistros() están en window.* para usarlas
        // desde atributos onclick/onchange del HTML.

        window.buscarProductos = function() {
            const input = document.getElementById('filtroProductos');
            const params = new URLSearchParams(window.location.search);
            const q = (input ? input.value.trim() : '');
            const qActual = (params.get('q') || '').trim();
            const paginaActual = params.get('pagina');
            // Si la búsqueda no cambió y no hay página que resetear,
            // no recargar (evita una recarga redundante que roba el foco)
            if (q === qActual && !paginaActual) {
                return;
            }
            if (q) {
                params.set('q', q);
            } else {
                params.delete('q');
            }
            params.delete('pagina'); // Siempre volver a página 1 al buscar
            window.location.search = params.toString();
        };

        window.cambiarRegistros = function() {
            const sel = document.getElementById('registrosPorPagina');
            if (!sel) return;
            const params = new URLSearchParams(window.location.search);
            params.set('registros', sel.value);
            params.delete('pagina'); // Volver a página 1 al cambiar registros
            window.location.search = params.toString();
        };

        const inputFiltro = document.getElementById('filtroProductos');
        if (inputFiltro) {
            const qEnUrl = () => ((new URLSearchParams(window.location.search).get('q')) || '').trim();
            // Auto-búsqueda con debounce (500 ms) para mejor UX
            let timeoutBusqueda = null;
            inputFiltro.addEventListener('input', function() {
                clearTimeout(timeoutBusqueda);
                // Solo buscar si el texto cambió respecto a la búsqueda actual
                if (inputFiltro.value.trim() === qEnUrl()) {
                    return;
                }
                timeoutBusqueda = setTimeout(window.buscarProductos, 500);
            });
            // Búsqueda al presionar Enter
            inputFiltro.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timeoutBusqueda);
                    window.buscarProductos();
                }
            });

            // Restaurar el foco del filtro después de la recarga de la
            // búsqueda (la paginación/búsqueda son navegación server-side)
            if (qEnUrl() !== '') {
                inputFiltro.focus();
                const largo = inputFiltro.value.length;
                try { inputFiltro.setSelectionRange(largo, largo); } catch (e) { /* no-op */ }
            }
        }

        // --- FILTRO POR POSESIÓN (Propio / Consignación) ---
        let tabPosesionActiva = 'todos';
        function contarPosesion() {
            const filas = document.querySelectorAll('#tablaProductos tbody tr.fila-prod');
            let propios = 0, consignados = 0;
            filas.forEach(f => {
                if (f.dataset.pos === 'consignacion') consignados++; else propios++;
            });
            const cP = document.getElementById('cantPropios');
            const cC = document.getElementById('cantConsignacion');
            if (cP) cP.innerText = propios;
            if (cC) cC.innerText = consignados;
        }
        function aplicarTabPosesion() {
            // La búsqueda es ahora server-side; aquí solo filtramos por posesión
            document.querySelectorAll('#tablaProductos tbody tr.fila-prod').forEach(fila => {
                const pasaTab = (tabPosesionActiva === 'todos') || (fila.dataset.pos === (tabPosesionActiva === 'propios' ? 'propio' : 'consignacion'));
                fila.style.display = pasaTab ? "" : "none";
            });
        }
        window.filtrarPosesion = function(btn) {
            tabPosesionActiva = btn.dataset.tab;
            document.querySelectorAll('.tab-pos').forEach(b => b.classList.remove('tab-pos-activa'));
            btn.classList.add('tab-pos-activa');
            aplicarTabPosesion();
        };
        contarPosesion();

        // --- TOGGLE COMISIÓN PROVEEDOR (form alta/edición) ---
        const chkConsigna = document.getElementById('chk_consignacion');
        const rowComision = document.getElementById('row_comision_proveedor');
        function toggleComision() {
            if (chkConsigna && rowComision) {
                rowComision.style.display = chkConsigna.checked ? '' : 'none';
                const txt = document.getElementById('chk_consignacion_txt');
                if (txt) txt.textContent = chkConsigna.checked ? '🤝 En Consignación (es del proveedor)' : 'En Consignación (es del proveedor)';
            }
        }
        if (chkConsigna) {
            chkConsigna.addEventListener('change', toggleComision);
            toggleComision();
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

        // --- FUNCIÓN OCULTA: CONSIGNACIÓN MASIVA ---
        // Disparadores sin botón visible: Ctrl+Alt+C o doble clic en el encabezado "Posesión"
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.altKey && !e.shiftKey && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                abrirConsignacionMasiva();
            }
        });
        const thPosesion = document.getElementById('thPosesion');
        if (thPosesion) {
            thPosesion.addEventListener('dblclick', function() {
                abrirConsignacionMasiva();
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

    // --- FUNCIONES OCULTAS: CONSIGNACIÓN MASIVA (ver atajo en DOMContentLoaded) ---
    window.abrirConsignacionMasiva = function() {
        const seleccionados = document.querySelectorAll('.prod-check:checked');
        if (seleccionados.length === 0) {
            mostrarMensaje('Sin selección', 'Primero marcá los productos con los checkboxes de la tabla.', 'error');
            return;
        }
        let yaMarcados = 0;
        seleccionados.forEach(cb => {
            const fila = cb.closest('tr');
            if (fila && fila.dataset.pos === 'consignacion') yaMarcados++;
        });
        const info = document.getElementById('infoConsignaMasiva');
        info.innerHTML = `Seleccionados: <b>${seleccionados.length}</b> &middot; Ya en consignación: <b>${yaMarcados}</b> &middot; Propios: <b>${seleccionados.length - yaMarcados}</b>`;
        document.getElementById('modalConsignacionMasiva').style.display = 'block';
    };

    window.cerrarModalConsignacionMasiva = function() {
        document.getElementById('modalConsignacionMasiva').style.display = 'none';
    };

    window.aplicarConsignacionMasiva = function(accion) {
        const ids = Array.from(document.querySelectorAll('.prod-check:checked')).map(cb => parseInt(cb.value));
        if (ids.length === 0) return;

        let comision = null;
        if (accion === 'marcar') {
            const comRaw = document.getElementById('com_consigna_masiva').value;
            if (comRaw !== '') comision = parseFloat(String(comRaw).replace(',', '.'));
            if (comision === null || isNaN(comision) || comision <= 0 || comision >= 100) {
                mostrarMensaje('Datos Incompletos', 'La comisión del proveedor debe estar entre 1 y 99 (%).', 'error');
                return;
            }
        }

        const verbo = (accion === 'marcar')
            ? 'MARCAR como CONSIGNACIÓN (es del proveedor)'
            : 'DESMARCAR (pasar a PROPIO de tu inventario)';
        const form = () => {
            fetch('<?php echo URL_BASE; ?>ajax/consignacion_masiva.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ ids: ids, accion: accion, comision: comision })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    cerrarModalConsignacionMasiva();
                    mostrarMensaje('Éxito', '✅ ' + data.mensaje, 'success', function() { location.reload(); });
                } else {
                    mostrarMensaje('Error', '❌ ' + (data.error || 'Error desconocido'), 'error');
                }
            })
            .catch(err => {
                mostrarMensaje('Error', '❌ Error de conexión: ' + err.message, 'error');
            });
        };
        confirmarAccion('Consignación Masiva', `¿Aplicar "${verbo}" a ${ids.length} producto(s) seleccionado(s)?`, 'APLICAR', accion === 'marcar' ? 'btn-primary' : 'btn-secondary', form);
    };


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
    window.abrirModalRubro = function() {
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
        <div class="modal-content">
            <div class="modal-head">
                <h3 class="text-accent"><i class="fas fa-tags"></i> Crear Nuevo Rubro</h3>
                <span class="modal-x" onclick="cerrarModalRubro()">&times;</span>
            </div>
            
            <div class="mb-20">
                <label class="modal-label">Nombre de la Categoría / Rubro:</label>
                <input type="text" id="input_nombre_rubro" class="input-field mt-5" placeholder="Ej: Herramientas, Iluminación...">
            </div>

            <div class="d-flex gap-10">
                <button type="button" class="btn btn-primary btn-tall" onclick="confirmarNuevoRubro()">
                    💾 Guardar Rubro
                </button>
                <button type="button" class="btn btn-secondary flex-1" onclick="cerrarModalRubro()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Personalizado para Nuevo Proveedor Rápido -->
    <div id="modalNuevoProveedorRapido" class="modal">
        <div class="modal-content">
            <div class="modal-head">
                <h3 class="text-orange"><i class="fas fa-truck"></i> Registrar Proveedor</h3>
                <span class="modal-x" onclick="cerrarModalProveedorRapido()">&times;</span>
            </div>
            
            <div class="mb-15">
                <label>Código Proveedor*</label>
                <input type="text" id="input_cod_prov" class="input-field" value="<?php echo $nuevo_cod_prov_sugerido; ?>">
                
                <label>Razón Social*</label>
                <input type="text" id="input_razon_prov" class="input-field" placeholder="Nombre de la empresa">
                
                <div class="d-flex gap-10">
                    <div class="flex-1">
                        <label>CUIT</label>
                        <input type="text" id="input_cuit_prov" class="input-field" placeholder="00-00000000-0">
                    </div>
                    <div class="flex-1">
                        <label>Teléfono</label>
                        <input type="text" id="input_tel_prov" class="input-field">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-10">
                <button type="button" class="btn btn-primary btn-tall btn-orange" onclick="confirmarNuevoProveedorRapido()">
                    💾 Guardar Proveedor
                </button>
                <button type="button" class="btn btn-secondary flex-1" onclick="cerrarModalProveedorRapido()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Aumento Masivo de Precios -->
    <div id="modalMasivo" class="modal">
        <div class="modal-content">
            <div class="modal-head">
                <h3 class="text-violet"><i class="fas fa-bolt"></i> Actualización Masiva de Precios</h3>
                <span class="modal-x" onclick="cerrarModalMasivo()">&times;</span>
            </div>
            
            <form method="POST" onsubmit="event.preventDefault(); const form=this; confirmarAccion('Aumento Masivo', '¿Estás seguro de aplicar este cambio de precios a todos los productos seleccionados?', 'APLICAR CAMBIOS', 'btn-primary', () => form.submit());">
                <input type="hidden" name="accion_post" value="aumento_masivo">
                <input type="hidden" name="seleccionados_ids" id="seleccionados_ids">
                
                <div id="infoSeleccionMasiva" class="alert alert-info alert-violet"></div>

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

                <div class="mb-20">
                    <label>Valor (Importante: use punto para decimales)</label>
                    <input type="text" name="masivo_valor" class="input-field" placeholder="Ej: 15.5 o 500" required>
                </div>

                <button type="submit" class="btn btn-primary btn-violet-block">
                    APLICAR CAMBIOS MASIVOS
</button>
             </form>
         </div>
     </div>

     <!-- Modal Carga Múltiple de Productos (nuevo diseño) -->
     <div id="modalMultiples" class="modal">
         <div class="modal-content">
             <div class="modal-head">
                 <h3 class="text-amber"><i class="fas fa-layer-group"></i> Carga Múltiple de Productos</h3>
                 <span class="modal-x" onclick="cerrarModalMultiples()">&times;</span>
             </div>

             <form id="formMultiples" method="POST" action="<?php echo URL_BASE; ?>productos">
                 <input type="hidden" name="accion_post" value="carga_multiple">

                 <!-- Fila 1 -->
                 <div class="d-flex gap-15 mb-10">
                     <div class="flex-1">
                         <label>Proveedor</label>
                         <select name="prov_multiple" id="prov_multiple" required>
                             <option value="">-- Seleccionar Proveedor --</option>
                             <?php foreach ($proveedores_list as $prov): ?>
                                 <option value="<?php echo $prov['razon']; ?>"><?php echo $prov['razon']; ?></option>
                             <?php endforeach; ?>
                         </select>
                     </div>

                     <div class="flex-1">
                         <label>Rubro / Categoría</label>
                         <select name="rubro_multiple" id="rubro_multiple" required>
                             <option value="">-- Seleccionar Rubro --</option>
                             <?php foreach ($rubros_list as $r): ?>
                                 <option value="<?php echo $r['nombre']; ?>"><?php echo $r['nombre']; ?></option>
                             <?php endforeach; ?>
                         </select>
                     </div>

                     <div class="flex-1">
                         <label>Moneda</label>
                         <select name="moneda_multiple" id="moneda_multiple" required>
                             <option value="pesos">Pesos ($)</option>
                             <option value="dolar">Dólar (U$S)</option>
                         </select>
                     </div>
                 </div>

                <!-- Fila 2: porcentaje al lado derecho de la misma columna de moneda -->
                 <div class="d-flex gap-15 mb-15">
                     <div class="flex-1">
                         <label class="chk-label mt-20">
                             <input type="checkbox" id="consignacion_multiple" class="chk-box" onchange="document.getElementById('row_comision_multiple').style.display = this.checked ? '' : 'none';">
                             <span>🤝 En Consignación</span>
                         </label>
                         <small class="hint">Aplica a TODOS los productos de esta carga</small>
                     </div>
                     <div class="flex-1" style="display: none;" id="row_comision_multiple">
                         <label>Comisión del Proveedor (%)</label>
                         <input type="number" step="0.01" min="1" max="99" id="comision_multiple" value="50" class="inp-sm">
                         <small class="text-muted">Ej: 50 = reparto 50/50</small>
                     </div>
                     <div class="flex-1 ml-auto">
                         <label>Porcentaje (%)</label>
                         <input type="number" step="0.01" name="porcentaje_multiple" id="porcentaje_multiple" value="60" class="inp-sm">
                         <small class="text-muted">Se aplicará a compra para calcular venta</small>
                     </div>
                 </div>

                 <!-- Acrílla dinámica -->
                 <div class="mb-15">
                     <label>Productos</label>
                     <div class="table-frame">
                         <table id="tablaMultiples">
                             <thead>
                                 <tr class="tr-line">
                                     <th class="th-cell w-160">Código</th>
                                     <th class="th-cell w-420">Descripción</th>
                                     <th class="th-cell w-180">Compra</th>
                                     <th class="th-cell w-180">Venta</th>
                                     <th class="th-cell w-160">Stock</th>
                                     <th class="th-cell w-68">Acciones</th>
                                 </tr>
                             </thead>
                             <tbody id="cuerpoMultiples">
                                 <tr>
                                     <td class="td-cell w-160"><input type="text" class="prod-cod input-cell" data-enter-next="prod-descrip"></td>
                                     <td class="td-cell w-420"><input type="text" class="prod-descrip input-cell" data-enter-next="prod-compra"></td>
                                     <td class="td-cell w-180"><input type="number" class="prod-compra input-cell" onchange="calcularVenta(this)" data-enter-next="prod-venta"></td>
                                     <td class="td-cell w-180"><input type="number" class="prod-venta input-cell" data-enter-next="prod-stock"></td>
                                     <td class="td-cell w-160"><input type="number" class="prod-stock input-cell" value="0" data-enter-next="__nueva_fila__"></td>
                                     <td class="td-cell w-68 text-center"><button type="button" class="btn btn-success btn-sm" onclick="agregarFila()" title="Agregar fila"><i class="fas fa-plus"></i></button></td>
                                 </tr>
                             </tbody>
                         </table>
                     </div>
                 </div>

                 <div class="mt-20 ta-right">
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
              '<td class="td-cell w-160"><input type="text" class="prod-cod input-cell"></td>' +
              '<td class="td-cell w-420"><input type="text" class="prod-descrip input-cell"></td>' +
              '<td class="td-cell w-180"><input type="number" class="prod-compra input-cell" onchange="calcularVenta(this)"></td>' +
              '<td class="td-cell w-180"><input type="number" class="prod-venta input-cell"></td>' +
              '<td class="td-cell w-160"><input type="number" class="prod-stock input-cell" value="0"></td>' +
              '<td class="td-cell w-68 text-center"><button type="button" class="btn btn-success btn-sm" onclick="agregarFila()" title="Agregar fila"><i class="fas fa-plus"></i></button></td>' +
          '</tr>'; 
      }
      
      function agregarFila() {
          const tbody = document.getElementById('cuerpoMultiples');
          // Evitar doble creación si por cualquier motivo se dispara dos veces.
          if (window.__agregarFila_en_curso) return;
          window.__agregarFila_en_curso = true;
          setTimeout(() => { window.__agregarFila_en_curso = false; }, 0);

          const tr = document.createElement('tr');


          tr.innerHTML = '<td class="td-cell w-160"><input type="text" class="prod-cod input-cell" data-enter-next="prod-descrip"></td>' +
              '<td class="td-cell w-420"><input type="text" class="prod-descrip input-cell" data-enter-next="prod-compra"></td>' +
              '<td class="td-cell w-180"><input type="number" class="prod-compra input-cell" onchange="calcularVenta(this)" data-enter-next="prod-venta"></td>' +
              '<td class="td-cell w-180"><input type="number" class="prod-venta input-cell" data-enter-next="prod-stock"></td>' +
              '<td class="td-cell w-160"><input type="number" class="prod-stock input-cell" value="0" data-enter-next="__nueva_fila__"></td>' +
              '<td class="td-cell w-68 text-center text-nowrap">'
                + '<div class="cell-actions">'
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
             mostrarMensaje('Datos Incompletos', 'Complete proveedor y rubro.', 'error');
             return;
         }

         const chkConsignaMult = document.getElementById('consignacion_multiple');
         const esConsignacion = (chkConsignaMult && chkConsignaMult.checked) ? 1 : 0;
         let comisionConsigna = null;
         if (esConsignacion) {
             const comRaw = document.getElementById('comision_multiple').value;
             if (comRaw !== '') {
                 comisionConsigna = parseFloat(String(comRaw).replace(',', '.'));
             }
             if (comisionConsigna === null || isNaN(comisionConsigna) || comisionConsigna <= 0 || comisionConsigna >= 100) {
                 mostrarMensaje('Datos Incompletos', 'La comisión del proveedor debe estar entre 1 y 99 (%).', 'error');
                 return;
             }
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
             mostrarMensaje('Datos Incompletos', 'Ingrese al menos un producto válido.', 'error');
             return;
         }

         fetch('<?php echo URL_BASE; ?>ajax/cargar_multiples_productos.php', {
             method: 'POST',
             headers: {'Content-Type': 'application/json'},
             body: JSON.stringify({proveedor, rubro, moneda, productos, es_consignacion: esConsignacion, comision_proveedor: comisionConsigna})
         })
         .then(res => res.json())
         .then(data => {
             if (data.success) {
                 mostrarMensaje('Éxito', '✅ ' + data.message, 'success', function() { cerrarModalMultiples(); location.reload(); });
             } else {
                 mostrarMensaje('Error', '❌ ' + data.error, 'error');
             }
         })
         .catch(err => {
             console.error(err);
             mostrarMensaje('Error', '❌ Error al guardar productos.', 'error');
         });
}
      </script>

    <!-- Modal OCULTO: Consignación Masiva (sin botón visible en la UI)
         Disparadores: Ctrl+Alt+C  |  doble clic en el encabezado "Posesión" -->
    <div id="modalConsignacionMasiva" class="modal">
        <div class="modal-content">
            <div class="modal-head">
                <h3 class="text-warning"><i class="fas fa-handshake"></i> Consignación Masiva</h3>
                <span class="modal-x" onclick="cerrarModalConsignacionMasiva()">&times;</span>
            </div>

            <div id="infoConsignaMasiva" class="alert alert-info mb-15"></div>

            <div class="mb-15">
                <label>Acción sobre los productos seleccionados</label>
                <div class="d-flex gap-10">
                    <button type="button" class="btn btn-primary flex-1 btn-gold" onclick="aplicarConsignacionMasiva('marcar')">🤝 Marcar como Consignación</button>
                    <button type="button" class="btn btn-secondary flex-1" onclick="aplicarConsignacionMasiva('desmarcar')">Desmarcar (Propio)</button>
                </div>
            </div>

            <div>
                <label>Comisión del Proveedor (%) — solo para "Marcar"</label>
                <input type="number" id="com_consigna_masiva" class="input-field" min="1" max="99" step="0.01" value="50">
                <small class="hint">Ej: 50 = reparto 50/50. Dejá el valor actual si ya lo definiste por producto.</small>
            </div>
        </div>
    </div>

    <!-- Modal Generar Listado PDF -->
    <div id="modalPdfPrecios" class="modal">
        <div class="modal-content">
            <div class="modal-head">
                <h3 class="text-accent"><i class="fas fa-file-pdf"></i> Generar Listado de Precios</h3>
                <span class="modal-x" onclick="cerrarModalPdfPrecios()">&times;</span>
            </div>
            
            <label class="text-faint">Seleccione el tipo de listado:</label>
            <div class="radio-group">
                <label class="radio-label">
                    <input type="radio" name="tipo_listado" value="todo" checked onchange="toggleFiltroPdf()" class="radio-input">
                    <i class="fas fa-list"></i> Listar todos los productos
                </label>
                <label class="radio-label">
                    <input type="radio" name="tipo_listado" value="busqueda" onchange="toggleFiltroPdf()" class="radio-input">
                    <i class="fas fa-search"></i> Según búsqueda actual
                </label>
                <label class="radio-label">
                    <input type="radio" name="tipo_listado" value="rubro" onchange="toggleFiltroPdf()" class="radio-input">
                    <i class="fas fa-tag"></i> Por Rubro / Categoría
                </label>
                <label class="radio-label">
                    <input type="radio" name="tipo_listado" value="proveedor" onchange="toggleFiltroPdf()" class="radio-input">
                    <i class="fas fa-truck"></i> Por Proveedor
                </label>
            </div>

            <div class="filtro-condicional" id="filtro_rubro_pdf">
                <label class="text-faint mt-15">Seleccione el Rubro:</label>
                <select id="select_rubro_pdf" class="sel-md">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($rubros_list as $r): ?>
                        <option value="<?php echo htmlspecialchars($r['nombre']); ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filtro-condicional" id="filtro_proveedor_pdf">
                <label class="text-faint mt-15">Seleccione el Proveedor:</label>
                <select id="select_proveedor_pdf" class="sel-md">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($proveedores_pdf_list as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['proveedor']); ?>"><?php echo htmlspecialchars($p['proveedor']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-actions-end">
                <button type="button" class="btn btn-secondary btn-lg-ghost" onclick="cerrarModalPdfPrecios()">Cancelar</button>
                <button type="button" class="btn btn-primary btn-lg-accent" onclick="generarPdfPrecios()"><i class="fas fa-file-pdf"></i> Generar PDF</button>
            </div>
        </div>
    </div>

    <script>
    // --- MODAL LISTADO PDF ---
    function abrirModalPdfPrecios() {
        document.getElementById('modalPdfPrecios').style.display = 'block';
        document.getElementById('modalPdfPrecios').style.alignItems = 'center';
        document.getElementById('modalPdfPrecios').style.justifyContent = 'center';
    }
    function cerrarModalPdfPrecios() {
        document.getElementById('modalPdfPrecios').style.display = 'none';
    }
    document.getElementById('modalPdfPrecios').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalPdfPrecios();
    });

    function toggleFiltroPdf() {
        const seleccion = document.querySelector('input[name="tipo_listado"]:checked').value;
        document.getElementById('filtro_rubro_pdf').style.display = (seleccion === 'rubro') ? 'block' : 'none';
        document.getElementById('filtro_proveedor_pdf').style.display = (seleccion === 'proveedor') ? 'block' : 'none';
    }

    function generarPdfPrecios() {
        const seleccion = document.querySelector('input[name="tipo_listado"]:checked').value;
        let url = '<?php echo URL_BASE; ?>pages/generar_pdf_lista_precios.php?tipo=' + seleccion;

        if (seleccion === 'rubro') {
            const rubro = document.getElementById('select_rubro_pdf').value;
            if (!rubro) {
                mostrarMensaje('Atención', 'Seleccione un rubro.', 'error');
                return;
            }
            url += '&valor=' + encodeURIComponent(rubro);
        } else if (seleccion === 'proveedor') {
            const proveedor = document.getElementById('select_proveedor_pdf').value;
            if (!proveedor) {
                mostrarMensaje('Atención', 'Seleccione un proveedor.', 'error');
                return;
            }
            url += '&valor=' + encodeURIComponent(proveedor);
        } else if (seleccion === 'busqueda') {
            const q = document.getElementById('filtroProductos').value.trim();
            if (q.length < 2) {
                mostrarMensaje('Atención', 'Ingrese al menos 2 caracteres en la búsqueda.', 'error');
                document.getElementById('filtroProductos').focus();
                cerrarModalPdfPrecios();
                return;
            }
            url += '&q=' + encodeURIComponent(q);
        }

        window.open(url, '_blank');
        cerrarModalPdfPrecios();
    }
    </script>
</body>
</html>
