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
$totales = ['venta' => 0, 'costo' => 0, 'ganancia' => 0, 'pagar_proveedor' => 0, 'mi_utilidad' => 0];

if ($prov_sel) {
    try {
        // Solo productos efectivamente EN CONSIGNACIÓN de ese proveedor
        // (los comprados normalmente al mismo proveedor no entran en la liquidación).
        $sql = "SELECT 
                    vd.cod_prod, 
                    vd.descripcion, 
                    SUM(vd.cant) as total_cant,
                    vd.p_unit as precio_venta,
                    COALESCE(p.p_compra, 0) as costo_unitario,
                    COALESCE(p.comision_proveedor, 50) as comision,
                    SUM(vd.total) as subtotal_venta,
                    SUM(COALESCE(p.p_compra, 0) * vd.cant) as subtotal_costo,
                    SUM(vd.total - (COALESCE(p.p_compra, 0) * vd.cant)) as ganancia_total
                FROM ventas_detalle vd
                JOIN ventas v ON vd.n_documento = v.n_documento AND v.empresa_id = :empresa_id1
                JOIN productos p ON vd.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci AND p.empresa_id = :empresa_id2
                WHERE v.estado = 'Finalizada' 
                  AND p.es_consignacion = 1
                  AND TRIM(p.proveedor) = :proveedor
                  AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta
                GROUP BY vd.cod_prod, vd.descripcion, vd.p_unit, p.p_compra, p.comision_proveedor
                ORDER BY vd.descripcion ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id, ':proveedor' => $prov_sel, ':desde' => $desde, ':hasta' => $hasta]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resultados as $r) {
            $totales['venta'] += $r['subtotal_venta'];
            $totales['costo'] += $r['subtotal_costo'];
            $totales['ganancia'] += $r['ganancia_total'];
            $totales['pagar_proveedor'] += $r['subtotal_costo'] + ($r['ganancia_total'] * $r['comision'] / 100);
            $totales['mi_utilidad'] += $r['ganancia_total'] * (1 - $r['comision'] / 100);
        }

        // Stock pendiente en el local (todas las sucursales) de productos consignados de ese proveedor
        $stmt_pend = $pdo->prepare(
            "SELECT COUNT(DISTINCT p.id) as productos,
                    COALESCE(SUM(s.stock_actual), 0) as unidades,
                    COALESCE(SUM(s.stock_actual * p.p_compra), 0) as valor_costo
             FROM productos p
             LEFT JOIN stocks s ON s.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci
             WHERE p.empresa_id = :empresa_id
               AND p.es_consignacion = 1
               AND TRIM(p.proveedor) = :proveedor"
        );
        $stmt_pend->execute([':empresa_id' => $empresa_id, ':proveedor' => $prov_sel]);
        $pendiente = $stmt_pend->fetch(PDO::FETCH_ASSOC) ?: ['productos' => 0, 'unidades' => 0, 'valor_costo' => 0];
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
        <h1><i class="fas fa-handshake"></i> Reporte de Consignación</h1>

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
                <div class="mini-card" style="border-top-color: #f39c12;">
                    <h4>A Pagar al Proveedor</h4>
                    <span class="monto">$ <?php echo number_format($totales['pagar_proveedor'], 2, ',', '.'); ?></span>
                </div>
                <div class="mini-card" style="border-top-color: #9b59b6;">
                    <h4>Stock Pendiente en Local</h4>
                    <span class="monto"><?php echo number_format($pendiente['unidades'], 0, ',', '.'); ?></span>
                    <small style="color: #888;"><?php echo number_format($pendiente['productos'], 0); ?> productos · valor costo $ <?php echo number_format($pendiente['valor_costo'], 2, ',', '.'); ?></small>
                </div>
            </div>

            <div class="reparto-box">
                <div class="reparto-item">
                    <h4>Total a Pagar al Proveedor</h4>
                    <span style="color: #f39c12;">$ <?php echo number_format($totales['pagar_proveedor'], 2, ',', '.'); ?></span>
                    <p style="margin-top: 10px; font-size: 0.9em; color: #aaa;">
                        Costo de Mercadería: <strong>$ <?php echo number_format($totales['costo'], 2, ',', '.'); ?></strong><br>
                        Comisión Ganancia Proveedor: <strong>$ <?php echo number_format($totales['pagar_proveedor'] - $totales['costo'], 2, ',', '.'); ?></strong>
                    </p>
                </div>
                <div style="border-left: 1px solid #333;"></div>
                <div class="reparto-item">
                    <h4>Utilidad Neta para el Negocio</h4>
                    <span style="color: #00bcd4;">$ <?php echo number_format($totales['mi_utilidad'], 2, ',', '.'); ?></span>
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
                            <th class="text-right" style="color: #00bcd4;">Mi Parte</th>
                            <th class="text-right" style="color: #f1c40f;">Prov.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $r): 
                            $comision = (float)$r['comision'];
                            $mi_parte = $r['ganancia_total'] * (1 - $comision / 100);
                            $parte_prov = $r['ganancia_total'] * $comision / 100;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['descripcion']); ?></strong><br><small><?php echo $r['cod_prod']; ?></small></td>
                            <td class="text-right"><?php echo number_format($r['total_cant'], 2); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['precio_venta'], 2, ',', '.'); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['costo_unitario'], 2, ',', '.'); ?></td>
                            <td class="text-right">$ <?php echo number_format($r['subtotal_venta'], 2, ',', '.'); ?></td>
                            <td class="text-right" style="font-weight: bold;">$ <?php echo number_format($r['ganancia_total'], 2, ',', '.'); ?></td>
                            <td class="text-right" style="color: #2ecc71;">$ <?php echo number_format($mi_parte, 2, ',', '.'); ?></td>
                            <td class="text-right" style="color: #f39c12;">$ <?php echo number_format($parte_prov, 2, ',', '.'); ?> <small style="color:#888;">(<?php echo number_format($comision, 0); ?>%)</small></td>
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
                <button class="btn btn-secondary" onclick="window.open('generar_pdf_consignacion.php?proveedor=<?php echo urlencode($prov_sel); ?>&desde=<?php echo urlencode($desde); ?>&hasta=<?php echo urlencode($hasta); ?>', '_blank')"><i class="fas fa-print"></i> Imprimir Liquidación</button>
            </div>

            <?php if ($totales['venta'] > 0): ?>
            <div class="card" style="margin-top: 20px; border-top: 3px solid #f39c12;">
                <h3 style="margin-top: 0;"><i class="fas fa-cash-register"></i> Registrar Liquidación</h3>
                <p style="color: #aaa; font-size: 0.9em;">
                    Registra el pago al proveedor por las ventas del período consultado
                    (<strong>$ <?php echo number_format($totales['pagar_proveedor'], 2, ',', '.'); ?></strong>)
                    y genera el egreso correspondiente en la caja.
                </p>
                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label>Método de Pago</label>
                        <select id="liq_metodo" class="input-field">
                            <option value="EFECTIVO">EFECTIVO</option>
                            <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary" style="background-color: #f39c12; height: 40px;" id="btnLiquidar" onclick="liquidarConsignacion()">
                        💸 Pagar y Registrar Liquidación
                    </button>
                </div>
                <div id="liq_resultado" style="margin-top: 15px; display: none;"></div>
            </div>
            <?php endif; ?>
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

    <script>
    function liquidarConsignacion() {
        if (!confirm('¿Confirmás la liquidación al proveedor "<?php echo htmlspecialchars($prov_sel); ?>" por $ <?php echo number_format($totales['pagar_proveedor'], 2, ',', '.'); ?> del período <?php echo date('d/m/Y', strtotime($desde)); ?> - <?php echo date('d/m/Y', strtotime($hasta)); ?>?')) {
            return;
        }
        const btn = document.getElementById('btnLiquidar');
        const res = document.getElementById('liq_resultado');
        btn.disabled = true;
        btn.textContent = 'Procesando...';
        res.style.display = 'none';

        const body = new URLSearchParams({
            proveedor: <?php echo json_encode($prov_sel); ?>,
            desde: <?php echo json_encode($desde); ?>,
            hasta: <?php echo json_encode($hasta); ?>,
            metodo_pago: document.getElementById('liq_metodo').value
        });

        fetch('<?php echo URL_BASE; ?>ajax/liquidar_consignacion.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '💸 Pagar y Registrar Liquidación';
            res.style.display = 'block';
            if (data.success) {
                res.className = 'alert alert-success';
                res.innerHTML = '✅ ' + data.mensaje;
                setTimeout(() => { window.location.reload(); }, 2500);
            } else {
                res.className = 'alert alert-error';
                res.innerHTML = '❌ ' + (data.error || 'Error desconocido');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '💸 Pagar y Registrar Liquidación';
            res.style.display = 'block';
            res.className = 'alert alert-error';
            res.innerHTML = '❌ Error de conexión: ' + err.message;
        });
    }
    </script>
</body>
</html>