<?php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/../funciones/funciones_pagos_ctacte.php';

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

// El abono se aplica sobre el saldo de la cuenta, así que el saldo de cada
// cliente se carga junto con el listado: la pantalla ya no pide facturas.
$clientes_cc = [];
try {
    $sql_clientes = "SELECT id, CONCAT(apellido, ', ', nombre) as nombre_completo, cuit
                     FROM clientes
                     WHERE habilita_cta = 'Si' AND empresa_id = ?
                     ORDER BY nombre_completo ASC";
    $stmt_clientes = $pdo->prepare($sql_clientes);
    $stmt_clientes->execute([$empresa_id]);
    $clientes_cc = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    $saldos_cc = saldosCuentaCc($pdo, (int)$empresa_id);
    foreach ($clientes_cc as &$cliente) {
        $cliente['saldo'] = $saldos_cc[(int)$cliente['id']] ?? 0.0;
    }
    unset($cliente);
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
        
        <?php echo $mensaje; ?>

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

                <section id="box_saldo" class="saldo-cta-cte" style="display: none;">
                    <div class="saldo-cta-cte-item">
                        <span class="saldo-cta-cte-label">Saldo actual de la cuenta</span>
                        <strong id="saldo_actual_cliente">$ 0,00</strong>
                    </div>
                    <div class="saldo-cta-cte-item">
                        <span class="saldo-cta-cte-label">Saldo después del pago</span>
                        <strong id="saldo_proyectado_cliente">$ 0,00</strong>
                    </div>
                    <p class="saldo-cta-cte-nota">
                        El abono se aplica al saldo de la cuenta: no se asocia a ninguna factura.
                        Si el pago supera la deuda, la diferencia queda a favor del cliente.
                    </p>
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
    const boxSaldo = document.getElementById('box_saldo');
    const saldoActualTexto = document.getElementById('saldo_actual_cliente');
    const saldoProyectadoTexto = document.getElementById('saldo_proyectado_cliente');
    let saldoCliente = 0;

    function formatMonto(valor) {
        return '$ ' + Number(valor || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // El saldo positivo es deuda; negativo es dinero a favor del cliente.
    function pintarSaldo(elemento, valor) {
        elemento.textContent = formatMonto(valor);
        elemento.style.color = valor > 0.005 ? '#f1c40f' : (valor < -0.005 ? '#2ecc71' : '#888');
    }

    function actualizarSaldoPago() {
        if (!boxSaldo || boxSaldo.style.display === 'none') return;
        const monto = Math.round((parseFloat(inputMonto.value) || 0) * 100) / 100;
        pintarSaldo(saldoProyectadoTexto, saldoCliente - monto);
    }

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

    function seleccionarCliente(cliente) {
        inputBusq.value = cliente.nombre_completo;
        idHidden.value = cliente.id;
        resDiv.style.display = 'none';
        document.getElementById('box_cliente').style.display = 'block';
        document.getElementById('display_nombre_cliente').innerText = cliente.nombre_completo;
        document.getElementById('display_cuit_cliente').innerText = 'CUIT/DNI: ' + (cliente.cuit || 'S/D');

        saldoCliente = Math.round((Number(cliente.saldo) || 0) * 100) / 100;
        boxSaldo.style.display = 'flex';
        pintarSaldo(saldoActualTexto, saldoCliente);
        actualizarSaldoPago();
    }

    function toggleChequeFields(suffix) {
        const combo = document.getElementById('condicion_pago');
        const panel = document.getElementById('panel_cheque_' + suffix);
        if (!combo || !panel) return;
        panel.style.display = combo.value === 'Cheque' ? 'block' : 'none';
    }

    inputMonto.addEventListener('input', actualizarSaldoPago);

    // El formulario ya no envia imputaciones: solo cliente, monto y datos del recibo.
    document.getElementById('formRegistroPagoCC').onsubmit = function(e) {
        e.preventDefault();
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
        const saldoDespues = Math.round((saldoCliente - monto) * 100) / 100;
        let detalleSaldo;
        if (saldoDespues > 0.005) {
            detalleSaldo = `Quedará una deuda de ${formatMonto(saldoDespues)}.`;
        } else if (saldoDespues < -0.005) {
            detalleSaldo = `Quedarán ${formatMonto(Math.abs(saldoDespues))} a favor del cliente.`;
        } else {
            detalleSaldo = 'La cuenta queda saldada.';
        }

        confirmarAccion(
            "Registrar Pago Cuenta Corriente",
            `¿Está seguro de registrar el abono de ${formatMonto(monto)} para el cliente ${nombreCli}? `
                + `Saldo actual: ${formatMonto(saldoCliente)}; después del pago: ${formatMonto(saldoDespues)}. ${detalleSaldo}`,
            "SÍ, REGISTRAR PAGO",
            "btn-success",
            () => {
                const btnSubmit = form.querySelector('button[type="submit"]');
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESANDO...';

                fetch('<?php echo url('procesos/registrar_pago_cc.php'); ?>', {
                    method: 'POST',
                    body: new FormData(form),
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



