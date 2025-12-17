<?php
session_start();
// La zona horaria debe ser la misma que la base de datos
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. Control de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// =========================================================================
// ******************* REQUERIMIENTOS CRÍTICOS *****************************
// =========================================================================

// Usamos '../' porque ventas.php está en la raíz del proyecto.
require '../config/db_config.php';

$mensaje = '';

// VERIFICACIÓN CLAVE: Si la conexión ($pdo) falla en db_config.php, detente.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $mensaje = "❌ ERROR CRÍTICO: La conexión a la base de datos no está disponible.";
    error_log($mensaje);
    $clientes = []; // Inicializar para evitar errores en el JSON más abajo
    $siguiente_n_documento = 1;
} else {
    // --- Bloque para obtener clientes ---
    try {
        $sql_clientes = "SELECT
                            id AS id_cliente,
                            CONCAT(apellido, ', ', nombre) AS nombre_completo,
                            cuit AS num_documento
                        FROM clientes
                        ORDER BY nombre_completo ASC";

        $stmt_clientes = $pdo->query($sql_clientes);
        $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error al cargar clientes: " . $e->getMessage());
        $mensaje = "⚠️ Advertencia: No se pudieron cargar los clientes.";
        $clientes = [];
    }
    // --- Fin bloque clientes ---

    // --- Bloque para obtener el Siguiente N° de Documento (Factura) ---
    $siguiente_n_documento = 1;
    try {
        $sql_ultimo_doc = "SELECT MAX(n_documento) AS ultimo_doc FROM ventas";
        $stmt_ultimo_doc = $pdo->query($sql_ultimo_doc);
        $resultado = $stmt_ultimo_doc->fetch(PDO::FETCH_ASSOC);

        if ($resultado && $resultado['ultimo_doc'] !== null) {
            $siguiente_n_documento = $resultado['ultimo_doc'] + 1;
        }
    } catch (Exception $e) {
        error_log("Error al buscar el último N° de Documento: " . $e->getMessage());
        $siguiente_n_documento = 1;
    }
    // --- Fin Bloque N° Documento ---

    // =====================================================
    // LÓGICA DE PROCESAMIENTO DE VENTA (FINALIZAR O PENDIENTE)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['venta_action'])) {

        $accion = $_POST['venta_action'];
        $es_finalizar = ($accion === 'Finalizar');
        $estado_venta = $es_finalizar ? 'Finalizada' : 'Pendiente';

        // 1. Obtener y sanitizar datos de la cabecera
        $id_venta_existente = (int)(isset($_POST['id_venta_existente']) ? $_POST['id_venta_existente'] : 0);
        $id_cliente = (int)$_POST['cliente_id'];
        $cond_pago = trim($_POST['cond_pago']);
        $n_documento = (int)$_POST['n_documento'];
        $total_venta = max(0.0, (float)$_POST['total_venta']);
        $pago_efectivo = max(0.0, (float)$_POST['pago_efectivo']);
        $pago_transf = max(0.0, (float)$_POST['pago_transf']);
        $fecha_venta = date('Y-m-d H:i:s'); // Usamos la fecha y hora actual del servidor

        $detalle_productos = json_decode($_POST['detalle_productos'], true);

        if (empty($detalle_productos)) {
            $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
        } else {
            try {
                $pdo->beginTransaction(); // === INICIA LA TRANSACCIÓN ===

                // --- A) Lógica para Venta Existente (Actualizar Pendiente) ---
                if ($id_venta_existente > 0) {
                    // Si se está actualizando, no se modifica la fecha de venta, solo el estado y pagos.
                    // **CORRECCIÓN:** La fecha NO DEBE actualizarse si es una venta pendiente que se finaliza.
                    $sql_update_venta = "UPDATE ventas SET id_cliente=?, cond_pago=?, total_venta=?, pago_efectivo=?, pago_transf=?, estado=? WHERE id=?";
                    $stmt_update_venta = $pdo->prepare($sql_update_venta);
                    $stmt_update_venta->execute([
                        $id_cliente, $cond_pago, $total_venta, $pago_efectivo, $pago_transf, $estado_venta, $id_venta_existente
                    ]);

                    // Eliminar Detalle anterior para reinsertar el nuevo (Simplifica la lógica)
                    $sql_delete_detalle = "DELETE FROM ventas_detalle WHERE n_documento = ?";
                    $pdo->prepare($sql_delete_detalle)->execute([$n_documento]);

                    $id_venta_actual = $id_venta_existente;

                }
                // --- B) Lógica para Venta Nueva ---
                else {

                    // 1. Insertar Cabecera
                    $sql_venta = "INSERT INTO ventas (id_cliente, cond_pago, n_documento, total_venta, pago_efectivo, pago_transf, fecha_venta, estado)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_venta = $pdo->prepare($sql_venta);
                    $stmt_venta->execute([
                        $id_cliente, $cond_pago, $n_documento, $total_venta, $pago_efectivo, $pago_transf, $fecha_venta, $estado_venta
                    ]);
                    $id_venta_actual = $pdo->lastInsertId();
                }

                // --- C) Insertar Detalle ---
                $sql_detalle = "INSERT INTO ventas_detalle (cod_prod, descripcion, cant, p_unit, total, n_documento, fecha)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)";

                foreach ($detalle_productos as $item) {
                    $stmt_detalle = $pdo->prepare($sql_detalle);

                    $stmt_detalle->execute([
                        $item['cod_prod'],
                        $item['descripcion'],
                        $item['cant'],
                        $item['p_unit'],
                        $item['total'],
                        $n_documento,
                        $fecha_venta // Usamos la misma fecha de cabecera
                    ]);
                }

                // --- D) Actualizar Stock y CC (Solo si es Venta Finalizada) ---
                if ($es_finalizar) {
                    // 1. Actualizar Stock
                    $sql_stock_update = "UPDATE productos SET stock = stock - ? WHERE cod_prod = ?";
                    foreach ($detalle_productos as $item) {
                        $stmt_stock = $pdo->prepare($sql_stock_update);
                        $stmt_stock->execute([ (float)$item['cant'], $item['cod_prod'] ]);
                    }

                    // 2. LÓGICA DE CUENTA CORRIENTE
                    if ($cond_pago === 'CUENTA CORRIENTE' && $id_cliente > 0) {
                        $saldo_deuda = $total_venta - ($pago_efectivo + $pago_transf);

                        // SOLO REGISTRAR MOVIMIENTO CC SI HAY DEUDA PENDIENTE
                        if ($saldo_deuda > 0) {

                            $monto_deuda_positivo = abs($saldo_deuda);

                            $sql_cc_insert = "
                                INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha)
                                VALUES (:id_cliente, 'FACTURA', :n_documento, :debe, 0, :fecha_venta)
                            ";

                            $stmt_cc_insert = $pdo->prepare($sql_cc_insert);

                            $stmt_cc_insert->execute([
                                ':id_cliente'   => $id_cliente,
                                ':n_documento'  => $n_documento,
                                ':debe'         => $monto_deuda_positivo,
                                ':fecha_venta'  => $fecha_venta
                            ]);
                        }
                    }
                } // Fin if ($es_finalizar)

                $pdo->commit(); // === CONFIRMA LA TRANSACCIÓN ===

                if ($es_finalizar) {
                    $mensaje = "✅ Venta (Documento {$n_documento}) FINALIZADA y stock actualizado con éxito.";
                    $_SESSION['ticket_a_imprimir_doc'] = $n_documento;
                } else {
                    $mensaje = "📝 Venta N° {$n_documento} guardada como PENDIENTE con éxito. ID: {$id_venta_actual}";
                }

                // Redirige para limpiar el POST y permitir que JS se ejecute limpio
                header("Location: ventas.php");
                exit();

            } catch (Exception $e) {
                $pdo->rollBack(); // === REVierte la transacción si algo falló ===
                $mensaje = "❌ Error al procesar la venta. La transacción fue revertida. Mensaje: " . $e->getMessage();
                error_log("Error de transacción en ventas: " . $e->getMessage());
            }
        }
    }
} // Fin del 'else' de verificación de conexión $pdo

// Lógica para capturar el N° de documento a imprimir después de la redirección
$ticket_doc_a_imprimir = null;
if (isset($_SESSION['ticket_a_imprimir_doc'])) {
    $ticket_doc_a_imprimir = (int)$_SESSION['ticket_a_imprimir_doc'];
    // IMPORTANTE: Lo eliminamos de sesión inmediatamente para que no se imprima dos veces
    unset($_SESSION['ticket_a_imprimir_doc']);
}

?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Venta | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/ticket_print.css">
    
</head>
<body>

    <?php include 'sidebar.php'; ?>
    <?php include 'infosesion.php'; ?>
    <div class="content">
        <h1>Nueva Venta</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="venta-grid">

            <div class="card">
                <h2>Detalle de Productos</h2>

                <label for="buscar_producto">Buscar Producto (Código o Descripción)</label>
                <input type="text" id="buscar_producto" class="input-field" placeholder="Escriba aquí el código o nombre del producto">
                <div id="resultadosBusqueda"></div>

                <hr>

                <h3>Carrito de Venta</h3>
                <table id="carrito" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th class="text-right">Precio</th>
                            <th style="width: 10%;">Cant.</th>
                            <th class="text-right">Subtotal</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <form id="formVenta" method="POST" action="ventas.php">
                    <input type="hidden" name="guardar_venta" value="1">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">

                    <h2>Datos de la Venta</h2>

                    <div class="contenedor-busqueda-cliente" style="margin-bottom: 20px; position: relative;">
                        <label for="buscar_cliente">Buscar Cliente (Nombre o CUIT)</label>
                        <input type="text" id="buscar_cliente" class="input-field" placeholder="Venta Genérica">
                        <div id="resultadosBusquedaClientes" style="left: 0;"></div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        Cliente Actual: <strong id="nombre_cliente_display">Venta Genérica</strong>
                    </div>

                    <input type="hidden" name="cliente_id" id="cliente_id_hidden" value="0">

                    <label for="num_documento_display">CUIT/Documento</label>
                    <input type="text" id="num_documento_display" class="input-field" value="" readonly>

                    <label for="n_documento">N° Documento (Factura)*</label>
                    <input
                        type="number"
                        id="n_documento"
                        name="n_documento"
                        class="input-field"
                        value="<?php echo htmlspecialchars($siguiente_n_documento); ?>"
                        readonly
                        required>

                    <hr>

                    <h3>Totales y Pago</h3>

                    <label for="cond_pago">Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field" required>
                        <option value="CONTADO" selected>CONTADO</option>
                        <option value="CUENTA CORRIENTE">CUENTA CORRIENTE</option>
                    </select>

                    <div id="contenedor_pagos">

                        <div style="display: flex; justify-content: space-between; font-size: 1.2em; margin-bottom: 10px;">
                            <strong>TOTAL VENTA:</strong>
                            <strong id="total_venta_display" style="color: lightgreen;">$0.00</strong>
                        </div>

                        <input type="hidden" name="total_venta" id="total_venta_input" value="0.00">

                        <label for="pago_efectivo">Pago en Efectivo</label>
                        <input type="number" step="0.01" id="pago_efectivo" name="pago_efectivo" class="input-field pago-input" value="" min="0">

                        <label for="pago_transf">Pago con Transferencia</label>
                        <input type="number" step="0.01" id="pago_transf" name="pago_transf" class="input-field pago-input" value="" min="0">

                        <div style="display: flex; justify-content: space-between; font-size: 1.1em; margin-top: 15px;">
                            <strong>Cambio / Saldo:</strong>
                            <strong id="cambio_saldo_display">$0.00</strong>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;" id="btnFinalizarVenta">
                        Finalizar y Guardar Venta
                    </button>

                    <button type="button" class="btn btn-green" style="width: 100%; margin-top: 10px;" id="btnGuardarPendiente">
                        Guardar como Pendiente
                    </button>

                    <input type="hidden" name="venta_action" id="venta_action_input" value="Finalizar">

                    <input type="hidden" name="id_venta_existente" id="id_venta_existente_input" value="0">

                </form>

                <button type="button" class="btn btn-yellow" style="width: 100%; margin-top: 10px;" id="btnVerPendientes">
                    Ver Ventas Pendientes
                </button>
                <div id="pendientesModal" class="modal">
                    <div class="modal-content" style="max-width: 800px;">
                        <span class="close-btn" onclick="cerrarModalPendientes()">&times;</span>
                        <h2>Ventas en Espera</h2>
                        <div id="listaPendientes">Cargando...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div id="ticketModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="cerrarModalTicket()">&times;</span>
            <div id="ticket-vista-previa" style="padding: 10px; border-radius: 5px;">
                Cargando vista previa...
            </div>
            <button id="btnImprimirTicket" class="btn btn-green" style="margin-top: 15px;">Imprimir Ticket</button>
            <p id="errorTicket" style="color: red; margin-top: 10px; display: none;">Error al cargar la vista previa.</p>
        </div>
    </div>


</body>
<script>
    // Variables PHP para JS
    const clientesData = <?php echo json_encode($clientes); ?>;
    const ticketDocImprimir = <?php echo json_encode($ticket_doc_a_imprimir); ?>;
</script>
<script src="../js/ventas.js"></script>
</html>