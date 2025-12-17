<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php'); 
    exit();
}

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
        .saldo-display {
            font-size: 1.5em;
            margin: 20px 0;
            padding: 15px;
            background-color: #3a3a3a;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .saldo-debe { color: #f44336; } /* Rojo para saldo acreedor (deuda para nosotros) */
        .saldo-cero { color: #aaa; }
    </style>
</head>
<body>

    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 
    
    <div class="content">
        <h1>Consulta y Pagos a Proveedores</h1>

        <div class="card">
            <h2>Seleccionar Proveedor</h2>

            <div style="display: flex; gap: 15px; align-items: center;">
                <select id="select_proveedor" class="input-field" style="flex-grow: 1;">
                    <option value="0">-- Seleccione un Proveedor --</option>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?php echo $p['id_proveedor']; ?>" data-cuit="<?php echo $p['cuit']; ?>">
                            <?php echo htmlspecialchars($p['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button id="btn_pagar_cc" class="btn btn-primary" disabled>Registrar Pago</button>
            </div>
            
        </div>

        <div class="card">
            <h2>Estado de Cuenta</h2>

            <div class="saldo-display">
                <span>Saldo Actual:</span>
                <strong id="saldo_actual_display" class="saldo-cero">$0.00</strong>
            </div>

            <h3>Historial de Movimientos</h3>
            <table id="historial_movimientos" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 15%;">Fecha</th>
                        <th>Movimiento</th>
                        <th style="width: 15%;">N° Doc.</th>
                        <th class="text-right" style="width: 15%;">Haber ($)</th>
                        <th class="text-right" style="width: 15%;">Debe ($)</th>
                    </tr>
                </thead>
                <tbody id="historial_tbody">
                    <tr>
                        <td colspan="5" style="text-align: center;">Esperando selección de proveedor...</td>
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

</body>
<script src="../js/global.js"></script> 
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectProveedor = document.getElementById('select_proveedor');
        const historialTbody = document.getElementById('historial_tbody');
        const saldoDisplay = document.getElementById('saldo_actual_display');
        const btnPagar = document.getElementById('btn_pagar_cc');
        
        // Modal elementos
        const modal = document.getElementById('modalPago');
        const closeModal = modal.querySelector('.close-button');
        const formPago = document.getElementById('formPago');
        const modalProveedorId = document.getElementById('modal_proveedor_id');
        const modalProveedorNombre = document.getElementById('modal_proveedor_nombre');
        const modalSaldoPendiente = document.getElementById('modal_saldo_pendiente');
        const inputMontoPago = document.getElementById('monto_pago');
        
        let saldoActual = 0.00; // Variable global para el saldo del proveedor seleccionado

        // Función para cargar el historial y calcular el saldo
        function cargarHistorial(idProveedor) {
            if (idProveedor === '0') {
                historialTbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Seleccione un proveedor...</td></tr>';
                saldoDisplay.textContent = '$0.00';
                saldoDisplay.className = 'saldo-cero';
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

                        if (data.movimientos && data.movimientos.length > 0) {
                            data.movimientos.forEach(mov => {
                                // HABER: Aumenta la deuda (Factura de Compra)
                                // DEBE: Disminuye la deuda (Pago, Nota de Crédito)
                                saldoActual += (parseFloat(mov.haber) || 0) - (parseFloat(mov.debe) || 0);

                                const row = historialTbody.insertRow();
                                row.innerHTML = `
                                    <td>${mov.fecha.split(' ')[0]}</td>
                                    <td>${mov.movimiento}</td>
                                    <td>${mov.n_documento}</td>
                                    <td class="text-right">$${parseFloat(mov.haber || 0).toFixed(2)}</td>
                                    <td class="text-right">$${parseFloat(mov.debe || 0).toFixed(2)}</td>
                                `;
                            });
                        } else {
                            historialTbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No hay movimientos registrados.</td></tr>';
                        }
                        
                        // Actualizar el display de saldo
                        saldoDisplay.textContent = '$' + saldoActual.toFixed(2);
                        if (saldoActual > 0) {
                            saldoDisplay.classList.replace('saldo-cero', 'saldo-debe'); // La empresa DEBE al proveedor
                            btnPagar.disabled = false;
                        } else {
                            saldoDisplay.className = 'saldo-cero';
                            btnPagar.disabled = true;
                        }

                    } catch (e) {
                        historialTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">Error al procesar los datos del servidor.</td></tr>';
                        console.error("Error al parsear JSON:", e);
                    }
                } else {
                    historialTbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Error al cargar datos del proveedor.</td></tr>';
                }
            };
            xhr.send();
        }

        // Evento de cambio en el selector de proveedor
        selectProveedor.addEventListener('change', function() {
            cargarHistorial(this.value);
        });

        // ===========================================
        // Lógica del Modal de Pago
        // ===========================================
        
        // Abrir Modal
        btnPagar.addEventListener('click', function() {
            const selectedOption = selectProveedor.options[selectProveedor.selectedIndex];
            const proveedorNombre = selectedOption.textContent.trim();
            const proveedorId = selectProveedor.value;
            
            if (proveedorId === '0' || saldoActual <= 0) return;

            // Llenar datos del modal
            modalProveedorId.value = proveedorId;
            modalProveedorNombre.textContent = selectedOption.textContent.trim();
            modalSaldoPendiente.textContent = '$' + saldoActual.toFixed(2);
            inputMontoPago.max = saldoActual.toFixed(2); // Máximo a pagar
            inputMontoPago.value = saldoActual.toFixed(2); // Sugerir el total
            
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
            
            const montoPago = parseFloat(inputMontoPago.value);
            const proveedorId = modalProveedorId.value;
            const tipoPago = document.getElementById('tipo_pago').value;
            const refPago = document.getElementById('ref_pago').value.trim();

            if (montoPago > saldoActual || montoPago <= 0) {
                alert("El monto de pago no es válido o excede el saldo pendiente.");
                return;
            }

            // Llamada AJAX para registrar el pago (Necesitamos crear 'registrar_pago_proveedor_ajax.php')
            const formData = new FormData();
            formData.append('id_proveedor', proveedorId);
            formData.append('monto_pago', montoPago);
            formData.append('tipo_pago', tipoPago);
            formData.append('ref_pago', refPago);

            const xhrPago = new XMLHttpRequest();
            xhrPago.open('POST', 'registrar_pago_proveedor_ajax.php', true);
            xhrPago.onload = function() {
                if (this.status === 200) {
                    const response = JSON.parse(this.responseText);
                    alert(response.mensaje);
                    
                    if (response.exito) {
                        modal.style.display = 'none';
                        cargarHistorial(proveedorId); // Recargar el historial para ver el nuevo pago
                    }
                } else {
                    alert('Error de conexión con el servidor al registrar el pago.');
                }
            };
            xhrPago.send(formData);
        });

        // Cargar historial al inicio (si hay un proveedor seleccionado, aunque por defecto será 0)
        cargarHistorial(selectProveedor.value);
    });
</script>
</html>