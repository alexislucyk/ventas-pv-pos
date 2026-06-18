<?php
// pages/cuentas_corrientes.php (REESTRUCTURADO)
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

// --- 1. CONSULTA PARA OBTENER SALDOS GENERALES ---
try {
    // Calculamos el saldo directamente en la consulta: SUM(debe) - SUM(haber)
    $sql_saldos = "
        SELECT 
            c.id AS id_cliente,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
            c.cuit,
            c.telefono,
            SUM(m.debe) AS total_debe,
            SUM(m.haber) AS total_haber,
            (SUM(m.debe) - SUM(m.haber)) AS saldo_actual
        FROM clientes c
        INNER JOIN ctacte m ON c.id = m.id_cliente
        GROUP BY c.id
        HAVING saldo_actual != 0
        ORDER BY saldo_actual DESC;
    ";
    
    $stmt_saldos = $pdo->query($sql_saldos);
    $clientes_cc = $stmt_saldos->fetchAll(PDO::FETCH_ASSOC);

    // Calcular deuda total de la calle
    $deuda_total = 0;
    $saldo_favor_total = 0;
    foreach($clientes_cc as $c) { 
        if($c['saldo_actual'] > 0) $deuda_total += $c['saldo_actual']; 
        else $saldo_favor_total += abs($c['saldo_actual']);
    }

} catch (Exception $e) {
    error_log("Error en CC: " . $e->getMessage());
    $clientes_cc = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuentas Corrientes | Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .card-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #2c2c2c;
            padding: 20px;
            border-radius: 8px;
            border-left: 5px solid #00bcd4;
            color: #fff;
        }
        .stat-card h3 { margin: 0; font-size: 0.85rem; color: #bbb; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 24px; font-weight: bold; margin-top: 10px; }
        
        .saldo-deudor { color: #ff5e5e; font-weight: bold; }
        .saldo-favor { color: #2ecc71; font-weight: bold; }
        
        .btn-view {
            background: #3498db;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-view:hover { background: #2980b9; }

        .btn-whatsapp-nodered {
            background: #25d366;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            margin-left: 5px;
            border: none;
            cursor: pointer;
        }
        .btn-whatsapp-nodered:hover { background: #128c7e; }

        /* Estilo para que se parezca a resumen_ventas */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #1f1f1f; color: #eee; }
        th { background: #333; color: #fff; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #444; }
        tr:hover { background: #292929; }

        /* Achicar la letra y compactar el modal de historial */
        #modalHistorial table { font-size: 0.85rem; }
        #modalHistorial table th, #modalHistorial table td { padding: 8px 10px; }
        #modalHistorial .text-right { font-size: 1em; } /* Anula el 1.1rem global para que coincida con el resto de la fila */
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>📊 Cuentas Corrientes</h1>
            <a href="pagos_ctacte.php" class="btn-primary" style="padding: 10px 20px; text-decoration: none; border-radius: 5px;">
                ➕ Registrar Pago / Cobro
            </a>
        </div>

        <div class="card" style="margin-bottom: 25px;">
            <label for="buscar_cliente_cc" style="color: #00bcd4; font-weight: bold; margin-bottom: 10px; display: block;">
                <i class="fas fa-search"></i> Buscar cliente para ver historial completo:
            </label>
            <div style="position: relative;">
                <input type="text" id="buscar_cliente_cc" class="input-field" placeholder="Escriba nombre, apellido o CUIT..." autocomplete="off">
                <div id="resultadosBusquedaCC" style="display: none; position: absolute; z-index: 1000; width: 100%; background: #2a2a2a; border: 1px solid #444; max-height: 250px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,0.5); border-radius: 0 0 8px 8px;"></div>
            </div>
        </div>

        <div class="card-stats">
            <div class="stat-card" style="border-left-color: #e74c3c;">
                <h3><i class="fas fa-arrow-down"></i> Deuda Total (Clientes)</h3>
                <div class="value">$ <?php echo number_format($deuda_total, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #2ecc71;">
                <h3><i class="fas fa-arrow-up"></i> Saldo a Favor Total</h3>
                <div class="value">$ <?php echo number_format($saldo_favor_total, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-users"></i> Clientes Activos</h3>
                <div class="value"><?php echo count($clientes_cc); ?></div>
            </div>
        </div>

        <!-- Filtros de Visualización -->
        <div style="margin-bottom: 15px; display: flex; gap: 10px;">
            <button class="btn btn-secondary" onclick="filtrarTabla('todos', this)" style="opacity: 1;">Todos</button>
            <button class="btn btn-secondary" onclick="filtrarTabla('deudores', this)" style="opacity: 0.6;">Solo Deudores</button>
            <button class="btn btn-secondary" onclick="filtrarTabla('favor', this)" style="opacity: 0.6;">Solo Saldo a Favor</button>
        </div>

        <div class="card">
            <table id="tablaSaldos">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>CUIT / Documento</th>
                        <th>Teléfono</th>
                        <th style="text-align: right;">Saldo Actual</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes_cc)): ?>
                        <tr><td colspan="5" style="text-align: center;">No hay clientes con saldos pendientes.</td></tr>
                    <?php else: ?>
                        <?php foreach ($clientes_cc as $c): ?>
                            <tr class="fila-cliente" data-saldo="<?php echo $c['saldo_actual']; ?>">
                                <td><strong><?php echo htmlspecialchars($c['nombre_completo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['cuit']); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono']); ?></td>
                                <td style="text-align: right;" class="<?php echo $c['saldo_actual'] > 0 ? 'saldo-deudor' : 'saldo-favor'; ?>">
                                    $ <?php echo number_format($c['saldo_actual'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-view" onclick="verDetalle(<?php echo $c['id_cliente']; ?>, '<?php echo $c['nombre_completo']; ?>')">
                                        👁️ Ver Historial
                                    </button>
                                    <?php if (tiene_permiso('whatsapp_enviar')): ?>
                                        <button class="btn-whatsapp-nodered" title="Enviar saldo vía Node-RED"
                                                onclick="enviarWhatsAppNodeRed('<?php echo $c['telefono']; ?>', '<?php echo addslashes($c['nombre_completo']); ?>', <?php echo $c['saldo_actual']; ?>)">
                                            <i class="fab fa-whatsapp"></i> WhatsApp
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de Historial Re-estructurado -->
    <div id="modalHistorial" class="modal">
        <div class="modal-content" style="max-width: 950px; border-top: 4px solid #3498db;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 id="tituloHistorial" style="margin: 0; color: #3498db;">Historial de Movimientos</h2>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalHistorial()">&times;</span>
            </div>
            
            <div style="max-height: 70vh; overflow-y: auto;">
                <table class="table-full" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Movimiento</th>
                            <th>N° Doc.</th>
                            <th style="text-align: right;">Debe</th>
                            <th style="text-align: right;">Haber</th>
                            <th style="text-align: right;">Saldo Acu.</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoHistorial"></tbody>
                </table>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid #444; padding-top: 15px;">
                <button class="btn btn-success" onclick="abrirModalPagoCliente()"><i class="fas fa-money-bill-wave"></i> Registrar Pago</button>
                <button class="btn btn-secondary" onclick="cerrarModalHistorial()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal para Confirmar Envío de WhatsApp -->
    <div id="modalWhatsApp" class="modal-custom" style="display:none;">
        <div class="modal-content-custom" style="max-width: 500px; border-top: 4px solid #25d366;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 style="margin: 0; color: #25d366;"><i class="fab fa-whatsapp"></i> Confirmar Envío</h2>
                <button onclick="cerrarModalWhatsApp()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            
            <div style="margin-top: 20px;">
                <p style="color: #bbb; font-size: 0.9em; margin-bottom: 10px;">Vista previa del mensaje a enviar:</p>
                <div id="mensajeWhatsAppPreview" style="background: #111; padding: 15px; border-radius: 6px; border-left: 3px solid #25d366; font-style: italic; color: #eee; line-height: 1.4; white-space: pre-wrap;">
                </div>
                <input type="hidden" id="wa_destino_tel">
                <input type="hidden" id="wa_destino_msg">
            </div>
            
            <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn" style="background: #444; color: white;" onclick="cerrarModalWhatsApp()">Cancelar</button>
                <button id="btnConfirmarWA" class="btn" style="background: #25d366; color: white; font-weight: bold; padding: 10px 20px;" onclick="ejecutarEnvioWhatsApp()">
                    <i class="fas fa-paper-plane"></i> ENVIAR AHORA
                </button>
            </div>
        </div>
    </div>

<!-- 	<div id="detalleModal" class="modal">
		<div class="modal-content-lg">
			<span class="close-button" onclick="document.getElementById('detalleModal').style.display='none';">&times;</span>
			<h2>Detalle de Venta #<span id="detalleNdocumento"></span></h2>
			
			<div id="detalleBody">
			</div>
			
			<button class="btn btn-success" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)" style="margin-top: 20px;">
				🖨️ Reimprimir desde Detalle
			</button>
		</div>
	</div> -->

	<div id="modalFactura" class="modal-custom" style="display:none;">
        <div class="modal-content-custom">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 id="tituloModalFactura">Detalle de Operación <span id="detalleNdocumento"></span></h2>
                <button onclick="cerrarModalFactura()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            
            <div id="cuerpoDetalleFactura" style="margin-top: 20px;">
                </div>
            
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn-action btn-ticket-v" id="btnTicketFactura" onclick="accionImprimirModal('ticket')">
                    🖨️ Imprimir Ticket
                </button>
                <button class="btn-action btn-pdf-v" id="btnPDFFactura" onclick="accionImprimirModal('pdf')">
                    📄 PDF A5
                </button>
            </div>
        </div>
    </div>

    <!-- Modal para Registrar Pago de Cliente -->
    <div id="modalRegistrarPagoCliente" class="modal">
        <div class="modal-content" style="max-width: 500px; border-top: 4px solid #2ecc71;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 style="margin: 0; color: #2ecc71;"><i class="fas fa-money-bill-wave"></i> Registrar Pago</h2>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalRegistrarPagoCliente()">&times;</span>
            </div>
            <form id="formRegistrarPagoCliente">
                <input type="hidden" name="id_cliente" id="pago_cliente_id">
                <p>Cliente: <strong id="pago_cliente_nombre" style="color: #00bcd4;"></strong></p>
                <label>Monto a Abonar ($)</label>
                <input type="number" id="pago_monto" name="monto_pago" step="0.01" min="0.01" class="input-field input-monto" placeholder="0.00" required>
                <label>N° Recibo / Referencia (Opcional)</label>
                <input type="text" id="pago_n_recibo" name="n_recibo" class="input-field" placeholder="Ej: Recibo 001">
                <label>Método de Pago</label>
                <select id="pago_condicion_pago" name="condicion_pago" class="input-field" required>
                    <option value="Efectivo">Efectivo</option>
                    <option value="Transferencia">Transferencia</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Tarjeta">Tarjeta</option>
                </select>
                <button type="submit" class="btn btn-primary btn-block" style="height: 50px; font-weight: bold; font-size: 1.1rem; margin-top: 15px;">
                    <i class="fas fa-check-circle"></i> CONFIRMAR PAGO
                </button>
            </form>
        </div>
    </div>

    <style>
    .btn-action { padding: 6px 12px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; font-size: 0.8rem; color: white; display: inline-flex; align-items: center; gap: 5px; }
    .btn-detalle-v { background-color: #3498db; }
    .btn-ticket-v { background-color: #2ecc71; }
    .btn-pdf-v { background-color: #00bcd4; }
    .btn-pdf-dev-v { background-color: #e67e22; }
    .btn-action:hover { opacity: 0.8; transform: translateY(-1px); }
    .btn-detalle-link { background: none; border: none; cursor: pointer; color: #3498db; font-size: 14px; }

    .toast-notificacion {
        position: fixed; top: 20px; right: 20px; background: #2ecc71; color: white;
        padding: 15px 25px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        z-index: 10000001; display: flex; align-items: center; gap: 10px; font-weight: bold;
        animation: slideInToast 0.3s ease-out forwards;
    }
    @keyframes slideInToast { from { transform: translateX(120%); } to { transform: translateX(0); } }
    .toast-fade-out { animation: fadeOutToast 0.5s ease-out forwards; }
    @keyframes fadeOutToast { from { opacity: 1; } to { opacity: 0; } }
    </style>

<script>
	const detalleNdocumento = document.getElementById('detalleNdocumento');
    // Variables para rastrear el cliente activo en los modales
    let currentClientId = null;
    let currentClientName = null;

    let modalActualTipo = ''; // 'VENTA', 'PAGO', 'DEVOLUCION'
    let modalActualId = 0;

    // Función maestra para los botones dentro del modal
    function accionImprimirModal(formato) {
        if (formato === 'ticket') {
            if (modalActualTipo === 'VENTA') imprimirTicket(modalActualId);
            else if (modalActualTipo === 'DEVOLUCION') imprimirRecibo(modalActualId, true);
            else if (modalActualTipo === 'PAGO') imprimirRecibo(modalActualId);
        } else { // formato === 'pdf'
            if (modalActualTipo === 'VENTA') descargarPDFVenta(modalActualId);
            else if (modalActualTipo === 'DEVOLUCION') imprimirReciboPDF(modalActualId, true);
            else if (modalActualTipo === 'PAGO') descargarPDFRecibo(modalActualId); // Usar descargarPDFRecibo para pagos
        }
    }

    // Función unificada para abrir el modal desde cualquier tipo de movimiento
    function abrirDetalleOperacion(id, tipo, etiqueta) {
        const modal = document.getElementById('modalFactura');
        const cuerpo = document.getElementById('cuerpoDetalleFactura');
        const labelDoc = document.getElementById('detalleNdocumento');
        
        modalActualTipo = tipo;
        modalActualId = id;
        
        modal.style.display = 'flex';
        if(labelDoc) labelDoc.textContent = etiqueta;
        
        cuerpo.innerHTML = '<p style="text-align:center; padding:20px;">Cargando información...</p>';

        let url = '';
        if (tipo === 'VENTA') url = '../ajax/obtener_detalle_venta.php?n_documento=' + id;
        else if (tipo === 'DEVOLUCION') url = '../ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE';
        else if (tipo === 'PAGO') url = '../ajax/obtener_detalle_pago.php?id=' + id;

        fetch(url)
            .then(res => res.text())
            .then(html => { cuerpo.innerHTML = html; })
            .catch(err => { cuerpo.innerHTML = '<p style="color:red;">Error al cargar detalle.</p>'; });
    }

    function imprimirTicket(nDocumento) {
        // Limpiamos el nDocumento por si viene con prefijos como # o OP#
        const doc = String(nDocumento).replace(/[^\d]/g, '');
        if(!doc || doc === "") {
            alert("No hay un número de documento válido para imprimir.");
            return;
        }
        const url = 'vista_previa_ticket.php?n_documento=' + doc;
        window.open(url, '_blank', 'width=350,height=600,scrollbars=yes,resizable=yes');
    }

    // Nueva función para descargar PDF de Venta
    function descargarPDFVenta(nDocumento) {
        const doc = String(nDocumento).replace(/[^\d]/g, '');
        window.location.href = 'generar_pdf_ticket.php?n_documento=' + doc + '&download=1';
    }

    // Función para Reimprimir Recibo de Pago (Movimiento de Cta Cte)
    function imprimirRecibo(idMovimiento, esDevolucion = false) {
        if (esDevolucion) {
            const url = 'vista_previa_ticket_devolucion.php?id=' + idMovimiento + '&tipo=CUENTA CORRIENTE';
            window.open(url, '_blank', 'width=400,height=700,scrollbars=yes,resizable=yes');
            return;
        }
        const url = 'vista_recibo.php?id_mov=' + idMovimiento + '&formato=ticket';
        window.open(url, '_blank', 'width=400,height=700,scrollbars=yes,resizable=yes');
    }

    // Función para Reimprimir Recibo de Pago en PDF A5
    function imprimirReciboPDF(idMovimiento, esDevolucion = false) {
        if (esDevolucion) {
            window.location.href = 'generar_pdf_devolucion.php?id=' + idMovimiento + '&tipo=CUENTA CORRIENTE&download=1';
            return;
        }
        const url = 'generar_pdf_recibo.php?id_mov=' + idMovimiento;
        window.open(url, '_blank');
    }

    // Nueva función para descargar PDF de Recibo
    function descargarPDFRecibo(idMovimiento) {
        window.location.href = 'generar_pdf_recibo.php?id_mov=' + idMovimiento + '&download=1';
    }

    function verDetalle(id, nombre) {
        const modalEl = document.getElementById('modalHistorial');
        const cuerpo = document.getElementById('cuerpoHistorial');
        const titulo = document.getElementById('tituloHistorial');
        
        modalEl.style.display = 'flex';
        modalEl.style.alignItems = 'center';
        modalEl.style.justifyContent = 'center';
        
        // Guardar datos del cliente para el modal de pago
        currentClientId = id;
        currentClientName = nombre;

        titulo.innerHTML = '<i class="fas fa-history"></i> Historial: ' + nombre;
        cuerpo.innerHTML = '<tr><td colspan="7" style="text-align:center;">Cargando movimientos...</td></tr>';

        fetch('../ajax/obtener_movimientos_cc.php?id_cliente=' + id)
            .then(res => res.text())
            .then(html => {
                cuerpo.innerHTML = html;
            })
            .catch(err => {
                cuerpo.innerHTML = '<tr><td colspan="7" style="color:red;">Error al cargar.</td></tr>';
            });
        // No cerramos el modal de historial aquí, solo lo actualizamos
    }

    function cerrarModalHistorial() {
        document.getElementById('modalHistorial').style.display = 'none';
    }

    function filtrarTabla(tipo, btn) {
        // Estilo visual de los botones de filtro
        document.querySelectorAll('.btn-secondary').forEach(b => b.style.opacity = '0.6');
        btn.style.opacity = '1';

        const filas = document.querySelectorAll('.fila-cliente');
        filas.forEach(f => {
            const saldo = parseFloat(f.dataset.saldo);
            if (tipo === 'todos') f.style.display = '';
            else if (tipo === 'deudores') f.style.display = (saldo > 0) ? '' : 'none';
            else if (tipo === 'favor') f.style.display = (saldo < 0) ? '' : 'none';
        });
    }

    function verDetalleFactura(idVenta) {
        const modal = document.getElementById('modalFactura');
        const cuerpo = document.getElementById('cuerpoDetalleFactura');
        const labelDoc = document.getElementById('detalleNdocumento'); // CAPTURAMOS EL SPAN
        
        modal.style.display = 'flex';
        if(labelDoc) labelDoc.textContent = '#' + idVenta; 

        // Mostramos los botones de factura
        document.getElementById('btnTicketFactura').style.display = 'inline-flex';
        document.getElementById('btnPDFFactura').style.display = 'inline-flex';
        
        cuerpo.innerHTML = '<p style="text-align:center;">Cargando detalle...</p>';

        fetch('../ajax/obtener_detalle_venta.php?n_documento=' + idVenta)
            .then(res => res.text())
            .then(html => {
                cuerpo.innerHTML = html;
            })
            .catch(err => {
                cuerpo.innerHTML = '<p style="color:red;">Error al cargar el detalle.</p>';
            });
    }

    // Nueva función para ver detalle de devoluciones
    function mostrarDetalleDevolucionCC(id) {
        const modal = document.getElementById('modalFactura');
        const cuerpo = document.getElementById('cuerpoDetalleFactura');
        const labelDoc = document.getElementById('detalleNdocumento');
        
        modal.style.display = 'flex';
        if(labelDoc) labelDoc.textContent = 'OP#' + id;

        // Ocultamos los botones de factura ya que las devoluciones tienen sus propias acciones en el listado
        document.getElementById('btnTicketFactura').style.display = 'none';
        document.getElementById('btnPDFFactura').style.display = 'none';
        
        cuerpo.innerHTML = '<p style="text-align:center; padding:20px;">Cargando información de devolución...</p>';

        fetch('../ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE')
            .then(res => res.text())
            .then(html => { cuerpo.innerHTML = html; })
            .catch(err => { cuerpo.innerHTML = '<p style="color:red;">Error al cargar.</p>'; });
    }

    function cerrarModalFactura() {
        document.getElementById('modalFactura').style.display = 'none';
    }
    function cerrarModalWhatsApp() { document.getElementById('modalWhatsApp').style.display = 'none'; }
    
    // Nueva función para abrir el modal de pago de cliente
    function abrirModalPagoCliente() {
        if (!currentClientId || !currentClientName) {
            mostrarMensaje("Error", "No hay un cliente seleccionado para registrar el pago.", "error");
            return;
        }

        // Pre-llenar el modal de pago
        document.getElementById('pago_cliente_id').value = currentClientId;
        document.getElementById('pago_cliente_nombre').innerText = currentClientName;
        document.getElementById('pago_monto').value = ''; // Limpiar monto anterior
        document.getElementById('pago_n_recibo').value = ''; // Limpiar recibo anterior

        // Cerrar el modal de historial y abrir el de pago
        cerrarModalHistorial();
        const modalPago = document.getElementById('modalRegistrarPagoCliente');
        modalPago.style.display = 'flex';
        modalPago.style.alignItems = 'center';
        modalPago.style.justifyContent = 'center';
    }

    function cerrarModalRegistrarPagoCliente() {
        document.getElementById('modalRegistrarPagoCliente').style.display = 'none';
        document.getElementById('formRegistrarPagoCliente').reset(); // Resetear el formulario
    }

    // Manejador de envío del formulario de pago de cliente
    document.getElementById('formRegistrarPagoCliente').onsubmit = function(e) {
        e.preventDefault();
        const form = this;
        const btnSubmit = form.querySelector('button[type="submit"]');

        const id_cliente = document.getElementById('pago_cliente_id').value;
        const monto_pago = parseFloat(document.getElementById('pago_monto').value);
        const n_recibo = document.getElementById('pago_n_recibo').value.trim();
        const condicion_pago = document.getElementById('pago_condicion_pago').value;

        if (isNaN(monto_pago) || monto_pago <= 0) {
            mostrarMensaje("Monto Inválido", "❌ Por favor, ingrese un monto superior a $0.00.", "error");
            return;
        }

        // Validación de Cheque
        if (condicion_pago === 'Cheque') {
            const nro = document.getElementById('pago_chq_nro').value.trim();
            const vto = document.getElementById('pago_chq_vto').value;
            if (!nro || !vto) {
                mostrarMensaje("Datos de Cheque", "⚠️ El N° de cheque y la fecha de vencimiento son obligatorios para este método.", "error");
                return;
            }
        }

        confirmarAccion(
            "Confirmar Pago a Cliente",
            `¿Está seguro de registrar un pago de $${monto_pago.toLocaleString('es-AR', {minimumFractionDigits:2})} para ${currentClientName}?`,
            "SÍ, REGISTRAR",
            "btn-success",
            () => {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

                const formData = new FormData();
                formData.append('id_cliente', id_cliente);
                formData.append('monto_pago', monto_pago);
                formData.append('n_recibo', n_recibo);
                formData.append('condicion_pago', condicion_pago);

                fetch('../procesos/registrar_pago_cc.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } // Indicar que es una petición AJAX
                })
                .then(response => response.json()) // Esperamos JSON como respuesta
                .then(data => {
                    if (data.success) {
                        cerrarModalRegistrarPagoCliente(); // CERRAR EL MODAL DE PAGO INMEDIATAMENTE
                        window.open(`../pages/vista_recibo.php?id_mov=${data.id_movimiento}`, '_blank', 'width=400,height=700,scrollbars=yes,resizable=yes'); // Abrir recibo en ventana pequeña
                        
                        mostrarMensaje("Pago Registrado", `✅ El pago ha sido registrado. Recibo N° ${data.id_movimiento}.`, "success", () => {
                            verDetalle(currentClientId, currentClientName); // Refrescar historial del cliente
                            location.reload(); // Recargar la página principal para actualizar saldos
                        });
                    } else {
                        mostrarMensaje("Error", `❌ ${data.error}`, "error");
                    }
                })
                .catch(error => {
                    console.error("Error al registrar pago:", error);
                    mostrarMensaje("Error Técnico", "❌ No se pudo conectar con el servidor para registrar el pago.", "error");
                })
                .finally(() => {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> CONFIRMAR PAGO';
                });
            }
        );
    };

    // Actualizar window.onclick para incluir el nuevo modal
    window.onclick = function(event) {
        const modals = [
            document.getElementById('modalFactura'),
            document.getElementById('modalWhatsApp'),
            document.getElementById('modalHistorial'),
            document.getElementById('modalRegistrarPagoCliente')
        ];
        modals.forEach(m => { if (m && event.target == m) m.style.display = 'none'; });
    }

    async function enviarWhatsAppNodeRed(telefono, nombre, saldo) {
        if (!telefono || telefono.trim() === "") {
            alert("⚠️ El cliente no tiene un teléfono registrado.");
            return;
        }

        // Preparamos el mensaje dinámico
        const saldoAbs = Math.abs(saldo).toLocaleString('es-AR', {minimumFractionDigits: 2});
        const tipoSaldo = saldo > 0 ? "deudor de $" : "a favor de $";
        const mensaje = `Hola ${nombre}, te informamos que tu estado de cuenta en Electricidad Lucyk registra un saldo ${tipoSaldo}${saldoAbs}. ¡Saludos!`;
        
        // Cargamos datos en el modal
        document.getElementById('wa_destino_tel').value = telefono;
        document.getElementById('wa_destino_msg').value = mensaje;
        document.getElementById('mensajeWhatsAppPreview').innerText = mensaje;
        
        // Mostramos el modal
        document.getElementById('modalWhatsApp').style.display = 'flex';
    }

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

    async function ejecutarEnvioWhatsApp() {
        const telefono = document.getElementById('wa_destino_tel').value;
        const mensaje = document.getElementById('wa_destino_msg').value;
        const btn = document.getElementById('btnConfirmarWA');

        try {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ENVIANDO...';

            const response = await fetch('../ajax/enviar_whatsapp_nodered.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    telefono: telefono,
                    mensaje: mensaje
                })
            });

            if (!response.ok) {
                throw new Error("Respuesta del servidor no válida (HTTP " + response.status + ")");
            }

            // Leemos como texto primero para limpiar posibles espacios y luego parseamos
            const text = await response.text();
            const result = JSON.parse(text.substring(text.indexOf('{')));

            if (result.success) {
                mostrarToast("Mensaje enviado correctamente");
                cerrarModalWhatsApp();
            } else {
                mostrarToast("Error: " + (result.error || "No se pudo enviar"), "error");
            }
        } catch (error) {
            console.error("Error en envío WA:", error);
            mostrarToast("Fallo técnico: " + error.message, "error");
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ENVIAR AHORA';
        }
    }

    // --- LÓGICA DEL BUSCADOR MANUAL DE CLIENTES ---
    const inputCC = document.getElementById('buscar_cliente_cc');
    const resCC = document.getElementById('resultadosBusquedaCC');

    if (inputCC) {
        inputCC.addEventListener('input', function() {
            const q = this.value.trim();
            // buscar_cliente_ajax.php requiere mínimo 3 caracteres según su código
            if (q.length < 3) {
                resCC.style.display = 'none';
                return;
            }

            fetch('buscar_cliente_ajax.php?q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    resCC.innerHTML = '';
                    if (data && data.length > 0) {
                        resCC.style.display = 'block';
                        data.forEach(c => {
                            const div = document.createElement('div');
                            div.style.padding = '12px';
                            div.style.cursor = 'pointer';
                            div.style.borderBottom = '1px solid #333';
                            div.style.color = '#fff';
                            div.innerHTML = `<strong>${c.nombre_completo}</strong> <small style="color:#888;">(Doc: ${c.num_documento || 'S/D'})</small>`;
                            
                            // Efecto visual al pasar el mouse
                            div.onmouseover = () => { div.style.background = '#3498db'; div.style.color = '#000'; };
                            div.onmouseout = () => { div.style.background = 'transparent'; div.style.color = '#fff'; };

                            div.onclick = () => {
                                verDetalle(c.id_cliente, c.nombre_completo);
                                inputCC.value = '';
                                resCC.style.display = 'none';
                            };
                            resCC.appendChild(div);
                        });
                    } else {
                        resCC.style.display = 'none';
                    }
                })
                .catch(err => console.error("Error en búsqueda CC:", err));
        });

        // Cerrar lista de resultados si se hace clic fuera del buscador
        document.addEventListener('click', (e) => {
            if (!inputCC.contains(e.target) && !resCC.contains(e.target)) {
                resCC.style.display = 'none';
            }
        });
    }
</script>
</body>
</html>