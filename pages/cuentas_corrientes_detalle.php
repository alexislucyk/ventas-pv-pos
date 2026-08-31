<?php
// pages/cuentas_corrientes_detalle.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 
require '../funciones/funciones_intereses.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Validar que se recibió el ID del cliente
if (!isset($_GET['id_cliente']) || !is_numeric($_GET['id_cliente'])) {
    die('❌ Error: ID de cliente no válido.');
}

$id_cliente = (int)$_GET['id_cliente'];

    // Obtener datos del cliente
    try {
        $sql_cliente = "SELECT id, nombre, apellido, cuit, telefono FROM clientes WHERE id = :id AND empresa_id = :empresa_id";
        
        $stmt_cliente = $pdo->prepare($sql_cliente);
        $stmt_cliente->execute([':id' => $id_cliente, ':empresa_id' => $empresa_id]);
        $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        
        if (!$cliente) {
            die('❌ Error: Cliente no encontrado.');
        }
        
        $nombre_completo = $cliente['apellido'] . ', ' . $cliente['nombre'];
        
        // Obtener movimientos del cliente
        $sql_movimientos = "
            SELECT
                id,
                movimiento,
                n_documento,
                debe,
                haber,
                fecha
            FROM ctacte
            WHERE id_cliente = :id_cliente AND empresa_id = :empresa_id
            ORDER BY fecha ASC, id ASC
        ";
        
        $stmt_mov = $pdo->prepare($sql_movimientos);
        $stmt_mov->execute([':id_cliente' => $id_cliente, ':empresa_id' => $empresa_id]);
        $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular saldo actual
        $saldo_actual = 0;
        foreach ($movimientos as $mov) {
            $saldo_actual += (float)$mov['debe'] - (float)$mov['haber'];
        }
        
        // Calcular intereses pendientes
        try {
            $intereses = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);
        } catch (Exception $e2) {
            error_log("Error al calcular intereses: " . $e2->getMessage());
            // Si hay error en intereses, continuar sin ellos
            $intereses = [
                'interes_total' => 0,
                'detalle' => [],
                'config' => []
            ];
        }
    
} catch (Exception $e) {
    error_log("ERROR en detalle CC: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    die('❌ Error al cargar los datos: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle Cuenta Corriente | <?php echo htmlspecialchars($nombre_completo); ?> | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>"> 
    <link rel="stylesheet" href="<?php echo url('css/pages/cuentas_corrientes_detalle.css'); ?>">

</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>📊 Detalle Cuenta Corriente</h1>
            <div style="display: flex; gap: 10px;">
                <a href="pagos_ctacte.php?id_cliente=<?php echo $id_cliente; ?>" class="btn-primary" style="padding: 8px 18px; text-decoration: none; border-radius: 5px;">
                    ➕ Registrar Pago / Cobro
                </a>
                <a href="cuentas_corrientes.php" class="btn btn-secondary" style="padding: 8px 18px; text-decoration: none; border-radius: 5px;">
                    ← Volver
                </a>
            </div>
        </div>

        <!-- Información del Cliente -->
        <div class="cliente-info">
            <h2><i class="fas fa-user"></i> <?php echo htmlspecialchars($nombre_completo); ?></h2>
            <div class="cliente-info-grid">
                <div class="cliente-info-item">
                    <label>CUIT / Documento</label>
                    <value><?php echo htmlspecialchars($cliente['cuit'] ?? 'N/A'); ?></value>
                </div>
                <div class="cliente-info-item">
                    <label>Teléfono</label>
                    <value><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></value>
                </div>
                <div class="cliente-info-item">
                    <label>ID Cliente</label>
                    <value>#<?php echo $cliente['id']; ?></value>
                </div>
            </div>
        </div>

        <!-- Estadísticas del Cliente -->
        <div class="cliente-stats">
            <div class="stat-card" style="border-left-color: <?php echo $saldo_actual > 0 ? '#e74c3c' : '#2ecc71'; ?>;">
                <h3><i class="fas fa-balance-scale"></i> Saldo Actual</h3>
                <div class="value <?php echo $saldo_actual > 0 ? 'saldo-deudor' : 'saldo-favor'; ?>">
                    $ <?php echo number_format(abs($saldo_actual), 2, ',', '.'); ?>
                    <small style="font-size: 0.8rem; color: #888;">
                        (<?php echo $saldo_actual > 0 ? 'Deudor' : 'A Favor'; ?>)
                    </small>
                </div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-exchange-alt"></i> Total Movimientos</h3>
                <div class="value"><?php echo count($movimientos); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-arrow-up"></i> Total Debe</h3>
                <div class="value">
                    $ <?php echo number_format(array_sum(array_column($movimientos, 'debe')), 2, ',', '.'); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-arrow-down"></i> Total Haber</h3>
                <div class="value">
                    $ <?php echo number_format(array_sum(array_column($movimientos, 'haber')), 2, ',', '.'); ?>
                </div>
            </div>
        </div>

        <!-- Sección de Intereses por Mora -->
        <?php if ($intereses['interes_total'] > 0): ?>
        <div class="card" style="background: #2c2c2c; border-left: 4px solid #f39c12; margin-bottom: 20px;">
            <h3 style="color: #f39c12; margin-bottom: 15px;">
                <i class="fas fa-percentage"></i> Intereses por Mora Pendientes
            </h3>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <p style="margin: 5px 0; color: #fff;">
                        <strong>Total Intereses:</strong> 
                        <span style="color: #f39c12; font-size: 1.2rem; font-weight: bold;">
                            $ <?php echo number_format($intereses['interes_total'], 2, ',', '.'); ?>
                        </span>
                    </p>
                    <p style="margin: 5px 0; color: #bbb; font-size: 0.85rem;">
                        Tasa aplicada: <?php echo $intereses['config']['tasa_mensual']; ?>% mensual
                        <?php if ($intereses['config']['dias_gracia'] > 0): ?>
                            | Días de gracia: <?php echo $intereses['config']['dias_gracia']; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <button onclick="aplicarIntereses(<?php echo $id_cliente; ?>)"
                        class="btn-primary"
                        style="background: #f39c12; color: white; padding: 10px 20px; 
                               border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-check"></i> Aplicar Intereses
                </button>
            </div>
            
            <!-- Detalle de cálculo -->
            <details style="margin-top: 15px;">
                <summary style="color: #00bcd4; cursor: pointer; font-weight: bold;">Ver detalle de cálculo</summary>
                <table style="margin-top: 10px; font-size: 0.8rem;">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Fecha Venc.</th>
                            <th>Saldo</th>
                            <th>Días Mora</th>
                            <th>Interés</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($intereses['detalle'] as $det): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($det['movimiento']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($det['fecha_vencimiento'])); ?></td>
                            <td>$ <?php echo number_format($det['saldo_pendiente'], 2, ',', '.'); ?></td>
                            <td><?php echo $det['dias_mora']; ?> días</td>
                            <td style="color: #f39c12; font-weight: bold;">$ <?php echo number_format($det['interes_calculado'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
        <?php endif; ?>
        <!-- Fin Sección de Intereses -->

        <!-- Historial de Movimientos -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <h3 style="color: #00bcd4; margin: 0;">
                    <i class="fas fa-history"></i> Historial Completo de Movimientos
                </h3>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="contadorSeleccion" style="color: #bbb; font-size: 0.85rem;">0 seleccionados</span>
                    <button type="button" class="btn-action btn-pdf-v" onclick="generarPDFSeleccion()" title="Generar PDF con los movimientos seleccionados">
                        <i class="fas fa-file-pdf"></i> Generar PDF
                    </button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;">
                            <input type="checkbox" id="seleccionarTodos" title="Seleccionar todos" onclick="toggleTodos(this)">
                        </th>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>N° Doc.</th>
                        <th style="text-align: right;">Debe</th>
                        <th style="text-align: right;">Haber</th>
                        <th style="text-align: right;">Saldo Acu.</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="cuerpoHistorial">
                    <?php
                    if (empty($movimientos)) {
                        echo "<tr><td colspan='8' style='text-align: center;'>No hay movimientos registrados para este cliente.</td></tr>";
                    } else {
                        $saldo_acumulado = 0;
                        foreach ($movimientos as $mov) {
                            $id_mov = $mov['id'];
                            $debe_val = (float)$mov['debe']; 
                            $haber_val = (float)$mov['haber'];
                            $saldo_acumulado += $debe_val - $haber_val;
                            
                            // Lógica visual de saldos
                            $clase_saldo = 'saldo-cero';
                            $monto_final_a_mostrar = $saldo_acumulado; 

                            if ($saldo_acumulado > 0) {
                                $clase_saldo = 'saldo-negativo'; 
                                $monto_final_a_mostrar = -$saldo_acumulado; 
                            } elseif ($saldo_acumulado < 0) {
                                $clase_saldo = 'saldo-positivo'; 
                                $monto_final_a_mostrar = abs($saldo_acumulado); 
                            }

                            // --- DETERMINAR TIPO DE MOVIMIENTO ---
                            $texto_movimiento = htmlspecialchars($mov['movimiento']);
                            $mov_upper = strtoupper($mov['movimiento']);
                            $is_sale = (strpos($mov_upper, 'FACTURA') !== false);
                            $is_dev = (strpos($mov_upper, 'ANULACIÓN') !== false || strpos($mov_upper, 'DEVOLUCIÓN') !== false);
                            $is_payment = ($haber_val > 0 && !$is_dev);

                            $columna_acciones = "";

                            if ($is_sale) {
                                $n_doc_val = $mov['n_documento'];
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$n_doc_val\", \"VENTA\", \"#$n_doc_val\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirTicket(\"$n_doc_val\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='descargarPDFVenta(\"$n_doc_val\")' title='PDF A5' style='color:#00bcd4;'><i class='fas fa-file-pdf'></i></button>";
                            } elseif ($is_dev) {
                                $op_n = 0;
                                if (preg_match('/OP#(\d+)/', $mov['movimiento'], $matches)) { $op_n = $matches[1]; }
                                if ($op_n > 0) {
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$op_n\", \"DEVOLUCION\", \"OP#$op_n\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$op_n\", true)' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$op_n\", true)' title='PDF A5' style='color:#e67e22;'><i class='fas fa-file-pdf'></i></button>";
                                }
                            } elseif ($is_payment) {
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$id_mov\", \"PAGO\", \"Recibo #$id_mov\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$id_mov\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$id_mov\")' title='PDF A5' style='color:#e74c3c;'><i class='fas fa-file-pdf'></i></button>";
                            }

                            // Si n_documento es 0 o está vacío, mostramos el ID interno como referencia sin el símbolo #
                            $n_doc_display = ($mov['n_documento'] && $mov['n_documento'] != "0") ? htmlspecialchars($mov['n_documento']) : $id_mov;
                            
                            echo "
                                <tr class='mov-row'>
                                    <td class='text-center'>
                                        <input type='checkbox' class='check-mov' value='$id_mov' onchange='actualizarContador()'>
                                    </td>
                                    <td>" . date('d/m/Y', strtotime($mov['fecha'])) . "</td>
                                    <td>" . $texto_movimiento . "</td>
                                    <td>" . $n_doc_display . "</td>
                                    <td class='text-right'>$" . number_format($debe_val, 2, ',', '.') . "</td> 
                                    <td class='text-right'>$" . number_format($haber_val, 2, ',', '.') . "</td> 
                                    <td class='text-right " . $clase_saldo . "'>$" . number_format($monto_final_a_mostrar, 2, ',', '.') . "</td>
                                    <td class='text-center'>" . $columna_acciones . "</td>
                                </tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de Detalle de Operación (Ventas/Pagos) -->
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

    <!-- Modal Confirmación WhatsApp -->
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
            }, 5000);
        }

        // --- LÓGICA DE SELECCIÓN DE MOVIMIENTOS Y GENERACIÓN DE PDF ---

        // Marcar/desmarcar todos los movimientos de la tabla
        window.toggleTodos = function(checkbox) {
            document.querySelectorAll('.check-mov').forEach(cb => cb.checked = checkbox.checked);
            actualizarContador();
        };

        // Actualiza el contador de movimientos seleccionados
        function actualizarContador() {
            const seleccionados = document.querySelectorAll('.check-mov:checked');
            const totalMov = document.querySelectorAll('.check-mov').length;
            const contador = document.getElementById('contadorSeleccion');
            const todos = document.getElementById('seleccionarTodos');

            if (contador) {
                contador.textContent = seleccionados.length + ' seleccionado' + (seleccionados.length === 1 ? '' : 's');
            }
            if (todos) {
                todos.checked = totalMov > 0 && seleccionados.length === totalMov;
                todos.indeterminate = seleccionados.length > 0 && seleccionados.length < totalMov;
            }
        }

        // Generar el PDF con los movimientos seleccionados
        window.generarPDFSeleccion = function() {
            const ids = Array.from(document.querySelectorAll('.check-mov:checked')).map(cb => cb.value);
            if (ids.length === 0) {
                mostrarToast('Seleccione al menos un movimiento para generar el PDF.', 'error');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'generar_pdf_cc_seleccion.php';
            form.target = '_blank';
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            form.remove();
        }

        // --- LÓGICA DE DETALLE Y PAGO ---

        window.abrirDetalleOperacion = function(id, tipo, etiqueta) {
            const modal = document.getElementById('modalFactura');
            const cuerpo = document.getElementById('cuerpoDetalleFactura');
            const labelDoc = document.getElementById('detalleNdocumento');
            modalActualTipo = tipo;
            modalActualId = id;
            modal.style.display = 'flex';
            if(labelDoc) labelDoc.textContent = etiqueta;
            cuerpo.innerHTML = '<p style="text-align:center; padding:20px;">Cargando...</p>';

            let url = '';
            if (tipo === 'VENTA') url = '<?php echo URL_BASE; ?>ajax/obtener_detalle_venta.php?n_documento=' + id;
            else if (tipo === 'DEVOLUCION') url = '<?php echo URL_BASE; ?>ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE';
            else if (tipo === 'PAGO') url = '<?php echo URL_BASE; ?>ajax/obtener_detalle_pago.php?id=' + id;

            fetch(url).then(res => res.text()).then(html => { cuerpo.innerHTML = html; });
        }

        window.imprimirTicket = function(nDocumento) {
            const doc = String(nDocumento).replace(/[^\d]/g, '');
            if (!doc) { mostrarMensaje("Atención", "Número de documento inválido.", "error"); return; }
            window.open('vista_previa_ticket.php?n_documento=' + doc, '_blank', 'width=400,height=700');
        }

        window.descargarPDFVenta = function(nDocumento) {
            const doc = String(nDocumento).replace(/[^\d]/g, '');
            if (!doc) { mostrarMensaje("Atención", "Número de documento inválido.", "error"); return; }
            window.location.href = 'generar_pdf_ticket.php?n_documento=' + doc + '&download=1';
        }

        window.imprimirRecibo = function(idMovimiento, esDevolucion = false) {
            const id = String(idMovimiento).replace(/[^\d]/g, '');
            if (!id) { mostrarMensaje("Atención", "ID de movimiento inválido.", "error"); return; }
            const url = esDevolucion 
                ? 'vista_previa_ticket_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE'
                : 'vista_recibo.php?id_mov=' + id + '&formato=ticket';
            window.open(url, '_blank', 'width=400,height=700');
        }

        window.imprimirReciboPDF = function(idMovimiento, esDevolucion = false) {
            const id = String(idMovimiento).replace(/[^\d]/g, '');
            if (!id) { mostrarMensaje("Atención", "ID de movimiento inválido.", "error"); return; }
            const url = esDevolucion 
                ? 'generar_pdf_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE&download=1'
                : 'generar_pdf_recibo.php?id_mov=' + id + '&download=1';
            window.location.href = url;
        }

        function accionImprimirModal(formato) {
            const cleanId = String(modalActualId).replace(/[^\d]/g, '');
            
            if (!cleanId) {
                mostrarMensaje("Error", "No se pudo obtener un ID de documento válido para imprimir.", "error");
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

        async function ejecutarEnvioWhatsApp() {
            const btn = document.getElementById('btnConfirmarWA');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ENVIANDO...';
            
            const tel = document.getElementById('wa_destino_tel').value;
            const msg = document.getElementById('wa_destino_msg').value;

            try {
                const response = await fetch('<?php echo URL_BASE; ?>ajax/enviar_whatsapp_nodered.php', {
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

        function cerrarModalFactura() { document.getElementById('modalFactura').style.display = 'none'; }
        function cerrarModalWhatsApp() { document.getElementById('modalWhatsApp').style.display = 'none'; }

        // Cerrar modales con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('modalFactura').style.display = 'none';
                document.getElementById('modalWhatsApp').style.display = 'none';
            }
        });

        // Cerrar modal al hacer clic fuera
        window.onclick = function(e) {
            const modales = [document.getElementById('modalFactura'), document.getElementById('modalWhatsApp')];
            modales.forEach(m => { if (m && e.target == m) m.style.display = 'none'; });
        }

        // --- LÓGICA DE INTERESES POR MORA ---

        window.aplicarIntereses = function(idCliente) {
            const btn = (window.event && window.event.target) ? window.event.target.closest('button') : null;

            const aplicarAhora = function() {
                if (!btn) { return; }
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Aplicando...';
            
            const formData = new FormData();
            formData.append('id_cliente', idCliente);
            
            fetch('<?php echo URL_BASE; ?>ajax/aplicar_interes_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    mostrarToast(data.mensaje, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    mostrarToast('Error: ' + data.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Aplicar Intereses';
                }
            })
            .catch(err => {
                mostrarToast('Error de conexión', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Aplicar Intereses';
            });
            };

            confirmarAccion('Aplicar Intereses', '¿Aplicar intereses por mora a este cliente?\n\nSe calcularán los intereses según la configuración y se agregarán como un nuevo movimiento en la cuenta corriente.', 'APLICAR', 'btn-primary', aplicarAhora);
        }
    </script>
</body>
</html>