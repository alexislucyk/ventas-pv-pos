<?php
include 'infosesion.php';
require_once '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Cargar lista de clientes para el autocompletado del buscador
$clientes = [];
try {
    $stmt_c = $pdo->prepare("SELECT id, CONCAT(apellido, ', ', nombre) as nombre_completo, cuit as num_documento FROM clientes WHERE empresa_id = ? ORDER BY nombre_completo");
    $stmt_c->execute([$empresa_id]);
} catch(Exception $e) {
    error_log("Error cargando clientes en anulaciones: " . $e->getMessage());
}

$mensaje = '';

// Procesar la anulación si se envía el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['n_documento_anular'])) {
    $n_doc = $_POST['n_documento_anular'];
    $motivo = trim($_POST['motivo']);
    $es_anulacion_total = isset($_POST['anular_todo']);
    $items_devolver = isset($_POST['items_devolver']) ? $_POST['items_devolver'] : []; // [cod_prod => cantidad]
    $fecha_hoy = date('Y-m-d H:i:s'); 
    $usuario_actual = $_SESSION['usuario_nombre'] ?? 'Sistema';

    try {
        $pdo->beginTransaction();

        // --- LÓGICA DE NUMERADOR CORRELATIVO DINÁMICO ---
        // Ahora que tenemos tabla propia, es mucho más simple y seguro obtener el siguiente OP#
        $stmt_max = $pdo->query("SELECT MAX(op_n) FROM devoluciones");
        $nuevo_n_op = (int)$stmt_max->fetchColumn() + 1;

        // 1. Obtener datos de la venta (incluyendo tipo de pago y cliente)
        // Asegúrate de que el campo en tu tabla 'ventas' sea 'tipo_pago' o 'cond_pago'
        $stmt = $pdo->prepare("SELECT id, estado, id_cliente, total_venta, cond_pago, pago_efectivo, pago_transf FROM ventas WHERE n_documento = ? AND empresa_id = ?");
        $stmt->execute([$n_doc, $empresa_id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            throw new Exception("La venta no existe.");
        }
        if ($venta['estado'] === 'Anulada') {
            throw new Exception("Esta venta ya ha sido anulada anteriormente.");
        }

        $monto_a_reintegrar = 0;
        $texto_movimiento = "";
        $lista_items_texto = "";
        $items_comprobante = [];

        if ($es_anulacion_total) {
            // --- LÓGICA ANULACIÓN TOTAL ---
            $stmtDetalle = $pdo->prepare("SELECT cod_prod, descripcion, cant, p_unit, total FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?");
            $stmtDetalle->execute([$n_doc, $empresa_id]);
            $productos = $stmtDetalle->fetchAll();

            foreach ($productos as $prod) {
                $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual + ? WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?")
                    ->execute([$prod['cant'], $empresa_id, $sucursal_id, $prod['cod_prod']]);
                $lista_items_texto .= "\n- " . $prod['descripcion'] . " (x" . $prod['cant'] . ")";
                
                $items_comprobante[] = [
                    'cod_prod' => $prod['cod_prod'],
                    'desc' => $prod['descripcion'],
                    'cant' => $prod['cant'],
                    'p_unit' => $prod['p_unit'],
                    'total' => $prod['total']
                ];
            }

            $pdo->prepare("UPDATE ventas SET estado = 'Anulada' WHERE n_documento = ? AND empresa_id = ?")->execute([$n_doc, $empresa_id]);
            $monto_a_reintegrar = $venta['total_venta'];
            $texto_movimiento = "ANULACIÓN TOTAL VENTA N° $n_doc";
        } else {
            // --- LÓGICA DEVOLUCIÓN PARCIAL ---
            foreach ($items_devolver as $cod_prod => $cant_dev) {
                // PHP Fix: Asegurar que cantidades fraccionadas (ej: 1,5 kg) no se guarden como enteros
                $cant_dev = (float)str_replace(',', '.', $cant_dev);
                if ($cant_dev <= 0) continue;

                // Validamos precio y cantidad original para seguridad
                $stmt_v = $pdo->prepare("SELECT descripcion, cant, p_unit FROM ventas_detalle WHERE n_documento = ? AND cod_prod = ? AND empresa_id = ?");
                $stmt_v->execute([$n_doc, $cod_prod, $empresa_id]);
                $original = $stmt_v->fetch();

                if ($original && $cant_dev <= $original['cant']) {
                    $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual + ? WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod = ?")
                        ->execute([$cant_dev, $empresa_id, $sucursal_id, $cod_prod]);
                    
                    $subtotal = ($cant_dev * $original['p_unit']);
                    $monto_a_reintegrar += $subtotal;
                    $lista_items_texto .= "\n- " . $original['descripcion'] . " (x" . $cant_dev . ")";

                    $items_comprobante[] = [
                        'cod_prod' => $cod_prod,
                        'desc' => $original['descripcion'],
                        'cant' => $cant_dev,
                        'p_unit' => $original['p_unit'],
                        'total' => $subtotal
                    ];
                }
            }
            $texto_movimiento = "DEVOLUCIÓN PARCIAL VENTA N° $n_doc";
        }

        // 4. Lógica de Cuenta Corriente (Crédito al cliente por lo devuelto/anulado)
        // Corrección: Usamos strtoupper para evitar errores de mayúsculas/minúsculas
        $detalle_final_db = "$texto_movimiento (OP#$nuevo_n_op) - MOTIVO: $motivo" . $lista_items_texto;

        if (strtoupper($venta['cond_pago']) === 'CUENTA CORRIENTE' && $monto_a_reintegrar > 0) {
            $pdo->prepare("INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                $venta['id_cliente'],
                $detalle_final_db,
                $n_doc,
                0,
                $monto_a_reintegrar,
                $fecha_hoy,
                $empresa_id
            ]);
        } 
        // 5. Lógica de Caja (Si fue CONTADO o FINANCIADO, registramos un EGRESO por el reintegro)
        elseif ((strtoupper($venta['cond_pago']) === 'CONTADO' || strtoupper($venta['cond_pago']) === 'FINANCIADO') && $monto_a_reintegrar > 0) {
            $monto_egreso_caja = $monto_a_reintegrar;

            if (strtoupper($venta['cond_pago']) === 'FINANCIADO') {
                // Si es financiado y la anulación es total, debemos anular el plan de pagos pendientes
                if ($es_anulacion_total) {
                    $pdo->prepare("UPDATE cuotas_seguimiento SET estado = 'Anulada' WHERE id_venta = ?")
                        ->execute([$venta['id']]);

                    // NEW LOGIC: Anular pagos de cuotas y revertir movimientos de caja
                    // 1. Obtener todos los IDs de cuotas de esta venta
                    $stmt_cuotas_ids = $pdo->prepare("SELECT id, nro_cuota FROM cuotas_seguimiento WHERE id_venta = ?");
                    $stmt_cuotas_ids->execute([$venta['id']]);
                    $cuotas_ids = $stmt_cuotas_ids->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($cuotas_ids as $cuota_info) {
                        $id_cuota_actual = $cuota_info['id'];
                        $nro_cuota_actual = $cuota_info['nro_cuota'];

                        // 2. Obtener todos los pagos realizados para cada cuota
                        $stmt_pagos_cuota = $pdo->prepare("SELECT id, monto, descuento, metodo_pago FROM cuotas_pagos WHERE id_cuota = ?");
                        $stmt_pagos_cuota->execute([$id_cuota_actual]);
                        $pagos_realizados = $stmt_pagos_cuota->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($pagos_realizados as $pago_cuota) {
                            $monto_pagado_total = (float)$pago_cuota['monto'] + (float)($pago_cuota['descuento'] ?? 0);
                            
                            // Registrar EGRESO en movimientos para revertir el INGRESO original del pago
            $detalle_reversion = "REVERSIÓN PAGO CUOTA {$nro_cuota_actual} - VENTA N° {$n_doc} (ANULACIÓN TOTAL)";
                            $pdo->prepare("INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) 
                                           VALUES ('EGRESO', ?, ?, ?, NOW(), ?, 0, ?, ?)")
                                ->execute([$monto_pagado_total, $pago_cuota['metodo_pago'], $detalle_reversion, $usuario_actual]);
                            
                            // Eliminar el registro de pago de la cuota
                            $pdo->prepare("DELETE FROM cuotas_pagos WHERE id = ?")->execute([$pago_cuota['id']]);
                        }
                    }
                }

                // IMPORTANTE: Solo devolvemos en EFECTIVO lo que realmente entró a caja (Entrega + Cuotas pagadas)
                // Esta lógica ya estaba presente y es correcta para el cálculo del egreso de caja.
                // No se modifica, ya que el egreso de los pagos de cuotas se maneja en el bloque anterior.
                $pago_inicial = (float)$venta['pago_efectivo'] + (float)$venta['pago_transf'];
                
                
                
                // Vamos a ajustar la lógica para que `monto_egreso_caja` en este punto sea solo la entrega inicial
                // si la anulación es total y ya se procesaron los pagos de cuotas.
                if ($es_anulacion_total) {
                    $monto_egreso_caja = $pago_inicial; // Solo la entrega inicial se devuelve aquí.
                } else {
                    // Si no es anulación total, la lógica original de `min($monto_a_reintegrar, $total_cobrado_real)` se mantiene.
                    // Esto es para devoluciones parciales de ventas financiadas, donde se devuelve una parte del monto.
                    // En este escenario, `pagado_cuotas` aún sería relevante.
                    $stmt_p = $pdo->prepare("SELECT SUM(cp.monto) + SUM(cp.descuento) FROM cuotas_pagos cp JOIN cuotas_seguimiento cs ON cp.id_cuota = cs.id WHERE cs.id_venta = ?");
                    $stmt_p->execute([$venta['id']]);
                    $pagado_cuotas = (float)$stmt_p->fetchColumn();
                    $total_cobrado_real = $pago_inicial + $pagado_cuotas;
                    $monto_egreso_caja = min($monto_a_reintegrar, $total_cobrado_real);
                }
            }

            // Determinar el método de pago original para reflejar el egreso correctamente en caja
            $metodo_pago_reintegro = 'EFECTIVO';
            if ((float)$venta['pago_efectivo'] > 0 && (float)$venta['pago_transf'] > 0) {
                $metodo_pago_reintegro = 'MIXTO';
            } elseif ((float)$venta['pago_transf'] > 0) {
                $metodo_pago_reintegro = 'TRANSFERENCIA';
            }

            $pdo->prepare("INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")
                ->execute([
                    'EGRESO',
                    $monto_egreso_caja,
                    $metodo_pago_reintegro,
                    $detalle_final_db,
                    $fecha_hoy,
                    $usuario_actual,
                    $empresa_id,
                    $sucursal_id
                ]);
        }

        // --- 6. REGISTRO EN NUEVAS TABLAS HISTÓRICAS DE DEVOLUCIÓN ---
        $stmt_h = $pdo->prepare("INSERT INTO devoluciones (op_n, n_documento_venta, id_cliente, total_reintegrado, motivo, fecha, usuario, cond_pago, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_h->execute([
            $nuevo_n_op,
            $n_doc,
            $venta['id_cliente'],
            $monto_a_reintegrar,
            $motivo,
            $fecha_hoy,
            $usuario_actual,
            strtoupper($venta['cond_pago']),
            $empresa_id
        ]);
        $id_devolucion_db = $pdo->lastInsertId();

        $stmt_hd = $pdo->prepare("INSERT INTO devoluciones_detalle (id_devolucion, cod_prod, descripcion, cantidad, p_unit, subtotal, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($items_comprobante as $it) {
            $stmt_hd->execute([
                $id_devolucion_db,
                $it['cod_prod'] ?? 'S/C',
                $it['desc'],
                $it['cant'],
                $it['p_unit'],
                $it['total'],
                $empresa_id
            ]);
        }

        // Guardamos en sesión para el ticket
        $_SESSION['last_op_data'] = [
            'op_n' => $nuevo_n_op,
            'n_documento' => $n_doc,
            'id_cliente' => $venta['id_cliente'],
            'items' => $items_comprobante,
            'total_reintegrado' => $monto_a_reintegrar,
            'motivo' => $motivo
        ];

        $pdo->commit();
        
        $tipo_op = $es_anulacion_total ? "Anulación Total" : "Devolución Parcial";
        $mensaje = "Operación OP#$nuevo_n_op finalizada.\n" . 
                   "Tipo: $tipo_op\n" . 
                   "Monto Reintegrado: $" . number_format($monto_a_reintegrar, 2, ',', '.') . "\n" .
                   "El stock ha sido actualizado correctamente.";

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error al anular venta (anulaciones.php): " . $e->getMessage());
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Anulaciones | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .status-anulada { color: red; font-weight: bold; }
        .card-danger { border-top: 5px solid #f44336; }
        /* Estilos Dark para Modales */
        .modal-anulacion {
            display: none; position: fixed; z-index: 2000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.98) !important;
            overflow-y: auto; backdrop-filter: blur(10px);
        }
        /* Prioridades de superposición */
        #modal_confirmacion { z-index: 10000000; }
        #modal_resultado { z-index: 11000000; }

        .modal-content-anulacion, .modal-resultado-content {
            background-color: #1a1a1a; margin: 2% auto; padding: 25px;
            border: 1px solid #333; width: 90%; max-width: 900px;
            border-radius: 12px; color: white; box-shadow: 0 20px 60px rgba(0,0,0,0.8);
        }
        .modal-resultado-content {
            max-width: 500px; text-align: center; margin-top: 10%;
        }
        .modal-header-dark {
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 20px;
        }
        .btn-close-modal {
            background: none; border: none; color: #666; font-size: 30px; cursor: pointer; transition: 0.3s;
        }
        .btn-close-modal:hover { color: #fff; }
        .icon-success { font-size: 50px; color: #2ecc71; margin-bottom: 15px; display: block; }
        .icon-error { font-size: 50px; color: #e74c3c; margin-bottom: 15px; display: block; }
        .input-cantidad-dev { width: 80px !important; text-align: center; margin-bottom: 0 !important; }
        .row-info { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; background: #222; padding: 15px; border-radius: 6px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Anulación de Ventas</h1>

        <!-- MODAL DE RESULTADO (EXITO/ERROR) -->
        <div id="modal_resultado" class="modal-anulacion" <?php echo $mensaje ? 'style="display:block;"' : ''; ?>>
            <div class="modal-resultado-content" style="<?php echo strpos($mensaje, '❌') !== false ? 'border-top: 5px solid #e74c3c;' : 'border-top: 5px solid #2ecc71;'; ?>">
                <?php if ($mensaje && strpos($mensaje, '❌') !== false): ?>
                    <i class="fas fa-times-circle icon-error"></i>
                    <h2 style="color: #e74c3c;">Hubo un problema</h2>
                <?php elseif ($mensaje): ?>
                    <i class="fas fa-check-circle icon-success"></i>
                    <h2 style="color: #2ecc71;">Operación Realizada</h2>
                <?php endif; ?>
                
                <p style="font-size: 1.1em; white-space: pre-line; line-height: 1.5; margin: 20px 0; color: #ccc;">
                    <?php // Usamos echo directo para permitir saltos de línea y formato si fuera necesario
                    echo htmlspecialchars($mensaje); ?>
                </p>
                
                <div style="display: flex; gap: 10px; flex-direction: column;">
                    <?php if (strpos($mensaje, '❌') === false): ?>
                        <button type="button" class="btn btn-success" style="width: 100%; padding: 15px; font-weight: bold;" onclick="imprimirComprobanteDevolucion()">
                            <i class="fas fa-print"></i> IMPRIMIR COMPROBANTE
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" style="width: 100%; padding: 12px; background: #444;" onclick="cerrarModalResultado()">
                        CERRAR
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL DE CONFIRMACIÓN (ESTILO DARK) -->
        <div id="modal_confirmacion" class="modal-anulacion">
            <div class="modal-resultado-content" style="border-top-color: #f1c40f; max-width: 450px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 50px; color: #f1c40f; margin-bottom: 15px; display: block;"></i>
                <h2 style="color: #f1c40f;">¿Confirmar Operación?</h2>
                <p id="confirm_msg" style="font-size: 1.1em; line-height: 1.5; margin: 20px 0; color: #ccc;"></p>
                
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="btn_confirmar_final" class="btn btn-danger" style="flex: 1; padding: 12px; font-weight: bold;" onclick="ejecutarAnulacion()">SÍ, EJECUTAR</button>
                    <button type="button" id="btn_cancelar_final" class="btn" style="flex: 1; padding: 12px; background: #444; color: white;" onclick="cerrarModalConfirmacion()">CANCELAR</button>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h2>1a. Seleccionar Cliente</h2>
                    <div class="contenedor-busqueda-cliente" style="position: relative;">
                        <input type="text" id="buscar_cliente" class="input-field" placeholder="Nombre o CUIT del cliente...">
                        <div id="resultadosBusquedaClientes"></div>
                    </div>
                </div>
                <div>
                    <h2>1b. Buscar Venta por Número</h2>
                    <div style="display: flex; gap: 10px;">
                        <input type="number" id="input_busqueda_directa" class="input-field" placeholder="N° de Venta / Documento" style="margin-bottom:0 !important;">
                        <button type="button" class="btn btn-primary" onclick="const n = document.getElementById('input_busqueda_directa').value; if(n) verDetalleParaAnular(n); else mostrarMensaje('Error', 'Ingrese un número de venta.', 'error');">BUSCAR</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="card_ventas" class="card" style="display:none;">
            <h2>2. Seleccionar Venta de: <span id="nombre_cliente_ventas" style="color: #00bcd4;"></span></h2>
            <div id="lista_ventas_html" style="margin-top: 15px; overflow-x: auto;"></div>
        </div>

        <!-- MODAL DE DETALLE -->
        <div id="modal_detalle" class="modal-anulacion">
            <div class="modal-content-anulacion card-danger">
                <form method="POST" onsubmit="return validarEnvio()">
                    <div class="modal-header-dark">
                        <h2 style="margin:0;">Detalle de la Venta N° <span id="span_n_doc"></span></h2>
                        <button type="button" class="btn-close-modal" onclick="cerrarModal()">&times;</button>
                    </div>

                    <input type="hidden" name="n_documento_anular" id="hidden_n_doc">
                    
                    <div class="row-info">
                        <div>
                            <p><strong>Cliente:</strong> <span id="det_cliente"></span></p>
                            <p><strong>Fecha:</strong> <span id="det_fecha"></span></p>
                        </div>
                        <div>
                            <p><strong>Total Original:</strong> <span id="det_total"></span></p>
                            <p><strong>Estado:</strong> <span id="det_estado"></span></p>
                        </div>
                    </div>

                    <div style="background: #333; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: #f1c40f; font-weight: bold;">
                            <input type="checkbox" name="anular_todo" id="chk_anular_todo" style="width: 20px; height: 20px;" onchange="toggleAnulacionTotal()">
                            MARCAR PARA ANULAR VENTA COMPLETA
                        </label>
                    </div>

                    <table class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th style="text-align: center;">Cant. Vendida</th>
                                <th style="text-align: center; color: #00bcd4;">Cant. a Devolver</th>
                            </tr>
                        </thead>
                        <tbody id="tabla_articulos"></tbody>
                    </table>

                    <div style="margin-top: 20px;">
                        <label>Motivo de la Operación:</label>
                        <textarea name="motivo" class="input-field" required placeholder="Ej: Error en carga, devolución de mercadería..."></textarea>
                    </div>

                    <button type="submit" name="confirmar_anulacion" class="btn btn-danger" style="width: 100%; margin-top: 20px; font-weight: bold; padding: 15px;">
                        EJECUTAR REINGRESO DE STOCK Y AJUSTE DE CUENTA
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    const clientesData = <?php echo json_encode($clientes); ?>;
    const inputCli = document.getElementById('buscar_cliente');
    const resCli = document.getElementById('resultadosBusquedaClientes');

    // --- LÓGICA DE BÚSQUEDA DE CLIENTES ---
    if (inputCli) {
        inputCli.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            resCli.innerHTML = '';
            if (q.length < 2) {
                resCli.style.display = 'none';
                return;
            }

            const filtrados = clientesData.filter(c => 
                (c.nombre_completo && c.nombre_completo.toLowerCase().includes(q)) || 
                (c.num_documento && String(c.num_documento).toLowerCase().includes(q))
            );

            // Inyectar opción de Consumidor Final si coincide con la búsqueda (Ventas con id_cliente = 0)
            if ("consumidor final".includes(q) || q === "0") {
                filtrados.unshift({id: 0, nombre_completo: "CONSUMIDOR FINAL (Ventas Genéricas)", num_documento: "---"});
            }

            if (filtrados.length > 0) {
                resCli.style.display = 'block';
                filtrados.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'resultado-cliente-item';
                    div.innerHTML = `<strong>${c.nombre_completo}</strong> <small>(${c.num_documento || 'S/D'})</small>`;
                    div.onclick = () => {
                        inputCli.value = c.nombre_completo;
                        resCli.style.display = 'none';
                        document.getElementById('nombre_cliente_ventas').textContent = c.nombre_completo;
                        cargarVentas(c.id);
                    };
                    resCli.appendChild(div);
                });
            } else {
                resCli.style.display = 'none';
            }
        });
    }

    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', (e) => {
        if (inputCli && !inputCli.contains(e.target) && !resCli.contains(e.target)) {
            resCli.style.display = 'none';
        }
    });

    // --- CARGAR VENTAS DEL CLIENTE ---
    function cargarVentas(idCliente) {
        const card = document.getElementById('card_ventas');
        const list = document.getElementById('lista_ventas_html');
        list.innerHTML = '<p>Cargando historial de ventas...</p>';
        card.style.display = 'block';

        fetch(`../ajax/buscar_ventas_cliente_ajax.php?id_cliente=${idCliente}`)
            .then(res => res.json())
            .then(data => {
                if (data.length === 0) {
                    list.innerHTML = '<p>No se encontraron ventas para este cliente.</p>';
                    return;
                }
                let html = '<table class="table" style="width:100%"><thead><tr><th>Doc. N°</th><th>Fecha</th><th>Total</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
                data.forEach(v => {
                    const isAnulada = v.estado === 'Anulada';
                    html += `<tr>
                        <td>${v.n_documento}</td>
                        <td>${v.fecha_venta}</td>
                        <td>$${parseFloat(v.total_venta).toLocaleString('es-AR', {minimumFractionDigits: 2})}</td>
                        <td class="${isAnulada ? 'status-anulada' : ''}">${v.estado}</td>
                        <td>
                            ${!isAnulada ? `<button type="button" class="btn btn-primary btn-sm" onclick="verDetalleParaAnular(${v.n_documento})">Seleccionar</button>` : '---'}
                        </td>
                    </tr>`;
                });
                html += '</tbody></table>';
                list.innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                list.innerHTML = '<p style="color:red;">Error al conectar con el servidor.</p>';
            });
    }

    function verDetalleParaAnular(nDoc) {
        const modal = document.getElementById('modal_detalle');
        fetch(`../ajax/obtener_venta_anulacion.php?n_documento=${nDoc}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { 
                    alert(data.error); 
                } else {
                    document.getElementById('span_n_doc').textContent = data.cabecera.n_documento;
                    document.getElementById('hidden_n_doc').value = data.cabecera.n_documento;
                    document.getElementById('det_cliente').textContent = data.cabecera.cliente_nombre;
                    document.getElementById('det_fecha').textContent = data.cabecera.fecha_venta;
                    document.getElementById('det_total').textContent = '$' + parseFloat(data.cabecera.total_venta).toLocaleString('es-AR');
                    document.getElementById('det_estado').textContent = data.cabecera.estado;
                    document.getElementById('chk_anular_todo').checked = false;

                    let html = '';
                    data.detalle.forEach(item => {
                        html += `<tr>
                            <td>${item.cod_prod}</td>
                            <td>${item.descripcion}</td>
                            <td style="text-align: center;">${item.cant}</td>
                            <td style="text-align: center;">
                                <input type="number" name="items_devolver[${item.cod_prod}]" 
                                       class="input-field input-cantidad-dev" 
                                       value="0" min="0" max="${item.cant}" step="any"
                                       data-original="${item.cant}">
                            </td>
                        </tr>`;
                    });
                    document.getElementById('tabla_articulos').innerHTML = html;
                    modal.style.display = 'block';
                }
            });
    }

    function toggleAnulacionTotal() {
        const isTotal = document.getElementById('chk_anular_todo').checked;
        const inputs = document.querySelectorAll('.input-cantidad-dev');
        inputs.forEach(input => {
            if (isTotal) {
                input.value = input.dataset.original;
                input.readOnly = true;
                input.style.opacity = "0.5";
            } else {
                input.value = "0";
                input.readOnly = false;
                input.style.opacity = "1";
            }
        });
    }

    function cerrarModal() {
        document.getElementById('modal_detalle').style.display = 'none';
    }

    function imprimirComprobanteDevolucion() {
        window.open('generar_pdf_devolucion.php', '_blank');
    }

    function cerrarModalResultado() {
        document.getElementById('modal_resultado').style.display = 'none';
        window.location.href = 'anulaciones.php'; // Recargamos para limpiar la URL
    }

    function cerrarModalConfirmacion() {
        document.getElementById('modal_confirmacion').style.display = 'none';
        // Si cancela, devolvemos la vista al detalle de la venta
        document.getElementById('modal_detalle').style.display = 'block';
    }

    function ejecutarAnulacion() {
        // Bypasseamos el onsubmit enviando el form directamente
        const btn = document.getElementById('btn_confirmar_final');
        const btnCancel = document.getElementById('btn_cancelar_final');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESANDO...';
        btnCancel.style.display = 'none';
        
        document.querySelector('#modal_detalle form').submit();
    }

    function validarEnvio() {
        const isTotal = document.getElementById('chk_anular_todo').checked;
        const inputs = Array.from(document.querySelectorAll('.input-cantidad-dev'));
        const tieneAlgo = inputs.some(i => parseFloat(i.value) > 0);

        if (!isTotal && !tieneAlgo) {
            mostrarMensaje("Dato Requerido", "Debe seleccionar al menos un producto para devolver o marcar la anulación total.", "error");
            return false;
        }

        if (isTotal) {
            document.getElementById('confirm_msg').innerText = "Se anulará la venta completa y se reintegrará TODO el stock a los depósitos.";
        } else {
            document.getElementById('confirm_msg').innerText = "Se reintegrará el stock de los productos seleccionados y se ajustará la cuenta del cliente.";
        }

        // IMPLEMENTACIÓN POR CÓDIGO: Forzamos la desaparición del modal de detalle
        document.getElementById('modal_detalle').style.setProperty('display', 'none', 'important');
        document.getElementById('modal_confirmacion').style.display = 'block';
        return false; // Evitamos el envío inmediato para esperar la confirmación del modal
    }
    </script>
</body>
</html>