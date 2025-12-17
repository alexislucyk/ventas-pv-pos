<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php'); 
    exit();
}

require '../config/db_config.php'; 

$reporte_stock = [];
$total_valoracion = 0;
$mensaje_error = '';

// Lógica para cargar el Reporte de Stock Actual (Se ejecuta siempre al cargar)
try {
    // AJUSTE CLAVE: p_costo -> p_compra
    $sql_stock = "SELECT 
                      cod_prod, 
                      descripcion, 
                      stock, 
                      p_compra,  /* USANDO p_compra */
                      p_venta,
                      (stock * p_compra) AS valor_inventario 
                  FROM 
                      productos 
                  ORDER BY 
                      cod_prod ASC";
    
    $stmt_stock = $pdo->query($sql_stock);
    $reporte_stock = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);

    // Calcular la Valoración Total del Inventario
    $total_valoracion = array_reduce($reporte_stock, function($sum, $item) {
        return $sum + (float)$item['valor_inventario'];
    }, 0);

} catch (Exception $e) {
    error_log("Error al generar Reporte de Stock: " . $e->getMessage());
    $mensaje_error = "❌ Error: No se pudo cargar el reporte de stock. (Verifique que el campo p_compra exista en la tabla productos)";
}

// Lógica para procesar la búsqueda de Movimientos de Compra por Producto
$movimientos_producto = [];
$producto_buscado = '';
if (isset($_GET['buscar_prod']) && !empty($_GET['cod_prod_historial'])) {
    $producto_buscado = htmlspecialchars($_GET['cod_prod_historial']);
    
    try {
        // Consulta para obtener el historial de compras para el producto
        $sql_historial = "SELECT 
                              cd.fecha, 
                              cd.n_documento, 
                              cd.cant, 
                              cd.p_unit, 
                              cd.total, 
                              c.cod_proveedor,
                              p.razon AS nombre_proveedor
                          FROM 
                              compras_detalle cd
                          JOIN 
                              compras c ON cd.n_documento = c.n_documento 
                          JOIN
                              proveedores p ON c.cod_proveedor = p.cod_prov
                          WHERE 
                              cd.cod_prod = :cod_prod
                          ORDER BY 
                              cd.fecha DESC";
                              
        $stmt_historial = $pdo->prepare($sql_historial);
        $stmt_historial->execute([':cod_prod' => $producto_buscado]);
        $movimientos_producto = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Error al generar Reporte Historial: " . $e->getMessage());
        $mensaje_error = "❌ Error: No se pudo cargar el historial de compras del producto.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Inventario y Costos</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .reporte-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .valoracion-total { font-size: 1.6em; font-weight: bold; color: #4caf50; }
        .seccion-reporte { margin-top: 30px; border-top: 1px solid #444; padding-top: 20px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>

    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 
    
    <div class="content">
        <h1>🔍 Reportes de Inventario y Costos</h1>
        
        <?php if (!empty($mensaje_error)): ?>
            <div class="alert alert-error"><?php echo $mensaje_error; ?></div>
        <?php endif; ?>

        <div class="seccion-reporte">
            <div class="reporte-header">
                <h2>1. Valoración de Stock Actual (Último Costo de Compra)</h2>
                <div class="valoracion-total">
                    Valor Total Inventario: $<?php echo number_format($total_valoracion, 2, ',', '.'); ?>
                </div>
            </div>
            
            <table id="tabla_stock" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 10%;">Código</th>
                        <th>Descripción</th>
                        <th class="text-right" style="width: 10%;">Stock</th>
                        <th class="text-right" style="width: 15%;">Último Costo Compra ($)</th> <th class="text-right" style="width: 15%;">Precio Venta ($)</th>
                        <th class="text-right" style="width: 15%;">Valor Inventario ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reporte_stock)): ?>
                        <tr><td colspan="6" class="text-center">No hay productos registrados en el sistema.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reporte_stock as $prod): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($prod['cod_prod']); ?></td>
                                <td><?php echo htmlspecialchars($prod['descripcion']); ?></td>
                                <td class="text-right"><?php echo number_format($prod['stock'], 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format($prod['p_compra'], 2, ',', '.'); ?></td> <td class="text-right">$<?php echo number_format($prod['p_venta'], 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format($prod['valor_inventario'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="seccion-reporte">
            <h2>2. Historial de Compras por Producto</h2>

            <form method="GET" action="reportes_inventario.php" style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input 
                    type="text" 
                    name="cod_prod_historial" 
                    class="input-field" 
                    placeholder="Ingrese Código de Producto"
                    value="<?php echo htmlspecialchars($producto_buscado); ?>"
                    required 
                    style="flex-grow: 1;">
                <button type="submit" name="buscar_prod" class="btn btn-primary">Buscar Historial</button>
            </form>
            
            <?php if ($producto_buscado && empty($movimientos_producto)): ?>
                <div class="alert alert-error">No se encontraron compras para el código **<?php echo htmlspecialchars($producto_buscado); ?>**.</div>
            <?php elseif (!empty($movimientos_producto)): ?>
                <h3>Historial de Movimientos para: **<?php echo htmlspecialchars($producto_buscado); ?>**</h3>
                <table id="tabla_historial" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Fecha Doc.</th>
                            <th style="width: 15%;">N° Documento</th>
                            <th>Proveedor</th>
                            <th class="text-right" style="width: 10%;">Cantidad</th>
                            <th class="text-right" style="width: 15%;">Precio Unit. Compra</th>
                            <th class="text-right" style="width: 15%;">Total Línea</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos_producto as $mov): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($mov['fecha']); ?></td>
                                <td><?php echo htmlspecialchars($mov['n_documento']); ?></td>
                                <td><?php echo htmlspecialchars($mov['nombre_proveedor']); ?></td>
                                <td class="text-right"><?php echo number_format($mov['cant'], 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format($mov['p_unit'], 2, ',', '.'); ?></td>
                                <td class="text-right">$<?php echo number_format($mov['total'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
</body>
<script src="../js/global.js"></script>
</html>