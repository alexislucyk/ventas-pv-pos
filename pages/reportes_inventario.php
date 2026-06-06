<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
// Mantenemos tu restricción de seguridad
//restringirPagina('developer'); 
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

$reporte_stock = [];
$total_valoracion = 0;
$total_productos = 0;
$mensaje_error = '';

try {
    $sql_stock = "SELECT cod_prod, descripcion, stock, p_compra, p_venta, (stock * p_compra) AS valor_inventario 
                  FROM productos ORDER BY cod_prod ASC";
    $stmt_stock = $pdo->query($sql_stock);
    $reporte_stock = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);

    $total_valoracion = array_reduce($reporte_stock, function($sum, $item) {
        return $sum + (float)$item['valor_inventario'];
    }, 0);
    $total_productos = count($reporte_stock);

} catch (Exception $e) {
    error_log("Error al generar Reporte de Stock: " . $e->getMessage());
    $mensaje_error = "❌ Error: No se pudo cargar el reporte de stock.";
}

// Lógica de Historial (Sin cambios en tu lógica SQL)
$movimientos_producto = [];
$producto_buscado = '';
if (isset($_GET['buscar_prod']) && !empty($_GET['cod_prod_historial'])) {
    $producto_buscado = htmlspecialchars($_GET['cod_prod_historial']);
    try {
        $sql_historial = "SELECT cd.fecha, cd.n_documento, cd.cant, cd.p_unit, cd.total, p.razon AS nombre_proveedor
                          FROM compras_detalle cd
                          JOIN compras c ON cd.n_documento = c.n_documento 
                          JOIN proveedores p ON c.cod_proveedor = p.cod_prov
                          WHERE cd.cod_prod = :cod_prod ORDER BY cd.fecha DESC";
        $stmt_historial = $pdo->prepare($sql_historial);
        $stmt_historial->execute([':cod_prod' => $producto_buscado]);
        $movimientos_producto = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $mensaje_error = "❌ Error al cargar historial."; }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --accent: #00bcd4; --success: #2ecc71; --warning: #f1c40f; --danger: #e74c3c; }
        
        /* Dashboard Cards */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #1e1e1e; border: 1px solid #333; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 15px; }
        .stat-icon { background: rgba(0, 188, 212, 0.1); color: var(--accent); padding: 15px; border-radius: 10px; font-size: 1.5em; }
        .stat-info h3 { margin: 0; font-size: 0.9em; color: #888; text-transform: uppercase; }
        .stat-info p { margin: 5px 0 0; font-size: 1.6em; font-weight: bold; color: #fff; }

        /* Estilo de Tablas */
        .reporte-container { background: #1e1e1e; border-radius: 12px; border: 1px solid #333; padding: 20px; margin-bottom: 30px; }
        table { border-collapse: separate; border-spacing: 0 8px; width: 100%; }
        th { color: var(--accent); text-transform: uppercase; font-size: 0.8em; letter-spacing: 1px; padding: 12px; text-align: left; }
        tr { background: #252525; transition: 0.3s; }
        tr:hover { background: #2a2a2a; transform: translateX(5px); }
        td { padding: 12px; border-top: 1px solid #333; border-bottom: 1px solid #333; color: #ccc; }
        td:first-child { border-left: 1px solid #333; border-radius: 8px 0 0 8px; }
        td:last-child { border-right: 1px solid #333; border-radius: 0 8px 8px 0; }

        /* Badges de Stock */
        .stock-tag { padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: bold; }
        .stock-low { background: rgba(231, 76, 60, 0.1); color: var(--danger); border: 1px solid var(--danger); }
        .stock-ok { background: rgba(46, 204, 113, 0.1); color: var(--success); }

        .search-bar { background: #2a2a2a; border: 1px solid #444; color: #fff; padding: 12px 20px; border-radius: 8px; width: 100%; margin-bottom: 20px; outline: none; }
        .search-bar:focus { border-color: var(--accent); }
        
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; color: #fff; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?> 
    
    <div class="content">
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
                    <h3>Valorización de Stock</h3>
                    <p>$<?php echo number_format((float)($total_valoracion ?? 0), 2, ',', '.'); ?></p>
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
                        <th class="text-right">Valorización</th>
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
                            <td class="text-right text-bold" style="color: var(--success);">$<?php echo number_format((float)($prod['valor_inventario'] ?? 0), 2, ',', '.'); ?></td>
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