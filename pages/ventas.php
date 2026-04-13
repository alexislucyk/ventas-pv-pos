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
    // 1. Obtener Clientes para el datalist
    try {
        $sql_clientes = "SELECT id AS id_cliente, CONCAT(apellido, ', ', nombre) AS nombre_completo, cuit AS num_documento FROM clientes ORDER BY nombre_completo ASC";
        $clientes = $pdo->query($sql_clientes)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $clientes = [];
    }

    // 2. LÓGICA DE PROCESAMIENTO
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {
        $accion = $_POST['venta_action'];
        $es_finalizar = ($accion === 'Finalizar');
        $estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';
        
        $id_venta_existente = (isset($_POST['id_venta_existente'])) ? (int)$_POST['id_venta_existente'] : 0;
        $id_cliente = (isset($_POST['id_cliente_hidden'])) ? (int)$_POST['id_cliente_hidden'] : 0;
        $cond_pago = (isset($_POST['cond_pago'])) ? trim($_POST['cond_pago']) : 'CONTADO';
        $pago_efectivo = (isset($_POST['pago_efectivo'])) ? max(0.0, (float)$_POST['pago_efectivo']) : 0.0;
        $pago_transf = (isset($_POST['pago_transf'])) ? max(0.0, (float)$_POST['pago_transf']) : 0.0;
        $detalle_productos = json_decode($_POST['detalle_productos'], true);

        if (empty($detalle_productos)) {
            $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
        } else {
            try {
                $pdo->beginTransaction();

                // --- RECALCULO Y VALIDACIÓN ---
                $total_recalculado = 0;
                foreach ($detalle_productos as &$item) {
                    $stmt_v = $pdo->prepare("SELECT p_venta, p_compra, stock, descripcion FROM productos WHERE cod_prod = ?");
                    $stmt_v->execute([$item['cod_prod']]);
                    $prod_db = $stmt_v->fetch(PDO::FETCH_ASSOC);

                    if (!$prod_db) throw new Exception("Producto no encontrado: " . $item['cod_prod']);
                    
                    if ($es_finalizar && $prod_db['stock'] < $item['cant']) {
                        throw new Exception("Stock insuficiente para: " . $prod_db['descripcion']);
                    }

                    $item['p_unit'] = (float)$prod_db['p_venta'];
                    $item['p_costo_venta'] = (float)$prod_db['p_compra']; 
                    $item['total'] = $item['p_unit'] * (float)$item['cant'];
                    $total_recalculado += $item['total'];
                }
                unset($item);

                // Obtener N° Documento correlativo
                if ($id_venta_existente <= 0) {
                    $stmt_n = $pdo->query("SELECT MAX(n_documento) AS ultimo FROM ventas FOR UPDATE");
                    $res_n = $stmt_n->fetch();
                    $n_documento = ($res_n['ultimo'] !== null) ? $res_n['ultimo'] + 1 : 1;
                }

                // --- A) Insertar o Actualizar Cabecera de Venta ---
                if ($id_venta_existente > 0) {
                    $sql_v = "UPDATE ventas SET id_cliente=?, cond_pago=?, total_venta=?, pago_efectivo=?, pago_transf=?, estado=?, usuario=? WHERE id=?";
                    $pdo->prepare($sql_v)->execute([$id_cliente, $cond_pago, $total_recalculado, $pago_efectivo, $pago_transf, $estado_venta, $usuario_activo, $id_venta_existente]);
                    
                    $stmt_doc = $pdo->prepare("SELECT n_documento FROM ventas WHERE id=?");
                    $stmt_doc->execute([$id_venta_existente]);
                    $n_documento = $stmt_doc->fetchColumn();

                    $pdo->prepare("DELETE FROM ventas_detalle WHERE n_documento = ?")->execute([$n_documento]);
                } else {
                    $fecha_venta = date('Y-m-d H:i:s');
                    $sql_v = "INSERT INTO ventas (id_cliente, cond_pago, n_documento, total_venta, pago_efectivo, pago_transf, fecha_venta, estado, usuario) VALUES (?,?,?,?,?,?,?,?,?)";
                    $pdo->prepare($sql_v)->execute([$id_cliente, $cond_pago, $n_documento, $total_recalculado, $pago_efectivo, $pago_transf, $fecha_venta, $estado_venta, $usuario_activo]);
                }

                // --- B) Insertar Detalle y Stock ---
                $sql_d = "INSERT INTO ventas_detalle (cod_prod, descripcion, cant, p_unit, p_costo_venta, total, n_documento, fecha) VALUES (?,?,?,?,?,?,?,?)";
                $stmt_d = $pdo->prepare($sql_d);
                foreach ($detalle_productos as $item) {
                    $stmt_d->execute([$item['cod_prod'], $item['descripcion'], $item['cant'], $item['p_unit'], $item['p_costo_venta'], $item['total'], $n_documento, date('Y-m-d H:i:s')]);
                    
                    if ($es_finalizar) {
                        $pdo->prepare("UPDATE productos SET stock = stock - ? WHERE cod_prod = ?")->execute([$item['cant'], $item['cod_prod']]);
                    }
                }

                // --- C) Cuenta Corriente ---
                if ($es_finalizar && $cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
                    $saldo_deuda = $total_recalculado - ($pago_efectivo + $pago_transf);
                    if ($saldo_deuda > 0) {
                        $sql_cc = "INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha) VALUES (?, 'FACTURA', ?, ?, 0, NOW())";
                        $pdo->prepare($sql_cc)->execute([$id_cliente, $n_documento, $saldo_deuda]);
                    }
                }

                // --- D) REGISTRO EN TABLA MOVIMIENTOS (CAJA) ---
                if ($es_finalizar) {
                    $monto_ingreso = ($cond_pago === 'CONTADO') ? $total_recalculado : ($pago_efectivo + $pago_transf);
                    
                    if ($monto_ingreso > 0) {
                        $metodo_pago_mov = ($pago_efectivo > 0 && $pago_transf > 0) ? 'MIXTO' : ($pago_efectivo > 0 ? 'EFECTIVO' : 'TRANSFERENCIA');
                        $sql_mov = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) VALUES ('INGRESO', ?, ?, ?, NOW(), ?, 0)";
                        
                        $detalle_mov = ($cond_pago === 'CUENTA CORRIENTE') ? "ENTREGA/PAGO - VENTA N° $n_documento (CTA. CTE.)" : "VENTA CONTADO N° $n_documento";
                        
                        $pdo->prepare($sql_mov)->execute([$monto_ingreso, $metodo_pago_mov, $detalle_mov, $usuario_activo]);
                    }
                }

                $pdo->commit();
                $_SESSION['ticket_a_imprimir_doc'] = $n_documento;
                $_SESSION['status_msj'] = "✅ Venta N° $n_documento procesada correctamente.";
                header("Location: ventas.php");
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                $mensaje = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

if (isset($_SESSION['status_msj'])) { $mensaje = $_SESSION['status_msj']; unset($_SESSION['status_msj']); }
$ticket_doc_a_imprimir = isset($_SESSION['ticket_a_imprimir_doc']) ? $_SESSION['ticket_a_imprimir_doc'] : null;
unset($_SESSION['ticket_a_imprimir_doc']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ventas | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* TEMA OSCURO REPORTES */
        body {
            background-color: #121212;
            color: #e0e0e0;
        }

        .content h1 {
            color: #00bcd4; /* Cian de la imagen */
            font-weight: 700;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
        }
        
        .content h1::before {
            content: "\f201"; /* Icono de reporte/ventas */
            font-family: "Font Awesome 5 Free";
            margin-right: 15px;
            font-size: 1.5rem;
        }

        .card {
            background-color: #1e1e1e !important;
            border: 1px solid #333 !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important;
            padding: 20px;
        }

        .venta-grid { display: grid; grid-template-columns: 3fr 1fr; gap: 20px; }

        /* INPUTS ESTILO DARK */
        .input-field, input[type="text"], input[type="number"], select {
            background-color: #2a2a2a !important;
            border: 1px solid #444 !important;
            color: #fff !important;
            border-radius: 4px;
            padding: 10px;
        }

        .input-field:focus {
            border-color: #00bcd4 !important;
            outline: none;
        }

        /* TABLAS ESTILO REPORTES */
        .table-full {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table-full th {
            background-color: #181818;
            color: #00bcd4;
            text-transform: uppercase;
            font-size: 0.85rem;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #333;
        }

        .table-full td {
            padding: 12px;
            border-bottom: 1px solid #222;
        }

        .table-full tr:hover {
            background-color: #252525;
        }

        /* CAJAS DE TOTALES */
        .total-box { 
            background: #181818; 
            padding: 20px; 
            text-align: center; 
            margin: 15px 0; 
            border-radius: 12px; 
            border: 1px solid #333; 
        }
        #total_venta_display { font-size: 2.2rem; color: #4caf50; font-weight: bold; }

        .vuelto-box { 
            background: #181818; 
            padding: 15px; 
            text-align: center; 
            margin-bottom: 15px; 
            border-radius: 12px; 
            border: 1px solid #f1c40f; 
            display: none; 
        }
        #vuelto_display { font-size: 1.8rem; color: #f1c40f; font-weight: bold; }

        /* BUSQUEDA */
        #resultadosBusqueda, #resultadosBusquedaClientes {
            position: absolute; z-index: 1000; background: #2a2a2a; width: 100%;
            max-height: 300px; overflow-y: auto; border: 1px solid #444; color: #fff;
            border-radius: 0 0 8px 8px;
        }
        .resultado-item { padding: 12px; cursor: pointer; border-bottom: 1px solid #333; }
        .resultado-item:hover { background-color: #00bcd4; color: #000; }

        /* ALERTAS */
        .alert-info {
            background-color: #181818;
            border-left: 4px solid #00bcd4;
            color: #e0e0e0;
            padding: 15px;
        }

        /* BOTONES */
        .btn-primary { background-color: #007bff; border: none; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-block { width: 100%; border-radius: 6px; }

        label {
            color: #aaa;
            font-size: 0.9rem;
            margin-bottom: 5px;
            display: block;
        }

        hr { border: 0; border-top: 1px solid #333; margin: 20px 0; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1>Nueva Venta</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <div class="venta-grid">
            <div class="card">
                <div class="contenedor-busqueda" style="position:relative;">
                    <label><i class="fas fa-search"></i> Buscar Producto</label>
                    <input type="text" id="buscar_producto" class="input-field" autocomplete="off" placeholder="Escribe nombre o código...">
                    <div id="resultadosBusqueda"></div> 
                </div>
                
                <h3 style="margin-top:25px; color:#00bcd4;"><i class="fas fa-shopping-cart"></i> Carrito de Compra</h3>
                <table id="carrito" class="table-full">
                    <thead>
                        <tr>
                            <th>Cód.</th>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Cant.</th>
                            <th>Subtotal</th>
                            <th>-</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="card">
                <form id="formVenta" method="POST">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">
                    <input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">
                    <input type="hidden" name="id_venta_existente" id="id_venta_existente" value="">
                    <input type="hidden" name="id_cliente_hidden" id="id_cliente_hidden" value="0">

                    <div class="contenedor-busqueda-cliente" style="position:relative;">
                        <label><i class="fas fa-user-tag"></i> Cliente</label>
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
                    </select>

                    <div class="total-box">
                        <p style="margin:0; color:#aaa; text-transform:uppercase; letter-spacing:1px; font-size:0.8rem;">Total a Cobrar</p>
                        <span id="total_venta_display">$ 0.00</span>
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

    <?php if ($ticket_doc_a_imprimir): ?>
    <div id="modalTicket" style="position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;">
        <div style="background: #1a1a1a; padding: 35px; border-radius: 12px; text-align: center; border: 1px solid #00bcd4; width: 380px;">
            <i class="fas fa-print" style="font-size: 3rem; color: #00bcd4; margin-bottom: 15px;"></i>
            <h3 style="color: #4caf50; margin-bottom: 10px;">Venta Procesada</h3>
            <p style="color: #ccc;">¿Desea imprimir el ticket N° <strong><?php echo $ticket_doc_a_imprimir; ?></strong>?</p>
            <div style="margin-top: 25px; display: flex; gap: 10px; justify-content: center;">
                <button onclick="window.open('vista_previa_ticket.php?n_documento=<?php echo $ticket_doc_a_imprimir; ?>', '_blank'); this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-primary" style="padding: 10px 20px;">Sí, Imprimir</button>
                <button onclick="this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-secondary" style="padding: 10px 20px; background:#444;">Cerrar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../js/ventas.js"></script>
    <script>
        var clientesData = <?php echo json_encode($clientes); ?>;

        function actualizarVuelto() {
            var cond = document.getElementById('cond_pago').value;
            var efec = parseFloat(document.getElementById('pago_efectivo').value) || 0;
            var tran = parseFloat(document.getElementById('pago_transf').value) || 0;
            var totalText = document.getElementById('total_venta_display').innerText;
            var totalLimpio = totalText.replace(/[^\d,.]/g, '');
            
            if (totalLimpio.includes(',') && totalLimpio.includes('.')) {
                totalLimpio = totalLimpio.replace(/\./g, '').replace(',', '.');
            } else if (totalLimpio.includes(',')) {
                totalLimpio = totalLimpio.replace(',', '.');
            }
            
            var total = parseFloat(totalLimpio) || 0;
            var pagoTotal = efec + tran;
            var box = document.getElementById('vuelto_contenedor');
            
            if (cond === 'CONTADO' && pagoTotal > total && total > 0) {
                var vuelto = pagoTotal - total;
                document.getElementById('vuelto_display').innerText = '$ ' + vuelto.toLocaleString('es-AR', {minimumFractionDigits:2});
                box.style.setProperty("display", "block", "important");
            } else {
                box.style.display = 'none';
            }
        }

        document.getElementById('pago_efectivo').addEventListener('input', actualizarVuelto);
        document.getElementById('pago_transf').addEventListener('input', actualizarVuelto);
        document.getElementById('cond_pago').addEventListener('change', actualizarVuelto);

        if (window.MutationObserver) {
            new MutationObserver(actualizarVuelto).observe(document.getElementById('total_venta_display'), {childList:true, characterData:true, subtree:true});
        }
    </script>
</body>
</html>