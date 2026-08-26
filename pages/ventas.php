<?php
// pages/ventas.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

require '../config/db_config.php';

$mensaje = '';

// Capturamos el usuario de la sesión
$usuario_activo = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'Sistema';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $mensaje = "❌ ERROR CRÍTICO: La conexión a la base de datos no está disponible.";
} else {
    // 1. Obtener Clientes, Rubros y Proveedores
    try {
        $empresa_id = $_SESSION['empresa_id'] ?? 1;
        $sql_clientes = "SELECT c.id AS id_cliente, 
                                CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo, 
                                c.cuit AS num_documento,
                                COALESCE(SUM(cc.debe - cc.haber), 0) AS saldo_deudor,
                                c.habilita_cta
                         FROM clientes c
                         LEFT JOIN ctacte cc ON c.id = cc.id_cliente AND cc.empresa_id = c.empresa_id
                         WHERE c.empresa_id = ?
                         GROUP BY c.id, c.apellido, c.nombre, c.cuit, c.habilita_cta
                         ORDER BY c.apellido, c.nombre ASC";
        $stmt_clientes = $pdo->prepare($sql_clientes);
        $stmt_clientes->execute([$empresa_id]);
        $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

        // Listas para el modal de registro rápido de productos
        $rubros_list = $pdo->query("SELECT nombre FROM rubros ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        $proveedores_list = $pdo->query("SELECT razon FROM proveedores ORDER BY razon ASC")->fetchAll(PDO::FETCH_ASSOC);

        // Obtener Ganancia Global de la configuración
        $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
        $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
    } catch (Exception $e) {
        $clientes = [];
        $rubros_list = [];
        $proveedores_list = [];
    }

    // Presupuestos emitidos para copiar sus productos a la venta
    $presupuestos_disponibles = [];
    try {
        $empresa_id_pres = $_SESSION['empresa_id'] ?? ($empresa_id ?? null);
        if ($empresa_id_pres) {
            $pres_query = "SELECT p.id, p.total_presupuesto, p.fecha_presupuesto,
                                  CONCAT(c.apellido, ' ', c.nombre) AS cliente_nombre
                           FROM presupuestos p
                           LEFT JOIN clientes c ON p.id_cliente = c.id AND c.empresa_id = ?
                           WHERE p.empresa_id = ?
                           ORDER BY p.id DESC LIMIT 50";
            $st = $pdo->prepare($pres_query);
            $st->execute([$empresa_id_pres, $empresa_id_pres]);
            $presupuestos_disponibles = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $presupuestos_disponibles = [];
    }

    // 2. LÓGICA DE PROCESAMIENTO
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {
        $accion = $_POST['venta_action'];
        $es_finalizar = ($accion === 'Finalizar');
        $estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';
        
        $id_venta_existente = (isset($_POST['id_venta_existente'])) ? (int)$_POST['id_venta_existente'] : 0;
        $id_cliente = (isset($_POST['id_cliente_hidden'])) ? (int)$_POST['id_cliente_hidden'] : 0;
        $cond_pago = (isset($_POST['cond_pago'])) ? trim($_POST['cond_pago']) : 'CONTADO';
        $observaciones = (isset($_POST['observaciones'])) ? trim($_POST['observaciones']) : '';
        
        // Validar que el cliente tenga habilitada la cuenta corriente si aplica
        if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
            $stmt_habilita = $pdo->prepare("SELECT habilita_cta FROM clientes WHERE id = ? AND empresa_id = ?");
            $stmt_habilita->execute([$id_cliente, $empresa_id]);
            $habilita_cta = strtoupper($stmt_habilita->fetchColumn() ?: 'NO');
            
            if ($habilita_cta === 'NO') {
                $mensaje = "❌ Error: Este cliente no tiene habilitada la cuenta corriente.";
                header("Location: " . url('ventas'));
                exit();
            }
        }
        // Fix: Soporte para decimales con coma en los campos de pago
        $pago_efectivo = (isset($_POST['pago_efectivo'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_efectivo'])) : 0.0;
        $pago_transf = (isset($_POST['pago_transf'])) ? max(0.0, (float)str_replace(',', '.', $_POST['pago_transf'])) : 0.0;
        // Nuevos campos para descuentos
        $desc_global_tipo = $_POST['desc_global_tipo'] ?? 'fijo';
        $desc_global_valor = (isset($_POST['desc_global_valor'])) ? max(0.0, (float)str_replace(',', '.', $_POST['desc_global_valor'])) : 0.0;
        // Nuevos campos para financiación
        $cant_cuotas = isset($_POST['cuotas_selector']) ? (int)$_POST['cuotas_selector'] : 1;
        $intervalo_dias = isset($_POST['intervalo_cuotas']) ? (int)$_POST['intervalo_cuotas'] : 30;
        $interes_porc = isset($_POST['interes_manual']) ? (float)$_POST['interes_manual'] : 0;
        $cobrar_primera_hoy = isset($_POST['cobrar_primera_hoy']) && $_POST['cobrar_primera_hoy'] === '1';
        $detalle_productos = json_decode($_POST['detalle_productos'], true);

        if (empty($detalle_productos)) {
            $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
        } else {
            try {
                $pdo->beginTransaction();
                $productos_sin_stock = [];

                // --- RECALCULO Y VALIDACIÓN ---
                $total_bruto = 0;
                $empresa_id = $_SESSION['empresa_id'] ?? 1;
                $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
                
                foreach ($detalle_productos as &$item) {
                    // Consultar producto y stock por sucursal
                    // CORRECCIÓN: Forzar collation en el JOIN para evitar error 1267
                    // (misma collation que usan los demás archivos del proyecto)
                    $sql_v = "SELECT p.p_venta, p.p_compra, p.descripcion, p.moneda, 
                                     COALESCE(s.stock_actual, 0) as stock_actual
                              FROM productos p 
                              LEFT JOIN stocks s ON p.cod_prod COLLATE utf8mb4_unicode_ci = s.cod_prod COLLATE utf8mb4_unicode_ci 
                                  AND s.empresa_id = ? 
                                  AND s.sucursal_id = ?
                              WHERE p.cod_prod = ?";
                    $stmt_v = $pdo->prepare($sql_v);
                    $stmt_v->execute([$empresa_id, $sucursal_id, $item['cod_prod']]);
                    $prod_db = $stmt_v->fetch(PDO::FETCH_ASSOC);

                    if (!$prod_db) throw new Exception("Producto no encontrado: " . $item['cod_prod']);
                    
                    // Ya no lanzamos excepción, solo guardamos el nombre para avisar luego
                    // CORRECCIÓN: Validar stock de tabla stocks (stock_actual) en vez de productos (stock)
                    if ($es_finalizar && (float)$prod_db['stock_actual'] < (float)$item['cant']) {
                        $productos_sin_stock[] = $prod_db['descripcion'];
                    }

                    // Soporte moneda del producto: si está en 'dolar', convertimos a pesos con el dólar operativo (dólar de venta).
                    $moneda_prod = $prod_db['moneda'] ?? 'pesos';

                    if ($moneda_prod === 'dolar') {
                        $dolar_operativo = null;
                        try {
                            // obtener_dolar.php devuelve compra/venta y arma un caché en cache/dolar_cache.json
                            $dolar_json = file_get_contents(__DIR__ . '/../funciones/obtener_dolar.php');
                            // Nota: obtener_dolar.php es un PHP que además imprime HTML en algunos casos; por eso evitamos usarlo.
                        } catch (Throwable $t) {
                            $dolar_operativo = null;
                        }

                        // Alternativa robusta: usar el archivo cache/dolar_cache.json si existe; si no, intentar obtener vía endpoint.
                        $cache_path = __DIR__ . '/../cache/dolar_cache.json';
                        if (file_exists($cache_path)) {
                            $cache = json_decode(file_get_contents($cache_path), true);
                            if (is_array($cache) && isset($cache['venta'])) {
                                $dolar_operativo = (float)$cache['venta'];
                            }
                        }

                        // Si no hay caché válido, intentamos capturar llamando al endpoint actualizar_dolar.php (devuelve JSON).
                        if ($dolar_operativo === null || $dolar_operativo <= 0) {
                            try {
                                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                                $json = @file_get_contents((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/../funciones/actualizar_dolar.php', false, $ctx);
                                $data_dolar = json_decode($json, true);
                                if (is_array($data_dolar) && isset($data_dolar['venta'])) {
                                    $dolar_operativo = (float)$data_dolar['venta'];
                                }
                            } catch (Throwable $t) {
                                $dolar_operativo = null;
                            }
                        }

                        if ($dolar_operativo === null || $dolar_operativo <= 0) {
                            throw new Exception("No se pudo obtener el dólar operativo para convertir el producto en USD: {$item['cod_prod']}.");
                        }

                        $item['p_unit'] = (float)$prod_db['p_venta'] * $dolar_operativo;
                        $item['p_costo_venta'] = (float)$prod_db['p_compra'] * $dolar_operativo;
                    } else {
                        $item['p_unit'] = (float)$prod_db['p_venta'];
                        $item['p_costo_venta'] = (float)$prod_db['p_compra'];
                    }

                    
                    // Descuento por producto (Tratado como porcentaje)
                    $desc_porc_item = isset($item['desc']) ? (float)$item['desc'] : 0;
                    $subtotal_item = $item['p_unit'] * (float)$item['cant'];
                    $monto_desc_item = $subtotal_item * ($desc_porc_item / 100);
                    $item['total'] = $subtotal_item - $monto_desc_item;
                    $total_bruto += $item['total'];
                }
                unset($item);

                $monto_desc_global = ($desc_global_tipo === 'porcentaje') ? ($total_bruto * ($desc_global_valor / 100)) : $desc_global_valor;
                $total_recalculado = max(0, $total_bruto - $monto_desc_global);

                // Obtener N° Documento correlativo
                if ($id_venta_existente <= 0) {
                    $stmt_n = $pdo->query("SELECT MAX(n_documento) AS ultimo FROM ventas FOR UPDATE");
                    $res_n = $stmt_n->fetch();
                    $n_documento = ($res_n['ultimo'] !== null) ? $res_n['ultimo'] + 1 : 1;
                }

                $id_venta_actual = 0;
                // --- A) Insertar o Actualizar Cabecera de Venta ---
                if ($id_venta_existente > 0) {
                    $sql_v = "UPDATE ventas SET id_cliente=?, cond_pago=?, total_venta=?, descuento_global=?, tipo_descuento_global=?, pago_efectivo=?, pago_transf=?, estado=?, usuario=?, observaciones=? WHERE id=?";
                    $pdo->prepare($sql_v)->execute([$id_cliente, $cond_pago, $total_recalculado, $monto_desc_global, $desc_global_tipo, $pago_efectivo, $pago_transf, $estado_venta, $usuario_activo, $observaciones, $id_venta_existente]);
                    
                    $stmt_doc = $pdo->prepare("SELECT n_documento FROM ventas WHERE id=?");
                    $stmt_doc->execute([$id_venta_existente]);
                    $n_documento = $stmt_doc->fetchColumn();

                    $pdo->prepare("DELETE FROM ventas_detalle WHERE n_documento = ?")->execute([$n_documento]);
                    // Limpiamos financiación previa si existiera para evitar duplicados
                    $pdo->prepare("DELETE FROM ventas_financiacion WHERE id_venta = ?")->execute([$id_venta_existente]);
                    $pdo->prepare("DELETE FROM cuotas_seguimiento WHERE id_venta = ?")->execute([$id_venta_existente]);
                    
                    $id_venta_actual = $id_venta_existente;
                } else {
                    $fecha_venta = date('Y-m-d H:i:s');
                    $empresa_id = $_SESSION['empresa_id'] ?? 1;
                    $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
                    $sql_v = "INSERT INTO ventas (empresa_id, sucursal_id, id_cliente, cond_pago, n_documento, total_venta, descuento_global, tipo_descuento_global, pago_efectivo, pago_transf, fecha_venta, estado, usuario, observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                    $pdo->prepare($sql_v)->execute([$empresa_id, $sucursal_id, $id_cliente, $cond_pago, $n_documento, $total_recalculado, $monto_desc_global, $desc_global_tipo, $pago_efectivo, $pago_transf, $fecha_venta, $estado_venta, $usuario_activo, $observaciones]);
                    $id_venta_actual = $pdo->lastInsertId();
                }

                // --- B) Insertar Detalle y Stock ---
                $empresa_id = $_SESSION['empresa_id'] ?? 1;
                $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
                $sql_d = "INSERT INTO ventas_detalle (empresa_id, sucursal_id, cod_prod, descripcion, cant, p_unit, descuento_unitario, p_costo_venta, total, n_documento, fecha) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                $stmt_d = $pdo->prepare($sql_d);
                foreach ($detalle_productos as $item) {
                    $desc_u = isset($item['desc']) ? (float)$item['desc'] : 0;
                    $stmt_d->execute([$empresa_id, $sucursal_id, $item['cod_prod'], $item['descripcion'], $item['cant'], $item['p_unit'], $desc_u, $item['p_costo_venta'], $item['total'], $n_documento, date('Y-m-d H:i:s')]);
                    
                    if ($es_finalizar) {
                        // Actualizar stock en la tabla stocks (por sucursal)
                        $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual - ? WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?")
                            ->execute([$item['cant'], $empresa_id, $sucursal_id, $item['cod_prod']]);
                    }
                }

                // --- C) Cuenta Corriente ---
                if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
                    $saldo_deuda = $total_recalculado - ($pago_efectivo + $pago_transf);
                    if ($saldo_deuda > 0) {
                        $empresa_id = $_SESSION['empresa_id'] ?? 1;
                        $sql_cc = "INSERT INTO ctacte (empresa_id, id_cliente, movimiento, n_documento, debe, haber, fecha) VALUES (?, ?, 'FACTURA', ?, ?, 0, NOW())";
                        $pdo->prepare($sql_cc)->execute([$empresa_id, $id_cliente, $n_documento, $saldo_deuda]);
                    }
                }

                // --- D) Lógica de Financiación (Cuotas) ---
                if ($es_finalizar && $cond_pago === 'FINANCIADO' && $id_cliente > 0) {
                    $saldo_a_financiar = $total_recalculado - ($pago_efectivo + $pago_transf);
                    $monto_interes = $saldo_a_financiar * ($interes_porc / 100);
                    $monto_total_cuotas = $saldo_a_financiar + $monto_interes;
                    $valor_cuota = $monto_total_cuotas / $cant_cuotas;

                    // 1. Insertar cabecera de financiación
                    $empresa_id = $_SESSION['empresa_id'] ?? 1;
                    $sql_finan = "INSERT INTO ventas_financiacion 
                                  (empresa_id, id_venta, cant_cuotas, intervalo_dias, interes_porcentaje, monto_interes, entrega_inicial, monto_cuota_sugerida)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_finan = $pdo->prepare($sql_finan);
                    $stmt_finan->execute([
                        $empresa_id,
                        $id_venta_actual,
                        $cant_cuotas,
                        $intervalo_dias,
                        $interes_porc,
                        $monto_interes,
                        ($pago_efectivo + $pago_transf),
                        $valor_cuota
                    ]);

                    // 2. Generar plan de cuotas en seguimiento
                    $sql_cuota = "INSERT INTO cuotas_seguimiento 
                                  (id_venta, nro_cuota, fecha_vencimiento, monto_original, estado)
                                  VALUES (?, ?, ?, ?, ?)";
                    $stmt_cuota = $pdo->prepare($sql_cuota);

                    for ($i = 1; $i <= $cant_cuotas; $i++) {
                        // Si "cobrar primera cuota hoy" está activo:
                        // - Cuota 1: vence hoy, estado "Pagada"
                        // - Cuotas 2..N: vencimiento según intervalo corrido desde hoy
                        if ($cobrar_primera_hoy && $i === 1) {
                            $vencimiento = date('Y-m-d'); // Hoy
                            $estado_cuota = 'Pagada';
                        } else {
                            // Para cuota 1 sin checkbox: vence según intervalo
                            // Para cuotas 2..N con checkbox: se corre el intervalo desde hoy
                            $dias_sumar = $cobrar_primera_hoy ? ($i - 1) * $intervalo_dias : $i * $intervalo_dias;
                            $vencimiento = date('Y-m-d', strtotime("+$dias_sumar days"));
                            $estado_cuota = 'Pendiente';
                        }
                        
                        $stmt_cuota->execute([
                            $id_venta_actual,
                            $i,
                            $vencimiento,
                            $valor_cuota,
                            $estado_cuota
                        ]);
                    }
                }

                // --- D) REGISTRO EN TABLA MOVIMIENTOS (CAJA) ---
                if ($es_finalizar) {
                    $monto_ingreso = ($cond_pago === 'CONTADO') ? $total_recalculado : ($pago_efectivo + $pago_transf);
                    
                    if ($monto_ingreso > 0) {
                        $metodo_pago_mov = ($pago_efectivo > 0 && $pago_transf > 0) ? 'MIXTO' : ($pago_efectivo > 0 ? 'EFECTIVO' : 'TRANSFERENCIA');
                        $empresa_id = $_SESSION['empresa_id'] ?? 1;
                        $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
                        
                        // Desglose de montos para ventas mixtas
                        $monto_efectivo_mov = ($metodo_pago_mov === 'MIXTO') ? $pago_efectivo : (($metodo_pago_mov === 'EFECTIVO') ? $monto_ingreso : 0);
                        $monto_transferencia_mov = ($metodo_pago_mov === 'MIXTO') ? $pago_transf : (($metodo_pago_mov === 'TRANSFERENCIA') ? $monto_ingreso : 0);
                        
                        $sql_mov = "INSERT INTO movimientos (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, monto_efectivo, monto_transferencia) VALUES (?, ?, 'INGRESO', ?, ?, ?, NOW(), ?, 0, ?, ?)";
                        
                        $detalle_mov = ($cond_pago === 'CUENTA CORRIENTE') ? "ENTREGA/PAGO - VENTA N° $n_documento (CTA. CTE.)" : "VENTA CONTADO N° $n_documento";
                        
                        $pdo->prepare($sql_mov)->execute([$empresa_id, $sucursal_id, $monto_ingreso, $metodo_pago_mov, $detalle_mov, $usuario_activo, $monto_efectivo_mov, $monto_transferencia_mov]);
                    }
                }

                $pdo->commit();
                $_SESSION['ticket_a_imprimir_doc'] = $n_documento;
                $_SESSION['ticket_a_imprimir_id'] = $id_venta_actual;
                $_SESSION['status_msj'] = "✅ Venta N° $n_documento procesada correctamente.";
                
                if (!empty($productos_sin_stock)) {
                    $_SESSION['status_msj_warning'] = "⚠️ Venta cerrada con stock insuficiente en: " . implode(", ", $productos_sin_stock);
                }

                header("Location: " . url('ventas'));
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                $mensaje = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

if (isset($_SESSION['status_msj'])) { $mensaje = $_SESSION['status_msj']; unset($_SESSION['status_msj']); }
$mensaje_warning = '';
if (isset($_SESSION['status_msj_warning'])) { $mensaje_warning = $_SESSION['status_msj_warning']; unset($_SESSION['status_msj_warning']); }
$ticket_doc_a_imprimir = isset($_SESSION['ticket_a_imprimir_doc']) ? $_SESSION['ticket_a_imprimir_doc'] : null;
$ticket_id_a_imprimir = isset($_SESSION['ticket_a_imprimir_id']) ? $_SESSION['ticket_a_imprimir_id'] : null;
$cliente_tel = '';
$cliente_nom = '';
if ($ticket_doc_a_imprimir) {
    $stmt_c = $pdo->prepare("SELECT c.telefono, CONCAT(c.apellido, ' ', c.nombre) as nombre FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.n_documento = ?");
    $stmt_c->execute([$ticket_doc_a_imprimir]);
    $res_c = $stmt_c->fetch();
    if ($res_c) {
        $cliente_tel = $res_c['telefono'];
        $cliente_nom = $res_c['nombre'];
    }
}
unset($_SESSION['ticket_a_imprimir_doc']);
unset($_SESSION['ticket_a_imprimir_id']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ventas | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/ventas.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Nueva Venta</h1>
        

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <div class="venta-grid">
            <div class="card">
                <div class="contenedor-busqueda" style="position:relative;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label><i class="fas fa-search"></i> Buscar Producto</label>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <button type="button" class="btn" onclick="abrirModalCopiarPresupuesto()" title="Copiar productos de un presupuesto emitido" style="padding: 2px 8px; margin-bottom: 5px; font-size: 0.8rem; background: #6c3483; color: #fff; border: 1px solid #8e44ad; white-space: nowrap;">Copiar Presupuesto</button>
                            <button type="button" class="btn btn-success" onclick="abrirModalNuevoProducto()" title="Agregar nuevo producto" style="padding: 2px 8px; margin-bottom: 5px; font-size: 0.8rem; background: #27ae60;">+ Nuevo</button>
                        </div>
                    </div>
                    <input type="text" id="buscar_producto" class="input-field" autocomplete="off" placeholder="Escribe nombre o código...">
                    <div id="resultadosBusqueda"></div> 
                </div>
                
                <h3 style="margin-top:25px; color:#00bcd4;"><i class="fas fa-shopping-cart"></i> Carrito de Compra</h3>
                <div class="carrito-container-scroll">
                    <table id="carrito" class="table-full">
                        <thead>
                            <tr>
                                <th>Cód.</th>
                                <th>Producto</th>
                                <th>Precio</th>
                                <th>Cant.</th>
                                <th>Subtotal</th>
                                <th>Desc.</th>
                                <th>-</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div style="margin-top: 20px;">
                    <label for="observaciones"><i class="fas fa-sticky-note"></i> Observaciones</label>
                    <textarea name="observaciones" id="observaciones" class="input-field" rows="3"
                              placeholder="Notas opcionales sobre la venta (aparecerán en el ticket)..."
                              style="width: 100%; resize: vertical; font-family: inherit;"></textarea>
                </div>
            </div>

            <div class="card">
                <form id="formVenta" method="POST">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">
                    <input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">
                    <input type="hidden" name="id_venta_existente" id="id_venta_existente" value="">
                    <input type="hidden" name="id_cliente_hidden" id="id_cliente_hidden" value="0">
                    <input type="hidden" name="observaciones" id="observaciones_hidden" value="">

                    <div class="contenedor-busqueda-cliente" style="position:relative;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label><i class="fas fa-user-tag"></i> Cliente</label>
                            <button type="button" class="btn btn-success" onclick="abrirModalNuevoCliente()" title="Agregar nuevo cliente" style="padding: 2px 8px; margin-bottom: 5px; font-size: 0.8rem; background: #27ae60;">+ Nuevo</button>
                        </div>
                        <input type="text" id="buscar_cliente" class="input-field" autocomplete="off" placeholder="Buscar cliente...">
                        <div id="resultadosBusquedaClientes"></div>
                    </div>

                    <div class="alert alert-info" style="margin-top:15px;">
                        <i class="fas fa-user"></i> <span id="nombre_cliente_display">Venta Genérica</span>
                    </div>

                    <hr>
                    <label>Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field">
                        <option value="CONTADO">CONTADO</option>
                        <option value="CUENTA CORRIENTE">CUENTA CORRIENTE</option>
                        <option value="FINANCIADO">FINANCIADO</option>
                    </select>

                    <div id="panel_descuento_global" style="margin-top: 15px; padding: 10px; background: #252525; border-radius: 6px; border: 1px solid #444;">
                        <label><i class="fas fa-percentage"></i> Descuento Global</label>
                        <div style="display: flex; gap: 5px;">
                            <select name="desc_global_tipo" id="desc_global_tipo" class="input-field" style="flex: 1;">
                            <option value="porcentaje">Porcentaje (%)</option>    
                            <option value="fijo">Monto ($)</option>
                            </select>
                            <input type="number" step="0.01" name="desc_global_valor" id="desc_global_valor" class="input-field" style="flex: 1;" placeholder="0.00" value="0">
                        </div>
                    </div>

                    <!-- Panel de Financiación (Solicitado en Manifiesto) -->
                    <div id="panel_financiacion" style="display: none; background: #252525; padding: 15px; border-radius: 8px; border: 1px dashed #00bcd4; margin-top: 15px;">
                        <h3 style="color: #00bcd4; margin-top: 0;"><i class="fas fa-hand-holding-usd"></i> Detalles de Financiación</h3>
                        
                        <label for="cuotas_selector">Cantidad de Cuotas</label>
                        <input type="number" id="cuotas_selector" name="cuotas_selector" class="input-field" value="1" min="1" step="1">

                        <label for="intervalo_cuotas">Intervalo entre Cuotas (días)</label>
                        <select id="intervalo_cuotas" name="intervalo_cuotas" class="input-field">
                            <option value="30">30 días</option>
                            <option value="15">15 días</option>
                            <option value="10">10 días</option>
                            <option value="7">7 días</option>
                        </select>

                        <label for="interes_manual">Interés Manual (%)</label>
                        <input type="number" step="0.01" id="interes_manual" name="interes_manual" class="input-field" value="0" min="0">

                        <!-- Checkbox: Cobrar primera cuota hoy -->
                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #444;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: #e0e0e0;">
                                <input type="checkbox" id="cobrar_primera_hoy" name="cobrar_primera_hoy" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                                <span><i class="fas fa-hand-holding-usd"></i> Cobrar primera cuota hoy</span>
                            </label>
                            <small style="color: #888; display: block; margin-top: 5px;">La primera cuota se cobra en el día de hoy. Las restantes se difieren según el intervalo.</small>
                        </div>

                        <div style="display: flex; justify-content: space-between; font-size: 1.1em; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #444;">
                            <strong>Valor Cuota:</strong>
                            <strong id="info_valor_cuota" style="color: #2ecc71;">$0.00</strong>
                        </div>

                        <!-- Botón Ver Plan de Cuotas -->
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn btn-info btn-block" id="btnVerPlanCuotas" style="background: #17a2b8; color: white; padding: 10px; border: none; border-radius: 6px; cursor: pointer; width: 100%; font-weight: bold;">
                                <i class="fas fa-table"></i> Ver Plan de Cuotas Detallado
                            </button>
                        </div>
                    </div>

                    <div class="total-box">
                        <p style="margin:0; color:#aaa; text-transform:uppercase; letter-spacing:1px; font-size:0.8rem;">Total a Cobrar</p>
                        <span id="total_venta_display">$ 0.00</span>
                        <input type="hidden" name="total_venta_input" id="total_venta_input" value="0.00">
                    </div>

                    <div id="vuelto_contenedor" class="vuelto-box">
                        <p style="margin:0; color:#aaa;">SU VUELTO</p>
                        <span id="vuelto_display">$ 0.00</span>
                    </div>

                    <label>Pago en Efectivo ($)</label>
                    <input type="number" step="0.01" name="pago_efectivo" id="pago_efectivo" class="input-field" value="" placeholder="0.00">
                    
                    <label style="margin-top:10px;">Pago por Transferencia ($)</label>
                    <input type="number" step="0.01" name="pago_transf" id="pago_transf" class="input-field" value="" placeholder="0.00">

                    <button type="submit" class="btn btn-primary btn-block" style="height:55px; font-size:1.1rem; margin-top:20px; cursor:pointer;">
                        <i class="fas fa-check-circle"></i> Finalizar Venta
                    </button>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px;">
                        <button type="button" class="btn btn-success" id="btnGuardarPendiente" style="padding:10px;">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                        <button type="button" id="btnVerPendiente" class="btn btn-yellow" style="background: #f1c40f; color: #000; padding:10px;">
                            <i class="fas fa-clock"></i> Pendientes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="pendientesModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.9);">
        <div class="modal-content" style="background: #1a1a1a; margin: 5% auto; padding: 25px; width: 80%; border-radius: 12px; border: 1px solid #333; max-height: 85vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <h2 style="margin:0; color:#00bcd4;">Ventas en Espera</h2>
                <span onclick="document.getElementById('pendientesModal').style.display='none'" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>
            <div id="listaPendientes"></div>
        </div>
    </div>

    <!-- Modal Nuevo Producto Rápido -->
    <div id="modalNuevoProducto" class="modal" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.9);">
        <div class="modal-content" style="background: #1a1a1a; margin: 2% auto; padding: 25px; width: 450px; border-radius: 12px; border: 1px solid #333; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <h2 style="margin:0; color:#00bcd4;">Registrar Producto</h2>
                <span onclick="cerrarModalNuevoProducto()" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>
            <form id="formNuevoProducto">
                <label>Código de Barras / Interno *</label>
                <input type="text" id="np_cod_prod" class="input-field" required>
                <label>Descripción *</label>
                <input type="text" id="np_descripcion" class="input-field" required>
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1;">
                        <label>Precio Costo ($) *</label>
                        <input type="number" step="0.01" id="np_p_compra" class="input-field" required oninput="calcularPrecioVentaSugerido()">
                    </div>
                    <div style="flex: 1;">
                        <label>Precio Venta ($) *</label>
                        <input type="number" step="0.01" id="np_p_venta" class="input-field" required>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1;">
                        <label>Stock Inicial</label>
                        <input type="number" step="0.01" id="np_stock" class="input-field" value="0">
                    </div>
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Rubro</label>
                    <select id="np_rubro" class="input-field">
                        <?php foreach ($rubros_list as $r): ?>
                            <option value="<?php echo $r['nombre']; ?>" <?php echo ($r['nombre'] == 'VARIOS') ? 'selected' : ''; ?>><?php echo $r['nombre']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Proveedor</label>
                    <select id="np_proveedor" class="input-field">
                        <?php foreach ($proveedores_list as $p): ?>
                            <option value="<?php echo $p['razon']; ?>" <?php echo ($p['razon'] == 'GENERAL') ? 'selected' : ''; ?>><?php echo $p['razon']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary btn-block" onclick="guardarNuevoProducto()" style="margin-top: 15px; height: 45px; font-weight: bold;">GUARDAR Y AGREGAR</button>
            </form>
        </div>
    </div>

    <!-- Modal Copiar Presupuesto -->
    <div id="modalCopiarPresupuestoVenta" class="modal" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.9);">
        <div class="modal-content" style="background: #1a1a1a; margin: 10% auto; padding: 25px; width: 500px; border-radius: 12px; border: 1px solid #333;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <h2 style="margin:0; color:#b07cc6;"><i class="fas fa-copy"></i> Copiar de Presupuesto Emitido</h2>
                <span onclick="cerrarModalCopiarPresupuesto()" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>

            <label>Selecciona un presupuesto:</label>
            <select id="selectPresupuestoVenta" class="input-field" style="width:100%; padding:10px;">
                <option value="">-- Seleccionar presupuesto --</option>
                <?php foreach ($presupuestos_disponibles as $pres): ?>
                <option value="<?php echo $pres['id']; ?>">
                    #<?php echo $pres['id']; ?> - 
                    <?php echo htmlspecialchars($pres['cliente_nombre'] ?: 'Sin cliente'); ?> - 
                    $<?php echo number_format($pres['total_presupuesto'], 2, ',', '.'); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <small style="color:#888;">Los productos se cargan con el precio del presupuesto. Si un precio cambió, haz clic en la flechita junto al precio para actualizarlo al valor actual.</small>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #333; padding-top: 15px;">
                <button type="button" onclick="cerrarModalCopiarPresupuesto()" class="btn btn-secondary" style="background:#444; color:#fff; padding:10px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer;">Cancelar</button>
                <button type="button" onclick="copiarPresupuestoVenta()" class="btn btn-success" style="background:#27ae60; color:#fff; padding:10px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer;">
                    <i class="fas fa-copy"></i> Copiar Productos
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Cliente -->
    <div id="modalNuevoCliente" class="modal" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.9);">
        <div class="modal-content" style="background: #1a1a1a; margin: 10% auto; padding: 25px; width: 400px; border-radius: 12px; border: 1px solid #333;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <h2 style="margin:0; color:#00bcd4;">Registrar Cliente</h2>
                <span onclick="cerrarModalNuevoCliente()" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>
            <form id="formNuevoCliente">
                <label>Apellido *</label>
                <input type="text" id="nc_apellido" class="input-field" required placeholder="Obligatorio">
                <label>Nombre</label>
                <input type="text" id="nc_nombre" class="input-field">
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label>DNI</label>
                        <input type="text" id="nc_dni" class="input-field" placeholder="Sólo números">
                    </div>
                    <div style="flex: 1;">
                        <label>CUIT</label>
                        <input type="text" id="nc_cuit" class="input-field" placeholder="Sólo números">
                    </div>
                </div>
                <label>Condición IVA</label>
                <select id="nc_id_tipo_iva" class="input-field">
                    <option value="99" selected>Consumidor Final</option>
                    <option value="1">Responsable Inscripto</option>
                    <option value="6">Monotributo</option>
                    <option value="4">Exento</option>
                </select>
                <label>Teléfono</label>
                <input type="text" id="nc_telefono" class="input-field">
                <button type="button" class="btn btn-primary btn-block" onclick="guardarNuevoCliente()" style="margin-top: 15px; height: 45px; font-weight: bold;">GUARDAR Y SELECCIONAR</button>
            </form>
        </div>
    </div>

    <?php if ($ticket_doc_a_imprimir): ?>
    <div id="modalTicket" style="position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;">
        <div style="background: #1a1a1a; padding: 35px; border-radius: 12px; text-align: center; border: 1px solid #00bcd4; width: 380px;">
            <i class="fas fa-print" style="font-size: 3rem; color: #00bcd4; margin-bottom: 15px;"></i>
            <h3 style="color: #4caf50; margin-bottom: 10px;">Venta Procesada</h3>
            <p style="color: #ccc;">¿Desea imprimir el ticket N° <strong><?php echo $ticket_doc_a_imprimir; ?></strong>?</p>
            <div style="margin-top: 25px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <button onclick="window.open('vista_previa_ticket.php?n_documento=<?php echo $ticket_doc_a_imprimir; ?>', '_blank'); this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-primary" style="padding: 10px 20px;">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <?php $pdf_ref_ventas = ((int)($ticket_id_a_imprimir ?? 0)) > 0 ? ('id=' . (int)$ticket_id_a_imprimir) : ('n_documento=' . (int)$ticket_doc_a_imprimir); ?>
                <button onclick="enviarTicketWA('', '', '<?php echo $pdf_ref_ventas; ?>', event)" class="btn" style="background: #e67e22; color: white; padding: 10px 20px;">
                    <i class="fas fa-file-pdf"></i> Descargar PDF
                </button>
                <button onclick="this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-secondary" style="padding: 10px 20px; background:#444;">Cerrar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Plan de Cuotas Detallado -->
    <div id="modalPlanCuotas" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.9);">
        <div class="modal-content" style="background: #1a1a1a; margin: 3% auto; padding: 25px; width: 70%; border-radius: 12px; border: 1px solid #333; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <h2 style="margin:0; color:#00bcd4;"><i class="fas fa-table"></i> Plan de Cuotas Detallado</h2>
                <span onclick="document.getElementById('modalPlanCuotas').style.display='none'" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>
            <div id="contenidoPlanCuotas">
                <p style="color: #888;">Cargando...</p>
            </div>
        </div>
    </div>

    <script src="<?php echo url('js/ventas.js?v=' . time()); ?>"></script>
    <script>
        var clientesData = <?php echo json_encode($clientes); ?>;

        // RESTAURAR CARRITO SI HUBO ERROR DE STOCK
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($mensaje) && strpos($mensaje, '❌') !== false): ?>
            document.addEventListener('DOMContentLoaded', function() {
                carrito = <?php echo $_POST['detalle_productos']; ?>;
                renderizarCarrito(); // Función definida en ventas.js
            });
        <?php endif; ?>

        // MOSTRAR ADVERTENCIA DE STOCK SI EXISTE
        <?php if (!empty($mensaje_warning)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                mostrarToast("<?php echo addslashes($mensaje_warning); ?>", "error");
            });
        <?php endif; ?>

        function mostrarToast(mensaje, tipo = 'success') {
            const toast = document.createElement('div');
            toast.className = 'toast-notificacion';
            if (tipo === 'error') toast.style.background = '#e74c3c';
            toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('toast-fade-out');
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }

        function enviarTicketWA(telefono, nombre, nDoc, event) {
            // En lugar de enviar por WhatsApp, redireccionamos a la generación del PDF con el flag de descarga.
            // nDoc puede venir como 'id=...' o 'n_documento=...' (la búsqueda por id es la más robusta).
            const urlDownload = 'generar_pdf_ticket.php?' + nDoc + '&download=1';
            window.location.href = urlDownload;
            mostrarToast("Iniciando descarga del comprobante...");
        }

        // --- LÓGICA MODAL NUEVO CLIENTE ---
        function abrirModalNuevoCliente() {
            document.getElementById('modalNuevoCliente').style.display = 'block';
            document.getElementById('nc_apellido').focus();
        }

        function cerrarModalNuevoCliente() {
            document.getElementById('modalNuevoCliente').style.display = 'none';
            document.getElementById('formNuevoCliente').reset();
        }

        function guardarNuevoCliente() {
            const apellido = document.getElementById('nc_apellido').value.trim();
            const nombre = document.getElementById('nc_nombre').value.trim();
            const dni = document.getElementById('nc_dni').value.trim();
            const id_tipo_iva = document.getElementById('nc_id_tipo_iva').value;
            const cuit = document.getElementById('nc_cuit').value.trim();
            const telefono = document.getElementById('nc_telefono').value.trim();

            if (!apellido) {
                mostrarToast("El apellido del cliente es obligatorio.", "error");
                return;
            }

            const formData = new FormData();
            formData.append('apellido', apellido);
            formData.append('nombre', nombre);
            formData.append('dni', dni);
            formData.append('id_tipo_iva', id_tipo_iva);
            formData.append('cuit', cuit);
            formData.append('telefono', telefono);

            fetch('<?php echo URL_BASE; ?>ajax/agregar_cliente_rapido.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Actualizar clientesData para que aparezca en futuras búsquedas sin recargar
                    clientesData.push({
                        id_cliente: data.id_cliente,
                        nombre_completo: data.nombre_completo,
                        num_documento: cuit
                    });

                    // Seleccionar automáticamente al nuevo cliente
                    document.getElementById('id_cliente_hidden').value = data.id_cliente;
                    document.getElementById('nombre_cliente_display').innerText = data.nombre_completo;
                    mostrarToast("✅ Cliente seleccionado: " + data.nombre_completo);
                    cerrarModalNuevoCliente();
                } else {
                    mostrarToast(data.error, "error");
                }
            })
            .catch(err => console.error(err));
        }

        // --- LÓGICA MODAL NUEVO PRODUCTO ---
        window.abrirModalNuevoProducto = function() {
            const modal = document.getElementById('modalNuevoProducto');
            if (modal) {
                modal.style.display = 'block';
                // Si el usuario escribió algo en el buscador, lo usamos como código por defecto
                const busqueda = document.getElementById('buscar_producto').value.trim();
                if (busqueda !== "") document.getElementById('np_cod_prod').value = busqueda;
                document.getElementById('np_cod_prod').focus();
            }
        };

        window.cerrarModalNuevoProducto = function() {
            document.getElementById('modalNuevoProducto').style.display = 'none';
            document.getElementById('formNuevoProducto').reset();
        };

        window.guardarNuevoProducto = function() {
            const cod_prod = document.getElementById('np_cod_prod').value.trim();
            const descripcion = document.getElementById('np_descripcion').value.trim();
            const p_venta = document.getElementById('np_p_venta').value.trim();
            const p_compra = document.getElementById('np_p_compra').value.trim(); // Capturar p_compra
            const stock = document.getElementById('np_stock').value.trim();
            const rubro = document.getElementById('np_rubro').value;
            const proveedor = document.getElementById('np_proveedor').value;

            if (!cod_prod || !descripcion || !p_venta) {
                mostrarToast("Código, descripción y precio son obligatorios.", "error");
                return;
            }

            const formData = new FormData();
            formData.append('cod_prod', cod_prod);
            formData.append('p_compra', p_compra); // Enviar p_compra
            formData.append('descripcion', descripcion);
            formData.append('p_venta', p_venta);
            formData.append('stock', stock);
            formData.append('rubro', rubro);
            formData.append('proveedor', proveedor);

            fetch('<?php echo URL_BASE; ?>ajax/agregar_producto_rapido.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (typeof window.agregarAlCarrito === 'function') {
                        window.agregarAlCarrito(data.producto);
                    }
                    mostrarToast("✅ Producto registrado y agregado.");
                    cerrarModalNuevoProducto();
                } else {
                    mostrarToast(data.error, "error");
                }
            })
            .catch(err => console.error("Error en alta rápida:", err));
        };

        // --- LÓGICA MODAL COPIAR PRESUPUESTO ---
        window.abrirModalCopiarPresupuesto = function() {
            document.getElementById('modalCopiarPresupuestoVenta').style.display = 'block';
        };

        window.cerrarModalCopiarPresupuesto = function() {
            document.getElementById('modalCopiarPresupuestoVenta').style.display = 'none';
        };

        // Cerrar modal al hacer clic fuera del contenido
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('modalCopiarPresupuestoVenta');
            if (event.target == modal) {
                cerrarModalCopiarPresupuesto();
            }
        });

        window.copiarPresupuestoVenta = function() {
            const select = document.getElementById('selectPresupuestoVenta');
            const id = select ? select.value : '';

            if (!id) {
                mostrarToast("⚠️ Selecciona un presupuesto emitido para copiar sus productos.", "error");
                return;
            }

            const cargarPresupuesto = function() {
                fetch('<?php echo URL_BASE; ?>ajax/obtener_detalle_presupuesto_json.php?id=' + id)
                    .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        mostrarToast("❌ " + (data.error || "No se pudo copiar el presupuesto."), "error");
                        return;
                    }

                    carrito.length = 0;
                    data.items.forEach(prod => {
                        const precioPres = parseFloat(prod.precio);
                        const precioAct = (prod.precio_actual !== null && prod.precio_actual !== undefined)
                                          ? parseFloat(prod.precio_actual) : null;
                        // Siempre se carga el precio del presupuesto. Las flechas permiten corregirlo luego.
                        const pUnit = precioPres;
                        const cant = parseFloat(prod.cantidad);
                        carrito.push({
                            cod_prod: prod.codigo,
                            descripcion: prod.descripcion,
                            p_unit: pUnit,
                            p_costo: 0,
                            cant: cant,
                            desc: 0,
                            total: pUnit * cant,
                            precio_presupuesto: precioPres,
                            precio_actual: precioAct,
                            precio_corregido: false
                        });
                    });

                    renderizarCarrito();
                    cerrarModalCopiarPresupuesto();
                    select.value = "";
                    mostrarToast("✅ Se copiaron " + carrito.length + " producto(s) al carrito (con el precio del presupuesto).");
                })
                .catch(err => {
                    console.error("Error al copiar presupuesto:", err);
                    mostrarToast("❌ Error al conectar con el servidor.", "error");
                });
            };

            if (carrito.length > 0) {
                confirmarAccion("Copiar Presupuesto", "⚠️ Esto reemplazará los productos actualmente en el carrito. ¿Deseas continuar?", "SÍ, CONTINUAR", "btn-primary", cargarPresupuesto);
                return;
            }
            cargarPresupuesto();
        };

        // Función para calcular precio de venta sugerido (60% de ganancia)
        window.calcularPrecioVentaSugerido = function() {
            const gananciaRef = <?php echo $ganancia_config; ?>;
            const pCompraInput = document.getElementById('np_p_compra');
            const pVentaInput = document.getElementById('np_p_venta'); // Asegúrate de que p_venta exista
            const pCompra = parseFloat(pCompraInput.value.replace(',', '.')) || 0;
            if (pCompra > 0) {
                const multiplicador = 1 + (gananciaRef / 100);
                pVentaInput.value = (pCompra * multiplicador).toFixed(2);
            } else { pVentaInput.value = ''; }
        };
    </script>
</body>
</html>