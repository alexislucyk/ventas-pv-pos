<?php
// pages/ventarapida.php — Venta Rápida Supermarket PRO
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once dirname(__FILE__) . '/../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? 1;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario_activo = $_SESSION['usuario_nombre'] ?? 'Sistema';
$mensaje = '';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $mensaje = "❌ ERROR CRÍTICO: La conexión a la base de datos no está disponible.";
} else {
    // 1. Obtener clientes (para Cuenta Corriente, opcional)
    try {
        $sql_clientes = "SELECT c.id AS id_cliente,
                                CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
                                c.cuit AS num_documento,
                                c.habilita_cta
                         FROM clientes c
                         WHERE c.empresa_id = ?
                         ORDER BY c.apellido, c.nombre ASC";
        $stmt_clientes = $pdo->prepare($sql_clientes);
        $stmt_clientes->execute([$empresa_id]);
        $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $clientes = [];
    }

    // 2. PROCESAMIENTO DE VENTA
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {
        $accion = $_POST['venta_action'];
        $es_finalizar = ($accion === 'Finalizar');
        $estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';

        $id_cliente = (isset($_POST['id_cliente_hidden'])) ? (int)$_POST['id_cliente_hidden'] : 0;
        $cond_pago = (isset($_POST['cond_pago'])) ? trim($_POST['cond_pago']) : 'CONTADO';
        $observaciones = (isset($_POST['observaciones'])) ? trim($_POST['observaciones']) : '';

        $pago_efectivo = (isset($_POST['pago_efectivo'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_efectivo'])) : 0.0;
        $pago_transf  = (isset($_POST['pago_transf'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_transf'])) : 0.0;

        $detalle_productos = json_decode($_POST['detalle_productos'], true);

        if (empty($detalle_productos)) {
            $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
        } else {
            try {
                $pdo->beginTransaction();
                $productos_sin_stock = [];
                $total_bruto = 0;

                foreach ($detalle_productos as &$item) {
                    $sql_v = "SELECT p.p_venta, p.p_compra, p.descripcion, p.moneda,
                                     COALESCE(s.stock_actual, 0) as stock_actual
                              FROM productos p
                              LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci
                                            AND s.empresa_id = ?
                                            AND s.sucursal_id = ?
                              WHERE p.cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci";
                    $stmt_v = $pdo->prepare($sql_v);
                    $stmt_v->execute([$empresa_id, $sucursal_id, $item['cod_prod']]);
                    $prod_db = $stmt_v->fetch(PDO::FETCH_ASSOC);

                    if (!$prod_db) {
                        throw new Exception("Producto no encontrado: " . $item['cod_prod']);
                    }

                    $moneda_prod = $prod_db['moneda'] ?? 'pesos';
                    if ($moneda_prod === 'dolar') {
                        $cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
                        $dolar_operativo = null;
                        if (file_exists($cache_path)) {
                            $cache = json_decode(file_get_contents($cache_path), true);
                            if (is_array($cache) && isset($cache['venta'])) {
                                $dolar_operativo = (float)$cache['venta'];
                            }
                        }
                        if ($dolar_operativo === null || $dolar_operativo <= 0) {
                            throw new Exception("No se pudo obtener el dólar operativo para: {$item['cod_prod']}.");
                        }
                        $item['p_unit'] = (float)$prod_db['p_venta'] * $dolar_operativo;
                        $item['p_costo_venta'] = (float)$prod_db['p_compra'] * $dolar_operativo;
                    } else {
                        $item['p_unit'] = (float)$prod_db['p_venta'];
                        $item['p_costo_venta'] = (float)$prod_db['p_compra'];
                    }

                    if ($es_finalizar && (float)$prod_db['stock_actual'] < (float)$item['cant']) {
                        $productos_sin_stock[] = $prod_db['descripcion'];
                    }

                    $desc_porc_item = isset($item['desc']) ? (float)$item['desc'] : 0;
                    $subtotal_item = $item['p_unit'] * (float)$item['cant'];
                    $monto_desc_item = $subtotal_item * ($desc_porc_item / 100);
                    $item['total'] = $subtotal_item - $monto_desc_item;
                    $total_bruto += $item['total'];
                }
                unset($item);

                $total_recalculado = max(0, $total_bruto);

                // Obtener N° Documento correlativo
                $stmt_n = $pdo->query("SELECT MAX(n_documento) AS ultimo FROM ventas FOR UPDATE");
                $res_n = $stmt_n->fetch();
                $n_documento = ($res_n['ultimo'] !== null) ? $res_n['ultimo'] + 1 : 1;

                // Insertar cabecera de venta
                $fecha_venta = date('Y-m-d H:i:s');
                $sql_v = "INSERT INTO ventas (empresa_id, sucursal_id, id_cliente, cond_pago, n_documento,
                                    total_venta, pago_efectivo, pago_transf, fecha_venta, estado, usuario, observaciones)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_v)->execute([
                    $empresa_id, $sucursal_id, $id_cliente, $cond_pago, $n_documento,
                    $total_recalculado, $pago_efectivo, $pago_transf, $fecha_venta,
                    $estado_venta, $usuario_activo, $observaciones
                ]);
                $id_venta_actual = $pdo->lastInsertId();

                // Insertar detalle y ajustar stock
                $sql_d = "INSERT INTO ventas_detalle (empresa_id, sucursal_id, cod_prod, descripcion, cant,
                                   p_unit, descuento_unitario, p_costo_venta, total, n_documento, fecha)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                $stmt_d = $pdo->prepare($sql_d);
                foreach ($detalle_productos as $item) {
                    $desc_u = isset($item['desc']) ? (float)$item['desc'] : 0;
                    $stmt_d->execute([
                        $empresa_id, $sucursal_id, $item['cod_prod'], $item['descripcion'],
                        $item['cant'], $item['p_unit'], $desc_u, $item['p_costo_venta'],
                        $item['total'], $n_documento, date('Y-m-d H:i:s')
                    ]);

                    if ($es_finalizar) {
                        $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual - ?
                                      WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?")
                            ->execute([$item['cant'], $empresa_id, $sucursal_id, $item['cod_prod']]);
                    }
                }

                // Cuenta corriente (si aplica)
                if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
                    $saldo_deuda = $total_recalculado - ($pago_efectivo + $pago_transf);
                    if ($saldo_deuda > 0) {
                        $sql_cc = "INSERT INTO ctacte (empresa_id, id_cliente, movimiento, n_documento, debe, haber, fecha)
                                   VALUES (?, ?, 'FACTURA', ?, ?, 0, NOW())";
                        $pdo->prepare($sql_cc)->execute([$empresa_id, $id_cliente, $n_documento, $saldo_deuda]);
                    }
                }

                // Registrar movimiento de caja (solo para CONTADO/FINANCIADO)
                if ($es_finalizar && $cond_pago !== 'CUENTA CORRIENTE') {
                    $monto_ingreso = $pago_efectivo + $pago_transf;
                    if ($monto_ingreso > 0) {
                        $metodo_pago_mov = ($pago_efectivo > 0 && $pago_transf > 0) ? 'MIXTO'
                            : ($pago_efectivo > 0 ? 'EFECTIVO' : 'TRANSFERENCIA');
                        $detalle_mov = "VENTA CONTADO N° $n_documento";
                        $sql_mov = "INSERT INTO movimientos (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, monto_efectivo, monto_transferencia)
                                    VALUES (?, ?, 'INGRESO', ?, ?, ?, NOW(), ?, 0, ?, ?)";
                        $pdo->prepare($sql_mov)->execute([
                            $empresa_id, $sucursal_id, $monto_ingreso, $metodo_pago_mov, $detalle_mov,
                            $usuario_activo, $pago_efectivo, $pago_transf
                        ]);
                    }
                }

                $pdo->commit();
                $_SESSION['ticket_a_imprimir_doc'] = $n_documento;
                $_SESSION['status_msj'] = "✅ Venta N° $n_documento procesada correctamente.";

                if (!empty($productos_sin_stock)) {
                    $_SESSION['status_msj_warning'] = "⚠️ Venta cerrada con stock insuficiente en: " . implode(", ", $productos_sin_stock);
                }

                if ($es_finalizar) {
                    header("Location: " . route_file('pages/ventarapida.php'));
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensaje = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

$ticket_doc_a_imprimir = isset($_SESSION['ticket_a_imprimir_doc']) ? $_SESSION['ticket_a_imprimir_doc'] : null;
$cliente_tel = '';
$cliente_nom = '';
if ($ticket_doc_a_imprimir) {
    $stmt_c = $pdo->prepare("SELECT c.telefono, CONCAT(c.apellido, ' ', c.nombre) as nombre
                             FROM ventas v JOIN clientes c ON v.id_cliente = c.id
                             WHERE v.n_documento = ?");
    $stmt_c->execute([$ticket_doc_a_imprimir]);
    $res_c = $stmt_c->fetch();
    if ($res_c) {
        $cliente_tel = $res_c['telefono'];
        $cliente_nom = $res_c['nombre'];
    }
}
unset($_SESSION['ticket_a_imprimir_doc']);

if (isset($_SESSION['status_msj'])) { $mensaje = $_SESSION['status_msj']; unset($_SESSION['status_msj']); }
$mensaje_warning = '';
if (isset($_SESSION['status_msj_warning'])) { $mensaje_warning = $_SESSION['status_msj_warning']; unset($_SESSION['status_msj_warning']); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venta Rápida | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ========== VARIABLES Y Tema ========== */
        :root {
            --color-primario: #00bcd4;
            --color-secundario: #009688;
            --color-fondo: #1a1a2e;
            --color-card: #16213e;
            --color-accent: #ffe66d;
            --color-borde: #3a4b5c;
            --color-texto: #e0e7f0;
            --color-success: #00d897;
            --color-warning: #f5a623;
            --color-error: #d32f2f;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--color-fondo);
            color: var(--color-texto);
            margin: 0;
            padding: 0;
        }

        .content {
            padding: 30px 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: var(--color-primario);
            font-size: 1.8rem;
            font-weight: 700;
            border-bottom: 3px solid var(--color-primario);
            padding-bottom: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h1::before {
            content: "\f201";
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            font-size: 1.3rem;
        }

        /* ========== GRID PRINCIPAL ========== */
        .pos-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .pos-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        /* ========== TARJETAS ========== */
        .card {
            background: var(--color-card);
            border: 1px solid var(--color-borde);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }

        .card-fullwidth {
            width: 100%;
        }

        /* ========== INPUTS ESTILO POS ========== */
        .input-field, input[type="text"], input[type="number"], select, textarea {
            width: 100%;
            padding: 12px 16px;
            background: var(--color-fondo);
            border: 2px solid var(--color-borde);
            border-radius: 8px;
            color: var(--color-texto);
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-field:focus, input[type="text"]:focus, input[type="number"]:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--color-primario);
            box-shadow: 0 0 0 4px rgba(0, 188, 214, 0.15);
        }

        .input-field::placeholder, input[type="text"]::placeholder, input[type="number"]::placeholder {
            color: #667085;
        }

        /* ========== INPUT UNIFICADO (Scanner + Búsqueda) ========== */
        .unified-search {
            width: 100%;
            padding: 16px 20px;
            font-size: 1.5rem;
            font-weight: 600;
            text-align: center;
            background: var(--color-fondo);
            border: 3px solid var(--color-borde);
            border-radius: 10px;
            color: var(--color-texto);
            font-family: 'Courier New', Courier, monospace;
            text-transform: uppercase;
            transition: all 0.2s;
        }

        .unified-search:focus {
            border-color: var(--color-primario);
            background: var(--color-fondo);
            box-shadow: 0 0 0 6px rgba(0, 188, 214, 0.1);
        }

        .unified-search::placeholder {
            color: #667085;
            font-weight: normal;
            font-size: 1.3rem;
        }

        /* Estado de error */
        .unified-search.error {
            border-color: var(--color-error);
            box-shadow: 0 0 0 6px rgba(211, 47, 47, 0.1);
        }

        /* Resultados debajo */
        .unified-search-card, .cliente-section {
            position: relative;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--color-fondo);
            border: 2px solid var(--color-primario);
            border-radius: 0 0 10px 10px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 100;
            margin-top: 8px;
            font-size: 0.9rem;
        }

        .search-result-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--color-borde);
            transition: background 0.15s;
        }

        .search-result-item:hover {
            background: rgba(0, 188, 214, 0.1);
            color: var(--color-primario);
        }

        .search-result-item .prod-code {
            font-weight: bold;
            color: var(--color-primario);
            font-size: 0.85rem;
        }

        .search-result-item .prod-desc {
            color: var(--color-texto);
            font-size: 0.95rem;
        }

        .search-result-item .prod-stock {
            color: var(--color-warning);
            font-size: 0.8rem;
            margin-left: 10px;
        }

        /* ========== ESCANNER VISUAL ========== */
        .scanner-hint {
            margin-top: 10px;
            text-align: center;
            color: #667085;
            font-size: 0.75rem;
        }

        .scanner-hint span {
            color: var(--color-primario);
            font-weight: bold;
        }

        /* ========== CARRITO ========== */
        .cart-summary {
            min-height: 200px;
        }

        .cart-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .cart-table thead th {
            background: var(--color-fondo);
            color: var(--color-primario);
            text-transform: uppercase;
            font-size: 0.65rem;
            padding: 12px 8px;
            border-bottom: 2px solid var(--color-borde);
            text-align: left;
        }

        .cart-table tbody td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--color-borde);
            vertical-align: middle;
            font-size: 0.85rem;
        }

        .cart-table .prod-code {
            color: var(--color-primario);
            font-weight: bold;
        }

        .cart-table .prod-desc {
            color: var(--color-texto);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .cart-table .prod-precio {
            color: var(--color-primario);
            text-align: right;
            font-weight: bold;
        }

        .cart-table .prod-cant {
            text-align: center;
        }

        .cart-table .prod-total {
            color: var(--color-accent);
            text-align: right;
            font-weight: bold;
        }

        .cart-table .action-btn {
            background: none;
            border: none;
            color: var(--color-texto);
            cursor: pointer;
            font-size: 0.8rem;
        }

        .cart-table .action-btn:hover {
            color: var(--color-primario);
        }

        .empty-cart {
            text-align: center;
            padding: 60px 20px;
            color: #667085;
        }

        .empty-cart i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.2;
        }

        /* Cantidad editable en carrito */
        .cant-input {
            width: 50px;
            padding: 6px 8px;
            text-align: center;
        }

        /* Botón eliminar del carrito */
        .remove-item {
            width: 28px;
            height: 28px;
            background: var(--color-error);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        /* ========== TOTAL ========== */
        .total-box {
            background: linear-gradient(135deg, var(--color-card), var(--color-fondo));
            border: 1px solid var(--color-borde);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            margin: 20px 0;
        }

        .total-amount {
            font-size: 2.2rem;
            color: var(--color-primario);
            font-weight: 700;
        }

        .total-label {
            color: #667085;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        /* ========== PAGO ========== */
        .payment-section {
            margin-top: 25px;
        }

        .payment-methods {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .payment-method {
            flex: 1;
            padding: 12px;
            background: var(--color-fondo);
            border: 2px solid var(--color-borde);
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .payment-method.active {
            border-color: var(--color-primario);
            background: rgba(0, 188, 214, 0.1);
        }

        .payment-method i {
            font-size: 1.5rem;
            margin-bottom: 8px;
            display: block;
        }

        .payment-method .method-name {
            color: var(--color-texto);
            font-size: 0.85rem;
            margin-top: 4px;
        }

        .payment-input-group {
            margin-top: 20px;
            display: none;
        }

        .payment-input-group.active {
            display: block;
        }

        .payment-input {
            width: 100%;
            padding: 12px;
            font-size: 1.2rem;
            text-align: right;
            background: var(--color-fondo);
            border: 2px solid var(--color-borde);
            border-radius: 8px;
            color: var(--color-texto);
        }

        .payment-input:focus {
            outline: none;
            border-color: var(--color-primario);
        }

        .vuelto {
            background: linear-gradient(135deg, var(--color-warning), #f5a623);
            color: #000;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-top: 20px;
            font-size: 1.3rem;
            font-weight: bold;
        }

        /* ========== PAGINA CLIENTE ========== */
        .cliente-section {
            margin-bottom: 25px;
        }

        .cliente-input {
            width: 100%;
            padding: 12px;
            background: var(--color-fondo);
            border: 2px solid var(--color-borde);
            border-radius: 8px;
            color: var(--color-texto);
            font-size: 1rem;
        }

        .cliente-input:focus {
            outline: none;
            border-color: var(--color-primario);
        }

        .cliente-display {
            background: var(--color-fondo);
            border: 1px solid var(--color-borde);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-top: 12px;
            color: var(--color-primario);
            font-weight: bold;
            display: none;
        }

        .cliente-display.active {
            display: block;
        }

        /* ========== BOTONES ========== */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
        }

        .btn-primary {
            background: var(--color-primario);
            color: #000;
        }

        .btn-primary:hover {
            background: #009688;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--color-success);
            color: #000;
        }

        .btn-success:hover {
            background: #00c8a7;
        }

        .btn-warning {
            background: var(--color-warning);
            color: #000;
        }

        .btn-warning:hover {
            background: #f4c430;
        }

        .btn-danger {
            background: var(--color-error);
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-block {
            width: 100%;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        /* ========== ALERTAS ========== */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background: rgba(0, 216, 151, 0.15);
            border: 1px solid var(--color-success);
            color: var(--color-success);
        }

        .alert.warning {
            background: rgba(245, 166, 35, 0.15);
            border: 1px solid var(--color-warning);
            color: var(--color-warning);
        }

        .alert.error {
            background: rgba(211, 47, 47, 0.15);
            border: 1px solid var(--color-error);
            color: var(--color-error);
        }

        .alert .icon {
            font-size: 1.2rem;
        }

        /* ========== TOAST ========== */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--color-success);
            color: #000;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.4);
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateX(120%); }
            to { transform: translateX(0); }
        }

        .toast .icon {
            font-size: 1.2rem;
        }

        /* ========== FOOTER / INFORME ========== */
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--color-borde);
            color: #667085;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <?php include 'topbar.php'; ?>

        <h1>Venta Rápida</h1>

        <?php if ($mensaje): ?>
            <div class="alert alert<?php echo str_contains($mensaje, '❌') ? ' error' : ' success'; ?>">
                <i class="fas <?php echo str_contains($mensaje, '❌') ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>
        <?php if ($mensaje_warning): ?>
            <div class="alert alert warning">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($mensaje_warning); ?>
            </div>
        <?php endif; ?>

        <div class="pos-layout">

            <!-- COLUMNA IZQUIERDA: Input unificado + Carrito -->
            <div>
                <!-- Input UNIFICADO: Escáner + Búsqueda por nombre -->
                <div class="card unified-search-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span>
                            <i class="fas fa-barcode" style="color: var(--color-primario); font-size: 1.2rem;"></i>
                            Escanear / Buscar
                        </span>
                    </div>
                    <input type="text" id="producto_input" class="unified-search"
                           placeholder="Posicioná el cursor, escaneá código y presioná ENTER..."
                           autofocus>
                    <div class="search-results" id="resultadosBusqueda"></div>
                    <div class="scanner-hint">
                        <span>Escaneá</span> → se agrega al carrito. <br>
                        <span>Escribí</span> → aparecen sugerencias debajo.
                    </div>
                </div>

                <!-- Carrito -->
                <div class="card cart-summary">
                    <h3 style="margin:0 0 15px 0; color:var(--color-primary); font-size:1rem;"><i class="fas fa-shopping-cart"></i> Carrito <span id="cart-count">0</span></h3>
                    <div class="cart-table">
                        <thead>
                            <tr>
                                <th class="prod-code">Cód.</th>
                                <th class="prod-desc">Producto</th>
                                <th class="prod-precio">Precio</th>
                                <th class="prod-cant">Cant.</th>
                                <th class="prod-total">Total</th>
                                <th style="width:30px; text-align:center;"></th>
                            </thead>
                            <tbody id="carrito_body">
                                <tr><td colspan="6" class="empty-cart"><i class="fas fa-barcode"></i><p>Tapee o busque productos para comenzar.</p></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-warning btn-block" onclick="vaciarCarrito()">🗑 Vaciar Carrito</button>
                </div>
            </div>

            <!-- COLUMNA DERECHA: Cliente + Total + Pago -->
            <div>
                <form id="formVenta" method="POST" style="height:100%; display:flex; flex-direction:column;">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">
                    <input type="hidden" name="cond_pago" id="cond_pago" value="CONTADO">
                    <input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">
                    <input type="hidden" name="id_venta_existente" id="id_venta_existente" value="0">
                    <input type="hidden" name="id_cliente_hidden" id="id_cliente_hidden" value="0">
                    <input type="hidden" name="pago_efectivo" id="pago_efectivo" value="0.00">
                    <input type="hidden" name="pago_transf" id="pago_transf" value="0.00">

                    <div class="card cliente-section">
                        <label style="color:var(--color-texto); font-size:0.85rem; margin-bottom:8px; display:block;">Cliente (opcional)</label>
                        <input type="text" id="buscar_cliente" class="cliente-input" autocomplete="off" placeholder="Buscar cliente..." value="">
                        <div class="search-results" id="resultadosBusquedaClientes" style="display:none;"></div>
                        <div class="cliente-display" id="nombre_cliente_display">Venta Genérica</div>
                    </div>

                    <div class="total-box">
                        <div class="total-label">Total a Cobrar</div>
                        <span class="total-amount" id="total_venta_display">$ 0.00</span>
                        <input type="hidden" name="total_venta_input" id="total_venta_input" value="0.00">
                    </div>

                    <div class="payment-section">
                        <div class="payment-methods" id="paymentMethods">
                            <div class="payment-method active" onclick="setPago('efectivo')">
                                <i class="fas fa-money-bill-wave"></i>
                                <div class="method-name">Efectivo</div>
                            </div>
                            <div class="payment-method" onclick="setPago('transferencia')">
                                <i class="fas fa-university"></i>
                                <div class="method-name">Transferencia</div>
                            </div>
                        </div>

                        <div class="payment-input-group" id="efectivoInputGroup">
                            <label style="color:var(--color-texto); font-size:0.85rem; margin-bottom:8px;">Efectivo recibido</label>
                            <input type="number" id="monto_efectivo_input" class="payment-input" min="0" step="0.01" value="0"
                                   oninput="calcularPago(this)">
                        </div>

                        <div class="payment-input-group" id="transferenciaInputGroup" style="display:none;">
                            <label style="color:var(--color-texto); font-size:0.85rem; margin-bottom:8px;">Transferencia recibida</label>
                            <input type="number" id="monto_transferencia_input" class="payment-input" min="0" step="0.01" value="0"
                                   oninput="calcularPago(this)">
                        </div>
                    </div>

                    <div class="vuelto" id="vueltoDisplay">$ 0.00</div>

                    <label style="color:var(--color-texto); font-size:0.85rem; margin-top:15px; display:block;">Observaciones</label>
                    <textarea name="observaciones" id="observaciones" class="input-field" placeholder="Notas para el ticket..."
                              style="height: 80px; resize: vertical; font-family: inherit;"></textarea>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">
                        <i class="fas fa-check-circle"></i> Finalizar Venta
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($ticket_doc_a_imprimir): ?>
    <div id="modalTicket" style="position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center;">
        <div style="background:var(--color-card); padding:35px; border-radius:12px; text-align:center; border:1px solid var(--color-primary); width:420px;">
            <i class="fas fa-print" style="font-size:3rem; color:var(--color-primary); margin-bottom:15px;"></i>
            <h3 style="color:var(--color-success); margin-bottom:10px;">Venta Procesada</h3>
            <p style="color:var(--color-texto);">¿Desea imprimir el ticket N° <strong><?php echo $ticket_doc_a_imprimir; ?></strong>?</p>
            <div style="margin-top:25px; display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                <button onclick="window.open('vista_previa_ticket.php?n_documento=<?php echo $ticket_doc_a_imprimir; ?>', '_blank'); this.parentElement.parentElement.style.display='none';" class="btn btn-primary" style="padding:12px 20px;">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button onclick="window.location.href='generar_pdf_ticket.php?n_documento=<?php echo $ticket_doc_a_imprimir; ?>&download=1'; this.parentElement.parentElement.style.display='none';" class="btn" style="background:var(--color-warning); color:#000; padding:12px 20px;">
                    <i class="fas fa-file-pdf"></i> Descargar PDF
                </button>
                <button onclick="this.parentElement.parentElement.style.display='none'; window.location.href='<?php echo route_file('pages/ventarapida.php'); ?>'" class="btn btn-secondary" style="background:var(--color-borde); color:var(--color-texto); padding:12px 20px;">Cerrar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const APP_BASE = '<?php echo url(''); ?>';
        const clientesData = <?php echo json_encode($clientes); ?>;
    </script>
    <script src="<?php echo url('js/ventarapida.js'); ?>"></script>
</body>
</html>