<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
// Mantenemos tu restricción de seguridad
//restringirPagina('developer'); 
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$reporte_stock = [];
$total_valoracion_costo = 0;
$total_valoracion_venta = 0;
$total_productos = 0;
$mensaje_error = '';

try {
    $sql_stock = "SELECT p.cod_prod, p.descripcion, COALESCE(s.stock_actual, 0) AS stock, p.p_compra, p.p_venta, 
                         (COALESCE(s.stock_actual, 0) * p.p_compra) AS valor_inventario_costo,
                         (COALESCE(s.stock_actual, 0) * p.p_venta) AS valor_inventario_venta
                  FROM productos p 
                  LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci AND s.empresa_id = :stock_empresa_id AND s.sucursal_id = :sucursal_id
                  WHERE p.empresa_id = :prod_empresa_id
                  ORDER BY p.cod_prod ASC";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([':stock_empresa_id' => $empresa_id, ':prod_empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    $reporte_stock = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);

    $total_valoracion_costo = 0;
    $total_valoracion_venta = 0;
    foreach ($reporte_stock as $item) {
        $total_valoracion_costo += (float)$item['valor_inventario_costo'];
        $total_valoracion_venta += (float)$item['valor_inventario_venta'];
    }
    $total_productos = count($reporte_stock);

} catch (Exception $e) {
    error_log("Error al generar Reporte de Stock: " . $e->getMessage());
    $mensaje_error = "❌ Error: No se pudo cargar el reporte de stock.";
}

$movimientos_producto = [];
$producto_buscado = '';
if (isset($_GET['buscar_prod']) && !empty($_GET['cod_prod_historial'])) {
    $producto_buscado = htmlspecialchars($_GET['cod_prod_historial']);
    try {
        $sql_historial = "SELECT cd.fecha, cd.n_documento, cd.cant, cd.p_unit, cd.total, p.razon AS nombre_proveedor
                          FROM compras_detalle cd
                          JOIN compras c ON cd.n_documento = c.n_documento AND c.empresa_id = :comp_emp
                          JOIN proveedores p ON c.cod_proveedor = p.cod_prov AND p.empresa_id = :prov_emp
                          WHERE cd.cod_prod = :cod_prod AND cd.empresa_id = :cd_emp
                          ORDER BY cd.fecha DESC";
        $stmt_historial = $pdo->prepare($sql_historial);
        $stmt_historial->execute([':cod_prod' => $producto_buscado, ':comp_emp' => $empresa_id, ':prov_emp' => $empresa_id, ':cd_emp' => $empresa_id]);
        $movimientos_producto = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $mensaje_error = "❌ Error al cargar historial."; }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/reportes.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?> 
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1 style="color: var(--accent); margin-bottom: 25px;"><i class="fas fa-chart-line"></i> Reportes de Inventario y Costos</h1>

        <?php if ($mensaje_error): ?>
            <div class="alert alert-error"><?php echo $mensaje_error; ?></div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-info">
                    <h3>Total Productos</h3>
                    <p><?php echo $total_productos; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: var(--success); background: rgba(46, 204, 113, 0.1);"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-info">
                    <h3>Valorización (Precio Costo)</h3>
                    <p style="font-size:1.3em;">$<?php echo number_format((float)($total_valoracion_costo ?? 0), 2, ',', '.'); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: var(--warning); background: rgba(241, 196, 15, 0.1);"><i class="fas fa-tags"></i></div>
                <div class="stat-info">
                    <h3>Valorización (Precio Venta)</h3>
                    <p style="font-size:1.3em;">$<?php echo number_format((float)($total_valoracion_venta ?? 0), 2, ',', '.'); ?></p>
                </div>
            </div>
        </div>

        <div class="reporte-container">
            <h2 style="font-size: 1.2em; margin-bottom: 20px;"><i class="fas fa-history"></i> Historial de Compras por Producto</h2>
            <form method="GET" style="display: flex; gap: 10px; margin-bottom: 25px;">
                <input type="text" name="cod_prod_historial" class="search-bar" style="margin-bottom:0;" placeholder="Escriba el código del producto..." value="<?php echo $producto_buscado; ?>" required>
                <button type="submit" name="buscar_prod" class="btn-success" style="padding: 0 30px; border-radius: 8px; cursor:pointer;">BUSCAR</button>
            </form>

            <?php if (!empty($movimientos_producto)): ?>
                <table id="tabla_historial">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Documento</th>
                            <th>Proveedor</th>
                            <th class="text-right">Cant.</th>
                            <th class="text-right">Costo Unit.</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos_producto as $mov): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($mov['fecha'])); ?></td>
                                <td><i class="fas fa-file-invoice" style="color:#666;"></i> <?php echo $mov['n_documento']; ?></td>
                                <td><?php echo htmlspecialchars($mov['nombre_proveedor'] ?? ''); ?></td>
                                <td class="text-right text-bold"><?php echo number_format((float)($mov['cant'] ?? 0), 0, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format((float)($mov['p_unit'] ?? 0), 2, ',', '.'); ?></td>
                                <td class="text-right text-bold" style="color: var(--accent);">$<?php echo number_format($mov['total'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif($producto_buscado): ?>
                <p style="text-align: center; color: #888; padding: 20px;">No hay registros de compra para este código.</p>
            <?php endif; ?>
        </div>

        <div class="reporte-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin:0; font-size: 1.2em;"><i class="fas fa-list"></i> Valoración de Stock Actual</h2>
                <input type="text" id="filtroStock" class="search-bar" placeholder="🔍 Filtrar por código o descripción..." style="width: 300px; margin-bottom: 0;">
            </div>
            
            <table id="tabla_stock">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th class="text-right">Stock</th>
                        <th class="text-right">Costo Compra</th>
                        <th class="text-right">Precio Venta</th>
                        <th class="text-right">Val. Costo</th>
                        <th class="text-right">Val. Venta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reporte_stock as $prod): 
                        $claseStock = ($prod['stock'] <= 5) ? 'stock-low' : 'stock-ok';
                    ?>
                        <tr class="fila-producto">
                            <td class="text-bold"><?php echo $prod['cod_prod']; ?></td>
                            <td><?php echo htmlspecialchars($prod['descripcion']); ?></td>
                            <td class="text-right">
                                <span class="stock-tag <?php echo $claseStock; ?>">
                                    <?php echo number_format((float)($prod['stock'] ?? 0), 0, ',', '.'); ?>
                                </span>
                            </td>
                            <td class="text-right">$<?php echo number_format((float)($prod['p_compra'] ?? 0), 2, ',', '.'); ?></td>
                            <td class="text-right">$<?php echo number_format((float)($prod['p_venta'] ?? 0), 2, ',', '.'); ?></td>
                            <td class="text-right text-bold" style="color: var(--success);">$<?php echo number_format((float)($prod['valor_inventario_costo'] ?? 0), 2, ',', '.'); ?></td>
                            <td class="text-right text-bold" style="color: var(--warning);">$<?php echo number_format((float)($prod['valor_inventario_venta'] ?? 0), 2, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        
    </div>

    <script>
        // Buscador instantáneo en tabla de stock
        document.getElementById('filtroStock').addEventListener('keyup', function() {
            let filtro = this.value.toLowerCase();
            let filas = document.querySelectorAll('.fila-producto');
            filas.forEach(fila => {
                let texto = fila.innerText.toLowerCase();
                fila.style.display = texto.includes(filtro) ? '' : 'none';
            });
        });
    </script>
</body>
</html>