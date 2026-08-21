<?php
// pages/ventarapida.php - Caja Registradora / Venta Rápida (tipo supermercado)
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once dirname(__FILE__) . '/../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? 1;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario_activo = $_SESSION['usuario_nombre'] ?? 'Sistema';
$mensaje = '';
$mensaje_warning = '';

$clientes = [];

if (isset($pdo) && ($pdo instanceof PDO)) {
    try {
        $sql_clientes = "SELECT c.id AS id_cliente,
                                CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
                                c.cuit AS num_documento,
                                CASE WHEN UPPER(TRIM(COALESCE(c.habilita_cta,''))) = 'SI' THEN 'SI' ELSE 'NO' END AS habilita_cta,
                                COALESCE(cc.saldo, 0) AS saldo
                         FROM clientes c
                         LEFT JOIN (SELECT id_cliente, empresa_id, SUM(debe - haber) AS saldo
                                    FROM ctacte GROUP BY id_cliente, empresa_id) cc
                                ON cc.id_cliente = c.id AND cc.empresa_id = c.empresa_id
                         WHERE c.empresa_id = ?
                         ORDER BY c.apellido, c.nombre ASC";
        $stmt_clientes = $pdo->prepare($sql_clientes);
        $stmt_clientes->execute([$empresa_id]);
        $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $clientes = [];
    }

    // ---- PROCESAMIENTO DE VENTA ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {
        $accion = $_POST['venta_action'];
        $es_finalizar = ($accion === 'Finalizar');
        $estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';

        $id_cliente = (isset($_POST['id_cliente_hidden'])) ? (int)$_POST['id_cliente_hidden'] : 0;
        $cond_pago = (isset($_POST['cond_pago'])) ? trim($_POST['cond_pago']) : 'CONTADO';
        $observaciones = (isset($_POST['observaciones'])) ? trim($_POST['observaciones']) : '';

        $pago_efectivo = (isset($_POST['pago_efectivo'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_efectivo'])) : 0.0;
        $pago_transf  = (isset($_POST['pago_transf'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_transf'])) : 0.0;

        if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE') {
            if ($id_cliente <= 0) {
                $mensaje = "❌ Error: Para ventas en cuenta corriente debe seleccionar un cliente.";
            } else {
                try {
                    $stmt_h = $pdo->prepare("SELECT CASE WHEN UPPER(TRIM(COALESCE(habilita_cta,''))) = 'SI' THEN 'SI' ELSE 'NO' END FROM clientes WHERE id = ? AND empresa_id = ?");
                    $stmt_h->execute([$id_cliente, $empresa_id]);
                    $habilita = $stmt_h->fetchColumn() ?: 'NO';
                    if ($habilita !== 'SI') {
                        $mensaje = "❌ Error: Este cliente no tiene habilitada la cuenta corriente.";
                    }
                } catch (Exception $e) {
                    $mensaje = "❌ Error al validar la cuenta corriente del cliente.";
                }
            }
        }

        $detalle_productos = json_decode($_POST['detalle_productos'], true);

        if ($mensaje === '' && empty($detalle_productos)) {
            $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
        }

        if ($mensaje === '') {
            try {
                $pdo->beginTransaction();
                $productos_sin_stock = [];
                $total_bruto = 0;

                foreach ($detalle_productos as &$item) {
                    $sql_v = "SELECT p.p_venta, p.p_compra, p.descripcion, p.moneda,
                                     COALESCE(s.stock_actual, 0) AS stock_actual
                              FROM productos p
                              LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci
                                            AND s.empresa_id = ?
                                            AND s.sucursal_id = ?
                              WHERE p.cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                                AND p.empresa_id = ?";
                    $stmt_v = $pdo->prepare($sql_v);
                    $stmt_v->execute([$empresa_id, $sucursal_id, $item['cod_prod'], $empresa_id]);
                    $prod_db = $stmt_v->fetch(PDO::FETCH_ASSOC);

                    if (!$prod_db) {
                        throw new Exception("Producto no encontrado: " . $item['cod_prod']);
                    }

                    $moneda_prod = $prod_db['moneda'] ?? 'pesos';
                    if ($moneda_prod === 'dolar') {
                        $dolar_operativo = null;
                        $cache_path = dirname(__FILE__) . '/../cache/dolar_cache.json';
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

                $stmt_n = $pdo->query("SELECT MAX(n_documento) AS ultimo FROM ventas FOR UPDATE");
                $res_n = $stmt_n->fetch();
                $n_documento = ($res_n['ultimo'] !== null) ? $res_n['ultimo'] + 1 : 1;

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

                if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
                    $saldo_deuda = $total_recalculado - ($pago_efectivo + $pago_transf);
                    if ($saldo_deuda > 0) {
                        $sql_cc = "INSERT INTO ctacte (empresa_id, id_cliente, movimiento, n_documento, debe, haber, fecha, usuario)
                                   VALUES (?, ?, 'FACTURA', ?, ?, 0, NOW(), ?)";
                        $pdo->prepare($sql_cc)->execute([$empresa_id, $id_cliente, $n_documento, $saldo_deuda, $usuario_activo]);
                    }
                }

                if ($es_finalizar) {
                    $monto_ingreso = ($cond_pago === 'CONTADO') ? $total_recalculado : ($pago_efectivo + $pago_transf);
                    if ($monto_ingreso > 0) {
                        $metodo_pago_mov = ($pago_efectivo > 0 && $pago_transf > 0) ? 'MIXTO'
                            : ($pago_efectivo > 0 ? 'EFECTIVO' : 'TRANSFERENCIA');
                        $monto_efectivo_mov = ($metodo_pago_mov === 'MIXTO') ? $pago_efectivo
                            : (($metodo_pago_mov === 'EFECTIVO') ? $monto_ingreso : 0);
                        $monto_transferencia_mov = ($metodo_pago_mov === 'MIXTO') ? $pago_transf
                            : (($metodo_pago_mov === 'TRANSFERENCIA') ? $monto_ingreso : 0);
                        $detalle_mov = ($cond_pago === 'CUENTA CORRIENTE')
                            ? "ENTREGA/PAGO - VENTA N° $n_documento (CTA. CTE.)"
                            : "VENTA CONTADO N° $n_documento";
                        $sql_mov = "INSERT INTO movimientos (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, es_fondo_inicial, monto_efectivo, monto_transferencia)
                                    VALUES (?, ?, 'INGRESO', ?, ?, ?, NOW(), ?, 0, 0, ?, ?)";
                        $pdo->prepare($sql_mov)->execute([
                            $empresa_id, $sucursal_id, $monto_ingreso, $metodo_pago_mov, $detalle_mov,
                            $usuario_activo, $monto_efectivo_mov, $monto_transferencia_mov
                        ]);
                    }
                }

                $pdo->commit();
                $_SESSION['ticket_a_imprimir_doc'] = $n_documento;
                $_SESSION['status_msj'] = "✅ Venta N° $n_documento procesada correctamente.";

                if (!empty($productos_sin_stock)) {
                    $_SESSION['status_msj_warning'] = "⚠️ Venta cerrada con stock insuficiente en: " . implode(", ", $productos_sin_stock);
                }

                header("Location: " . route_file('pages/ventarapida.php'));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

$ticket_doc_a_imprimir = isset($_SESSION['ticket_a_imprimir_doc']) ? $_SESSION['ticket_a_imprimir_doc'] : null;
$cliente_tel = '';
$cliente_nom = '';
if ($ticket_doc_a_imprimir) {
    try {
        $stmt_c = $pdo->prepare("SELECT c.telefono, CONCAT(c.apellido, ' ', c.nombre) AS nombre
                                 FROM ventas v JOIN clientes c ON v.id_cliente = c.id
                                 WHERE v.n_documento = ?");
        $stmt_c->execute([$ticket_doc_a_imprimir]);
        $res_c = $stmt_c->fetch();
        if ($res_c) {
            $cliente_tel = $res_c['telefono'];
            $cliente_nom = $res_c['nombre'];
        }
    } catch (Exception $e) {
    }
}
unset($_SESSION['ticket_a_imprimir_doc']);

if (isset($_SESSION['status_msj'])) {
    $mensaje = $_SESSION['status_msj'];
    unset($_SESSION['status_msj']);
}
if (isset($_SESSION['status_msj_warning'])) {
    $mensaje_warning = $_SESSION['status_msj_warning'];
    unset($_SESSION['status_msj_warning']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja Registradora | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg: #121212;
            --panel: #1e1e1e;
            --panel-alt: #181818;
            --border: #333;
            --input-bg: #2a2a2a;
            --accent: #00bcd4;
            --accent-soft: rgba(0, 188, 212, 0.12);
            --success: #4caf50;
            --success-soft: rgba(76, 175, 80, 0.14);
            --warn: #f1c40f;
            --danger: #e74c3c;
            --danger-soft: rgba(231, 76, 60, 0.14);
            --text: #e0e0e0;
            --muted: #aaa;
            --muted-2: #666;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', 'Roboto', Helvetica, Arial, sans-serif;
            margin: 0;
            overflow-x: hidden;
        }

        .content {
            padding-top: 66px;
        }

        /* ===== CABECERA POS ===== */
        .pos-wrap {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 96px);
            min-height: 0;
        }

        .pos-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 12px 22px;
            flex-shrink: 0;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #171717, #141414);
        }

        .pos-head h1 {
            margin: 0;
            color: var(--accent);
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pos-head h1 i { background: var(--accent-soft); padding: 8px; border-radius: 10px; color: var(--accent); }

        .pos-sub {
            margin: 3px 0 0 0;
            color: var(--muted-2);
            font-size: 0.78rem;
        }

        .pos-clock {
            text-align: right;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        #relojHora { font-size: 1.5rem; font-weight: 700; color: var(--text); line-height: 1; }
        #relojFecha { font-size: 0.75rem; color: var(--muted-2); }

        /* ===== GRID PRINCIPAL ===== */
        .pos-grid {
            flex: 1;
            min-height: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 400px;
            gap: 16px;
            padding: 16px 22px;
        }

        .pos-left { display: flex; flex-direction: column; gap: 12px; min-height: 0; }
        .pos-right { display: flex; flex-direction: column; gap: 12px; min-height: 0; }

        @media (max-width: 1150px) {
            body { overflow: auto; }
            .pos-wrap { height: auto; }
            .pos-grid { grid-template-columns: 1fr; }
            .pos-totals { min-height: 320px; }
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        }

        /* ===== BÚSQUEDA ===== */
        .search-card { position: relative; flex-shrink: 0; padding: 12px 14px; }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #0e0e0e;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 4px 8px 4px 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .search-box:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-soft);
        }

        .search-box .search-icon { color: var(--accent); font-size: 1.2rem; }

        .search-box input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text);
            font-size: 1.25rem;
            font-weight: 600;
            height: 46px;
            padding: 0;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .search-box input::placeholder {
            color: #444;
            font-family: 'Segoe UI', sans-serif;
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
        }

        .search-box input.error { color: var(--danger); }

        .btn-limpiar {
            background: none;
            border: none;
            color: var(--muted-2);
            cursor: pointer;
            font-size: 1rem;
            padding: 8px 10px;
            border-radius: 8px;
        }

        .btn-limpiar:hover { color: var(--danger); background: var(--danger-soft); }

        .search-scanner-hint {
            margin-top: 8px;
            color: var(--muted-2);
            font-size: 0.72rem;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-scanner-hint i { color: var(--accent); }

        /* ===== RESULTADOS BÚSQUEDA (dropdown) ===== */
        .search-results {
            position: absolute;
            top: calc(100% + 4px);
            left: 14px;
            right: 14px;
            background: #191919;
            border: 1px solid var(--accent);
            border-radius: 0 0 12px 12px;
            max-height: 320px;
            overflow-y: auto;
            z-index: 500;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
        }

        .search-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            cursor: pointer;
            border-bottom: 1px solid #2a2a2a;
            transition: background 0.12s;
        }

        .search-result-item:last-child { border-bottom: none; }
        .search-result-item:hover { background: var(--accent-soft); }
        .search-result-item.resaltado { background: var(--accent-soft); box-shadow: inset 3px 0 0 var(--accent); }

        .search-result-item .sr-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; }
        .search-result-item .sr-meta { color: var(--muted-2); font-size: 0.72rem; margin-top: 2px; }
        .search-result-item .sr-precio { color: var(--accent); font-weight: 700; font-size: 0.95rem; white-space: nowrap; }
        .search-result-item .sr-stock { font-size: 0.7rem; padding: 2px 8px; border-radius: 999px; background: var(--success-soft); color: var(--success); white-space: nowrap; }
        .search-result-item .sr-stock.agotado { background: var(--danger-soft); color: var(--danger); }

        .search-results .sr-empty { padding: 18px; text-align: center; color: var(--muted-2); font-size: 0.85rem; }

        /* Ocultar flechas de los inputs numéricos */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"] {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        /* ===== CARRITO ===== */
        .pos-cart { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }

        .cart-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        .cart-head h3 { margin: 0; color: var(--accent); font-size: 0.95rem; display: flex; align-items: center; gap: 8px; }

        .badge {
            background: var(--accent);
            color: #000;
            border-radius: 999px;
            padding: 1px 9px;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .cart-scroll { flex: 1; min-height: 0; overflow-y: auto; }

        .cart-scroll::-webkit-scrollbar { width: 8px; }
        .cart-scroll::-webkit-scrollbar-thumb { background: #333; border-radius: 8px; }

        .cart-empty {
            text-align: center;
            padding: 46px 20px;
            color: var(--muted-2);
        }

        .cart-empty i { font-size: 2.4rem; opacity: 0.25; display: block; margin-bottom: 10px; }

        .cart-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(56px, 90px) auto auto;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-bottom: 1px solid #282828;
            transition: background 0.12s;
        }

        .cart-item:hover { background: #191919; }

        .ci-info { min-width: 0; line-height: 1.2; }

        .ci-nombre { font-size: 0.78rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .ci-cod { font-size: 0.62rem; color: var(--muted-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .ci-remove {
            background: none;
            border: none;
            color: #666;
            font-size: 1rem;
            cursor: pointer;
            padding: 2px 4px;
            line-height: 1;
        }

        .ci-remove:hover { color: var(--danger); }

        .ci-qty { min-width: 0; }

        .ci-qty input {
            width: 100% !important;
            height: 26px;
            padding: 0 !important;
            margin-bottom: 0 !important;
            text-align: center;
            background: var(--panel-alt) !important;
            border: 1px solid var(--border) !important;
            border-radius: 6px !important;
            color: var(--text) !important;
            font-size: 0.8rem;
            font-weight: 700;
            outline: none;
        }

        .ci-qty input:focus { border-color: var(--accent); }

        .ci-total { color: var(--warn); font-weight: 700; font-size: 0.85rem; white-space: nowrap; }

        .ci-total.sin-stock { color: var(--danger); }

        /* ===== TOTALES / RESUMEN ===== */
        .pos-totals {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            padding: 18px 20px;
        }

        .totals-head {
            color: var(--accent);
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        .totals-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 2px;
            color: var(--muted);
            font-size: 0.85rem;
        }

        .totals-row strong { color: var(--text); }

        .total-grande {
            text-align: center;
            padding: 22px 2px;
            margin: 20px 0;
            border-top: 1px dashed var(--border);
            border-bottom: 1px dashed var(--border);
        }

        .total-grande span { color: var(--muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; display: block; margin-bottom: 8px; }
        .total-grande strong { font-size: 2.3rem; color: var(--success); font-weight: 800; word-break: break-word; }

        .totals-actions { display: flex; flex-direction: column; gap: 10px; flex-shrink: 0; }

        .btn-cobrar {
            width: 100%;
            height: 62px;
            font-size: 1.35rem;
            font-weight: 800;
            border-radius: 14px;
            letter-spacing: 1px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #43a047, #2e7d32);
            color: #fff;
            box-shadow: 0 8px 20px rgba(46, 125, 50, 0.35);
            transition: all 0.15s;
            font-family: inherit;
        }

        .btn-cobrar:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(46, 125, 50, 0.5); filter: brightness(1.1); }
        .btn-cobrar:disabled { background: #333; color: #666; box-shadow: none; transform: none; cursor: not-allowed; }

        .btn-vaciar {
            width: 100%;
            background: var(--panel);
            border: 1px solid var(--border);
            color: var(--muted);
            border-radius: 12px;
            padding: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
        }

        .btn-vaciar:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-soft); }

        .btn-cobrar:disabled { cursor: not-allowed; }
        .btn-vaciar:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ===== MODAL COBRO ===== */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(0, 0, 0, 0.78);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.open { display: flex; }

        .modal-cobro {
            width: 500px;
            max-width: 100%;
            max-height: 92vh;
            overflow-y: auto;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.7);
            animation: modalIn 0.2s ease-out;
        }

        @keyframes modalIn {
            from { transform: translateY(24px); opacity: 0; }
            to { transform: none; opacity: 1; }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .modal-head h3 { margin: 0; color: var(--accent); font-size: 1.05rem; }
        .modal-close { background: none; border: none; color: #ff6b6b; font-size: 1.7rem; cursor: pointer; line-height: 1; padding: 0 4px; }
        .modal-close:hover { color: var(--danger); }

        .modal-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 22px;
            background: var(--panel-alt);
            border-bottom: 1px solid var(--border);
        }

        .modal-total span { color: var(--muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
        .modal-total strong { color: var(--success); font-size: 1.9rem; font-weight: 800; }

        .modal-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 16px; }

        .form-label {
            color: var(--muted);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-label .link-quitar { text-transform: none; color: var(--danger); cursor: pointer; font-size: 0.72rem; border: none; background: none; font-family: inherit; }
        .form-label .link-quitar:hover { text-decoration: underline; }

        .modal-body input[type="text"],
        .modal-body input[type="number"],
        .modal-body textarea {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 1rem;
            padding: 11px 14px;
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s;
        }

        .modal-body input:focus, .modal-body textarea:focus { border-color: var(--accent); }

        .cliente-display {
            background: var(--success-soft);
            border: 1px solid var(--success);
            color: var(--success);
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .cliente-display.generico { background: rgba(255,255,255,0.04); border-color: var(--border); color: var(--muted); }

        .pago-metodos { display: grid; grid-template-columns: repeat(auto-fit, minmax(88px, 1fr)); gap: 8px; }

        .pago-chip {
            padding: 12px 6px;
            background: var(--input-bg);
            border: 2px solid transparent;
            border-radius: 12px;
            color: var(--muted);
            cursor: pointer;
            text-align: center;
            font-size: 0.74rem;
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: center;
            font-family: inherit;
            transition: all 0.15s;
            font-weight: 600;
        }

        .pago-chip i { font-size: 1.15rem; }

        .pago-chip.active { border-color: var(--accent); background: var(--accent-soft); color: #fff; }
        .pago-chip.ctacte.active { border-color: var(--warn); background: rgba(241, 196, 15, 0.12); color: #fff; }
        .pago-chip.disabled { opacity: 0.35; cursor: not-allowed; }

        .pago-grupo { display: none; flex-direction: column; gap: 8px; background: var(--panel-alt); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
        .pago-grupo.active { display: flex; }

        .pago-grupo .double { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        .pago-grupo label { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; }

        .pago-grupo .big-input { font-size: 1.35rem; font-weight: 700; text-align: right; }

        .vuelto-box {
            padding: 13px;
            border-radius: 10px;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            background: var(--panel-alt);
            border: 1px solid var(--border);
        }

        .vuelto-box.ok { color: var(--success); border-color: var(--success); background: var(--success-soft); }
        .vuelto-box.falta { color: var(--danger); border-color: var(--danger); background: var(--danger-soft); }
        .vuelto-box.info { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }

        .modal-foot {
            display: flex;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
        }

        .modal-foot .btn {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            border: none;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
        }

        .modal-foot .btn-cancelar { background: var(--panel-alt); border: 1px solid var(--border); color: var(--muted); }
        .modal-foot .btn-cancelar:hover { border-color: var(--danger); color: var(--danger); }
        .modal-foot .btn-confirmar { background: linear-gradient(135deg, #43a047, #2e7d32); color: #fff; }
        .modal-foot .btn-confirmar:hover { filter: brightness(1.1); }
        .modal-foot .btn-confirmar:disabled { background: #333; color: #666; cursor: not-allowed; }

        /* ===== ALERTAS ===== */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .alert.error { background: var(--danger-soft); border: 1px solid var(--danger); color: var(--danger); }
        .alert.success { background: var(--success-soft); border: 1px solid var(--success); color: var(--success); }
        .alert.warning { background: rgba(241, 196, 15, 0.12); border: 1px solid var(--warn); color: var(--warn); }

        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            top: 64px;
            right: 20px;
            z-index: 20000;
            padding: 14px 18px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5);
            animation: toastIn 0.25s ease-out;
        }

        .toast.ok { background: #1b5e20; color: #c8ffc8; border: 1px solid var(--success); }
        .toast.error { background: #7f1d1d; color: #ffd6d6; border: 1px solid var(--danger); }
        .toast.warn { background: #7a5c00; color: #fff3c4; border: 1px solid var(--warn); }

        @keyframes toastIn { from { transform: translateX(120%); } to { transform: none; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <?php include 'topbar.php'; ?>

        <div class="pos-wrap">
            <header class="pos-head">
                <div>
                    <h1><i class="fas fa-cash-register"></i> Caja Registradora</h1>
                    <p class="pos-sub">Venta rápida: escaneá el código de barras o buscá un producto para comenzar</p>
                </div>
                <div class="pos-clock">
                    <span id="relojHora">--:--</span>
                    <span id="relojFecha">--</span>
                </div>
            </header>

            <?php if ($mensaje): ?>
                <div class="alert <?php echo str_contains($mensaje, '❌') ? 'error' : 'success'; ?>" style="margin:12px 22px 0;">
                    <i class="fas <?php echo str_contains($mensaje, '❌') ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>
            <?php if ($mensaje_warning): ?>
                <div class="alert warning" style="margin:12px 22px 0;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($mensaje_warning); ?>
                </div>
            <?php endif; ?>

            <div class="pos-grid">
                <!-- ===== COLUMNA IZQUIERDA: Búsqueda + Carrito ===== -->
                <section class="pos-left">
                    <div class="card search-card">
                        <div class="search-box">
                            <i class="fas fa-barcode search-icon"></i>
                            <input type="text" id="producto_input" autocomplete="off" autofocus spellcheck="false"
                                   placeholder="Escanear código de barras o buscar por nombre...">
                            <button type="button" class="btn-limpiar" id="btnLimpiarBusqueda" title="Limpiar"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="search-scanner-hint">
                            <span><i class="fas fa-sync-alt"></i> Enter: agregar por código exacto</span>
                            <span><i class="fas fa-keyboard"></i> Escribí para buscar por nombre</span>
                        </div>
                        <div id="resultadosBusqueda" class="search-results"></div>
                    </div>

                    <div class="card pos-cart">
                        <div class="cart-head">
                            <h3><i class="fas fa-shopping-cart"></i> Carrito <span id="cart-count" class="badge">0</span></h3>
                        </div>
                        <div id="carritoBody" class="cart-scroll">
                            <div class="cart-empty">
                                <i class="fas fa-cart-plus"></i>
                                <p>Escaneá o buscá un producto para comenzar</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ===== COLUMNA DERECHA: Resumen de Totales ===== -->
                <aside class="pos-right">
                    <div class="card pos-totals">
                        <div class="totals-head"><span><i class="fas fa-receipt"></i> Resumen de venta</span></div>
                        <div class="totals-body">
                            <div class="totals-row"><span>Artículos</span><strong id="totalItems">0</strong></div>
                            <div class="totals-row"><span>Subtotal</span><strong id="subtotalDisplay">$ 0,00</strong></div>
                            <div class="total-grande">
                                <span>Total a cobrar</span>
                                <strong id="total_venta_display">$ 0,00</strong>
                            </div>
                        </div>
                        <div class="totals-actions">
                            <button type="button" id="btnCobrar" class="btn-cobrar" onclick="abrirModalCobro()" disabled>
                                <i class="fas fa-coins"></i> Cobrar
                            </button>
                            <button type="button" id="btnVaciar" class="btn-vaciar" onclick="vaciarCarrito()" disabled>
                                <i class="fas fa-trash-alt"></i> Vaciar carrito
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    <!-- ===== MODAL COBRO ===== -->
    <div id="modalCobro" class="modal-backdrop">
        <div class="modal-cobro">
            <div class="modal-head">
                <h3><i class="fas fa-cash-register"></i> Cobrar Venta</h3>
                <button type="button" class="modal-close" onclick="cerrarModalCobro()">&times;</button>
            </div>
            <div class="modal-total">
                <span>Total a cobrar</span>
                <strong id="modalTotal">$ 0,00</strong>
            </div>
            <div class="modal-body">
                <div>
                    <label class="form-label">
                        Cliente
                        <button type="button" class="link-quitar" id="btnQuitarCliente" onclick="quitarCliente()" style="display:none;">Quitar cliente</button>
                    </label>
                    <div style="position:relative;">
                        <input type="text" id="buscar_cliente" autocomplete="off" placeholder="Buscar cliente por nombre o documento...">
                        <div id="resultadosBusquedaClientes" class="search-results"></div>
                    </div>
                    <div id="clienteDisplay" class="cliente-display generico" style="margin-top:8px;">
                        <span><i class="fas fa-user"></i> Venta genérica (Consumidor final)</span>
                    </div>
                </div>

                <div>
                    <label class="form-label">Forma de pago</label>
                    <div class="pago-metodos">
                        <button type="button" class="pago-chip active" data-metodo="efectivo" onclick="seleccionarPago('efectivo')">
                            <i class="fas fa-money-bill-wave"></i> Efectivo
                        </button>
                        <button type="button" class="pago-chip" data-metodo="tarjeta" onclick="seleccionarPago('tarjeta')">
                            <i class="fas fa-credit-card"></i> Tarjeta
                        </button>
                        <button type="button" class="pago-chip" data-metodo="transferencia" onclick="seleccionarPago('transferencia')">
                            <i class="fas fa-university"></i> Transf. / QR
                        </button>
                        <button type="button" class="pago-chip" data-metodo="mixto" onclick="seleccionarPago('mixto')">
                            <i class="fas fa-columns"></i> Mixto
                        </button>
                        <button type="button" class="pago-chip ctacte" data-metodo="ctacte" onclick="seleccionarPago('ctacte')">
                            <i class="fas fa-book"></i> Cta. Cte.
                        </button>
                    </div>
                </div>

                <div class="pago-grupo" id="grupoEfectivo">
                    <label for="recibidoInput">Dinero recibido</label>
                    <input type="number" id="recibidoInput" class="big-input" min="0" step="0.01" value="0" oninput="calcularVuelto()">
                    <div class="vuelto-box" id="vueltoBox">Vuelto: $ 0,00</div>
                </div>

                <div class="pago-grupo" id="grupoMixto">
                    <div class="double">
                        <div>
                            <label for="mixtoEfectivo">Efectivo</label>
                            <input type="number" id="mixtoEfectivo" class="big-input" min="0" step="0.01" value="0" oninput="calcularMixto()">
                        </div>
                        <div>
                            <label for="mixtoDigital">Digital / Transf.</label>
                            <input type="number" id="mixtoDigital" class="big-input" min="0" step="0.01" value="0" oninput="calcularMixto()">
                        </div>
                    </div>
                    <div class="vuelto-box" id="mixtoEstado">Falta: $ 0,00</div>
                </div>

                <div class="pago-grupo" id="grupoInfo">
                    <div class="vuelto-box info" id="infoPago">Se cobrará el total por el medio seleccionado.</div>
                </div>

                <div>
                    <label class="form-label" for="obsInput">Observaciones (opcional)</label>
                    <textarea id="obsInput" rows="2" placeholder="Notas para el ticket..."></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-cancelar" onclick="cerrarModalCobro()">Cancelar</button>
                <button type="button" class="btn btn-confirmar" id="btnFinalizarVenta" onclick="confirmarVenta()">
                    <i class="fas fa-check-circle"></i> Finalizar venta
                </button>
            </div>
        </div>
    </div>

    <!-- ===== FORMULARIO OCULTO DE VENTA ===== -->
    <form id="formVenta" method="POST" action="<?php echo route_file('pages/ventarapida.php'); ?>">
        <input type="hidden" name="detalle_productos" id="detalle_productos_input">
        <input type="hidden" name="cond_pago" id="cond_pago" value="CONTADO">
        <input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">
        <input type="hidden" name="id_cliente_hidden" id="id_cliente_hidden" value="0">
        <input type="hidden" name="pago_efectivo" id="pago_efectivo_hidden" value="0.00">
        <input type="hidden" name="pago_transf" id="pago_transf_hidden" value="0.00">
        <input type="hidden" name="observaciones" id="observaciones_hidden">
    </form>

    <?php if ($ticket_doc_a_imprimir): ?>
    <div id="modalTicket" style="position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center; padding:20px;">
        <div style="background:var(--panel); padding:35px; border-radius:14px; text-align:center; border:1px solid var(--accent); width:420px; max-width:100%; box-shadow:0 24px 70px rgba(0,0,0,.7);">
            <i class="fas fa-check-circle" style="font-size:3rem; color:var(--success); margin-bottom:15px;"></i>
            <h3 style="color:var(--success); margin:0 0 10px 0;">Venta Procesada</h3>
            <p style="color:var(--text);">¿Desea imprimir el ticket N° <strong style="color:var(--accent);"><?php echo (int)$ticket_doc_a_imprimir; ?></strong>?</p>
            <div style="margin-top:25px; display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                <button onclick="window.open('<?php echo route_file('pages/vista_previa_ticket.php'); ?>?n_documento=<?php echo (int)$ticket_doc_a_imprimir; ?>', '_blank'); this.parentElement.parentElement.parentElement.style.display='none';"
                        style="background:var(--accent); color:#000; border:none; border-radius:10px; padding:12px 20px; font-weight:700; cursor:pointer; font-family:inherit;">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button onclick="window.location.href='<?php echo route_file('pages/generar_pdf_ticket.php'); ?>?n_documento=<?php echo (int)$ticket_doc_a_imprimir; ?>&download=1';"
                        style="background:#e67e22; color:#fff; border:none; border-radius:10px; padding:12px 20px; font-weight:700; cursor:pointer; font-family:inherit;">
                    <i class="fas fa-file-pdf"></i> Descargar PDF
                </button>
                <button onclick="this.parentElement.parentElement.parentElement.style.display='none'; window.location.href='<?php echo route_file('pages/ventarapida.php'); ?>'"
                        style="background:var(--panel-alt); color:var(--text); border:1px solid var(--border); border-radius:10px; padding:12px 20px; font-weight:700; cursor:pointer; font-family:inherit;">
                    Cerrar
                </button>
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