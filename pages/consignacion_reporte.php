<?php
include 'infosesion.php';
require_once '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Cargar lista de proveedores
$proveedores = [];
try {
    $stmt_p = $pdo->prepare("SELECT DISTINCT TRIM(proveedor) as proveedor_nombre FROM productos WHERE empresa_id = :empresa_id AND proveedor IS NOT NULL AND TRIM(proveedor) != '' ORDER BY proveedor_nombre ASC");
    $stmt_p->execute([':empresa_id' => $empresa_id]);
    $proveedores = $stmt_p->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error cargando proveedores: " . $e->getMessage());
}

// 2. Parámetros de búsqueda
$prov_sel = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$desde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
$hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

$resultados = [];
$totales = ['venta' => 0, 'costo' => 0, 'ganancia' => 0];

if ($prov_sel) {
    try {
        $sql = "SELECT 
                    vd.cod_prod, 
                    vd.descripcion, 
                    SUM(vd.cant) as total_cant,
                    vd.p_unit as precio_venta,
                    COALESCE(p.p_compra, 0) as costo_unitario,
                    SUM(vd.total) as subtotal_venta,
                    SUM(COALESCE(p.p_compra, 0) * vd.cant) as subtotal_costo,
                    SUM(vd.total - (COALESCE(p.p_compra, 0) * vd.cant)) as ganancia_total
                FROM ventas_detalle vd
                JOIN ventas v ON vd.n_documento = v.n_documento AND v.empresa_id = :empresa_id1
                JOIN productos p ON vd.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci AND p.empresa_id = :empresa_id2
                WHERE v.estado = 'Finalizada' 
                  AND TRIM(p.proveedor) = :proveedor
                  AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta
                GROUP BY vd.cod_prod, vd.descripcion, vd.p_unit, p.p_compra
                ORDER BY vd.descripcion ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id, ':proveedor' => $prov_sel, ':desde' => $desde, ':hasta' => $hasta]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resultados as $r) {
            $totales['venta'] += $r['subtotal_venta'];
            $totales['costo'] += $r['subtotal_costo'];
            $totales['ganancia'] += $r['ganancia_total'];
        }
    } catch (Exception $e) {
        $mensaje_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Consignación | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/consignaciones.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1><i class="fas fa-handshake"></i> Reporte de Consignación (50/50)</h1>

        <div class="card">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label>Proveedor en Consignación:</label>
                    <select name="proveedor" class="input-field" required>
                        <option value="">-- Seleccionar Proveedor --</option>
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($prov_sel === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Desde:</label>
                    <input type="date" name="desde" class="input-field" value="<?php echo $desde; ?>">
                </div>
                <div>
                    <label>Hasta:</label>
                    <input type="date" name="hasta" class="input-field" value="<?php echo $hasta; ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 40px; margin-bottom: 15px;">Consultar</button>
            </form>
        </div>

        <?php if ($prov_sel): ?>
            <div class="resumen-consignacion">
                <div class="mini-card">
                    <h4>Ventas Totales</h4>
                    <span class="monto">$ <?php echo number_format($totales['venta'], 2, ',', '.'); ?></span>
                </div>
                <div class="mini-card" style="border-top-color: #e74c3c;">
                    <h4>Costo de Mercadería</h4>
                    <span class="monto">$ <?php echo number_format($totales['costo'], 2, ',', '.'); ?></span>
                </div>
                <div class="mini-card" style="border-top-color: #2ecc71;">
                    <h4>Ganancia Líquida</h4>
                    <span class="monto">$ <?php echo number_format($totales['ganancia'], 2, ',', '.'); ?></span>
                </div>
            </div>

            <div class="reparto-box">
                <div class="reparto-item">
                    <h4>Total a Pagar al Proveedor</h4>
                    <span style="color: #f39c12;">$ <?php echo number_format($totales['costo'] + ($totales['ganancia'] / 2), 2, ',', '.'); ?></span>
                    <p style="margin-top: 10px; font-size: 0.9em; color: #aaa;">
                        Costo de Mercadería: <strong>$ <?php echo number_format($totales['costo'], 2, ',', '.'); ?></strong><br>
                        50% Ganancia Proveedor: <strong>$ <?php echo number_format($totales['ganancia'] / 2, 2, ',', '.'); ?></strong>
                    </p>
                </div>
                <div style="border-left: 1px solid #333;"></div>
                <div class="reparto-item">
                    <h4>Utilidad Neta para el Negocio</h4>
                    <span style="color: #00bcd4;">$ <?php echo number_format($totales['ganancia'] / 2, 2, ',', '.'); ?></span>
                </div>
            </div>

            <div class="card">
                <table class="table-full">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-right">Cant.</th>
                            <th class="text-right">Venta Unit.</th>
                            <th class="text-right">Costo Unit.</th>
                            <th class="text-right">Venta Total</th>
                            <th class="text-right">Ganancia Bruta</th>
                            <th class="text-right" style="color: #00bcd4;">Mi Parte (50%)</th>
                            <th class="text-right" style="color: #f1c40f;">Prov. (50%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $r): 
                            $ganancia_mitad = $r['ganancia_total'] / 2;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['descripcion']); ?></strong><br><small><?php echo $r['cod_prod']; ?></small></td>
                            <td class="text-right"><?php echo number_format($r['total_cant'], 2); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['precio_venta'], 2, ',', '.'); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['costo_unitario'], 2, ',', '.'); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['subtotal_venta'], 2, ',', '.'); ?></td>
                            <td class="text-right" style="font-weight: bold;">$ <?php echo number_format($r['ganancia_total'], 2, ',', '.'); ?></td>
                            <td class="text-right" style="color: #2ecc71;">$ <?php echo number_format($ganancia_mitad, 2, ',', '.'); ?></td>
                            <td class="text-right" style="color: #f39c12;">$ <?php echo number_format($ganancia_mitad, 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($resultados)): ?>
                            <tr><td colspan="8" style="text-align: center; padding: 30px;">No se registraron ventas de este proveedor en el rango seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: flex-end;">
                <a href="generar_pdf_consignacion.php?proveedor=<?php echo urlencode($prov_sel); ?>&desde=<?php echo urlencode($desde); ?>&hasta=<?php echo urlencode($hasta); ?>" class="btn btn-danger" target="_blank"><i class="fas fa-file-pdf"></i> Generar PDF</a>
                <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Liquidación</button>
            </div>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 50px; color: #666;">
                <i class="fas fa-search" style="font-size: 3rem; margin-bottom: 15px;"></i>
                <p>Seleccione un proveedor y un rango de fechas para visualizar la liquidación.</p>
            </div>
        <?php endif; ?>
        <?php if (isset($mensaje_error)): ?>
            <div class="alert alert-error" style="margin-top: 20px;">❌ Error al generar el reporte: <?php echo htmlspecialchars($mensaje_error); ?></div>
        <?php endif; ?>
    </div>
</body>
</html>