<?php
// pages/cuentas_corrientes.php
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
            COALESCE(SUM(m.debe), 0) AS total_debe,
            COALESCE(SUM(m.haber), 0) AS total_haber,
            COALESCE(SUM(m.debe), 0) - COALESCE(SUM(m.haber), 0) AS saldo_actual
        FROM clientes c
        INNER JOIN ctacte m ON c.id = m.id_cliente
        GROUP BY c.id
        HAVING saldo_actual != 0
        ORDER BY saldo_actual DESC;
    ";
    
    $stmt_saldos = $pdo->query($sql_saldos);
    $clientes_cc = $stmt_saldos->fetchAll(PDO::FETCH_ASSOC);

    // Calcular deuda total de la calle y saldo a favor acumulado
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
    <title>Cuentas Corrientes | Electricidad Lucyk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* --- ESTILOS DE DASHBOARD --- */
        .card-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #2c2c2c;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #00bcd4;
            color: #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .stat-card h3 { margin: 0; font-size: 0.7rem; color: #bbb; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 1.4rem; font-weight: bold; margin-top: 5px; }
        
        .saldo-deudor { color: #ff5e5e; font-weight: bold; }
        .saldo-favor { color: #2ecc71; font-weight: bold; }
        
        /* Reducción de Escala General */
        .content { padding: 20px 30px; }
        .content h1 { font-size: 1.6rem; margin-bottom: 20px; padding-bottom: 8px; }
        .card { padding: 12px 15px; margin-bottom: 15px; }
        .card h2, .card h3 { font-size: 1.1rem; }
        .input-field { padding: 8px !important; font-size: 0.9rem; margin-bottom: 10px !important; }
        label { font-size: 0.85rem; margin-bottom: 4px; }
        .btn, .btn-primary, .btn-secondary, .btn-success, .btn-view, .btn-whatsapp-nodered { padding: 6px 12px; font-size: 0.85rem; }

        .btn-view { background: #3498db; color: white; border-radius: 4px; text-decoration: none; cursor: pointer; border:none; }
        .btn-whatsapp-nodered { background: #25d366; color: white; border-radius: 4px; text-decoration: none; margin-left: 5px; border: none; cursor: pointer; }

        /* Toast Notifications */
        .toast-notificacion {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #2ecc71;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 0.9rem;
            z-index: 2147483647;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            animation: toast-slide-in 0.3s ease;
        }
        .toast-fade-out {
            animation: toast-fade-out 0.5s ease forwards;
        }
        @keyframes toast-slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes toast-fade-out {
            to { transform: translateX(100%); opacity: 0; }
        }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #1f1f1f; color: #eee; font-size: 0.82rem; }
        th { background: #333; color: #fff; padding: 6px 10px !important; text-align: left; }
        td { padding: 6px 10px !important; border-bottom: 1px solid #444; }
        table .text-right { font-size: 0.85rem !important; }
        tr:hover { background: #292929; }

        /* --- FIX DEFINITIVO MODALES --- */
        /* Forzamos el centrado absoluto sobre el viewport ignorando márgenes y flujos del sistema */
        .modal, .modal-custom {
            position: fixed !important;
            z-index: 2147483640 !important; /* Bajamos ligeramente para dejar espacio al toast */
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background-color: rgba(0, 0, 0, 0.98) !important;
            display: none; /* El JS usará flex para mostrarlo */
            align-items: center !important;
            justify-content: center !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .modal-content, .modal-content-custom {
            background-color: #1e1e1e !important;
            margin: 0 !important; /* Eliminamos el margen que lo empuja hacia abajo */
            padding: 15px 20px !important;
            border: 1px solid #444 !important;
            border-radius: 12px !important;
            width: 95% !important;
            max-width: 900px !important;
            color: white !important;
            box-shadow: 0 15px 60px rgba(0,0,0,1) !important;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .btn-action { padding: 6px 12px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; font-size: 0.8rem; color: white; display: inline-flex; align-items: center; gap: 5px; }
        .btn-detalle-v { background-color: #3498db; }
        .btn-ticket-v { background-color: #2ecc71; }
        .btn-pdf-v { background-color: #00bcd4; }

        /* Ocultamos específicamente los botones de impresión en el historial para mantener la lupa visible */
        #cuerpoHistorial .btn-ticket-v, 
        #cuerpoHistorial .btn-pdf-v,
        #cuerpoHistorial .btn-success,
        #cuerpoHistorial .fa-print,
        #cuerpoHistorial .fa-file-pdf,
        #cuerpoHistorial .fa-file-invoice { display: none !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>📊 Cuentas Corrientes</h1>
            <a href="pagos_ctacte.php" class="btn-primary" style="padding: 8px 18px; text-decoration: none; border-radius: 5px;">
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
<button class="btn-view" onclick="verDetalle(<?php echo $c['id_cliente']; ?>, '<?php echo htmlspecialchars($c['nombre_completo'], ENT_QUOTES); ?>')" aria-label="Ver historial de <?php echo htmlspecialchars($c['nombre_completo'], ENT_QUOTES); ?>">
                                            <i class="fas fa-eye" aria-hidden="true"></i> Ver Historial
                                        </button>
<?php if (tiene_permiso('whatsapp_enviar')): ?>
                                            <button class="btn-whatsapp-nodered" title="Enviar saldo vía Node-RED" aria-label="Enviar WhatsApp a <?php echo htmlspecialchars($c['nombre_completo'], ENT_QUOTES); ?>"
                                                    onclick="enviarWhatsAppNodeRed('<?php echo htmlspecialchars($c['telefono'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['nombre_completo'], ENT_QUOTES); ?>', <?php echo $c['saldo_actual']; ?>)">
                                                <i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp
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

    <!-- Modales (Cerrados por defecto) -->
    
    <!-- 1. Modal de Historial -->
    <div id="modalHistorial" class="modal" style="display:none;">
        <div class="modal-content" style="border-top: 4px solid #3498db;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 id="tituloHistorial" style="margin: 0; color: #3498db;">Historial de Movimientos</h2>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalHistorial()">&times;</span>
            </div>
            <div style="max-height: 60vh; overflow-y: auto;">
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

    <!-- 2. Modal de Detalle de Operación (Ventas/Pagos) -->
	<div id="modalFactura" class="modal-custom" style="display:none;">
        <div class="modal-content-custom">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 id="tituloModalFactura">Detalle de Operación <span id="detalleNdocumento"></span></h2>
                <button onclick="cerrarModalFactura()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            <div id="cuerpoDetalleFactura" style="margin-top: 20px;"></div>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn-action btn-ticket-v" id="btnTicketFactura" onclick="accionImprimirModal('ticket')">🖨️ Ticket</button>
                <button class="btn-action btn-pdf-v" id="btnPDFFactura" onclick="accionImprimirModal('pdf')">📄 PDF A5</button>
            </div>
        </div>
    </div>

    <!-- 3. Modal Registrar Pago Rápido -->
    <div id="modalRegistrarPagoCliente" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 500px; border-top: 4px solid #2ecc71;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 style="margin: 0; color: #2ecc71;"><i class="fas fa-money-bill-wave"></i> Registrar Pago</h2>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalRegistrarPagoCliente()">&times;</span>
            </div>
            <form id="formRegistrarPagoCliente">
                <input type="hidden" name="id_cliente" id="pago_cliente_id">
                <p>Cliente: <strong id="pago_cliente_nombre" style="color: #00bcd4;"></strong></p>
                <label>Monto a Abonar ($)</label>
                <input type="number" id="pago_monto" name="monto_pago" step="0.01" min="0.01" class="input-field" placeholder="0.00" required>
                <label>Método de Pago</label>
                <select name="condicion_pago" id="pago_condicion_pago" class="input-field" required onchange="toggleChequeFields('modal')">
                    <option value="Efectivo">Efectivo</option>
                    <option value="Transferencia">Transferencia</option>
                    <option value="Cheque">Cheque</option>
                </select>

                <!-- Campos extra para Cheque en Modal -->
                <div id="panel_cheque_modal" style="display: none; background: #111; padding: 12px; border-radius: 8px; border: 1px dashed #f1c40f; margin-bottom: 15px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="grid-column: span 2;">
                            <label style="font-size: 0.75rem;">N° de Cheque</label>
                            <input type="text" id="pago_chq_nro" name="chq_nro" class="input-field" placeholder="Número del documento">
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">Emisión</label>
                            <input type="date" id="pago_chq_emision" name="chq_emision" class="input-field" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">Vencimiento</label>
                            <input type="date" id="pago_chq_vto" name="chq_vto" class="input-field">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="height: 50px; font-weight: bold; margin-top: 15px;">CONFIRMAR PAGO</button>
            </form>
        </div>
    </div>

    <!-- 4. Modal Confirmación WhatsApp -->
    <div id="modalWhatsApp" class="modal-custom" style="display:none;">
        <div class="modal-content-custom" style="max-width: 500px; border-top: 4px solid #25d366;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 style="margin: 0; color: #25d366;"><i class="fab fa-whatsapp"></i> WhatsApp</h2>
                <button onclick="cerrarModalWhatsApp()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            <div style="margin-top: 20px;">
                <div id="mensajeWhatsAppPreview" style="background: #111; padding: 15px; border-radius: 6px; border-left: 3px solid #25d366; font-style: italic; color: #eee; line-height: 1.4; white-space: pre-wrap;"></div>
                <input type="hidden" id="wa_destino_tel">
                <input type="hidden" id="wa_destino_msg">
            </div>
            <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn" style="background: #444; color: white;" onclick="cerrarModalWhatsApp()">Cancelar</button>
                <button id="btnConfirmarWA" class="btn" style="background: #25d366; color: white; font-weight: bold; padding: 10px 20px;" onclick="ejecutarEnvioWhatsApp()">ENVIAR AHORA</button>
            </div>
        </div>
    </div>

<script>
    let currentClientId = null;
    let currentClientName = null;
    let modalActualTipo = ''; 
    let modalActualId = 0;

    // --- FUNCIONES DE UTILIDAD ---

    // Función para mostrar notificaciones tipo "toast"
    function mostrarToast(mensaje, tipo = 'success') {
        const toast = document.createElement('div');
        toast.className = 'toast-notificacion';
        if (tipo === 'error') toast.style.background = '#e74c3c';
        toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            setTimeout(() => toast.remove(), 500);
        }, 5000); // Mostrar por 5 segundos
    }

    window.toggleChequeFields = function(suffix) {
        const combo = document.getElementById(suffix === 'modal' ? 'pago_condicion_pago' : 'condicion_pago');
        const panel = document.getElementById('panel_cheque_' + suffix);
        if (combo.value === 'Cheque') {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }

    // --- LÓGICA DE DETALLE Y PAGO ---

    function verDetalle(id, nombre) {
        currentClientId = id;
        currentClientName = nombre;
        const modalEl = document.getElementById('modalHistorial');
        const cuerpo = document.getElementById('cuerpoHistorial');
        const titulo = document.getElementById('tituloHistorial');
        
        modalEl.style.display = 'flex';
        titulo.innerHTML = '<i class="fas fa-history"></i> Historial: ' + nombre;
        cuerpo.innerHTML = '<tr><td colspan="7" style="text-align:center;">Cargando movimientos...</td></tr>';

        fetch('../ajax/obtener_movimientos_cc.php?id_cliente=' + id)
            .then(res => res.text())
            .then(html => cuerpo.innerHTML = html)
            .catch(() => cuerpo.innerHTML = '<tr><td colspan="7" style="color:red;">Error al cargar.</td></tr>');
    }

    function abrirDetalleOperacion(id, tipo, etiqueta) {
        const modal = document.getElementById('modalFactura');
        const cuerpo = document.getElementById('cuerpoDetalleFactura');
        const labelDoc = document.getElementById('detalleNdocumento');
        modalActualTipo = tipo;
        modalActualId = id;
        modal.style.display = 'flex';
        if(labelDoc) labelDoc.textContent = etiqueta;
        cuerpo.innerHTML = '<p style="text-align:center; padding:20px;">Cargando...</p>';

        let url = '';
        if (tipo === 'VENTA') url = '../ajax/obtener_detalle_venta.php?n_documento=' + id;
        else if (tipo === 'DEVOLUCION') url = '../ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE';
        else if (tipo === 'PAGO') url = '../ajax/obtener_detalle_pago.php?id=' + id;

        fetch(url).then(res => res.text()).then(html => { cuerpo.innerHTML = html; });
    }

    function accionImprimirModal(formato) {
        // Limpiamos el ID por si tiene prefijos como # o OP# para asegurar que la URL sea válida
        const cleanId = String(modalActualId).replace(/[^\d]/g, '');
        
        if (!cleanId) {
            alert("No se pudo obtener un ID de documento válido para imprimir.");
            return;
        }

        if (formato === 'ticket') {
            if (modalActualTipo === 'VENTA') window.open('vista_previa_ticket.php?n_documento=' + cleanId, '_blank', 'width=400,height=700');
            else if (modalActualTipo === 'PAGO') window.open('vista_recibo.php?id_mov=' + cleanId + '&formato=ticket', '_blank', 'width=400,height=700');
            else if (modalActualTipo === 'DEVOLUCION') window.open('vista_previa_ticket_devolucion.php?id=' + cleanId + '&tipo=CUENTA CORRIENTE', '_blank', 'width=400,height=700');
        } else { // Formato PDF
            if (modalActualTipo === 'VENTA') window.location.href = 'generar_pdf_ticket.php?n_documento=' + cleanId + '&download=1';
            else if (modalActualTipo === 'PAGO') window.location.href = 'generar_pdf_recibo.php?id_mov=' + cleanId + '&download=1';
            else if (modalActualTipo === 'DEVOLUCION') window.location.href = 'generar_pdf_devolucion.php?id=' + cleanId + '&tipo=CUENTA CORRIENTE&download=1';
        }
    }

    function abrirModalPagoCliente() {
        if (!currentClientId) return;
        document.getElementById('pago_cliente_id').value = currentClientId;
        document.getElementById('pago_cliente_nombre').innerText = currentClientName;
        cerrarModalHistorial();
        document.getElementById('modalRegistrarPagoCliente').style.display = 'flex';
    }

    document.getElementById('formRegistrarPagoCliente').onsubmit = function(e) {
        e.preventDefault();

        // Validación de Cheque
        const condicion = document.getElementById('pago_condicion_pago').value;
        if (condicion === 'Cheque') {
            const nro = document.getElementById('pago_chq_nro').value.trim();
            const vto = document.getElementById('pago_chq_vto').value;
            if (!nro || !vto) {
                mostrarToast("⚠️ El N° de cheque y el vencimiento son obligatorios.", "error");
                return;
            }
        }

        // Truco para evitar el bloqueador de popups: Abrir la ventana inmediatamente durante el evento
        const receiptWin = window.open('', '_blank', 'width=400,height=700,scrollbars=yes');
        if (receiptWin) receiptWin.document.write('<p style="font-family:sans-serif; text-align:center; margin-top:20px;">Generando recibo...</p>');

        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

        const formData = new FormData(this);
        fetch('../procesos/registrar_pago_cc.php', { 
            method: 'POST', 
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Si logramos abrir la ventana, la redirigimos al recibo real
                if (receiptWin) {
                    receiptWin.location.href = 'vista_recibo.php?id_mov=' + data.id_movimiento;
                } else {
                    mostrarToast("⚠️ Pago registrado, pero se bloqueó el recibo. Habilite pop-ups.", "error");
                }
                
                // Recarga inmediata para actualizar saldos sin mensajes de éxito intermedios
                location.reload();
            } else {
                if (receiptWin) receiptWin.close();
                mostrarToast("❌ Error: " + data.error, "error");
                btn.disabled = false;
                btn.innerHTML = 'CONFIRMAR PAGO';
            }
        }).catch(err => {
            if (receiptWin) receiptWin.close();
            mostrarToast("❌ Error de conexión con el servidor", "error");
            btn.disabled = false;
            btn.innerHTML = 'CONFIRMAR PAGO';
        });
    };

    async function ejecutarEnvioWhatsApp() {
        const btn = document.getElementById('btnConfirmarWA');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ENVIANDO...';
        
        const tel = document.getElementById('wa_destino_tel').value;
        const msg = document.getElementById('wa_destino_msg').value;

        try {
            const response = await fetch('../ajax/enviar_whatsapp_nodered.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ telefono: tel, mensaje: msg })
            });

            const text = await response.text();
            const data = JSON.parse(text.substring(text.indexOf('{')));

            if (data.success) {
                mostrarToast("¡Mensaje enviado con éxito!", "success");
                cerrarModalWhatsApp();
            } else {
                mostrarToast("Error: " + (data.error || "No se pudo enviar"), "error");
            }
        } catch (err) {
            console.error("Error en fetch de WhatsApp:", err);
            mostrarToast("Error de conexión con el servidor.", "error");
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'ENVIAR AHORA';
        }
    }

    function cerrarModalHistorial() { document.getElementById('modalHistorial').style.display = 'none'; } // Ya existía
    function cerrarModalFactura() { document.getElementById('modalFactura').style.display = 'none'; }
    function cerrarModalWhatsApp() { document.getElementById('modalWhatsApp').style.display = 'none'; }
    function cerrarModalRegistrarPagoCliente() { document.getElementById('modalRegistrarPagoCliente').style.display = 'none'; }

    function filtrarTabla(tipo, btn) {
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

    // --- WHATSAPP ---

    async function enviarWhatsAppNodeRed(telefono, nombre, saldo) {
        if (!telefono || telefono.trim() === "") {
            return mostrarToast("El cliente no tiene un teléfono registrado.", "error");
        }
        
        const saldoAbs = Math.abs(saldo).toLocaleString('es-AR', {minimumFractionDigits: 2});
        const tipoSaldo = saldo > 0 ? "deudor de $" : "a favor de $";
        const msg = `Hola ${nombre}, te informamos que tu estado de cuenta en Electricidad Lucyk registra un saldo ${tipoSaldo}${saldoAbs}. ¡Saludos!`;
        
        document.getElementById('wa_destino_tel').value = telefono;
        document.getElementById('wa_destino_msg').value = msg;
        document.getElementById('mensajeWhatsAppPreview').innerText = msg;
        document.getElementById('modalWhatsApp').style.display = 'flex';
    }
    // --- BUSCADOR MANUAL CON DEBOUNCE ---
    const inputCC = document.getElementById('buscar_cliente_cc');
    const resCC = document.getElementById('resultadosBusquedaCC');
    let searchTimeout = null;
    
    if (inputCC) {
        inputCC.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const q = this.value.trim();
            
            if (q.length < 3) { 
                resCC.style.display = 'none'; 
                resCC.innerHTML = '';
                return; 
            }
            
            searchTimeout = setTimeout(() => {
                fetch('buscar_cliente_ajax.php?q=' + encodeURIComponent(q))
                    .then(res => res.json())
                    .then(data => {
                        resCC.innerHTML = '';
                        if (data && data.length > 0) {
                            resCC.style.display = 'block';
                            data.forEach(c => {
                                const div = document.createElement('div');
                                div.style.padding = '12px'; div.style.cursor = 'pointer'; div.style.borderBottom = '1px solid #333'; div.style.color = '#fff';
                                div.innerHTML = `<strong>${c.nombre_completo}</strong> <small style="color:#888;">(Doc: ${c.num_documento || 'S/D'})</small>`;
                                div.onclick = () => { verDetalle(c.id_cliente, c.nombre_completo); inputCC.value = ''; resCC.style.display = 'none'; };
                                resCC.appendChild(div);
                            });
                        } else {
                            resCC.innerHTML = '<div style="padding:12px; color:#888;">No se encontraron clientes</div>';
                            resCC.style.display = 'block';
                        }
                    }).catch(() => {
                        resCC.innerHTML = '<div style="padding:12px; color:#e74c3c;">Error de conexión</div>';
                        resCC.style.display = 'block';
                    });
            }, 300); // Debounce 300ms
        });
        document.addEventListener('click', (e) => { if (!inputCC.contains(e.target) && !resCC.contains(e.target)) resCC.style.display = 'none'; });
    }

    window.onclick = function(e) {
        const modales = [document.getElementById('modalFactura'), document.getElementById('modalWhatsApp'), document.getElementById('modalHistorial'), document.getElementById('modalRegistrarPagoCliente')];
        modales.forEach(m => { if (m && e.target == m) m.style.display = 'none'; });
    }

    // Cerrar modales con ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('modalHistorial').style.display = 'none';
            document.getElementById('modalFactura').style.display = 'none';
            document.getElementById('modalWhatsApp').style.display = 'none';
            document.getElementById('modalRegistrarPagoCliente').style.display = 'none';
        }
    });
</script>
</body>
</html>