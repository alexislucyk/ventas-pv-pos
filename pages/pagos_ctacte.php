<?php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = '<p style="color: green; font-weight: bold;">✅ ' . htmlspecialchars($_GET['success']) . '</p>';
} elseif (isset($_GET['error'])) {
    $mensaje = '<p style="color: red; font-weight: bold;">❌ Error: ' . htmlspecialchars($_GET['error']) . '</p>';
}

$clientes_cc = [];
try {
    $sql_clientes = "SELECT id, CONCAT(apellido, ', ', nombre) as nombre_completo, cuit
                     FROM clientes
                     WHERE habilita_cta = 'Si' AND empresa_id = ?
                     ORDER BY nombre_completo ASC";
    $stmt_clientes = $pdo->prepare($sql_clientes);
    $stmt_clientes->execute([$empresa_id]);
    $clientes_cc = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando clientes en pagos_ctacte: " . $e->getMessage());
}

$cliente_inicial = null;
$id_cliente_inicial = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
if ($id_cliente_inicial) {
    foreach ($clientes_cc as $cliente) {
        if ((int)$cliente['id'] === $id_cliente_inicial) {
            $cliente_inicial = $cliente;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pagos Cta. Cte. | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/cuentas_corrientes.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1><i class="fas fa-hand-holding-usd"></i> Registrar Pago a Cuenta Corriente</h1>
        
        <?php echo $mensaje; // Mostrar mensaje de éxito/error ?>

        <div class="card form-pagos">
            <form id="formRegistroPagoCC" action="<?php echo url('procesos/registrar_pago_cc.php'); ?>" method="POST">
                <label>Buscar Cliente</label>
                <div style="position: relative; margin-bottom: 20px;">
                    <input type="text" id="buscar_cliente_pago" class="input-field" placeholder="Escriba nombre o CUIT..." autocomplete="off">
                    <div id="resultadosBusquedaCC"></div>
                    <input type="hidden" name="id_cliente" id="id_cliente_hidden" required>
                </div>

                <div id="box_cliente" class="client-info-box">
                    <p style="margin:0; color:#888; font-size: 0.8rem;">Registrando pago para:</p>
                    <h3 id="display_nombre_cliente" style="margin:5px 0; color:#00bcd4;"></h3>
                    <p id="display_cuit_cliente" style="margin:0; font-size: 0.85rem; color:#aaa;"></p>
                </div>

                <label>Monto a Abonar ($)</label>
                <input type="number" id="monto_pago" name="monto_pago" step="0.01" min="0.01" class="input-field input-monto" placeholder="0.00" required>

                <label>Destino del Pago</label>
                <select name="modo_imputacion" id="modo_imputacion" class="input-field" required>
                    <option value="facturas">Aplicar a facturas (el excedente queda a favor)</option>
                    <option value="a_cuenta">Pago a cuenta (todo queda como saldo a favor)</option>
                </select>

                <section id="panel_imputacion" class="panel-imputacion" style="display: none;">
                    <div class="panel-imputacion-header">
                        <div>
                            <label>Facturas a Imputar</label>
                            <p>Seleccione las facturas y ajuste los importes. Si el pago supera la deuda, el excedente queda como saldo a favor.</p>
                        </div>
                        <button type="button" id="btn_imputar_antiguedad" class="btn btn-secondary">
                            <i class="fas fa-layer-group"></i> Imputar por antigüedad
                        </button>
                    </div>
                    <div id="facturas_imputacion" class="facturas-imputacion">
                        <p class="sin-facturas">Seleccione un cliente para cargar sus facturas pendientes.</p>
                    </div>
                    <div class="resumen-imputacion">
                        <span>Deuda imputable: <strong id="saldo_cliente_pendiente">$ 0,00</strong></span>
                        <span>Saldo a favor actual: <strong id="saldo_a_favor_actual_cliente">$ 0,00</strong></span>
                        <span>Imputado: <strong id="total_imputado" class="texto-imputado">$ 0,00</strong></span>
                        <span>A favor de este pago: <strong id="saldo_a_favor_pago" class="texto-pendiente">$ 0,00</strong></span>
                    </div>
                </section>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                    <div>
                        <label>N° Recibo / Referencia</label>
                        <input type="text" name="n_recibo" class="input-field" placeholder="Ej: Recibo 001">
                    </div>
                    <div>
                        <label>Método de Pago</label>
                        <select name="condicion_pago" id="condicion_pago" class="input-field" required onchange="toggleChequeFields('pago_directo')">
                            <option value="Efectivo">Efectivo</option>
                            <option value="Transferencia">Transferencia</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Tarjeta">Tarjeta</option>
                        </select>
                    </div>
                </div>

                <!-- Campos extra para Cheque -->
                <div id="panel_cheque_pago_directo" style="display: none; background: #252525; padding: 15px; border-radius: 8px; border: 1px dashed #f1c40f; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="font-size: 0.75rem;">N° Cheque</label>
                            <input type="text" name="chq_nro" class="input-field" placeholder="00000000">
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">F. Emisión</label>
                            <input type="date" name="chq_emision" class="input-field" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">F. Vencimiento</label>
                            <input type="date" name="chq_vto" class="input-field">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="height: 50px; font-weight: bold; font-size: 1.1rem; margin-top: 15px;">
                    <i class="fas fa-check-circle"></i> CONFIRMAR REGISTRO DE PAGO
                </button>
                <a href="cuentas_corrientes.php" class="btn btn-secondary btn-block" style="text-align: center; margin-top: 10px; display: block; text-decoration: none;">Volver al Listado</a>
            </form>
        </div>
    </div>

<script>
    const clientesData = <?php echo json_encode($clientes_cc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const clienteInicial = <?php echo json_encode($cliente_inicial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const inputBusq = document.getElementById('buscar_cliente_pago');
    const resDiv = document.getElementById('resultadosBusquedaCC');
    const idHidden = document.getElementById('id_cliente_hidden');
    const inputMonto = document.getElementById('monto_pago');
    const modoImputacion = document.getElementById('modo_imputacion');
    const panelImputacion = document.getElementById('panel_imputacion');
    const contenedorFacturas = document.getElementById('facturas_imputacion');
    const btnImputarAntiguedad = document.getElementById('btn_imputar_antiguedad');
    let facturasPendientes = [];
    let saldoAFavorActual = 0;

    inputBusq.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        resDiv.innerHTML = '';
        if (q.length < 2) { resDiv.style.display = 'none'; return; }

        const filtrados = clientesData.filter(c =>
            c.nombre_completo.toLowerCase().includes(q) || (c.cuit && c.cuit.toString().toLowerCase().includes(q))
        );

        if (filtrados.length > 0) {
            resDiv.style.display = 'block';
            filtrados.forEach(c => {
                const div = document.createElement('div');
                div.className = 'resultado-cliente-item';
                const nombre = document.createElement('strong');
                nombre.textContent = c.nombre_completo;
                const cuit = document.createElement('small');
                cuit.textContent = `(${c.cuit || 'S/D'})`;
                div.append(nombre, cuit);
                div.onclick = () => seleccionarCliente(c);
                resDiv.appendChild(div);
            });
        } else {
            resDiv.style.display = 'none';
        }
    });

    function formatMonto(valor) {
        return '$ ' + Number(valor || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatFecha(fecha) {
        if (!fecha) return '-';
        const partes = String(fecha).split(' ')[0].split('-');
        return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : fecha;
    }

    function seleccionarCliente(cliente) {
        inputBusq.value = cliente.nombre_completo;
        idHidden.value = cliente.id;
        resDiv.style.display = 'none';
        document.getElementById('box_cliente').style.display = 'block';
        document.getElementById('display_nombre_cliente').innerText = cliente.nombre_completo;
        document.getElementById('display_cuit_cliente').innerText = 'CUIT/DNI: ' + (cliente.cuit || 'S/D');
        cargarFacturasPendientes(cliente.id);
    }

    function actualizarResumenImputacion() {
        const monto = Math.round((parseFloat(inputMonto.value) || 0) * 100) / 100;
        let total = 0;
        if (modoImputacion.value === 'facturas') {
            document.querySelectorAll('.chk-factura-pago:checked').forEach(checkbox => {
                const inputImporte = checkbox.closest('tr').querySelector('.input-imputacion');
                total = Math.round((total + (parseFloat(inputImporte.value) || 0)) * 100) / 100;
            });
        }
        const saldoCliente = facturasPendientes.reduce((total, factura) => total + factura.saldo_disponible, 0);
        document.getElementById('saldo_cliente_pendiente').textContent = formatMonto(saldoCliente);
        document.getElementById('saldo_a_favor_actual_cliente').textContent = formatMonto(saldoAFavorActual);
        document.getElementById('total_imputado').textContent = formatMonto(total);
        document.getElementById('saldo_a_favor_pago').textContent = formatMonto(Math.max(0, monto - total));
    }


    function renderizarFacturasPendientes(facturas) {
        contenedorFacturas.innerHTML = '';
        facturasPendientes = facturas;

        if (!facturas.length) {
            const mensaje = document.createElement('p');
            mensaje.className = 'sin-facturas';
            mensaje.textContent = 'El cliente no tiene facturas pendientes disponibles para imputar.';
            contenedorFacturas.appendChild(mensaje);
            btnImputarAntiguedad.disabled = true;
            actualizarResumenImputacion();
            return;
        }

        btnImputarAntiguedad.disabled = false;
        const tabla = document.createElement('table');
        tabla.className = 'tabla-imputacion';
        tabla.innerHTML = `
            <thead><tr>
                <th></th><th>Documento</th><th>Fecha</th><th>Vencimiento</th>
                <th class="text-right">Saldo</th><th class="text-right">Importe a imputar</th>
            </tr></thead><tbody></tbody>`;
        const tbody = tabla.querySelector('tbody');

        facturas.forEach(factura => {
            const fila = document.createElement('tr');
            fila.dataset.facturaId = factura.id;

            const celdaSeleccion = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'chk-factura-pago';
            checkbox.setAttribute('aria-label', `Seleccionar factura ${factura.n_documento || factura.id}`);
            celdaSeleccion.appendChild(checkbox);

            const celdaDocumento = document.createElement('td');
            celdaDocumento.textContent = `${factura.movimiento || 'Factura'} ${factura.n_documento || '#' + factura.id}`;
            const celdaFecha = document.createElement('td');
            celdaFecha.textContent = formatFecha(factura.fecha);
            const celdaVencimiento = document.createElement('td');
            celdaVencimiento.textContent = formatFecha(factura.fecha_vencimiento);
            const celdaSaldo = document.createElement('td');
            celdaSaldo.className = 'text-right saldo-factura';
            celdaSaldo.textContent = formatMonto(factura.saldo_disponible);

            const celdaImporte = document.createElement('td');
            const inputImporte = document.createElement('input');
            inputImporte.type = 'number';
            inputImporte.step = '0.01';
            inputImporte.min = '0.01';
            inputImporte.max = String(factura.saldo_disponible);
            inputImporte.className = 'input-field input-imputacion';
            inputImporte.disabled = true;
            inputImporte.dataset.saldo = String(factura.saldo_disponible);
            inputImporte.setAttribute('aria-label', `Importe a imputar a ${factura.n_documento || factura.id}`);
            celdaImporte.appendChild(inputImporte);

            fila.append(celdaSeleccion, celdaDocumento, celdaFecha, celdaVencimiento, celdaSaldo, celdaImporte);
            tbody.appendChild(fila);
        });

        contenedorFacturas.appendChild(tabla);
        contenedorFacturas.querySelectorAll('.chk-factura-pago').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const inputImporte = checkbox.closest('tr').querySelector('.input-imputacion');
                inputImporte.disabled = !checkbox.checked;
                if (checkbox.checked && !inputImporte.value) {
                    inputImporte.value = Number(inputImporte.dataset.saldo || 0).toFixed(2);
                }
                if (!checkbox.checked) inputImporte.value = '';
                actualizarResumenImputacion();
            });
        });
        contenedorFacturas.querySelectorAll('.input-imputacion').forEach(inputImporte => {
            inputImporte.addEventListener('input', actualizarResumenImputacion);
        });
        actualizarResumenImputacion();
    }

    async function cargarFacturasPendientes(idCliente) {
        panelImputacion.style.display = modoImputacion.value === 'a_cuenta' ? 'none' : 'block';
        btnImputarAntiguedad.disabled = true;
        contenedorFacturas.innerHTML = '<p class="sin-facturas"><i class="fas fa-spinner fa-spin"></i> Cargando facturas pendientes...</p>';
        facturasPendientes = [];
        saldoAFavorActual = 0;
        actualizarResumenImputacion();

        try {
            const response = await fetch('<?php echo url('ajax/obtener_facturas_ctacte_ajax.php'); ?>?id_cliente=' + encodeURIComponent(idCliente), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'No se pudieron cargar las facturas pendientes.');
            renderizarFacturasPendientes(data.facturas || []);
            saldoAFavorActual = Number(data.saldo_a_favor_actual || 0);
            actualizarResumenImputacion();
        } catch (error) {
            console.error('Error al cargar facturas pendientes:', error);
            contenedorFacturas.innerHTML = '';
            const mensaje = document.createElement('p');
            mensaje.className = 'sin-facturas error-carga-facturas';
            mensaje.textContent = error.message;
            contenedorFacturas.appendChild(mensaje);
            actualizarResumenImputacion();
        }
    }

    function toggleChequeFields(suffix) {
        const combo = suffix === 'pago_directo' ? document.getElementById('condicion_pago') : document.getElementById('pago_condicion_pago');
        const panel = document.getElementById('panel_cheque_' + suffix);
        if (combo.value === 'Cheque') {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }

    function limpiarImputacionFacturas() {
        document.querySelectorAll('.chk-factura-pago').forEach(checkbox => {
            const inputImporte = checkbox.closest('tr').querySelector('.input-imputacion');
            checkbox.checked = false;
            inputImporte.value = '';
            inputImporte.disabled = true;
        });
    }

    modoImputacion.addEventListener('change', () => {
        if (modoImputacion.value === 'a_cuenta') {
            limpiarImputacionFacturas();
            panelImputacion.style.display = 'none';
        } else {
            panelImputacion.style.display = 'block';
        }
        actualizarResumenImputacion();
    });

    btnImputarAntiguedad.addEventListener('click', () => {
        let restante = Math.round((parseFloat(inputMonto.value) || 0) * 100) / 100;
        if (restante <= 0) {
            mostrarMensaje('Monto Inválido', 'Ingrese el monto del pago antes de imputar por antigüedad.', 'error');
            return;
        }

        document.querySelectorAll('.chk-factura-pago').forEach(checkbox => {
            const inputImporte = checkbox.closest('tr').querySelector('.input-imputacion');
            checkbox.checked = false;
            inputImporte.value = '';
            inputImporte.disabled = true;
        });

        facturasPendientes.forEach(factura => {
            if (restante <= 0.005) return;
            const fila = document.querySelector(`[data-factura-id="${factura.id}"]`);
            const checkbox = fila.querySelector('.chk-factura-pago');
            const inputImporte = fila.querySelector('.input-imputacion');
            const importe = Math.min(restante, factura.saldo_disponible);
            checkbox.checked = true;
            inputImporte.disabled = false;
            inputImporte.value = importe.toFixed(2);
            restante = Math.round((restante - importe) * 100) / 100;
        });
        actualizarResumenImputacion();
    });

    inputMonto.addEventListener('input', actualizarResumenImputacion);

    // Interceptamos el envío del formulario para usar los modales estilizados
    document.getElementById('formRegistroPagoCC').onsubmit = function(e) {
        e.preventDefault(); // Detenemos el envío automático
        const form = this;

        if (!idHidden.value) {
            mostrarMensaje("Faltan Datos", "⚠️ Debe seleccionar un cliente de la lista de resultados para continuar.", "error");
            return;
        }

        const monto = Math.round((parseFloat(inputMonto.value) || 0) * 100) / 100;
        if (isNaN(monto) || monto <= 0) {
            mostrarMensaje("Monto Inválido", "❌ Por favor, ingrese un monto superior a $0.00.", "error");
            return;
        }

        const pagarACuenta = modoImputacion.value === 'a_cuenta';
        const filasImputadas = pagarACuenta
            ? []
            : Array.from(document.querySelectorAll('.chk-factura-pago:checked'));
        if (!pagarACuenta && !filasImputadas.length) {
            mostrarMensaje("Imputación Requerida", "⚠️ Seleccione al menos una factura o elija 'Pago a cuenta'.", "error");
            return;
        }

        const imputaciones = {};
        let totalImputado = 0;
        for (const checkbox of filasImputadas) {
            const fila = checkbox.closest('tr');
            const inputImporte = fila.querySelector('.input-imputacion');
            const importe = Math.round((parseFloat(inputImporte.value) || 0) * 100) / 100;
            const saldo = Math.round((parseFloat(inputImporte.dataset.saldo) || 0) * 100) / 100;
            const documento = fila.children[1].textContent;
            if (importe <= 0) {
                mostrarMensaje("Importe Inválido", `❌ Ingrese un importe mayor a $0,00 para ${documento}.`, "error");
                return;
            }
            if (importe > saldo + 0.005) {
                mostrarMensaje("Importe Excedido", `❌ El importe de ${documento} supera su saldo disponible.`, "error");
                return;
            }
            imputaciones[fila.dataset.facturaId] = importe;
            totalImputado = Math.round((totalImputado + importe) * 100) / 100;
        }
        if (totalImputado > monto + 0.005) {
            mostrarMensaje(
                "Imputación Excedida",
                `❌ La suma de las facturas supera el monto del pago. Asignado: $${totalImputado.toLocaleString('es-AR', {minimumFractionDigits:2})}; recibido: $${monto.toLocaleString('es-AR', {minimumFractionDigits:2})}.`,
                "error"
            );
            return;
        }
        const saldoFavorPago = Math.round((monto - totalImputado) * 100) / 100;

        // Validación de Cheque
        const condicion = document.getElementById('condicion_pago').value;
        if (condicion === 'Cheque') {
            const nro = this.querySelector('[name="chq_nro"]').value.trim();
            const vto = this.querySelector('[name="chq_vto"]').value;
            if (!nro || !vto) {
                mostrarMensaje("Datos de Cheque", "⚠️ Cuando el método es Cheque, el N° de cheque y la fecha de vencimiento son obligatorios.", "error");
                return;
            }
        }

        const nombreCli = document.getElementById('display_nombre_cliente').innerText;

        const detalleImputacion = pagarACuenta
            ? `Todo el pago quedará como saldo a favor ($${saldoFavorPago.toLocaleString('es-AR', {minimumFractionDigits:2})}).`
            : `Se imputarán $${totalImputado.toLocaleString('es-AR', {minimumFractionDigits:2})} a ${filasImputadas.length} factura(s)${saldoFavorPago > 0 ? ` y quedarán $${saldoFavorPago.toLocaleString('es-AR', {minimumFractionDigits:2})} a favor` : ''}.`;

        confirmarAccion(
            "Registrar Pago Cuenta Corriente",
            `¿Está seguro de registrar el abono de $${monto.toLocaleString('es-AR', {minimumFractionDigits:2})} para el cliente ${nombreCli}? ${detalleImputacion}`,
            "SÍ, REGISTRAR PAGO",
            "btn-success",
            () => {
                const btnSubmit = form.querySelector('button[type="submit"]');
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESANDO...';

                const formData = new FormData(form);
                Object.entries(imputaciones).forEach(([idFactura, importe]) => {
                    formData.append(`imputaciones[${idFactura}]`, importe.toFixed(2));
                });
                fetch('<?php echo url('procesos/registrar_pago_cc.php'); ?>', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Abrir recibo en una nueva pestaña (popup de impresión)
                        window.open(`vista_recibo.php?id_mov=${data.id_movimiento}`, '_blank', 'width=400,height=700,scrollbars=yes,resizable=yes');
                        
                        // Redirigimos inmediatamente para limpiar el formulario sin mostrar avisos
                        window.location.href = 'pagos_ctacte.php';
                    } else {
                        mostrarMensaje("Error", "❌ " + (data.error || "No se pudo registrar el pago."), "error");
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> CONFIRMAR REGISTRO DE PAGO';
                    }
                })
                .catch(err => {
                    console.error("Error en registro:", err);
                    mostrarMensaje("Error de Conexión", "❌ No se pudo conectar con el servidor.", "error");
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> CONFIRMAR REGISTRO DE PAGO';
                });
            }
        );
    };

    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', (e) => {
        if (!inputBusq.contains(e.target) && !resDiv.contains(e.target)) {
            resDiv.style.display = 'none';
        }
    });

    if (clienteInicial) {
        seleccionarCliente(clienteInicial);
    }
</script>
</body>
</html>
