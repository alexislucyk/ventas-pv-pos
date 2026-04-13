
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
                    $monto_ingreso = $pago_efectivo + $pago_transf;
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

// Mensajes
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
    <style>
        #resultadosBusqueda, #resultadosBusquedaClientes {
            position: absolute; z-index: 1000; background: white; width: 100%;
            max-height: 300px; overflow-y: auto; border: 1px solid #ccc; color: #333;
        }
        .resultado-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }
        .resultado-item:hover { background-color: #f0f7ff; }
        .venta-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .total-box { background: #333; padding: 15px; text-align: center; margin: 15px 0; border-radius: 8px; border: 1px solid #444; }
        #total_venta_display { font-size: 2rem; color: #2ecc71; font-weight: bold; }
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
                    <label>Buscar Producto</label>
                    <input type="text" id="buscar_producto" class="input-field" autocomplete="off" placeholder="Escribe nombre o código...">
                    <div id="resultadosBusqueda"></div> 
                </div>
                
                <h3>Carrito de Compra</h3>
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
                        <label>Cliente</label>
                        <input type="text" id="buscar_cliente" class="input-field" autocomplete="off" placeholder="Buscar cliente...">
                        <div id="resultadosBusquedaClientes"></div>
                    </div>

                    <div class="alert alert-info" style="margin-top:10px;">
                        <i class="fas fa-user"></i> <span id="nombre_cliente_display">Venta Genérica</span>
                    </div>

                    <hr>
                    <label>Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field">
                        <option value="CONTADO">CONTADO</option>
                        <option value="CUENTA CORRIENTE">CUENTA CORRIENTE</option>
                    </select>

                    <div class="total-box">
                        <p style="margin:0; color:#aaa;">TOTAL A COBRAR</p>
                        <span id="total_venta_display">$ 0.00</span>
                    </div>

                    <label>Pago en Efectivo ($)</label>
                    <input type="number" step="0.01" name="pago_efectivo" id="pago_efectivo" class="input-field" value="">
                    
                    <label>Pago por Transferencia ($)</label>
                    <input type="number" step="0.01" name="pago_transf" id="pago_transf" class="input-field" value="">

                    <button type="submit" class="btn btn-primary btn-block" style="height:50px; font-size:1.1rem; margin-top:10px;">
                        <i class="fas fa-check-circle"></i> Finalizar Venta
                    </button>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <button type="button" class="btn btn-success" id="btnGuardarPendiente">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                        <button type="button" id="btnVerPendiente" class="btn btn-yellow" style="background: #f1c40f; color: #000;">
                            <i class="fas fa-clock"></i> Pendientes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="pendientesModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.8);">
        <div class="modal-content" style="background: #1a1a1a; margin: 5% auto; padding: 20px; width: 80%; border-radius: 12px; border: 1px solid #333; max-height: 80vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin:0;">Ventas en Espera</h2>
                <span onclick="document.getElementById('pendientesModal').style.display='none'" style="cursor:pointer; font-size: 28px; color: #ff4444;">&times;</span>
            </div>
            <div id="listaPendientes"></div>
        </div>
    </div>

    <?php if ($ticket_doc_a_imprimir): ?>
    <div id="modalTicket" style="position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;">
        <div style="background: #111; padding: 30px; border-radius: 12px; text-align: center; border: 1px solid #333; width: 350px;">
            <h3 style="color: #2ecc71;">✅ Venta Exitosa</h3>
            <p>¿Imprimir ticket N° <strong><?php echo $ticket_doc_a_imprimir; ?></strong>?</p>
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                <button onclick="window.open('vista_previa_ticket.php?n_documento=<?php echo $ticket_doc_a_imprimir; ?>', '_blank'); this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-primary">Sí, Imprimir</button>
                <button onclick="this.parentElement.parentElement.parentElement.style.display='none';" class="btn btn-secondary">Cerrar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../js/ventas.js"></script>
    <script>var clientesData = <?php echo json_encode($clientes); ?>;</script>
</body>
</html>