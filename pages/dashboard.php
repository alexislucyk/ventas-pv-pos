<?php
include 'infosesion.php';
require_once __DIR__ . '/../core/helpers.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$hoy = date('Y-m-d');
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre'] ?? '');
$rol = htmlspecialchars($_SESSION['usuario_rol'] ?? '');

// Período seleccionado para el dashboard (Hoy / 7d / 30d / 90d / rango libre)
$periodo = strtolower($_GET['periodo'] ?? '7d');
$periodos_validos = ['hoy', '7d', '30d', '90d', 'rango'];
if (!in_array($periodo, $periodos_validos)) {
    $periodo = '7d';
}

$desde = $hoy;
$hasta = $hoy;
$titulo_periodo = 'Hoy';
switch ($periodo) {
    case 'hoy':
        $desde = $hoy; $hasta = $hoy;
        $titulo_periodo = 'Hoy';
        break;
    case '7d':
        $desde = date('Y-m-d', strtotime('-6 days')); $hasta = $hoy;
        $titulo_periodo = 'Últimos 7 días';
        break;
    case '30d':
        $desde = date('Y-m-d', strtotime('-29 days')); $hasta = $hoy;
        $titulo_periodo = 'Últimos 30 días';
        break;
    case '90d':
        $desde = date('Y-m-d', strtotime('-89 days')); $hasta = $hoy;
        $titulo_periodo = 'Últimos 90 días';
        break;
}

// Rango libre (desde / hasta) elegido por el usuario
if ($periodo === 'rango') {
    $fDesde = $_GET['desde'] ?? '';
    $fHasta = $_GET['hasta'] ?? '';
    $es_fecha = function ($v) {
        return is_string($v) && preg_match('#^\d{4}-\d{2}-\d{2}$#', $v) && strtotime($v) !== false;
    };
    if ($es_fecha($fDesde) && $es_fecha($fHasta)) {
        $desde = $fDesde;
        $hasta = $fHasta;
        if ($desde > $hasta) { list($desde, $hasta) = array($hasta, $desde); }
        $titulo_periodo = 'Rango personalizado';
    } else {
        // Valores inválidos => vuelve a 7 días
        $periodo = '7d';
        $desde = date('Y-m-d', strtotime('-6 days'));
        $hasta = $hoy;
        $titulo_periodo = 'Últimos 7 días';
    }
}

$vencimientos_prov = [];

// Valores por defecto para que no haya notices si la consulta DB falla
$total_contado = 0;
$total_ctacte = 0;
$cant_ventas = 0;
$stock_critico = 0;
$presup_pendientes = 0;
$ticket_promedio = 0;
$suma_semana = 0;
$suma_semana_ant = 0;
$var_semana_pct = 0;
$labels_semana = [];
$ventas_semana = [];
$ventas_semana_ant = [];
$donut_labels = [];
$donut_values = [];
$mostrar_serie_ant = false;
$items_vendidos = 0;
$deuda_cc_clientes = 0;
$cobros_periodo = 0;

try {
    $sql_efectivo = "SELECT SUM(total_venta) as total FROM ventas
                     WHERE DATE(fecha_venta) BETWEEN ? AND ?
                     AND estado != 'Anulada'
                     AND (cond_pago = 'Contado' OR cond_pago = 'Transferencia')
                     AND empresa_id = ?";
    $stmt_efectivo = $pdo->prepare($sql_efectivo);
    $stmt_efectivo->execute([$desde, $hasta, $empresa_id]);
    $result = $stmt_efectivo->fetch(PDO::FETCH_ASSOC);
    $total_contado = isset($result['total']) ? $result['total'] : 0;

    $sql_ctacte = "SELECT SUM(total_venta) as total FROM ventas
                   WHERE DATE(fecha_venta) BETWEEN ? AND ?
                   AND estado != 'Anulada'
                   AND cond_pago = 'Cuenta Corriente'
                   AND empresa_id = ?";
    $stmt_ctacte = $pdo->prepare($sql_ctacte);
    $stmt_ctacte->execute([$desde, $hasta, $empresa_id]);
    $result = $stmt_ctacte->fetch(PDO::FETCH_ASSOC);
    $total_ctacte = isset($result['total']) ? $result['total'] : 0;

    $sql_cant = "SELECT COUNT(*) as cantidad FROM ventas WHERE DATE(fecha_venta) BETWEEN ? AND ? AND estado != 'Anulada' AND empresa_id = ?";
    $stmt_cant = $pdo->prepare($sql_cant);
    $stmt_cant->execute([$desde, $hasta, $empresa_id]);
    $result = $stmt_cant->fetch(PDO::FETCH_ASSOC);
    $cant_ventas = isset($result['cantidad']) ? $result['cantidad'] : 0;

    $sql_stock = "SELECT COUNT(*) FROM productos WHERE stock <= 2 AND empresa_id = ?";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([$empresa_id]);
    $stock_critico = $stmt_stock->fetchColumn();

    $sql_presup = "SELECT COUNT(*) FROM presupuestos WHERE DATE(fecha_presupuesto) = ? AND estado = 'Pendiente' AND empresa_id = ?";
    $stmt_presup = $pdo->prepare($sql_presup);
    $stmt_presup->execute([$hoy, $empresa_id]);
    $presup_pendientes = $stmt_presup->fetchColumn();

    $sql_top = "SELECT vd.cod_prod, vd.descripcion, SUM(vd.cant) as total_cant, p.stock 
                FROM ventas_detalle vd
                JOIN ventas v ON (vd.n_documento = v.n_documento)
                JOIN productos p ON (vd.cod_prod = p.cod_prod COLLATE utf8mb4_unicode_ci)
                WHERE v.estado = 'Finalizada' COLLATE utf8mb4_unicode_ci
                  AND v.empresa_id = :empresa_id
                GROUP BY vd.cod_prod, vd.descripcion, p.stock
                ORDER BY total_cant DESC
                LIMIT 10";
    $stmt_top = $pdo->prepare($sql_top);
    $stmt_top->execute([':empresa_id' => $empresa_id]);
    $top_productos = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

    $stmt_ult = $pdo->prepare("SELECT n_documento, total_venta, cond_pago, usuario FROM ventas WHERE estado = 'Finalizada' AND empresa_id = ? ORDER BY id DESC LIMIT 5");
    $stmt_ult->execute([$empresa_id]);
    $ultimas_ventas = $stmt_ult->fetchAll(PDO::FETCH_ASSOC);

    // 8. Flujo de ventas en el período seleccionado.
    //    - Para "Hoy": agrupa por hora del día.
    //    - Para el resto: agrupa por día del rango.
    $labels_semana = [];
    $ventas_semana = [];
    $ventas_semana_ant = [];
    $agrupar_por_hora = ($periodo === 'hoy');
    $mostrar_serie_ant = in_array($periodo, ['7d', '30d', '90d']);

    // Consulta del flujo del período actual
    if ($agrupar_por_hora) {
        $stmt_flujo = $pdo->prepare("SELECT HOUR(fecha_venta) as hh, SUM(total_venta) as total
                                     FROM ventas
                                     WHERE DATE(fecha_venta) = ? AND estado != 'Anulada' AND empresa_id = ?
                                     GROUP BY HOUR(fecha_venta)");
        $stmt_flujo->execute([$hasta, $empresa_id]);
        $mapa_flujo = [];
        foreach ($stmt_flujo->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $mapa_flujo[(int)$fila['hh']] = (float)$fila['total'];
        }
        for ($h = 0; $h <= 23; $h++) {
            $labels_semana[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $ventas_semana[] = isset($mapa_flujo[$h]) ? $mapa_flujo[$h] : 0;
        }
    } else {
        $stmt_flujo = $pdo->prepare("SELECT DATE(fecha_venta) as d, SUM(total_venta) as total
                                     FROM ventas
                                     WHERE DATE(fecha_venta) BETWEEN ? AND ? AND estado != 'Anulada' AND empresa_id = ?
                                     GROUP BY DATE(fecha_venta)");
        $stmt_flujo->execute([$desde, $hasta, $empresa_id]);
        $mapa_flujo = [];
        foreach ($stmt_flujo->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $mapa_flujo[$fila['d']] = (float)$fila['total'];
        }
        $cursor = $desde;
        while ($cursor <= $hasta) {
            $labels_semana[] = date('d/m', strtotime($cursor));
            $ventas_semana[] = isset($mapa_flujo[$cursor]) ? $mapa_flujo[$cursor] : 0;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
    }

    // Período anterior equivalente (para la comparativa)
    $desde_ant = $desde;
    $hasta_ant = $hasta;
    switch ($periodo) {
        case 'hoy':
            $desde_ant = date('Y-m-d', strtotime('-1 day')); $hasta_ant = $desde_ant;
            break;
        case '7d':
            $desde_ant = date('Y-m-d', strtotime('-13 days')); $hasta_ant = date('Y-m-d', strtotime('-7 days'));
            break;
        case '30d':
            $desde_ant = date('Y-m-d', strtotime('-59 days')); $hasta_ant = date('Y-m-d', strtotime('-30 days'));
            break;
        case '90d':
            $desde_ant = date('Y-m-d', strtotime('-179 days')); $hasta_ant = date('Y-m-d', strtotime('-90 days'));
            break;
        case 'rango':
            $dias = (int)floor((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
            $desde_ant = date('Y-m-d', strtotime($desde . " -{$dias} days"));
            $hasta_ant = date('Y-m-d', strtotime($desde . ' -1 day'));
            break;
    }

    $stmt_ant = $pdo->prepare("SELECT DATE(fecha_venta) as d, SUM(total_venta) as total
                               FROM ventas
                               WHERE DATE(fecha_venta) BETWEEN ? AND ? AND estado != 'Anulada' AND empresa_id = ?
                               GROUP BY DATE(fecha_venta)");
    $stmt_ant->execute([$desde_ant, $hasta_ant, $empresa_id]);
    $mapa_ant = [];
    foreach ($stmt_ant->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $mapa_ant[$fila['d']] = (float)$fila['total'];
    }

    // Segunda serie alineada por día (solo 7d y 30d, donde tiene sentido índice a índice)
    if ($mostrar_serie_ant && !$agrupar_por_hora) {
        $cursor = $desde_ant;
        while ($cursor <= $hasta_ant) {
            $ventas_semana_ant[] = isset($mapa_ant[$cursor]) ? $mapa_ant[$cursor] : 0;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
    }

    $suma_semana      = array_sum($ventas_semana);
    $suma_semana_ant  = array_sum($mapa_ant);
    $var_semana_pct   = $suma_semana_ant > 0 ? (($suma_semana - $suma_semana_ant) / $suma_semana_ant) * 100 : 0;

    // 9. Próximos Vencimientos a Proveedores (Solo facturas a CRÉDITO con saldo pendiente)
    $sql_vencimientos = "SELECT 
                            c.n_documento, 
                            p.razon as proveedor, 
                            c.fecha_vencimiento, 
                            c.total_compra,
                            (c.total_compra - COALESCE(SUM(cc.debe), 0)) as saldo
                         FROM compras c
                         JOIN proveedores p ON c.cod_proveedor = p.cod_prov
                         LEFT JOIN ctacte_proveedores cc ON c.n_documento = cc.n_documento AND c.cod_proveedor = cc.id_proveedor AND cc.empresa_id = ?
                         WHERE c.cond_pago = 'CRÉDITO'
                           AND c.empresa_id = ?
                         GROUP BY c.id, c.n_documento, p.razon, c.fecha_vencimiento, c.total_compra
                         HAVING saldo > 0
                         ORDER BY c.fecha_vencimiento ASC
                         LIMIT 10";
    $stmt_venc = $pdo->prepare($sql_vencimientos);
    $stmt_venc->execute([$empresa_id, $empresa_id]);
    $vencimientos_prov = $stmt_venc->fetchAll(PDO::FETCH_ASSOC);

    // 10. Deuda a Proveedores Vencida
    // Calculamos el saldo de cada proveedor y restamos las facturas que aún no vencieron.
    $sql_vencido_prov = "
        SELECT SUM(CASE WHEN (saldo_prov - COALESCE(no_vencido_prov, 0)) > 0 
                        THEN (saldo_prov - COALESCE(no_vencido_prov, 0)) 
                        ELSE 0 END) as total_vencido
        FROM (
            SELECT 
                cp.id_proveedor,
                SUM(cp.haber - cp.debe) as saldo_prov,
                (SELECT SUM(c.total_compra) FROM compras c 
                 WHERE c.cod_proveedor = cp.id_proveedor 
                 AND c.cond_pago = 'CRÉDITO' 
                 AND (c.fecha_vencimiento >= ? OR c.fecha_vencimiento IS NULL)
                 AND c.empresa_id = ?
                ) as no_vencido_prov
            FROM ctacte_proveedores cp
            WHERE cp.empresa_id = ?
            GROUP BY cp.id_proveedor
        ) as calculo";
    $stmt_v_prov = $pdo->prepare($sql_vencido_prov);
    $stmt_v_prov->execute([$hoy, $empresa_id, $empresa_id]);
    $deuda_vencida_prov = (float)($stmt_v_prov->fetchColumn() ?: 0);

    // 11. Ticket promedio del día
    $total_dia = $total_contado + $total_ctacte;
    $ticket_promedio = $cant_ventas > 0 ? $total_dia / $cant_ventas : 0;

    // 12. Desglose de ventas del día por método de pago (gráfico donut)
    $stmt_pagos = $pdo->prepare("SELECT cond_pago, SUM(total_venta) as total
                                 FROM ventas
                                 WHERE DATE(fecha_venta) BETWEEN ? AND ? AND estado != 'Anulada' AND empresa_id = ?
                                 GROUP BY cond_pago");
    $stmt_pagos->execute([$desde, $hasta, $empresa_id]);
    $nom_pago = [
        'Contado'         => 'Contado',
        'Transferencia'   => 'Transferencia',
        'Cuenta Corriente' => 'Cuenta C.',
    ];
    $donut_labels = [];
    $donut_values = [];
    foreach ($stmt_pagos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $donut_labels[] = $nom_pago[$fila['cond_pago']] ?? $fila['cond_pago'];
        $donut_values[] = (float)$fila['total'];
    }

    // 13. Cantidad de ítems vendidos en el período
    $stmt_items = $pdo->prepare("SELECT COALESCE(SUM(vd.cant),0) as total
                                 FROM ventas_detalle vd
                                 JOIN ventas v ON (vd.n_documento = v.n_documento)
                                 WHERE v.estado != 'Anulada' AND v.empresa_id = ?
                                   AND DATE(v.fecha_venta) BETWEEN ? AND ?");
    $stmt_items->execute([$empresa_id, $desde, $hasta]);
    $items_vendidos = (float)$stmt_items->fetchColumn();

    // 14. Deuda pendiente de cuentas corrientes de clientes (debe - haber)
    $stmt_deuda_cc = $pdo->prepare("SELECT COALESCE(SUM(debe),0) - COALESCE(SUM(haber),0) as saldo
                                    FROM ctacte WHERE empresa_id = ?");
    $stmt_deuda_cc->execute([$empresa_id]);
    $deuda_cc_clientes = (float)$stmt_deuda_cc->fetchColumn();
    if ($deuda_cc_clientes < 0) {
        $deuda_cc_clientes = 0;
    }

    // 15. Cobros a cuenta corriente recibidos en el período (pagos de clientes)
    $stmt_cobros = $pdo->prepare("SELECT COALESCE(SUM(haber),0) as total
                                  FROM ctacte
                                  WHERE movimiento = 'Pago Cta.Cte.' AND empresa_id = ?
                                    AND DATE(fecha) BETWEEN ? AND ?");
    $stmt_cobros->execute([$empresa_id, $desde, $hasta]);
    $cobros_periodo = (float)$stmt_cobros->fetchColumn();

} catch (PDOException $e) {
    $error_db = "Error en el Dashboard: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | <?php echo $nombre_empresa_sistema; ?></title>
            <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-card: #1e1e1e;
            --accent: #00bcd4;
            --text-muted: #888;
        }

        body { 
            background-color: #121212; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: #e0e0e0;
            margin: 0;
        }

        .content { padding: 20px; }

        .welcome-banner {
            margin-bottom: 30px;
            border-left: 4px solid var(--accent);
            padding-left: 20px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 16px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #333;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.4);
            border-color: #555;
        }

        /* Iconos "Ghost" de fondo */
        .stat-card .icon-bg {
            position: absolute;
            right: -6px;
            bottom: -6px;
            font-size: 3.5rem;
            opacity: 0.08;
            color: #fff;
            transform: rotate(-10deg);
        }

        .stat-card h3 {
            color: var(--text-muted);
            font-size: 0.75rem;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 1.7rem;
            font-weight: 800;
            margin: 8px 0 4px 0;
            color: #fff;
        }

        .stat-card .footer {
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        /* Colores por categoría */
        .card-green { border-top: 4px solid #4CAF50; }
        .card-green .footer { color: #4CAF50; }

        .card-orange { border-top: 4px solid #FF9800; }
        .card-orange .footer { color: #FF9800; }

        .card-blue { border-top: 4px solid #2196F3; }
        .card-blue .footer { color: #2196F3; }

        .card-red { border-top: 4px solid #F44336; }
        .card-red .footer { color: #F44336; }

        .card-purple { border-top: 4px solid #9C27B0; }
        .card-purple .footer { color: #9C27B0; }

        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .secondary-grid { grid-template-columns: 1fr !important; }
        }

        /* Accesos Rápidos Mejorados */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .action-btn {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: var(--bg-card);
            border: 1px solid #333;
            border-radius: 12px;
            text-decoration: none;
            color: #fff;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        }
        .action-btn:hover {
            transform: translateY(-3px);
            background: #252525;
            box-shadow: 0 8px 15px rgba(0,0,0,0.4);
        }
        .action-btn i {
            font-size: 1.4rem;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .action-btn span { font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .btn-venta i { color: #4CAF50; background: rgba(76, 175, 80, 0.1); }
        .btn-venta:hover { border-color: #4CAF50; }
        .btn-cc i { color: #FF9800; background: rgba(255, 152, 0, 0.1); }
        .btn-cc:hover { border-color: #FF9800; }
        .btn-compra i { color: #2196F3; background: rgba(33, 150, 243, 0.1); }
        .btn-compra:hover { border-color: #2196F3; }
        .btn-precio i { color: var(--accent); background: rgba(0, 188, 212, 0.1); }
        .btn-precio:hover { border-color: var(--accent); }

        /* Contenedor del Gráfico y Tablas secundarias */
        .chart-container { background: var(--bg-card); padding: 25px; border-radius: 12px; border: 1px solid #333; margin: 30px 0; }
        .secondary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; margin-top: 30px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .data-table th { text-align: left; color: var(--accent); padding: 10px; border-bottom: 1px solid #333; text-transform: uppercase; font-size: 0.7rem; }
        .data-table td { padding: 10px; border-bottom: 1px solid #252525; }
        .text-accent { color: var(--accent); font-weight: bold; }

        /* Fila de gráficos: flujo semanal + donut de pagos del día */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-top: 30px;
        }
        .charts-row .chart-container { margin: 0; }
        @media (max-width: 900px) { .charts-row { grid-template-columns: 1fr; } }

        /* Selector de período */
        .period-selector {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 20px 0 25px 0;
            background: var(--bg-card);
            padding: 6px;
            border: 1px solid #333;
            border-radius: 10px;
        }
        .period-btn {
            padding: 7px 16px;
            border-radius: 7px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s ease;
        }
        .period-btn:hover { color: #fff; background: #2a2a2a; }
        .period-btn.active {
            color: #121212;
            background: var(--accent);
        }

        /* Rango personalizado desde/hasta */
        .period-rango {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 4px;
        }
        .period-rango input[type="date"] {
            width: auto !important;
            margin: 0 !important;
            padding: 5px 8px !important;
            font-size: 0.8rem;
            background: #333 !important;
            border: 1px solid #444 !important;
            color: #f0f0f0 !important;
            border-radius: 6px;
        }
        .period-rango button {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            background: var(--accent);
            color: #121212;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .period-rango button:hover { filter: brightness(1.1); }

        /* Estado de carga al cambiar de período */
        body.is-loading { pointer-events: none; }
        body.is-loading::after {
            content: '';
            position: fixed; inset: 0; z-index: 99999999;
            background: rgba(0,0,0,0.35);
        }
        body.is-loading::before {
            content: 'Cargando...';
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            z-index: 100000000; color: #121212; background: var(--accent);
            padding: 14px 22px; border-radius: 10px; font-weight: 700;
            box-shadow: 0 4px 20px rgba(0,0,0,.5);
        }

        /* Responsive móvil: accesos rápidos y rango */
        @media (max-width: 768px) {
            .quick-actions { flex-direction: column; align-items: stretch; }
            .quick-actions .action-btn { width: 100%; justify-content: flex-start; }
            .period-rango { flex-wrap: wrap; margin: 8px 0 0 0; width: 100%; }
        }
    </style>
    <style>
        /* Parche para ocultar scrollbars nativos */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #121212; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #444; }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="content">
    <?php if (isset($error_db)): ?>
        <div style="background: #b71c1c; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_db; ?>
        </div>
    <?php endif; ?>

    <div class="welcome-banner">
        <h1 style="font-weight: 300; margin: 0;">Panel <span style="color: var(--accent); font-weight: 700;">Principal</span></h1>
        <p style="color: var(--text-muted); margin: 5px 0;">Período: <strong><?php echo $titulo_periodo; ?></strong> (<?php echo date('d/m/Y', strtotime($desde)); ?> → <?php echo date('d/m/Y', strtotime($hasta)); ?>) | Usuario: <strong><?php echo $nombre_usuario; ?></strong></p>
    </div>

    <div class="period-selector">
        <a href="?periodo=hoy" class="period-btn <?php echo $periodo === 'hoy' ? 'active' : ''; ?>">Hoy</a>
        <a href="?periodo=7d" class="period-btn <?php echo $periodo === '7d' ? 'active' : ''; ?>">7 días</a>
        <a href="?periodo=30d" class="period-btn <?php echo $periodo === '30d' ? 'active' : ''; ?>">30 días</a>
        <a href="?periodo=90d" class="period-btn <?php echo $periodo === '90d' ? 'active' : ''; ?>">90 días</a>
        <form class="period-rango" id="periodRango" method="get">
            <input type="date" name="desde" value="<?php echo htmlspecialchars($periodo === 'rango' ? $desde : ''); ?>" required aria-label="Desde">
            <span style="color: var(--text-muted);">→</span>
            <input type="date" name="hasta" value="<?php echo htmlspecialchars($periodo === 'rango' ? $hasta : ''); ?>" required aria-label="Hasta">
            <button type="submit" name="periodo" value="rango">Aplicar</button>
        </form>
    </div>

    <div class="quick-actions">
                <?php if (tiene_permiso('pages/ventas.php')): ?>
        <a href="<?php echo route('ventas'); ?>" class="action-btn btn-venta"><i class="fas fa-shopping-cart"></i><span>Nueva Venta</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/ventarapida.php')): ?>
        <a href="<?php echo route('ventas.rapida'); ?>" class="action-btn btn-venta"><i class="fas fa-bolt"></i><span>Venta Rápida</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/cuentas_corrientes.php')): ?>
        <a href="<?php echo route('ctacte'); ?>" class="action-btn btn-cc"><i class="fas fa-users"></i><span>Clientes CC</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/compras.php')): ?>
        <a href="<?php echo route('compras'); ?>" class="action-btn btn-compra"><i class="fas fa-truck-loading"></i><span>Cargar Compra</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/reporte_cuotas.php')): ?>
        <a href="<?php echo route('reporte.cuotas'); ?>" class="action-btn" style="border-color: #ff5252;"><i class="fas fa-hand-holding-usd" style="color: #ff5252; background: rgba(255, 82, 82, 0.1);"></i><span>Cuentas a Cobrar</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/consignacion_reporte.php')): ?>
        <a href="<?php echo route('consignaciones'); ?>" class="action-btn" style="border-color: #9C27B0;"><i class="fas fa-clipboard-list" style="color: #9C27B0; background: rgba(156, 39, 176, 0.1);"></i><span>Consignaciones</span></a>
        <?php endif; ?>
        <?php if (tiene_permiso('pages/consulta_consignaciones_remota.php')): ?>
        <a href="<?php echo route('consulta.consignaciones'); ?>" class="action-btn" style="border-color: #00bcd4;"><i class="fas fa-search" style="color: #00bcd4; background: rgba(0, 188, 212, 0.1);"></i><span>Consulta Consig. Remota</span></a>
        <?php endif; ?>
    </div>

    <div class="dashboard-grid">
        
        <div class="stat-card card-green">
            <i class="fas fa-cash-register icon-bg"></i>
            <h3>Efectivo / Transf.</h3>
            <div class="value">$<?php echo number_format($total_contado, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-check-circle"></i> Cobros liquidados en el período</div>
        </div>

        <div class="stat-card card-orange">
            <i class="fas fa-file-invoice-dollar icon-bg"></i>
            <h3>Cuenta Corriente</h3>
            <div class="value" style="color: #FF9800;">$<?php echo number_format($total_ctacte, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-user-clock"></i> Pendiente de cobro</div>
        </div>

        <a href="<?php echo route('resumen.ventas'); ?>" class="stat-card card-blue">
            <i class="fas fa-shopping-basket icon-bg"></i>
            <h3>Operaciones</h3>
            <div class="value"><?php echo $cant_ventas; ?></div>
            <div class="footer"><i class="fas fa-list-ul"></i> Ver detalles de ventas</div>
        </a>

        <div class="stat-card card-green" title="Ventas del período divididas por cantidad de operaciones">
            <i class="fas fa-receipt icon-bg"></i>
            <h3>Ticket Promedio</h3>
            <div class="value" style="color: #4CAF50;">$<?php echo number_format($ticket_promedio, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-percent"></i> Promedio por operación en el período</div>
        </div>

        <a href="<?php echo route('inventario'); ?>" class="stat-card card-red">
            <i class="fas fa-boxes icon-bg"></i>
            <h3>Stock Crítico</h3>
            <div class="value" style="color: #F44336;"><?php echo $stock_critico; ?></div>
            <div class="footer"><i class="fas fa-exclamation-triangle"></i> Items para reponer</div>
        </a>

        <a href="<?php echo route('presupuestos'); ?>" class="stat-card card-purple">
            <i class="fas fa-calculator icon-bg"></i>
            <h3>Presupuestos</h3>
            <div class="value"><?php echo $presup_pendientes; ?></div>
            <div class="footer"><i class="fas fa-hourglass-start"></i> Pendientes de cierre</div>
        </a>

        <a href="<?php echo route('ctacte.prov'); ?>" class="stat-card card-red">
            <i class="fas fa-exclamation-triangle icon-bg"></i>
            <h3>Deuda Prov. Vencida</h3>
            <div class="value" style="color: #F44336;">$<?php echo number_format($deuda_vencida_prov, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-calendar-times"></i> Pagos con plazo expirado</div>
        </a>

        <div class="stat-card card-purple" title="Deuda pendiente acumulada de todas las cuentas corrientes de clientes (debe - haber)">
            <i class="fas fa-file-invoice-dollar icon-bg"></i>
            <h3>Cuentas a Cobrar</h3>
            <div class="value" style="color: #9C27B0;">$<?php echo number_format($deuda_cc_clientes, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-users"></i> Deuda CC clientes pendiente</div>
        </div>

        <div class="stat-card card-green" title="Cobros a cuenta corriente recibidos de clientes en el período">
            <i class="fas fa-hand-holding-usd icon-bg"></i>
            <h3>Cobros Cta. Cte.</h3>
            <div class="value" style="color: #4CAF50;">$<?php echo number_format($cobros_periodo, 2, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-wallet"></i> Pagos de clientes en el período</div>
        </div>

        <div class="stat-card card-blue" title="Total de unidades vendidas en el período">
            <i class="fas fa-boxes-stacked icon-bg"></i>
            <h3>Ítems Vendidos</h3>
            <div class="value"><?php echo number_format($items_vendidos, 0, ',', '.'); ?></div>
            <div class="footer"><i class="fas fa-shopping-cart"></i> Unidades en el período</div>
        </div>

    </div>

    <div class="charts-row">
        <div class="chart-container">
            <h3 style="margin: 0 0 10px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase;">
                <i class="fas fa-chart-line" style="color: var(--accent);"></i> Flujo de Ventas (<?php echo $titulo_periodo; ?>)
            </h3>
            <span style="font-size: 0.8rem; color: var(--text-muted); display: inline-block; margin-bottom: 8px;">
                Suma del período: <strong style="color: var(--accent);">$<?php echo number_format($suma_semana, 2, ',', '.'); ?></strong>
                vs. anterior:
                <?php if ($suma_semana_ant > 0): ?>
                    <span style="color: <?php echo $var_semana_pct >= 0 ? '#4CAF50' : '#F44336'; ?>; font-weight: 700;">
                        <?php echo ($var_semana_pct >= 0 ? '▲ +' : '▼ ') . number_format(abs($var_semana_pct), 1, ',', '.') . '%'; ?>
                    </span>
                <?php else: ?>
                    <span style="color: var(--text-muted);">sin datos previos</span>
                <?php endif; ?>
            </span>
            <canvas id="salesChart" style="max-height: 250px;"></canvas>
        </div>

        <div class="chart-container">
            <h3 style="margin: 0 0 20px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase;">
                <i class="fas fa-chart-pie" style="color: var(--accent);"></i> Ventas por Pago (<?php echo $titulo_periodo; ?>)
            </h3>
            <canvas id="pagosChart" style="max-height: 250px;"></canvas>
        </div>
    </div>

    <div class="secondary-grid">
        <!-- TOP 10 PRODUCTOS -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-trophy" style="color: #f1c40f;"></i> Top 10 Más Vendidos</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th style="text-align: center;">Vendido</th>
                        <th style="text-align: center;">Stock Actual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($top_productos as $tp): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tp['descripcion']); ?></td>
                        <td style="text-align: center;" class="text-accent"><?php echo number_format($tp['total_cant'], 0); ?></td>
                        <td style="text-align: center;">
                            <span style="color: <?php echo $tp['stock'] <= 2 ? '#f44336' : '#888'; ?>">
                                <?php echo $tp['stock']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- VENCIMIENTOS PROVEEDORES -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-calendar-alt" style="color: #FF5252;"></i> Vencimientos Proveedores</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th style="text-align: center;">Vence</th>
                        <th style="text-align: right;">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($vencimientos_prov as $vp): 
                        $es_vencida = ($vp['fecha_vencimiento'] && $vp['fecha_vencimiento'] < $hoy);
                    ?>
                    <tr>
                        <td>
                            <small style="color: #666;">#<?php echo htmlspecialchars($vp['n_documento']); ?></small><br>
                            <strong><?php echo htmlspecialchars($vp['proveedor']); ?></strong>
                        </td>
                        <td style="text-align: center; <?php echo $es_vencida ? 'color: #F44336; font-weight: bold;' : ''; ?>">
                            <?php echo $vp['fecha_vencimiento'] ? date('d/m/y', strtotime($vp['fecha_vencimiento'])) : '---'; ?>
                        </td>
                        <td style="text-align: right;" class="text-accent">$<?php echo number_format($vp['total_compra'], 2, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($vencimientos_prov)): ?>
                        <tr><td colspan="3" style="text-align:center; padding: 20px; color: #666;">Sin deudas a crédito registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ÚLTIMAS VENTAS -->
        <div class="stat-card">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-history"></i> Últimas Ventas</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ultimas_ventas as $uv): ?>
                    <tr>
                        <td><small>#<?php echo $uv['n_documento']; ?></small> <br> <span style="font-size: 0.7rem; color: #666;"><?php echo $uv['usuario']; ?></span></td>
                        <td style="text-align: right;" class="text-accent">$<?php echo number_format($uv['total_venta'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels_semana); ?>,
                datasets: [
                    {
                        label: '<?php echo $titulo_periodo; ?> ($)',
                        data: <?php echo json_encode($ventas_semana); ?>,
                        borderColor: '#00bcd4',
                        backgroundColor: 'rgba(0, 188, 212, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        pointBackgroundColor: '#00bcd4'
                    }
                    <?php if ($mostrar_serie_ant): ?>,
                    {
                        label: 'Período anterior ($)',
                        data: <?php echo json_encode($ventas_semana_ant); ?>,
                        borderColor: '#9e9e9e',
                        backgroundColor: 'rgba(158, 158, 158, 0.05)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: false,
                        pointRadius: 3,
                        pointBackgroundColor: '#9e9e9e'
                    }
                    <?php endif; ?>
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#aaa', boxWidth: 12 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': $' +
                                    Number(context.parsed.y).toLocaleString('es-AR', { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#333' },
                        ticks: {
                            color: '#aaa',
                            callback: function(value) { return '$' + value.toLocaleString('es-AR'); }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#aaa' }
                    }
                }
            }
        });

        // Gráfico donut: ventas del día por método de pago
        const pctx = document.getElementById('pagosChart').getContext('2d');
        new Chart(pctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($donut_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($donut_values); ?>,
                    backgroundColor: ['#4CAF50', '#00bcd4', '#FF9800', '#9C27B0'],
                    borderColor: '#1e1e1e',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#aaa', boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0) || 1;
                                const pct = ((context.parsed / total) * 100).toFixed(0);
                                return context.label + ': $' +
                                    Number(context.parsed).toLocaleString('es-AR', { minimumFractionDigits: 2 }) +
                                    ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
<script>
    // Estado de carga al cambiar de período o aplicar un rango
    document.addEventListener('DOMContentLoaded', function() {
        var buttons = document.querySelectorAll('.period-btn');
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.body.classList.add('is-loading');
            });
        });
        var rangoForm = document.getElementById('periodRango');
        if (rangoForm) {
            rangoForm.addEventListener('submit', function() {
                document.body.classList.add('is-loading');
            });
        }
    });
</script>
</body>
</html>
