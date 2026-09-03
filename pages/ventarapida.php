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
                        // Determinar plazo de vencimiento configurable (default: 30 dias)
                        // IMPORTANTE: fecha_vencimiento es necesaria para que el sistema
                        // de intereses por mora detecte este movimiento como vencido.
                        $plazo_fiado = 30;
                        try {
                            $stmt_plazo = $pdo->prepare("SELECT plazo_fiado_dias FROM configuracion_intereses WHERE empresa_id = :empresa_id AND activo = 1 LIMIT 1");
                            $stmt_plazo->execute([':empresa_id' => $empresa_id]);
                            $plazo_fiado = (int)($stmt_plazo->fetchColumn() ?: 30);
                            if ($plazo_fiado <= 0) $plazo_fiado = 30;
                        } catch (Exception $e) {
                            $plazo_fiado = 30;
                        }
                        $fecha_vencimiento = date('Y-m-d', strtotime("+{$plazo_fiado} days"));
                        $sql_cc = "INSERT INTO ctacte (empresa_id, id_cliente, movimiento, n_documento, debe, haber, fecha, fecha_vencimiento, usuario)
                                   VALUES (?, ?, 'FACTURA', ?, ?, 0, NOW(), ?, ?)";
                        $pdo->prepare($sql_cc)->execute([$empresa_id, $id_cliente, $n_documento, $saldo_deuda, $fecha_vencimiento, $usuario_activo]);
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
                $_SESSION['ticket_a_imprimir_id'] = $id_venta_actual;
                $_SESSION['status_msj'] = "✅ Venta N° $n_documento procesada correctamente.";
                // Marca para que ventarapida.js limpie el carrito persistido en localStorage
                $_SESSION['pos_limpiar_carrito'] = true;

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
$ticket_id_a_imprimir = isset($_SESSION['ticket_a_imprimir_id']) ? $_SESSION['ticket_a_imprimir_id'] : null;
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
unset($_SESSION['ticket_a_imprimir_id']);

if (isset($_SESSION['status_msj'])) {
    $mensaje = $_SESSION['status_msj'];
    unset($_SESSION['status_msj']);
}
if (isset($_SESSION['status_msj_warning'])) {
    $mensaje_warning = $_SESSION['status_msj_warning'];
    unset($_SESSION['status_msj_warning']);
}
// Flag para que ventarapida.js borre el carrito persistido tras una venta exitosa
$pos_limpiar_carrito_js = false;
if (isset($_SESSION['pos_limpiar_carrito'])) { $pos_limpiar_carrito_js = true; unset($_SESSION['pos_limpiar_carrito']); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja Registradora | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/ventarapida.css'); ?>">
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
                <?php $pdf_ref_venta = ((int)($ticket_id_a_imprimir ?? 0)) > 0 ? ('id=' . (int)$ticket_id_a_imprimir) : ('n_documento=' . (int)$ticket_doc_a_imprimir); ?>
                <button onclick="window.location.href='<?php echo route_file('pages/generar_pdf_ticket.php'); ?>?<?php echo $pdf_ref_venta; ?>&download=1';"
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
<script>window.LIMPIAR_CARRITO = <?php echo !empty($pos_limpiar_carrito_js) ? 'true' : 'false'; ?>;</script>
    <script src="<?php echo url('js/ventarapida.js?v=' . time()); ?>"></script>
</body>
</html>