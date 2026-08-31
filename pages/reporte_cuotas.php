<?php
include 'infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$id_cliente_filtro = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

$where_clauses = ["cs.estado IN ('Pendiente', 'Parcial')", "v.estado != 'Anulada'", "v.empresa_id = ?"];
$params = [$empresa_id, $empresa_id, $empresa_id];

if ($id_cliente_filtro > 0) {
    $where_clauses[] = "v.id_cliente = ?";
    $params[] = $id_cliente_filtro;
}

if (!empty($fecha_desde)) {
    $where_clauses[] = "cs.fecha_vencimiento >= ?";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $where_clauses[] = "cs.fecha_vencimiento <= ?";
    $params[] = $fecha_hasta;
}

$sql_where = implode(" AND ", $where_clauses);

$sql = "SELECT 
            cs.id,
            cs.nro_cuota,
            cs.fecha_vencimiento,
            cs.monto_original,
            cs.monto_pagado,
            cs.estado as estado_cuota,
            v.n_documento,
            v.cond_pago,
            c.apellido,
            c.nombre,
            c.telefono,
            DATEDIFF(CURDATE(), cs.fecha_vencimiento) as dias_mora
        FROM cuotas_seguimiento cs
        INNER JOIN ventas v ON cs.id_venta = v.id AND v.empresa_id = ?
        INNER JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = ?
        WHERE $sql_where
        ORDER BY cs.fecha_vencimiento ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lista_clientes = $pdo->query("SELECT id, CONCAT(apellido, ', ', nombre) as nombre_completo FROM clientes WHERE empresa_id = " . (int)$empresa_id . " ORDER BY apellido ASC")->fetchAll();

$total_pendiente = 0;
$total_vencido = 0;
foreach ($cuotas as $c) {
    $saldo = $c['monto_original'] - $c['monto_pagado'];
    $total_pendiente += $saldo;
    if ($c['dias_mora'] > 0) {
        $total_vencido += $saldo;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuentas a Cobrar | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/reportes.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Cuentas a Cobrar (Financiación)</h1>

        <!-- Panel de Filtros -->
        <div class="filter-card">
            <form method="GET" class="filter-grid">
                <div>
                    <label>Filtrar por Cliente:</label>
                    <select name="id_cliente" class="input-field" style="margin-bottom:0 !important;">
                        <option value="0">-- Todos los clientes --</option>
                        <?php foreach($lista_clientes as $lc): ?>
                            <option value="<?php echo $lc['id']; ?>" <?php echo ($id_cliente_filtro == $lc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lc['nombre_completo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Vencimiento Desde:</label>
                    <input type="date" name="fecha_desde" class="input-field" value="<?php echo $fecha_desde; ?>" style="margin-bottom:0 !important;">
                </div>
                <div>
                    <label>Vencimiento Hasta:</label>
                    <input type="date" name="fecha_hasta" class="input-field" value="<?php echo $fecha_hasta; ?>" style="margin-bottom:0 !important;">
                </div>
                <div style="display: flex; gap: 5px;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 20px;"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="reporte_cuotas.php" class="btn btn-secondary" style="padding: 10px 15px; background: #444;" title="Limpiar Filtros"><i class="fas fa-sync"></i></a>
                </div>
            </form>
        </div>

        <div class="resumen-cuotas">
            <div class="widget-cuotas">
                <h3>Total Pendiente de Cobro</h3>
                <p>$ <?php echo number_format($total_pendiente, 2, ',', '.'); ?></p>
            </div>
            <div class="widget-cuotas" style="border-left: 5px solid #ff5252;">
                <h3>Total Vencido (Mora)</h3>
                <p style="color: #ff5252;">$ <?php echo number_format($total_vencido, 2, ',', '.'); ?></p>
            </div>
        </div>

        <div class="card">
            <table class="table-full">
                <thead>
                    <tr>
                        <th>Vencimiento</th>
                        <th>Cliente</th>
                        <th>Venta</th>
                        <th>Cuota</th>
                        <th style="text-align: right;">Saldo</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cuotas)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: #666;">No hay cuotas pendientes de cobro.</td></tr>
                    <?php else: ?>
                        <?php foreach ($cuotas as $c): 
                            $saldo = $c['monto_original'] - $c['monto_pagado'];
                            $es_vencida = ($c['dias_mora'] > 0);
                        ?>
                        <tr>
                            <td class="<?php echo $es_vencida ? 'cuota-vencida' : ''; ?>">
                                <?php echo date('d/m/Y', strtotime($c['fecha_vencimiento'])); ?>
                                <?php if($es_vencida): ?>
                                    <br><span class="mora-badge"><?php echo $c['dias_mora']; ?> días de mora</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($c['apellido'] . ', ' . $c['nombre']); ?></strong>
                                <br><small style="color: #777;"><?php echo $c['telefono']; ?></small>
                            </td>
                            <td>#<?php echo str_pad($c['n_documento'], 8, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo $c['nro_cuota']; ?></td>
                            <td style="text-align: right; font-weight: bold;">
                                $ <?php echo number_format($saldo, 2, ',', '.'); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo ($c['estado_cuota'] == 'Parcial') ? 'badge-warning' : 'badge-danger'; ?>">
                                    <?php echo $c['estado_cuota']; ?></span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <a href="cobro_cuotas.php?id_venta=<?php echo $c['n_documento']; ?>" class="btn btn-primary btn-sm" title="Ir a Cobrar">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </a>
                                    <?php if ($c['telefono']): 
                                        $mensaje_wa = "Hola " . $c['nombre'] . ", te recordamos que tenés una cuota vencida de $" . number_format($saldo, 2) . " en " . $nombre_empresa_sistema . ". Venta #" . $c['n_documento'];
                                        $link_wa = "https://wa.me/" . preg_replace('/[^0-9]/', '', $c['telefono']) . "?text=" . urlencode($mensaje_wa);
                                    ?>
                                        <a href="<?php echo $link_wa; ?>" target="_blank" class="btn-whatsapp" title="Enviar Recordatorio">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>