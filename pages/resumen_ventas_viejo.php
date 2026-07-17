<?php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Rango de fechas por defecto: últimos 30 días
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$id_cliente_filtro = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

try {
    // Obtener lista de clientes para el filtro
    $stmt_cli = $pdo->prepare("SELECT id, CONCAT(apellido, ', ', nombre) as nombre FROM clientes WHERE empresa_id = ? ORDER BY apellido ASC");
    $stmt_cli->execute([$empresa_id]);
    $clientes = $stmt_cli->fetchAll(PDO::FETCH_ASSOC);

    $where_cliente = ($id_cliente_filtro > 0) ? " AND id_cliente = :cliente " : "";
    $where_v_cliente = ($id_cliente_filtro > 0) ? " AND v.id_cliente = :cliente " : "";

    // 1. Ventas Cobradas (Contado)
    $sql_resumen = "SELECT 
                        SUM(CASE WHEN cond_pago = 'CONTADO' THEN total_venta ELSE (COALESCE(pago_efectivo, 0) + COALESCE(pago_transf, 0)) END) as total_cobrado
                    FROM ventas v
                    WHERE estado = 'Finalizada' 
                    AND DATE(fecha_venta) BETWEEN :inicio AND :fin 
                    AND v.empresa_id = :empresa_id" . $where_v_cliente;
    $stmt_res = $pdo->prepare($sql_resumen);
    $params_res = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_res[':cliente'] = $id_cliente_filtro;
    $stmt_res->execute($params_res);
    $resumen = $stmt_res->fetch(PDO::FETCH_ASSOC);

    // 2. Devoluciones del período
    $sql_egresos_dev = "SELECT SUM(total_reintegrado) FROM devoluciones d
                        WHERE cond_pago = 'CONTADO'
                        AND DATE(fecha) BETWEEN :inicio AND :fin 
                        AND d.empresa_id = :empresa_id" . $where_cliente;
    $stmt_eg_dev = $pdo->prepare($sql_egresos_dev);
    $params_eg = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_eg[':cliente'] = $id_cliente_filtro;
    $stmt_eg_dev->execute($params_eg);
    $total_egresos_dev = (float)$stmt_eg_dev->fetchColumn() ?: 0;

    $total_contado_periodo = (isset($resumen['total_cobrado']) ? (float)$resumen['total_cobrado'] : 0) - $total_egresos_dev;

    // 3. Calcular ganancia: Total Ventas - Costo Ventas - Devoluciones
    $sql_total_ventas = "SELECT SUM(total_venta) as total FROM ventas v
                        WHERE estado = 'Finalizada' 
                        AND DATE(fecha_venta) BETWEEN :inicio AND :fin 
                        AND v.empresa_id = :empresa_id" . $where_v_cliente;
    $stmt_total_ventas = $pdo->prepare($sql_total_ventas);
    $params_tv = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_tv[':cliente'] = $id_cliente_filtro;
    $stmt_total_ventas->execute($params_tv);
    $total_ventas = (float)$stmt_total_ventas->fetchColumn() ?: 0;

    $sql_costo_ventas = "SELECT SUM(vd.cant * vd.p_costo_venta) as costo 
                        FROM ventas_detalle vd
                        INNER JOIN ventas v ON v.empresa_id = vd.empresa_id AND v.n_documento = vd.n_documento
                        WHERE DATE(v.fecha_venta) BETWEEN :inicio AND :fin 
                        AND v.estado = 'Finalizada' 
                        AND v.empresa_id = :empresa_id
                        AND vd.empresa_id = :empresa_id2" . $where_v_cliente;
    $stmt_costo = $pdo->prepare($sql_costo_ventas);
    $params_costo = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id, ':empresa_id2' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_costo[':cliente'] = $id_cliente_filtro;
    $stmt_costo->execute($params_costo);
    $costo_ventas = (float)$stmt_costo->fetchColumn() ?: 0;

    $ganancia_periodo = $total_ventas - $costo_ventas - $total_egresos_dev;

    // 4. Total Cuentas por Cobrar (deuda global)
    $sql_total_deuda = "SELECT SUM(debe) - SUM(haber) as saldo_neto FROM ctacte WHERE empresa_id = :empresa_id " . $where_cliente;
    $stmt_deuda = $pdo->prepare($sql_total_deuda);
    $params_deuda = [':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_deuda[':cliente'] = $id_cliente_filtro;
    $stmt_deuda->execute($params_deuda);
    $res_deuda = $stmt_deuda->fetch(PDO::FETCH_ASSOC);
    $saldo_total_por_cobrar = ($res_deuda['saldo_neto'] > 0) ? $res_deuda['saldo_neto'] : 0;

    // 5. Ventas en Cta. Cte. del período
    $sql_ctacte_periodo = "SELECT SUM(total_venta - (COALESCE(pago_efectivo, 0) + COALESCE(pago_transf, 0))) as total_ctacte 
                           FROM ventas v
                           WHERE estado = 'Finalizada' 
                           AND cond_pago = 'CUENTA CORRIENTE'
                           AND DATE(fecha_venta) BETWEEN :inicio AND :fin 
                           AND v.empresa_id = :empresa_id" . $where_v_cliente;
    $stmt_ctacte = $pdo->prepare($sql_ctacte_periodo);
    $params_ct = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_ct[':cliente'] = $id_cliente_filtro;
    $stmt_ctacte->execute($params_ct);
    $res_ctacte = $stmt_ctacte->fetch(PDO::FETCH_ASSOC);

    $sql_ctacte_dev = "SELECT SUM(haber) FROM ctacte m
                       WHERE (movimiento LIKE 'ANULACIÓN%' OR movimiento LIKE 'DEVOLUCIÓN%')
                       AND DATE(fecha) BETWEEN :inicio AND :fin 
                       AND m.empresa_id = :empresa_id" . $where_cliente;
    $stmt_ctacte_dev = $pdo->prepare($sql_ctacte_dev);
    $params_ct_dev = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin, ':empresa_id' => $empresa_id];
    if ($id_cliente_filtro > 0) $params_ct_dev[':cliente'] = $id_cliente_filtro;
    $stmt_ctacte_dev->execute($params_ct_dev);
    $total_ctacte_dev = (float)$stmt_ctacte_dev->fetchColumn() ?: 0;

    $total_ctacte = (isset($res_ctacte['total_ctacte']) ? (float)$res_ctacte['total_ctacte'] : 0) - $total_ctacte_dev;

    // 6. Listado de ventas para la tabla (CORRECCIÓN: parámetros diferenciados para evitar fallos de reutilización en UNION ALL de PDO)
    $where_v_cliente_list = ($id_cliente_filtro > 0) ? " AND v.id_cliente = :cliente1 " : "";
    $where_d_cliente_list = ($id_cliente_filtro > 0) ? " AND d.id_cliente = :cliente2 " : "";

    $where_v_list = " WHERE DATE(v.fecha_venta) BETWEEN :inicio1 AND :fin1 AND v.estado = 'Finalizada' AND v.empresa_id = :empresa_id" . $where_v_cliente_list;
    $where_d_list = " WHERE DATE(d.fecha) BETWEEN :inicio2 AND :fin2 AND d.empresa_id = :empresa_id" . $where_d_cliente_list;

    $sql_ventas = "
        SELECT 
            'VENTA' as tipo_registro,
            v.id AS id_ref, v.n_documento, v.fecha_venta as fecha, v.total_venta as monto,
            v.cond_pago COLLATE utf8mb4_unicode_ci as cond_pago,
            CONCAT(c.apellido, ', ', c.nombre) COLLATE utf8mb4_unicode_ci AS nombre_cliente,
            af.cae
        FROM ventas v
        LEFT JOIN clientes c ON v.id_cliente = c.id
        LEFT JOIN ventas_afip af ON v.id = af.id_venta
        $where_v_list

        UNION ALL

        SELECT 
            'DEVOLUCION' as tipo_registro,
            d.op_n as id_ref, d.op_n as n_documento, d.fecha, -d.total_reintegrado as monto,
            d.cond_pago COLLATE utf8mb4_unicode_ci as cond_pago,
            CONCAT(c.apellido, ', ', c.nombre) COLLATE utf8mb4_unicode_ci as nombre_cliente,
            NULL as cae
        FROM devoluciones d
        LEFT JOIN clientes c ON d.id_cliente = c.id
        $where_d_list

        ORDER BY fecha DESC, n_documento DESC";
    
    $stmt_ventas = $pdo->prepare($sql_ventas);
    
    $params_ventas = [
        ':inicio1' => $fecha_inicio, ':fin1' => $fecha_fin,
        ':inicio2' => $fecha_inicio, ':fin2' => $fecha_fin,
        ':empresa_id' => $empresa_id
    ];
    
    if ($id_cliente_filtro > 0) {
        $params_ventas[':cliente1'] = $id_cliente_filtro;
        $params_ventas[':cliente2'] = $id_cliente_filtro;
    }
    
    $stmt_ventas->execute($params_ventas);
    $ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div style='background:red; color:white; padding:10px;'>Error en SQL: " . $e->getMessage() . "</div>";
    $ventas = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Ventas | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>"> 
    <link rel="stylesheet" href="../css/ticket_print.css">
    <style>
        .btn-action { margin-right: 5px; padding: 5px 10px; cursor: pointer; border-radius: 4px; border: none; }
        .text-right { text-align: right; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-warning { background: #f1c40f; color: #000; }
        .badge-success { background: #2ecc71; color: #fff; }

        .modal {
            display: none; 
            position: fixed; 
            z-index: 99999 !important;
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0, 0, 0, 0.95) !important;
            backdrop-filter: blur(10px);
        }

        .modal-content-lg {
            background-color: #1a1a1a !important;
            margin: 5% auto; 
            padding: 25px; 
            border: 1px solid #3498db; 
            border-radius: 12px;
            width: 85%; 
            max-width: 900px; 
            color: white;
            position: relative;
            box-shadow: 0 10px 50px rgba(0,0,0,1);
        }

        .close-button { color: #f1c40f; float: right; font-size: 30px; font-weight: bold; cursor: pointer; line-height: 20px; }
        .close-button:hover { color: #fff; }
        
        .tipo-dev { color: #ff7675; font-weight: bold; font-size: 0.85em; }
        #detalleBody { min-height: 150px; padding-top: 20px; color: #eee; }

        .form-control { padding: 10px; border-radius: 5px; border: 1px solid #555; background: #444; color: white; height: 42px; box-sizing: border-box; }
        
        .btn-filter-action {
            height: 42px;
            min-width: 120px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 20px;
            border-radius: 5px;
            font-size: 0.9rem;
            font-weight: bold;
            border: none;
            cursor: pointer;
            text-decoration: none;
            margin-bottom: 15px;
            transition: 0.2s;
        }
        .btn-filter-action:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-filter-primary { background-color: #005cd4; color: white; }
        .btn-filter-secondary { background-color: #c2ca56; color: white !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>📊 Resumen Histórico de Ventas</h1>

        <div class="card" style="margin-bottom: 20px; padding: 15px;">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label>Desde:</label>
                    <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" class="form-control">
                </div>
                <div>
                    <label>Hasta:</label>
                    <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>" class="form-control">
                </div>
                <div style="flex-grow: 1; min-width: 200px;">
                    <label>Cliente:</label>
                    <select name="id_cliente" class="form-control" style="width: 100%;">
                        <option value="0">-- Todos los Clientes --</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($id_cliente_filtro == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-filter-action btn-filter-primary">🔍 Filtrar</button>
                <a href="resumen_ventas.php" class="btn-filter-action btn-filter-secondary">🔄 Limpiar</a>
            </form>
        </div>

        <div class="resumen-container">
            <div class="widget widget-contado">
                <h3>💰 Ventas Cobradas</h3>
                <p>$<?php echo number_format((float)($total_contado_periodo ?? 0), 2, ',', '.'); ?></p>
                <small>Efectivo + Transferencia</small>
            </div>
            
            <div class="widget widget-contado" style="background: linear-gradient(135deg, #27ae60, #229954);">
                <h3>📈 Ganancia del Periodo</h3>
                <p>$<?php echo number_format((float)($ganancia_periodo ?? 0), 2, ',', '.'); ?></p>
                <small>Ventas - Costos - Devoluciones</small>
            </div>

            <div class="widget widget-ctacte">
                <h3>⏳ Ventas en Cta. Cte.</h3>
                <p>$<?php echo number_format((float)($total_ctacte ?? 0), 2, ',', '.'); ?></p>
                <small>Pendientes del periodo</small>
            </div>

            <div class="widget widget-ctacte" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                <h3>📉 Total Cuentas por Cobrar</h3>
                <p>$<?php echo number_format((float)($saldo_total_por_cobrar ?? 0), 2, ',', '.'); ?></p>
                <small>Deuda global acumulada</small>
            </div>
        </div>
        
        <div class="card">
            <table id="tablaVentas" style="width: 100%;">
                <thead>
                    <tr>
                        <th>N° Doc.</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Condición</th> 
                        <th>Total</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($ventas) > 0): ?>
                        <?php foreach ($ventas as $venta): ?>
                            <tr>
                                <td>
                                    <?php if ($venta['tipo_registro'] === 'DEVOLUCION'): ?>
                                        <span class="tipo-dev">↩ N° <?php echo $venta['n_documento']; ?></span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($venta['n_documento']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($venta['fecha'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($venta['nombre_cliente'] ? $venta['nombre_cliente'] : 'Consumidor Final'); ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($venta['cond_pago'] == 'CUENTA CORRIENTE') {
                                        echo '<span class="badge badge-warning">⏳ Cta. Cte.</span>';
                                    } else {
                                        echo '<span class="badge badge-success">💵 Contado</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-right"><strong>$<?php echo number_format((float)($venta['monto'] ?? 0), 2, ',', '.'); ?></strong></td>
                                <td>
                                    <?php if ($venta['tipo_registro'] === 'VENTA'): ?>
                                        <button class="btn btn-primary btn-action" onclick="mostrarDetalle(<?php echo $venta['n_documento']; ?>)">Detalle</button>
                                        <button class="btn btn-success btn-action" onclick="imprimirTicket(<?php echo $venta['n_documento']; ?>)">Ticket</button>
                                        <button class="btn btn-action" style="background-color: #00bcd4; color: white;" onclick="descargarPDF(<?php echo $venta['n_documento']; ?>)">PDF</button>
                                        
                                        <?php if (empty($venta['cae']) && tiene_permiso('pages/facturacion_arca.php')): ?>
                                            <button class="btn btn-action" style="background-color: #6f42c1; color: white;" onclick="enviarArca(<?php echo $venta['id_ref']; ?>)" title="Convertir a Factura ARCA">ARCA</button>
                                        <?php elseif (!empty($venta['cae'])): ?>
                                            <span class="badge" style="background: #27ae60; cursor: help;" title="CAE: <?php echo $venta['cae']; ?>">✓ AFIP</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn btn-primary btn-action" onclick="mostrarDetalleDevolucion(<?php echo $venta['id_ref']; ?>, '<?php echo $venta['cond_pago']; ?>')">Detalle</button>
                                        <button class="btn btn-success btn-action" onclick="imprimirTicketDevolucion(<?php echo $venta['id_ref']; ?>, '<?php echo $venta['cond_pago']; ?>')">Ticket</button>
                                        <button class="btn btn-action" style="background-color: #e67e22; color: white;" onclick="descargarPDFDevolucion(<?php echo $venta['id_ref']; ?>, '<?php echo $venta['cond_pago']; ?>')">PDF</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 20px; background: #fff3cd; color: #856404;">
                                <strong>No se encontraron ventas en este rango.</strong><br>
                                <small>Rango: <?php echo $fecha_inicio; ?> a <?php echo $fecha_fin; ?> | Empresa ID: <?php echo $empresa_id; ?></small>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="detalleModal" class="modal">
        <div class="modal-content-lg">
            <span class="close-button" onclick="cerrarModal()">&times;</span>
            <h2 style="border-bottom: 2px solid #3498db; padding-bottom: 10px;">Detalle de Venta <span id="detalleNdocumento"></span></h2>
            
            <div id="detalleBody">
            </div>
            
            <div style="text-align: right; margin-top: 25px; border-top: 1px solid #444; padding-top: 15px;">
                <button class="btn btn-success" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)">
                    🖨️ Reimprimir Ticket
                </button>
                <button class="btn" style="background-color: #00bcd4; color: white; margin-left: 10px;" onclick="descargarPDF(document.getElementById('detalleNdocumento').textContent)">
                    📥 Descargar Orden (A5)
                </button>
                <button class="btn btn-secondary" onclick="cerrarModal()" style="margin-left: 10px;">Cerrar</button>
            </div>
        </div>
    </div>

<script>
    const detalleModal = document.getElementById('detalleModal');
    const detalleBody = document.getElementById('detalleBody');
    const detalleNdocumento = document.getElementById('detalleNdocumento');
    
    function mostrarDetalle(nDocumento) {
        detalleNdocumento.textContent = nDocumento;
        detalleBody.innerHTML = '<div style="text-align:center; padding:20px;"><p>Cargando información del sistema...</p></div>';
        detalleModal.style.display = 'block';

        fetch('../ajax/obtener_detalle_venta.php?n_documento=' + nDocumento)
            .then(response => {
                if (!response.ok) throw new Error('No se encontró el archivo de detalle.');
                return response.text();
            })
            .then(html => {
                detalleBody.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                detalleBody.innerHTML = '<p style="color: #e74c3c; text-align:center;">❌ Error al cargar los datos. Verifique que el archivo obtener_detalle_venta.php exista en la carpeta ajax.</p>';
            });
    }

    function cerrarModal() {
        detalleModal.style.display = 'none';
    }

    function imprimirTicket(nDocumento) {
        const url = 'vista_previa_ticket.php?n_documento=' + nDocumento;
        window.open(url, '_blank', 'width=400,height=700,scrollbars=yes');
    }

    function descargarPDF(nDocumento) {
        window.location.href = 'generar_pdf_ticket.php?n_documento=' + nDocumento + '&download=1';
    }

    function mostrarDetalleDevolucion(id, tipo) {
        detalleNdocumento.textContent = 'N° ' + id;
        detalleBody.innerHTML = '<div style="text-align:center; padding:20px;"><p>Cargando información del sistema...</p></div>';
        detalleModal.style.display = 'block';

        fetch('../ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=' + tipo)
            .then(res => res.text())
            .then(html => { detalleBody.innerHTML = html; })
            .catch(err => {
                console.error(err);
                detalleBody.innerHTML = '<p style="color: #e74c3c; text-align:center;">❌ Error al cargar los datos.</p>';
            });
    }

    function imprimirTicketDevolucion(id, tipo) {
        const url = 'vista_previa_ticket_devolucion.php?id=' + id + '&tipo=' + tipo;
        window.open(url, '_blank', 'width=400,height=700,scrollbars=yes');
    }

    function descargarPDFDevolucion(id, tipo) {
        window.location.href = 'generar_pdf_devolucion.php?id=' + id + '&tipo=' + tipo + '&download=1';
    }

    <?php if (tiene_permiso('pages/facturacion_arca.php')): ?>
    function enviarArca(idVenta) {
        confirmarAccion(
            'Facturación Electrónica', 
            '¿Desea enviar esta venta a ARCA (AFIP) para generar la factura legal?', 
            'GENERAR FACTURA', 
            'btn-primary', 
            () => {
                fetch('procesar_factura_arca.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'id_venta=' + idVenta
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        mostrarMensaje('¡Éxito!', 'Factura generada con CAE: ' + data.cae, 'success', () => location.reload());
                    } else {
                        mostrarMensaje('Error AFIP', data.message, 'error');
                    }
                })
                .catch(err => mostrarMensaje('Error Técnico', 'No se pudo conectar con el procesador.', 'error'));
            }
        );
    }
    <?php endif; ?>

    window.onclick = function(event) {
        if (event.target == detalleModal) {
            cerrarModal();
        }
    }
</script>
</body>
</html>