<?php
include 'infosesion.php';
// VALIDACIÓN CRÍTICA:
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require '../config/db_config.php'; 

// Carga de Proveedores (Usamos la misma lógica corregida de compras.php)
try {
    $sql_proveedores = "SELECT 
                            cod_prov AS id_proveedor, 
                            razon AS nombre, 
                            cuit 
                        FROM proveedores 
                        ORDER BY razon ASC";
    $stmt_proveedores = $pdo->query($sql_proveedores);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) {
    error_log("Error al cargar proveedores: " . $e->getMessage());
    $proveedores = []; 
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuentas Corrientes Proveedores</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* --- ESTILOS DE DASHBOARD --- */
        .card-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
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
        
        /* Fix para centrado de modales con flex */
        .modal {
            align-items: center;
            justify-content: center;
        }

        /* Reducción de Escala General */
        .content { padding: 20px 30px; }
        .content h1 { font-size: 1.6rem; margin-bottom: 20px; padding-bottom: 8px; }
        .card { padding: 12px 15px; margin-bottom: 15px; }
        .card h2, .card h3 { font-size: 1.1rem; }
        .input-field { padding: 8px !important; font-size: 0.9rem; margin-bottom: 10px !important; }
        label { font-size: 0.85rem; margin-bottom: 4px; }
        .btn { padding: 6px 12px; font-size: 0.85rem; }

        .saldo-debe { color: #f44336 !important; } /* Rojo: Deuda pendiente */
        .saldo-favor { color: #2ecc71 !important; } /* Verde: Saldo a favor */
        .saldo-cero { color: #aaa; }
        .fila-excedente { background-color: rgba(46, 204, 113, 0.08) !important; }

        .movimiento-vencido {
            color: #e74c3c; /* Rojo para movimientos vencidos */
            font-weight: bold;
        }
        .observaciones-btn { background: none; border: none; color: #00bcd4; cursor: pointer; font-size: 0.8em; padding: 0; text-decoration: underline; text-align: left; }
        .observaciones-btn:hover { color: #2980b9; }
        
        .table-full { font-size: 0.82rem; }
        .table-full th, .table-full td { padding: 6px 10px !important; }
        .table-full .text-right { font-size: 0.85rem !important; }
        .table-full th { position: sticky; top: 0; z-index: 10; background: #181818; }
        .badge-vencido { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
        
        /* Botones de filtro */
        .filter-btn { opacity: 0.6; transition: 0.3s; }
        .filter-btn.active { opacity: 1; border-bottom: 2px solid #00bcd4; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1><i class="fas fa-truck-loading"></i> Cuentas Corrientes Proveedores</h1>
            <button id="btn_pagar_cc" class="btn btn-primary" style="padding: 8px 18px; font-weight: bold;" disabled>
                <i class="fas fa-money-bill-wave"></i> REGISTRAR PAGO
            </button>
        </div>

        <div class="card">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; align-items: flex-end;">
                <div>
                    <label><i class="fas fa-search"></i> Seleccionar Proveedor para ver Estado de Cuenta:</label>
                    <select id="select_proveedor" class="input-field" style="margin-bottom: 0 !important;">
                    <option value="0">-- Seleccione un Proveedor --</option>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?php echo $p['id_proveedor']; ?>" data-cuit="<?php echo $p['cuit']; ?>">
                            <?php echo htmlspecialchars($p['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                </div>
                <div id="cuit_display_area" style="background: #111; padding: 10px; border-radius: 5px; border-left: 3px solid #444; color: #888; font-size: 0.9rem;">
                    CUIT: <span id="txt_cuit_proveedor">---</span>
                </div>
            </div>
        </div>

        <div id="area_estadisticas" class="card-stats" style="display: none;">
            <div class="stat-card">
                <h3>Saldo Total Adeudado</h3>
                <div class="value" id="val_saldo_total">$ 0.00</div>
            </div>
            <div class="stat-card" style="border-left-color: #e74c3c;">
                <h3>Deuda Vencida</h3>
                <div class="value" id="val_saldo_vencido" style="color: #ff5252;">$ 0.00</div>
            </div>
            <div class="stat-card" style="border-left-color: #f1c40f;">
                <h3>Facturas Pendientes</h3>
                <div class="value" id="val_cant_pendientes">0</div>
            </div>
        </div>

        <div class="card" id="card_historial" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #00bcd4;"><i class="fas fa-history"></i> Historial de Movimientos</h3>
                <div style="display: flex; gap: 10px;">
                    <button class="btn btn-secondary btn-sm filter-btn active" onclick="filtrarHistorial('todos', this)">Todos</button>
                    <button class="btn btn-secondary btn-sm filter-btn" onclick="filtrarHistorial('pendientes', this)">Pendientes</button>
                    <button class="btn btn-secondary btn-sm filter-btn" onclick="filtrarHistorial('vencidos', this)">Vencidos</button>
                </div>
            </div>

            <table id="historial_movimientos" class="table-full">
                <thead>
                    <tr>
                        <th style="width: 12%;">Fecha</th>
                        <th style="width: 15%;">Movimiento</th>
                        <th style="width: 10%;">N° Doc.</th>
                        <th style="width: 10%;">Vencimiento</th>
                        <th style="width: 15%;">Observaciones</th>
                        <th class="text-right" style="width: 10%;">Saldo Fact.</th>
                        <th class="text-right" style="width: 10%;">Haber ($)</th>
                        <th class="text-right" style="width: 10%;">Debe ($)</th>
                        <th class="text-right" style="width: 11%;">Saldo Acu.</th>
                        <th style="width: 7%;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="historial_tbody">
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px; color: #666;">Seleccione un proveedor para cargar los datos.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="modalPago" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Registrar Pago a Proveedor</h2>
            <form id="formPago">
                <input type="hidden" id="modal_proveedor_id">
                <p>Proveedor: <strong id="modal_proveedor_nombre"></strong></p>
                <p>Saldo Pendiente: <strong id="modal_saldo_pendiente"></strong></p>

                <label>Seleccionar Facturas a Imputar (Opcional)</label>
                <div id="contenedor_imputacion_multiple" style="background: #1a1a1a; padding: 10px; border-radius: 5px; max-height: 150px; overflow-y: auto; margin-bottom: 15px; border: 1px solid #444;">
                    <!-- Checkboxes se cargan por JS -->
                </div>

                <label for="monto_pago">Monto a Pagar ($)</label>
                <input type="number" step="0.01" min="0.01" id="monto_pago" class="input-field" required>

                <label for="tipo_pago">Tipo de Pago</label>
                <select id="tipo_pago" class="input-field" required>
                    <option value="Efectivo">Efectivo</option>
                    <option value="Transferencia">Transferencia</option>
                    <option value="Cheque">Cheque</option>
                </select>
                
                <label for="ref_pago">Referencia / N° Recibo Interno</label>
                <input type="text" id="ref_pago" class="input-field" placeholder="Ej: Recibo E-001">

                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 15px;">Confirmar Pago</button>
            </form>
        </div>
    </div>

    <!-- Modal para Re-imputar Excedente -->
    <div id="modalReimputarExcedente" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px; border-top: 4px solid #3498db;">
            <span class="close-button" onclick="cerrarModalReimputarExcedente()">&times;</span>
            <h2>Re-imputar Saldo Excedente</h2>
            <form id="formReimputarExcedente">
                <input type="hidden" id="reimputar_ctacte_id">
                <input type="hidden" id="reimputar_proveedor_id">
                <p>Proveedor: <strong id="reimputar_proveedor_nombre" style="color: #00bcd4;"></strong></p>
                <p>Monto disponible: <strong id="reimputar_monto_display" style="color: #2ecc71;"></strong></p>

                <label>Seleccionar Facturas para aplicar saldo:</label>
                <div id="contenedor_imputacion_reimputar" style="background: #1a1a1a; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto; margin-bottom: 15px; border: 1px solid #444;">
                    <!-- Checkboxes se cargan por JS -->
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="height: 45px; font-weight: bold;">
                    CONFIRMAR RE-IMPUTACIÓN
                </button>
            </form>
        </div>
    </div>

    <!-- Modal para Observaciones -->
    <div id="modalObservaciones" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close-button">&times;</span>
            <h2>Observaciones del Comprobante</h2>
            <p id="observacionesTexto" style="white-space: pre-wrap;"></p>
        </div>
    </div>


</body>
<script src="../js/global.js"></script> 
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectProveedor = document.getElementById('select_proveedor');
        const historialTbody = document.getElementById('historial_tbody');
        const btnPagar = document.getElementById('btn_pagar_cc');
        const areaStats = document.getElementById('area_estadisticas');
        
        // Modal elementos
        const modal = document.getElementById('modalPago');
        const closeModal = modal.querySelector('.close-button');
        const formPago = document.getElementById('formPago');
        const modalProveedorId = document.getElementById('modal_proveedor_id');
        const modalProveedorNombre = document.getElementById('modal_proveedor_nombre');
        const modalSaldoPendiente = document.getElementById('modal_saldo_pendiente');
        const inputMontoPago = document.getElementById('monto_pago');
        
        let saldoActual = 0.00; // Variable global para el saldo del proveedor seleccionado
        let movimientosActuales = []; // Guardamos movimientos para la imputación
        let hoy = new Date();
        hoy.setHours(0,0,0,0);

        // Función para cargar el historial y calcular el saldo
        function cargarHistorial(idProveedor) {
            if (idProveedor === '0') {
                historialTbody.innerHTML = '<tr><td colspan="10" style="text-align: center;">Seleccione un proveedor...</td></tr>';
                areaStats.style.display = 'none';
                btnPagar.disabled = true;
                return;
            }

            // Llamada AJAX (Necesitamos crear el archivo 'cargar_ctacte_proveedor_ajax.php')
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '../ajax/cargar_ctacte_proveedor_ajax.php?id=' + idProveedor, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const data = JSON.parse(this.responseText);
                        
                        historialTbody.innerHTML = '';
                        saldoActual = 0.00;
                        let saldoVencido = 0;
                        let cantPendientes = 0;
                        movimientosActuales = data.movimientos || [];

                        if (data.movimientos && data.movimientos.length > 0) {                            
                            data.movimientos.forEach(mov => {
                                saldoActual += (parseFloat(mov.haber) || 0) - (parseFloat(mov.debe) || 0);
                                
                                // Identificamos si es un comprobante de DEUDA real (Factura, Remito, etc)
                                // Excluimos explícitamente cualquier movimiento que sea un Pago, Ajuste o Imputación para evitar tildes duplicadas.
                                const esMovimientoDeuda = (mov.movimiento.includes('FACTURA') || mov.movimiento.includes('S/DETALLE') || mov.movimiento.includes('REMITO'));
                                const esAccionDePago = (mov.movimiento.includes('AJUSTE') || mov.movimiento.includes('PAGO') || mov.movimiento.includes('IMPUTACIÓN'));
                                
                                const isFactura = esMovimientoDeuda && !esAccionDePago;
                                const saldoFactura = parseFloat(mov.saldo_pendiente_factura) || 0;

                                const row = historialTbody.insertRow();
                                row.className = 'fila-mov';
                                row.dataset.vencido = 'no';
                                row.dataset.pendiente = (saldoFactura > 0) ? 'si' : 'no';
                                
                                let vencimientoHtml = '<span style="color:#666;">---</span>';
                                let observacionesHtml = '';
                                let rowClass = '';

                                // Resaltar filas que son pagos a cuenta o excedentes disponibles (dinero a favor)
                                if (!mov.compra_id && parseFloat(mov.debe) > 0 && (mov.movimiento.includes('SALDO EXCEDENTE') || mov.movimiento.includes('PAGO'))) {
                                    rowClass = 'fila-excedente';
                                }

                                if (mov.fecha_vencimiento && mov.fecha_vencimiento !== '0000-00-00' && isFactura) {
                                    // Parseo robusto de YYYY-MM-DD para evitar errores de zona horaria (UTC vs Local)
                                    const parts = mov.fecha_vencimiento.split('-');
                                    const fechaVencimiento = new Date(parts[0], parts[1] - 1, parts[2]);
                                    
                                    // Solo marcamos como vencido si la fecha es estrictamente anterior a HOY
                                    // a las 00:00:00 y aún tiene saldo pendiente.
                                    if (fechaVencimiento.getTime() < hoy.getTime() && saldoFactura > 0) {
                                        rowClass = 'movimiento-vencido'; // Clase para la fila
                                        row.dataset.vencido = 'si';
                                        saldoVencido += saldoFactura;
                                    }
                                    vencimientoHtml = `<span class="${rowClass}">${fechaVencimiento.toLocaleDateString('es-AR')}</span>`;
                                }

                                if (saldoFactura > 0 && isFactura) cantPendientes++;

                                if (mov.observaciones) {
                                    const obsCorta = mov.observaciones.length > 30 ? mov.observaciones.substring(0, 27) + '...' : mov.observaciones;
                                    observacionesHtml = `<button class="observaciones-btn" onclick="mostrarModalObservaciones('${encodeURIComponent(mov.observaciones)}')">${obsCorta}</button>`;
                                }
                                
                                let accionesHtml = '';
                                if (mov.compra_id && isFactura && saldoFactura > 0) {
                                    accionesHtml = `<button class="btn btn-sm btn-success" 
                                                        onclick="marcarComoPagado(${idProveedor}, '${mov.n_documento}', ${saldoFactura}, this, ${mov.compra_id})">
                                                        <i class="fas fa-check"></i>
                                                    </button>`;
                                } else if (!mov.compra_id && parseFloat(mov.debe) > 0 && (mov.movimiento.includes('SALDO EXCEDENTE') || mov.movimiento.includes('PAGO'))) {
                                    // Botón para re-imputar excedentes o pagos generales sin imputar
                                    accionesHtml = `<button class="btn btn-sm btn-primary" 
                                                        onclick="abrirModalReimputarExcedente(${mov.ctacte_id}, ${parseFloat(mov.debe)}, ${idProveedor}, '${selectProveedor.options[selectProveedor.selectedIndex].text.trim()}')">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>`;

                                }

                                if (rowClass) {
                                    row.classList.add(rowClass);
                                }

                                const fParts = mov.fecha.split(' ')[0].split('-');
                                const fechaArg = `${fParts[2]}/${fParts[1]}/${fParts[0]}`;

                                row.innerHTML = `
                                    <td>${fechaArg}</td>
                                    <td>${mov.movimiento}</td>
                                    <td>${mov.n_documento}</td>
                                    <td>${vencimientoHtml}</td>
                                    <td>${observacionesHtml}</td>
                                    <td class="text-right" style="color:#f1c40f; font-weight:bold;">${saldoFactura > 0 ? '$ ' + saldoFactura.toLocaleString('es-AR', {minimumFractionDigits: 2}) : '-'}</td>
                                    <td class="text-right">$ ${parseFloat(mov.haber || 0).toLocaleString('es-AR', {minimumFractionDigits: 2})}</td>
                                    <td class="text-right">$ ${parseFloat(mov.debe || 0).toLocaleString('es-AR', {minimumFractionDigits: 2})}</td>
                                    <td class="text-right ${saldoActual > 0 ? 'saldo-debe' : (saldoActual < 0 ? 'saldo-favor' : 'saldo-cero')}" style="font-weight:bold;">$ ${saldoActual.toLocaleString('es-AR', {minimumFractionDigits: 2})}</td>
                                    <td style="text-align:center;">${accionesHtml}</td>
                                `;
                            });
                        } else {
                            historialTbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding:20px;">No hay movimientos registrados para este proveedor.</td></tr>';
                        }
                        
                        // Actualizar Stats
                        document.getElementById('val_saldo_total').innerText = '$ ' + saldoActual.toLocaleString('es-AR', {minimumFractionDigits: 2});
                        document.getElementById('val_saldo_vencido').innerText = '$ ' + saldoVencido.toLocaleString('es-AR', {minimumFractionDigits: 2});
                        document.getElementById('val_cant_pendientes').innerText = cantPendientes;
                        
                        areaStats.style.display = 'grid';
                        document.getElementById('card_historial').style.display = 'block';
                        btnPagar.disabled = false; // Permitimos pagar siempre (ej: pagos a cuenta/anticipos)

                    } catch (e) {
                        historialTbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: red;">Error al procesar los datos del servidor.</td></tr>';
                        console.error("Error al parsear JSON:", e);
                    }
                } else {
                    historialTbody.innerHTML = '<tr><td colspan="10" style="text-align: center;">Error al cargar datos del proveedor.</td></tr>';
                }
            };
            xhr.send();
        }

        window.filtrarHistorial = function(tipo, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            const filas = document.querySelectorAll('.fila-mov');
            filas.forEach(f => {
                if (tipo === 'todos') f.style.display = '';
                else if (tipo === 'pendientes') f.style.display = (f.dataset.pendiente === 'si') ? '' : 'none';
                else if (tipo === 'vencidos') f.style.display = (f.dataset.vencido === 'si') ? '' : 'none';
            });
        };

        // Evento de cambio en el selector de proveedor
        selectProveedor.addEventListener('change', function() {
            const id = this.value;
            const selected = this.options[this.selectedIndex];
            document.getElementById('txt_cuit_proveedor').innerText = selected.dataset.cuit || 'S/D';
            cargarHistorial(id);
        });

        // ===========================================
        // Lógica del Modal de Pago
        // ===========================================
        
        // Abrir Modal
        btnPagar.addEventListener('click', function() {
            const selectedOption = selectProveedor.options[selectProveedor.selectedIndex];
            const proveedorNombre = selectedOption.textContent.trim();
            const proveedorId = selectProveedor.value;
            
            if (proveedorId === '0') return;

            // Llenar datos del modal
            modalProveedorId.value = proveedorId;
            modalProveedorNombre.textContent = selectedOption.textContent.trim();
            modalSaldoPendiente.textContent = '$ ' + saldoActual.toLocaleString('es-AR', {minimumFractionDigits: 2});
            inputMontoPago.removeAttribute('max'); // Quitamos el tope para permitir pagos excedentes
            inputMontoPago.value = saldoActual.toFixed(2); // Sugerir el total
            
            // Llenar contenedor de imputación con facturas pendientes
            const container = document.getElementById('contenedor_imputacion_multiple');
            container.innerHTML = '';
            let hayFacturas = false;

            movimientosActuales.forEach(mov => {
                if (mov.compra_id && mov.saldo_pendiente_factura > 0) {
                    hayFacturas = true;
                    const div = document.createElement('div');
                    div.style.marginBottom = '8px';
                    div.innerHTML = `
                        <label style="display: flex; align-items: center; gap: 8px; color: #eee; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" class="chk-imputar" value="${mov.compra_id}" data-doc="${mov.n_documento}" style="width: 18px; height: 18px; margin: 0;">
                            <span>${mov.movimiento} #${mov.n_documento} (Saldo: $ ${parseFloat(mov.saldo_pendiente_factura).toLocaleString('es-AR', {minimumFractionDigits: 2})})</span>
                        </label>`;
                    container.appendChild(div);
                }
            });

            if (!hayFacturas) container.innerHTML = '<p style="color: #666; font-size: 0.85rem; margin: 0;">No hay facturas pendientes para imputar.</p>';

            modal.style.display = 'block';
        });

        // Cerrar Modal
        closeModal.addEventListener('click', () => {
            modal.style.display = 'none';
        });
        window.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
        
        // Enviar Pago
        formPago.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btnSubmit = formPago.querySelector('button[type="submit"]');
            const montoPago = parseFloat(inputMontoPago.value);
            const proveedorId = modalProveedorId.value;
            const tipoPago = document.getElementById('tipo_pago').value;
            const refPago = document.getElementById('ref_pago').value.trim();
            
            const imputarDocs = Array.from(document.querySelectorAll('.chk-imputar:checked')).map(cb => cb.value);

            if (montoPago <= 0) {
                mostrarMensaje("Validación", "❌ Por favor, ingrese un monto válido superior a $ 0,00.", "error");
                return;
            }

            // --- MEJORA 1: Bloquear el botón para evitar duplicados ---
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Procesando pago...';

            const formData = new FormData();
            formData.append('id_proveedor', proveedorId);
            formData.append('monto_pago', montoPago);
            formData.append('tipo_pago', tipoPago);
            formData.append('ref_pago', refPago);
            formData.append('imputar_docs', JSON.stringify(imputarDocs));

            const xhrPago = new XMLHttpRequest();
            xhrPago.open('POST', '../ajax/registrar_pago_proveedor_ajax.php', true);
            
            xhrPago.onload = function() {
                // --- MEJORA 2: Restaurar el botón al finalizar ---
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Confirmar Pago';

                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.exito) {
                            // --- MEJORA 3: Cierre y limpieza total ---
                            modal.style.display = 'none'; // Cerramos el modal inmediatamente
                            formPago.reset();             // Limpiamos los campos del formulario
                            
                            mostrarMensaje("Pago Registrado", response.mensaje || "Pago registrado con éxito.", "success", () => {
                                // Recargar el historial y actualizar el saldo en la pantalla principal
                                cargarHistorial(proveedorId); 
                            });
                        } else {
                            mostrarMensaje("Error al Registrar", response.mensaje, "error");
                        }
                    } catch (err) {
                        console.error("Error en respuesta:", this.responseText);
                        mostrarMensaje("Error de Respuesta", "No se pudo procesar la respuesta del servidor.", "error");
                    }
                } else {
                    mostrarMensaje("Error de Conexión", "Error de conexión con el servidor.", "error");
                }
            };

            xhrPago.onerror = function() {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Confirmar Pago';
                mostrarMensaje("Error de Red", "Error de red al intentar registrar el pago.", "error");
            };

            xhrPago.send(formData);
        });

        // Cargar historial al inicio (si hay un proveedor seleccionado, aunque por defecto será 0)
        cargarHistorial(selectProveedor.value);

        // Función para marcar una factura de compra como pagada (Ajuste rápido)
        window.marcarComoPagado = function(idProveedor, nDocumento, montoPendiente, btnElement, idCompra = null) {
            confirmarAccion(
                'Marcar Factura como Pagada',
                `¿Está seguro de registrar un pago de ajuste por $${montoPendiente.toLocaleString('es-AR', {minimumFractionDigits:2})} para la factura N° ${nDocumento}? Esto saldará el comprobante.`,
                'SÍ, MARCAR COMO PAGADA',
                'btn-success',
                () => {
                    if (btnElement) {
                        btnElement.disabled = true;
                        btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    }

                    fetch('../ajax/marcar_compra_pagada_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_proveedor: idProveedor,
                            n_documento: nDocumento,
                            monto_pendiente: montoPendiente,
                            compra_id: idCompra
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            mostrarMensaje('Éxito', data.mensaje, 'success', () => {
                                cargarHistorial(idProveedor); // Refrescar la tabla automáticamente
                            });
                        } else {
                            mostrarMensaje('Error', data.mensaje, 'error');
                            if (btnElement) {
                                btnElement.disabled = false;
                                btnElement.innerHTML = '<i class="fas fa-check"></i> Pagar';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error en fetch:', error);
                        mostrarMensaje('Error de Conexión', 'No se pudo conectar con el servidor.', 'error');
                        if (btnElement) {
                            btnElement.disabled = false;
                            btnElement.innerHTML = '<i class="fas fa-check"></i> Pagar';
                        }
                    });
                }
            );
        };

        // ===========================================
        // Lógica del Modal de Re-imputación de Excedente
        // ===========================================
        const modalReimputar = document.getElementById('modalReimputarExcedente');
        const reimputarCtacteId = document.getElementById('reimputar_ctacte_id');
        const reimputarProveedorId = document.getElementById('reimputar_proveedor_id');
        const reimputarMontoDisplay = document.getElementById('reimputar_monto_display');
        const reimputarProveedorNombre = document.getElementById('reimputar_proveedor_nombre');
        const contenedorImputacionReimputar = document.getElementById('contenedor_imputacion_reimputar');
        const formReimputarExcedente = document.getElementById('formReimputarExcedente');

        window.abrirModalReimputarExcedente = function(ctacteId, montoExcedente, idProveedor, nombreProveedor) {
            reimputarCtacteId.value = ctacteId;
            reimputarProveedorId.value = idProveedor;
            reimputarMontoDisplay.setAttribute('data-valor', montoExcedente); // Guardamos el número puro de forma segura
            reimputarMontoDisplay.innerText = `$ ${montoExcedente.toLocaleString('es-AR', {minimumFractionDigits:2})}`;
            reimputarProveedorNombre.innerText = nombreProveedor;

            // Título dinámico para diferenciar el origen del saldo a aplicar
            const movOrigen = movimientosActuales.find(m => m.ctacte_id == ctacteId);
            const esExc = movOrigen && movOrigen.movimiento.includes('SALDO EXCEDENTE');
            document.querySelector('#modalReimputarExcedente h2').innerText = esExc ? 'Re-imputar Saldo Excedente' : 'Imputar Pago General';

            // Cargar facturas pendientes en el modal de re-imputación
            contenedorImputacionReimputar.innerHTML = '';
            let hayFacturas = false;
            movimientosActuales.forEach(mov => {
                if (mov.compra_id && mov.saldo_pendiente_factura > 0) {
                    hayFacturas = true;
                    const div = document.createElement('div');
                    div.style.marginBottom = '8px';
                    div.innerHTML = `
                        <label style="display: flex; align-items: center; gap: 8px; color: #eee; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" class="chk-imputar-reimputar" value="${mov.compra_id}" data-doc="${mov.n_documento}" data-saldo="${mov.saldo_pendiente_factura}" style="width: 18px; height: 18px; margin: 0;">
                            <span>${mov.movimiento} #${mov.n_documento} (Saldo: $${parseFloat(mov.saldo_pendiente_factura).toLocaleString('es-AR', {minimumFractionDigits:2})})</span>
                        </label>`;
                    contenedorImputacionReimputar.appendChild(div);
                }
            });
            if (!hayFacturas) contenedorImputacionReimputar.innerHTML = '<p style="color: #666; font-size: 0.85rem; margin: 0;">No hay facturas pendientes para imputar.</p>';

            modalReimputar.style.display = 'flex'; // Cambiado de block a flex para el centrado
        };

        window.cerrarModalReimputarExcedente = function() {
            modalReimputar.style.display = 'none';
            formReimputarExcedente.reset();
        };

        formReimputarExcedente.addEventListener('submit', function(e) {
            e.preventDefault();
            const btnSubmit = formReimputarExcedente.querySelector('button[type="submit"]');
            
            const ctacteIdExcedente = reimputarCtacteId.value;
            const idProveedor = reimputarProveedorId.value;
            const montoExcedente = parseFloat(reimputarMontoDisplay.getAttribute('data-valor')); // Leemos el valor puro
            const imputarDocs = Array.from(document.querySelectorAll('.chk-imputar-reimputar:checked')).map(cb => cb.value);

            if (imputarDocs.length === 0) {
                mostrarMensaje('Advertencia', 'Debe seleccionar al menos una factura para re-imputar el excedente.', 'warning');
                return;
            }

            confirmarAccion(
                'Confirmar Re-imputación',
                `¿Está seguro de re-imputar $${montoExcedente.toLocaleString('es-AR', {minimumFractionDigits:2})} a las facturas seleccionadas?`,
                'SÍ, RE-IMPUTAR',
                'btn-primary',
                () => {
                    btnSubmit.disabled = true;
                    btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

                    fetch('../ajax/reimputar_excedente_proveedor_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_ctacte_excedente: ctacteIdExcedente,
                            monto_excedente: montoExcedente,
                            id_proveedor: idProveedor,
                            imputar_docs: imputarDocs
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            mostrarMensaje('Éxito', data.mensaje, 'success', () => {
                                cerrarModalReimputarExcedente();
                                cargarHistorial(idProveedor); // Recargar el historial
                            });
                        } else {
                            mostrarMensaje('Error', data.mensaje, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error en re-imputación:', error);
                        mostrarMensaje('Error de Conexión', 'No se pudo conectar con el servidor.', 'error');
                    })
                    .finally(() => {
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = '<i class="fas fa-exchange-alt"></i> Confirmar Re-imputación';
                    });
                }
            );
        });
    });

    // Global function to show observations modal
    window.mostrarModalObservaciones = function(encodedObs) {
        const obs = decodeURIComponent(encodedObs);
        document.getElementById('observacionesTexto').innerText = obs;
        document.getElementById('modalObservaciones').style.display = 'block';
    };

    // Close observations modal
    document.querySelector('#modalObservaciones .close-button').addEventListener('click', () => {
        document.getElementById('modalObservaciones').style.display = 'none';
    });
    // Also close if clicking outside the modal content
    window.addEventListener('click', (e) => { if (e.target === document.getElementById('modalObservaciones')) { document.getElementById('modalObservaciones').style.display = 'none'; } });
</script>
</html>