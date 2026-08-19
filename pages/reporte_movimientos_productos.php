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

// Filtros
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$cod_prod_filtro = isset($_GET['cod_prod']) ? trim($_GET['cod_prod']) : '';
$id_cliente_filtro = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

// Paginación
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 50;
$offset = ($pagina - 1) * $por_pagina;

$movimientos = [];
$total_cantidad = 0;
$total_subtotal = 0;
$total_costo = 0;
$total_ganancia = 0;
$total_movimientos = 0;
$total_paginas = 1;
$clientes = [];
$mensaje = '';

try {
    // Obtener lista de clientes para el filtro
    $stmt_cli = $pdo->prepare("SELECT id, CONCAT(apellido, ', ', nombre) as nombre FROM clientes WHERE empresa_id = ? ORDER BY apellido ASC");
    $stmt_cli->execute([$empresa_id]);
    $clientes = $stmt_cli->fetchAll(PDO::FETCH_ASSOC);

    // Obtener lista de productos para autocompletado
    $stmt_prod = $pdo->prepare("SELECT cod_prod, descripcion FROM productos WHERE empresa_id = ? ORDER BY cod_prod ASC");
    $stmt_prod->execute([$empresa_id]);
    $productos = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

    // Construcción dinámica de WHERE
    $where_fecha = " AND DATE(v.fecha_venta) BETWEEN :inicio AND :fin ";
    $where_sucursal = ($sucursal_id > 0) ? " AND v.sucursal_id = :sucursal_id " : "";
    $where_producto = ($cod_prod_filtro !== '') ? " AND vd.cod_prod = :cod_prod " : "";
    $where_cliente = ($id_cliente_filtro > 0) ? " AND v.id_cliente = :id_cliente " : "";

    // 1. Contar total de movimientos para paginación
    $sql_count = "SELECT COUNT(*) as total
                  FROM ventas_detalle vd
                  INNER JOIN ventas v ON v.empresa_id = vd.empresa_id AND v.n_documento = vd.n_documento
                  WHERE v.estado = 'Finalizada'
                  AND v.empresa_id = :empresa_id_c
                  $where_fecha
                  $where_sucursal
                  $where_producto
                  $where_cliente";
    $params_count = [
        ':empresa_id_c' => $empresa_id,
        ':inicio' => $fecha_inicio,
        ':fin' => $fecha_fin
    ];
    if ($sucursal_id > 0) $params_count[':sucursal_id'] = $sucursal_id;
    if ($cod_prod_filtro !== '') $params_count[':cod_prod'] = $cod_prod_filtro;
    if ($id_cliente_filtro > 0) $params_count[':id_cliente'] = $id_cliente_filtro;
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params_count);
    $total_movimientos = (int)$stmt_count->fetchColumn();
    $total_paginas = max(1, ceil($total_movimientos / $por_pagina));

    // 2. Obtener movimientos con paginación
    $sql_mov = "SELECT 
                    vd.cod_prod,
                    vd.descripcion AS producto_desc,
                    vd.cant,
                    vd.p_unit,
                    vd.descuento_unitario,
                    vd.total AS subtotal,
                    vd.p_costo_venta,
                    (vd.total - (vd.cant * vd.p_costo_venta)) AS ganancia,
                    v.n_documento,
                    v.fecha_venta AS fecha,
                    v.cond_pago,
                    CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
                FROM ventas_detalle vd
                INNER JOIN ventas v ON v.empresa_id = vd.empresa_id AND v.n_documento = vd.n_documento
                LEFT JOIN clientes c ON v.id_cliente = c.id
                WHERE v.estado = 'Finalizada'
                AND v.empresa_id = :empresa_id
                $where_fecha
                $where_sucursal
                $where_producto
                $where_cliente
                ORDER BY v.fecha_venta DESC, v.n_documento DESC, vd.cod_prod ASC
                LIMIT :limit OFFSET :offset";
    $params_mov = [
        ':empresa_id' => $empresa_id,
        ':inicio' => $fecha_inicio,
        ':fin' => $fecha_fin,
        ':limit' => $por_pagina,
        ':offset' => $offset
    ];
    if ($sucursal_id > 0) $params_mov[':sucursal_id'] = $sucursal_id;
    if ($cod_prod_filtro !== '') $params_mov[':cod_prod'] = $cod_prod_filtro;
    if ($id_cliente_filtro > 0) $params_mov[':id_cliente'] = $id_cliente_filtro;
    $stmt_mov = $pdo->prepare($sql_mov);
    $stmt_mov->bindValue(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt_mov->bindValue(':inicio', $fecha_inicio, PDO::PARAM_STR);
    $stmt_mov->bindValue(':fin', $fecha_fin, PDO::PARAM_STR);
    $stmt_mov->bindValue(':limit', (int)$por_pagina, PDO::PARAM_INT);
    $stmt_mov->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    if ($sucursal_id > 0) $stmt_mov->bindValue(':sucursal_id', $sucursal_id, PDO::PARAM_INT);
    if ($cod_prod_filtro !== '') $stmt_mov->bindValue(':cod_prod', $cod_prod_filtro, PDO::PARAM_STR);
    if ($id_cliente_filtro > 0) $stmt_mov->bindValue(':id_cliente', $id_cliente_filtro, PDO::PARAM_INT);
    $stmt_mov->execute();
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);

    // 3. Calcular totales
    foreach ($movimientos as $m) {
        $total_cantidad += (float)$m['cant'];
        $total_subtotal += (float)$m['subtotal'];
        $total_costo += (float)$m['cant'] * (float)$m['p_costo_venta'];
        $total_ganancia += (float)$m['ganancia'];
    }

} catch (PDOException $e) {
    $mensaje = "❌ Error en SQL: " . $e->getMessage();
    $movimientos = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Movimientos de Productos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css?v=' . time()); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --accent: #00bcd4; --success: #2ecc71; --warning: #f1c40f; --danger: #e74c3c; }
        
        .reporte-container { background: #1e1e1e; border-radius: 12px; border: 1px solid #333; padding: 20px; margin-bottom: 30px; }
        table { border-collapse: separate; border-spacing: 0 6px; width: 100%; }
        th { color: var(--accent); text-transform: uppercase; font-size: 0.75em; letter-spacing: 1px; padding: 10px 8px; text-align: left; white-space: nowrap; }
        tr { background: #252525; transition: 0.3s; }
        tr:hover { background: #2a2a2a; }
        td { padding: 10px 8px; border-top: 1px solid #333; border-bottom: 1px solid #333; color: #ccc; font-size: 0.9em; }
        td:first-child { border-left: 1px solid #333; border-radius: 8px 0 0 8px; }
        td:last-child { border-right: 1px solid #333; border-radius: 0 8px 8px 0; }

        .text-right { text-align: right; }
        .text-bold { font-weight: bold; color: #fff; }
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-warning { color: var(--warning); }

        .form-control { padding: 10px; border-radius: 5px; border: 1px solid #555; background: #444; color: white; height: 42px; box-sizing: border-box; }
        .btn-filter-action {
            height: 42px; min-width: 120px; display: inline-flex; align-items: center; justify-content: center;
            padding: 0 20px; border-radius: 5px; font-size: 0.9rem; font-weight: bold;
            border: none; cursor: pointer; text-decoration: none; transition: 0.2s;
        }
        .btn-filter-action:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-filter-primary { background-color: #005cd4; color: white; }
        .btn-filter-secondary { background-color: #c2ca56; color: white !important; }
        .filter-form .btn-filter-action { margin-bottom: 0; }

        .summary-bar { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px; }
        .summary-item { background: #252525; border: 1px solid #444; border-radius: 8px; padding: 12px 20px; flex: 1; min-width: 150px; }
        .summary-item label { display: block; font-size: 0.75em; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        .summary-item .value { font-size: 1.3em; font-weight: bold; margin-top: 5px; }

        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin: 10px 0; padding: 8px 15px; background: #2d2d2d; border-radius: 5px; color: white; }
        
        .badge-cond { padding: 3px 8px; border-radius: 4px; font-size: 0.75em; font-weight: bold; }
        .badge-contado { background: rgba(46, 204, 113, 0.15); color: var(--success); border: 1px solid var(--success); }
        .badge-ctacte { background: rgba(241, 196, 15, 0.15); color: var(--warning); border: 1px solid var(--warning); }

        .filter-btn-group {
            display: inline-flex;
            flex-direction: column;
            justify-content: flex-end;
            min-height: 66px;
        }
        .filter-btn-group-inner {
            display: flex;
            gap: 8px;
        }
        .datalist-wrapper { position: relative; }
        .datalist-wrapper input { width: 100%; box-sizing: border-box; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1 style="color: var(--accent); margin-bottom: 25px;">
            <i class="fas fa-exchange-alt"></i> Reporte de Movimientos de Productos
        </h1>

        <?php if ($mensaje): ?>
            <div style="background: rgba(231,76,60,0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="reporte-container">
            <form method="GET" class="filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label style="display: block; font-size: 0.8em; color: #888; margin-bottom: 4px;">Desde:</label>
                    <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" class="form-control">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8em; color: #888; margin-bottom: 4px;">Hasta:</label>
                    <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>" class="form-control">
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label style="display: block; font-size: 0.8em; color: #888; margin-bottom: 4px;">Producto:</label>
                    <input type="text" name="cod_prod" list="listaProductos" class="form-control" style="width: 100%;" 
                           placeholder="Código o descripción..." value="<?php echo htmlspecialchars($cod_prod_filtro); ?>">
                    <datalist id="listaProductos">
                        <?php foreach ($productos as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['cod_prod']); ?>"><?php echo htmlspecialchars($p['descripcion']); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label style="display: block; font-size: 0.8em; color: #888; margin-bottom: 4px;">Cliente:</label>
                    <select name="id_cliente" class="form-control" style="width: 100%;">
                        <option value="0">-- Todos --</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($id_cliente_filtro == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top: 17px; padding-bottom: 15px;">
                    <button type="submit" class="btn-filter-action btn-filter-primary">🔍 Filtrar</button>
                    <a href="reporte_movimientos_productos.php" class="btn-filter-action btn-filter-secondary" style="margin-left: 8px;">🔄 Limpiar</a>
                </div>
                
            </form>
        </div>

        <!-- Totales -->
        <div class="summary-bar">
            <div class="summary-item">
                <label><i class="fas fa-shopping-cart"></i> Movimientos</label>
                <div class="value"><?php echo number_format($total_movimientos, 0, ',', '.'); ?></div>
            </div>
            <div class="summary-item">
                <label><i class="fas fa-cubes"></i> Total Unidades</label>
                <div class="value"><?php echo number_format($total_cantidad, 0, ',', '.'); ?></div>
            </div>
            <div class="summary-item">
                <label><i class="fas fa-dollar-sign"></i> Total Vendido</label>
                <div class="value text-success">$<?php echo number_format($total_subtotal, 2, ',', '.'); ?></div>
            </div>
            <div class="summary-item">
                <label><i class="fas fa-coins"></i> Costo Total</label>
                <div class="value text-warning">$<?php echo number_format($total_costo, 2, ',', '.'); ?></div>
            </div>
            <div class="summary-item">
                <label><i class="fas fa-chart-line"></i> Ganancia Total</label>
                <div class="value" style="color: <?php echo ($total_ganancia >= 0) ? 'var(--success)' : 'var(--danger)'; ?>;">
                    $<?php echo number_format($total_ganancia, 2, ',', '.'); ?>
                </div>
            </div>
        </div>

        <!-- Paginación -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination-bar">
            <span>Total: <?php echo $total_movimientos; ?> movimientos — Pág. <?php echo $pagina; ?> de <?php echo $total_paginas; ?></span>
            <div style="display: flex; gap: 5px;">
                <?php if ($pagina > 1): ?>
                    <a href="?fecha_inicio=<?php echo urlencode($fecha_inicio); ?>&fecha_fin=<?php echo urlencode($fecha_fin); ?>&cod_prod=<?php echo urlencode($cod_prod_filtro); ?>&id_cliente=<?php echo $id_cliente_filtro; ?>&pagina=<?php echo ($pagina - 1); ?>" class="btn-filter-action btn-filter-secondary" style="height: 32px; min-width: auto; padding: 0 12px; font-size: 0.8rem;">⬅ Anterior</a>
                <?php endif; ?>
                <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                    <a href="?fecha_inicio=<?php echo urlencode($fecha_inicio); ?>&fecha_fin=<?php echo urlencode($fecha_fin); ?>&cod_prod=<?php echo urlencode($cod_prod_filtro); ?>&id_cliente=<?php echo $id_cliente_filtro; ?>&pagina=<?php echo $i; ?>" class="btn-filter-action <?php echo ($i == $pagina) ? 'btn-filter-primary' : 'btn-filter-secondary'; ?>" style="height: 32px; min-width: 36px; padding: 0 8px; font-size: 0.8rem;"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $total_paginas): ?>
                    <a href="?fecha_inicio=<?php echo urlencode($fecha_inicio); ?>&fecha_fin=<?php echo urlencode($fecha_fin); ?>&cod_prod=<?php echo urlencode($cod_prod_filtro); ?>&id_cliente=<?php echo $id_cliente_filtro; ?>&pagina=<?php echo ($pagina + 1); ?>" class="btn-filter-action btn-filter-secondary" style="height: 32px; min-width: auto; padding: 0 12px; font-size: 0.8rem;">Siguiente ➡</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de movimientos -->
        <div class="reporte-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>N° Doc.</th>
                        <th>Cliente</th>
                        <th>Cond.</th>
                        <th>Código</th>
                        <th>Producto</th>
                        <th class="text-right">Cant.</th>
                        <th class="text-right">P. Unit.</th>
                        <th class="text-right">Dto.</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">Costo</th>
                        <th class="text-right">Ganancia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($movimientos) > 0): ?>
                        <?php foreach ($movimientos as $m): 
                            $ganancia = (float)$m['ganancia'];
                            $margen = ((float)$m['subtotal'] > 0) ? ($ganancia / (float)$m['subtotal'] * 100) : 0;
                        ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo date('d/m/Y', strtotime($m['fecha'])); ?></td>
                                <td><strong><?php echo $m['n_documento']; ?></strong></td>
                                <td><?php echo htmlspecialchars($m['nombre_cliente'] ?? 'Consumidor Final'); ?></td>
                                <td>
                                    <?php if ($m['cond_pago'] == 'CUENTA CORRIENTE'): ?>
                                        <span class="badge-cond badge-ctacte">Cta.Cte.</span>
                                    <?php else: ?>
                                        <span class="badge-cond badge-contado">Contado</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--accent); font-weight: bold;"><?php echo htmlspecialchars($m['cod_prod']); ?></td>
                                <td><?php echo htmlspecialchars($m['producto_desc']); ?></td>
                                <td class="text-right text-bold"><?php echo number_format((float)$m['cant'], 0, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format((float)$m['p_unit'], 2, ',', '.'); ?></td>
                                <td class="text-right"><?php echo number_format((float)$m['descuento_unitario'], 2, ',', '.'); ?></td>
                                <td class="text-right text-bold text-success">$<?php echo number_format((float)$m['subtotal'], 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format((float)$m['cant'] * (float)$m['p_costo_venta'], 2, ',', '.'); ?></td>
                                <td class="text-right text-bold" style="color: <?php echo ($ganancia >= 0) ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    $<?php echo number_format($ganancia, 2, ',', '.'); ?>
                                    <small style="display: block; font-size: 0.7em; opacity: 0.7;"><?php echo number_format($margen, 1); ?>%</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 30px; color: #888;">
                                <i class="fas fa-inbox" style="font-size: 2em; display: block; margin-bottom: 10px;"></i>
                                No se encontraron movimientos para los filtros seleccionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>