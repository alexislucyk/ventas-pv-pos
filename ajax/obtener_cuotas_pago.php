<?php
include '../pages/infosesion.php';

// VALIDACIÓN DE PERMISOS
if (!tiene_permiso('pages/cobro_cuotas.php')) {
    exit("<p style='padding:20px; color:red;'>Acceso denegado.</p>");
}

$id_cliente_raw = $_GET['id_cliente'] ?? '0';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$params = [];
$where_clause = "";
$is_all = ($id_cliente_raw === 'all');

if ($is_all) {
    $where_clause = "WHERE 1=1"; // No filtrar por estado de venta aquí
} else {
    $id_cliente = (int)$id_cliente_raw;
    if ($id_cliente <= 0) exit("<p style='padding:20px; text-align:center; color:#aaa;'>Seleccione un cliente.</p>");
    $where_clause = "WHERE v.id_cliente = ?"; // Filtrar solo por cliente
    $params[] = $id_cliente;
}

if (!empty($desde) && !empty($hasta)) {
    $where_clause .= " AND DATE(v.fecha_venta) BETWEEN ? AND ?";
    $params[] = $desde;
    $params[] = $hasta;
}

try {
    $sql = "SELECT 
                v.id, v.n_documento, v.fecha_venta, 
                v.estado AS estado_venta, /* Añadimos el estado de la venta */
                vf.cant_cuotas, vf.interes_porcentaje, vf.monto_interes,
                CONCAT(c.apellido, ', ', c.nombre) as nombre_cliente,
                (SELECT COALESCE(SUM(monto_original), 0) FROM cuotas_seguimiento WHERE id_venta = v.id AND estado != 'Anulada') as total_financiado,
                (SELECT COALESCE(SUM(monto_original - monto_pagado), 0) FROM cuotas_seguimiento WHERE id_venta = v.id AND estado != 'Anulada') as saldo_total
            FROM ventas v
            JOIN ventas_financiacion vf ON v.id = vf.id_venta
            JOIN clientes c ON v.id_cliente = c.id
            $where_clause
            ORDER BY v.fecha_venta DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll();

    if (!$ventas) {
        exit("<p style='padding:20px; text-align:center; color:#aaa;'>No se encontraron créditos registrados.</p>");
    }

    echo '<table class="table-full">
            <thead>
                <tr>
                    <th>Fecha</th>';
    if ($is_all) echo '<th>Cliente</th>';
    echo '          <th>Documento</th>
                    <th class="text-right">Capital</th>
                    <th class="text-right">Interés (%)</th>
                    <th class="text-right">Monto Interés</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Saldo Deudor</th>
                    <th class="text-center">Estado Venta</th>
                    <th class="text-center">Estado Cuotas</th>
                    <th class="text-center">Detalle</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($ventas as $v) {
        $capital = $v['total_financiado'] - $v['monto_interes'];
        $clase_saldo = '';
        $badge_cuotas = '';
        $badge_venta = '';
        $btn_disabled = '';

        if ($v['estado_venta'] === 'Anulada') {
            $badge_venta = '<span class="badge badge-danger">Anulada</span>';
            $badge_cuotas = '<span class="badge badge-danger">Anuladas</span>'; // Si la venta está anulada, sus cuotas también lo están
            $v['saldo_total'] = 0; // El saldo de una venta anulada es 0
            $btn_disabled = 'disabled'; // Deshabilitar el botón de ver cuotas
        } else {
            $badge_venta = '<span class="badge badge-success">Activa</span>'; // Asumimos 'Finalizada' o similar
            $esta_pago = ($v['saldo_total'] <= 0.05); // Tolerancia por decimales
            $badge_cuotas = $esta_pago ? '<span class="badge badge-success">Saldado</span>' : '<span class="badge badge-warning">Pendiente</span>';
            $clase_saldo = $esta_pago ? '' : 'saldo-destacado';
        }
        
        echo "<tr>
                <td>" . date('d/m/Y', strtotime($v['fecha_venta'])) . "</td>";
        if ($is_all) echo "<td>" . htmlspecialchars($v['nombre_cliente']) . "</td>";
        echo "  <td><b>#{$v['n_documento']}</b></td>
                <td class='text-right'>$" . number_format($capital, 2, ',', '.') . "</td>
                <td class='text-right'>{$v['interes_porcentaje']}%</td>
                <td class='text-right' style='color:#e67e22;'>$" . number_format($v['monto_interes'], 2, ',', '.') . "</td>
                <td class='text-right'>$" . number_format($v['total_financiado'], 2, ',', '.') . "</td>
                <td class='text-right $clase_saldo'>$" . number_format($v['saldo_total'], 2, ',', '.') . "</td>
                <td class='text-center'>$badge_venta</td>
                <td class='text-center'>$badge_cuotas</td>
                <td class='text-center'>
                    <button class='btn btn-primary btn-sm' onclick='verDetalleCuotas({$v['id']}, \"{$v['n_documento']}\")' $btn_disabled>
                        <i class='fas fa-list'></i> Ver Cuotas
                    </button>
                </td>
              </tr>";
    }
    echo '</tbody></table>';
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }