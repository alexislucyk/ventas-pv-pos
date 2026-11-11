<?php
include 'infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if (!tiene_permiso('pages/cobro_cuotas.php')) {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

$mensaje = '';
if (isset($_SESSION['status_msj'])) {
    $mensaje = $_SESSION['status_msj'];
    unset($_SESSION['status_msj']);
}

try {
    $sql_clientes = "SELECT DISTINCT c.id, CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo, c.cuit 
                     FROM clientes c
                     JOIN ventas v ON c.id = v.id_cliente AND v.empresa_id = :empresa_id1
                     JOIN ventas_financiacion vf ON v.id = vf.id_venta
                     WHERE c.empresa_id = :empresa_id2
                     ORDER BY nombre_completo ASC";
    $stmt_clientes = $pdo->prepare($sql_clientes);
    $stmt_clientes->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id]);
    $clientes_financiados = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientes_financiados = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cobro de Cuotas | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/cobro_cuotas.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1 style="border-bottom: none !important;"><i class="fas fa-hand-holding-usd"></i> Cobro de Cuotas</h1>

        <?php if ($mensaje): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <div class="card">
            <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; justify-content: space-between; padding: 8px 0 0 0;">
                <!-- 1. Buscar cliente -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 240px; flex: 1 1 240px;">
                    <label for="buscar_cliente_cuotas" style="font-size: 0.8rem; color: #ccc; white-space: nowrap;">Buscar Cliente con Deuda Financiada</label>
                    <div class="contenedor-busqueda-cliente" style="position:relative; flex: 1; min-width: 0;">
                        <input type="text" id="buscar_cliente_cuotas" class="input-field" autocomplete="off" placeholder="Escriba nombre o CUIT..." style="flex: 1; min-width: 0;">
                        <div id="resultadosBusquedaClientes"></div>
                    </div>
                </div>

                <!-- 2. Buscar por N° de Venta -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 200px; flex: 1 1 200px;">
                    <label for="input_busqueda_venta" style="font-size: 0.8rem; color: #ccc; white-space: nowrap;">Buscar por N° de Venta / Documento</label>
                    <input type="number" id="input_busqueda_venta" class="input-field" placeholder="Ingrese N° de Venta..." style="margin-bottom:0 !important;" min="1">
                </div>

                <!-- 3. Ventas Desde -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 140px; flex: 0 0 140px;">
                    <label style="font-size: 0.8rem; color: #ccc; white-space: nowrap;">Ventas Desde:</label>
                    <input type="date" id="fecha_desde" class="input-field" style="margin-bottom: 0 !important; min-width: 0;">
                </div>

                <!-- 4. Ventas Hasta -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 140px; flex: 0 0 140px;">
                    <label style="font-size: 0.8rem; color: #ccc; white-space: nowrap;">Ventas Hasta:</label>
                    <input type="date" id="fecha_hasta" class="input-field" style="margin-bottom: 0 !important; min-width: 0;">
                </div>

                <!-- 5. Estado de cuotas -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 130px; flex: 0 0 130px;">
                    <label style="font-size: 0.8rem; color: #ccc; white-space: nowrap;">Estado de cuotas</label>
                    <select id="filtro_estado_cuota" class="input-field" style="margin-bottom: 0 !important; min-width: 0;">
                        <option value="todos">Todos</option>
                        <option value="pendiente">Pendiente</option>
                        <option value="vencido">Vencido</option>
                        <option value="pagado">Pagado</option>
                    </select>
                </div>

                <!-- 6. Ver Todos los Créditos -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 130px; flex: 0 0 130px;">
                    <div style="height: 1rem;"></div>
                    <button class="btn btn-secondary" style="height: 40px;" onclick="verTodosLosCreditos()"><i class="fas fa-list"></i> Ver Todos los Créditos</button>
                </div>

                <!-- 7. Botón Buscar (al final, a la derecha de todo) -->
                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 100px; flex: 0 0 100px;">
                    <div style="height: 1rem;"></div>
                    <button type="button" class="btn btn-primary" style="height: 40px;" onclick="buscarVentaDesdeInput()"><i class="fas fa-search"></i> BUSCAR</button>
                </div>
            </div>
        </div>

        <div id="panel_cuotas" class="card" style="display:none;">
            <h2 id="titulo_cliente">Créditos de: <span></span></h2>
            <div id="lista_cuotas_container" style="margin-top:20px;">
                <!-- Se carga vía AJAX -->
            </div>
        </div>
    </div>

    <!-- Modal para Ver Detalle de Cuotas de una Venta -->
    <div id="modalDetalleVentaFinanciada" class="modal">
        <div class="modal-content" style="max-width: 900px; border-top: 4px solid #3498db;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #3498db;"><i class="fas fa-list-ol"></i> Plan de Pagos - Venta #<span id="n_doc_modal"></span></h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalDetalle()">&times;</span>
            </div>
            
            <div id="contenedor_detalle_cuotas" style="max-height: 60vh; overflow-y: auto;">
                <!-- Se carga vía AJAX -->
            </div>
        </div>
    </div>

    <!-- Modal para Procesar Pago -->
    <div id="modalPagoCuota" class="modal">
        <div class="modal-content" style="max-width: 450px; border-top: 4px solid #2ecc71;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #2ecc71;"><i class="fas fa-money-bill-wave"></i> Registrar Pago</h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalPago()">&times;</span>
            </div>
            
            <form id="formPagoCuota">
                <input type="hidden" id="pago_id_cuota">
                <input type="hidden" id="pago_id_venta">
                
                <div style="background: #1a1a1a; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #333;">
                    <p style="margin:0; font-size:0.9rem; color:#aaa;">Saldo Pendiente de la Cuota:</p>
                    <h2 id="display_saldo_cuota" style="margin:5px 0; color:#f1c40f;">$0.00</h2>
                </div>

                <label>Monto a Cobrar ($)</label>
                <input type="number" step="0.01" id="monto_cobrar" class="input-field" required style="font-size: 1.2rem; font-weight: bold; color: #2ecc71;" oninput="actualizarImpactoDeuda('monto')">
                
                <label>Descuento / Ajuste de Interés ($)</label>
                <input type="number" step="0.01" id="descuento_ajuste" class="input-field" value="0.00" oninput="actualizarImpactoDeuda('desc')">
                <p id="preview_impacto" style="font-size: 0.85rem; color: #aaa; margin-top: -10px;">Impacto total en la deuda: <b>$0.00</b></p>
                <small style="color:#888;">Use esto para condonar intereses y dar la cuota por pagada.</small>

                <label style="margin-top:15px;">Método de Pago</label>
                <select id="metodo_pago_cuota" class="input-field">
                    <option value="EFECTIVO">EFECTIVO</option>
                    <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                </select>

                <div style="margin-top: 25px; display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="flex: 2; height: 45px; font-weight: bold;">CONFIRMAR COBRO</button>
                    <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="cerrarModalPago()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const clientesData = <?php echo json_encode($clientes_financiados); ?>;
        const inputBusqueda = document.getElementById('buscar_cliente_cuotas');
        const resultadosDiv = document.getElementById('resultadosBusquedaClientes');

        // Lógica de auto-carga de búsqueda desde parámetros de URL
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Obtener los parámetros de la URL
            const urlParams = new URLSearchParams(window.location.search);
            const idVenta = urlParams.get('id_venta');

            // 2. Si existe el parámetro, disparamos la búsqueda
            if (idVenta) {
                const inputBusqVenta = document.getElementById('input_busqueda_venta');
                if (inputBusqVenta) {
                    inputBusqVenta.value = idVenta;
                    // Pequeño delay para asegurar que todo esté cargado
                    setTimeout(() => {
                        if (typeof buscarVentaParaCobro === 'function') {
                            buscarVentaParaCobro(parseInt(idVenta, 10));
                        }
                    }, 300);
                }
            }
        });

        inputBusqueda.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            resultadosDiv.innerHTML = '';
            if (q.length < 2) { resultadosDiv.style.display = 'none'; return; }

            const filtrados = clientesData.filter(c => 
                c.nombre_completo.toLowerCase().includes(q) || c.cuit.includes(q)
            );

            if (filtrados.length > 0) {
                resultadosDiv.style.display = 'block';
                filtrados.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'resultado-cliente-item';
                    div.innerHTML = `<strong>${c.nombre_completo}</strong> <small>(${c.cuit})</small>`;
                    div.onclick = () => seleccionarCliente(c);
                    resultadosDiv.appendChild(div);
                });
            }
        });

        function seleccionarCliente(c) {
            inputBusqueda.value = c.nombre_completo;
            resultadosDiv.style.display = 'none';
            document.getElementById('panel_cuotas').style.display = 'block';
            document.querySelector('#titulo_cliente span').innerText = c.nombre_completo;
            window.clienteActual = c.id;
            cargarCuotas(c.id);
        }

        function cargarCuotas(idCliente) {
            const desde = document.getElementById('fecha_desde').value;
            const hasta = document.getElementById('fecha_hasta').value;
            const estadoCuota = document.getElementById('filtro_estado_cuota').value;
            const nDocumento = document.getElementById('input_busqueda_venta').value.trim();
            const container = document.getElementById('lista_cuotas_container');
            container.innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Cargando créditos...</p>';
            
            let url = `<?php echo URL_BASE; ?>ajax/obtener_cuotas_pago.php?id_cliente=${idCliente}&desde=${desde}&hasta=${hasta}&estado_cuota=${estadoCuota}`;
            if (nDocumento) {
                url += `&n_documento=${encodeURIComponent(nDocumento)}`;
            }
            
            fetch(url)
                .then(res => res.text())
                .then(html => container.innerHTML = html);
        }

        window.verTodosLosCreditos = function() {
            document.getElementById('panel_cuotas').style.display = 'block';
            document.querySelector('#titulo_cliente span').innerText = "Listado General del Sistema";
            document.getElementById('buscar_cliente_cuotas').value = '';
            window.clienteActual = 'all';
            cargarCuotas('all');
        };

        window.buscarVentaParaCobro = function(nDoc) {
            if (!nDoc || nDoc <= 0) {
                mostrarMensaje("Dato Inválido", "⚠️ Por favor ingrese un número de venta válido.", "error");
                return;
            }
            // Usamos obtener_venta_detalle_ajax.php que está en la misma carpeta 'pages' y ya soporta n_documento
            fetch(`<?php echo URL_BASE; ?>pages/obtener_venta_detalle_ajax.php?n_documento=${nDoc}`)
                .then(res => {
                    if (!res.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.error) {
                        mostrarMensaje("No encontrado", "⚠️ No se encontró ninguna venta financiada con el N° " + nDoc + ". Verifique el número e intente nuevamente.", "error");
                    } else {
                        const internalId = data.cabecera.id;
                        if (internalId) {
                            verDetalleCuotas(internalId, data.cabecera.n_documento);
                        } else {
                            mostrarMensaje("Error", "❌ La respuesta del servidor no incluyó un ID de venta válido.", "error");
                        }
                    }
                })
                .catch(err => {
                    console.error("Error buscando venta:", err);
                    mostrarMensaje("Error de Conexión", "❌ No se pudo conectar con el servidor. Intente nuevamente.", "error");
                });
        };

        window.buscarVentaDesdeInput = function() {
            const input = document.getElementById('input_busqueda_venta');
            const valor = input.value.trim();

            // Validar que el n° de venta, si se ingresó, sea numérico y mayor a 0
            if (valor !== '') {
                const nDoc = parseInt(valor, 10);
                if (isNaN(nDoc) || nDoc <= 0) {
                    mostrarMensaje("Valor Inválido", "⚠️ El número de venta debe ser un valor numérico mayor a 0.", "error");
                    input.focus();
                    input.select();
                    return;
                }
            }

            // Mostrar el panel de cuotas si está oculto
            document.getElementById('panel_cuotas').style.display = 'block';

            // Aplicar todos los filtros: cliente, fechas, estado de cuotas y n° de venta
            const clienteId = window.clienteActual || 'all';
            cargarCuotas(clienteId);
        };

        window.verDetalleCuotas = function(idVenta, nDoc) {
            document.getElementById('n_doc_modal').innerText = nDoc;
            const container = document.getElementById('contenedor_detalle_cuotas');
            container.innerHTML = '<p style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Obteniendo detalle de cuotas...</p>';
            
            fetch(`<?php echo URL_BASE; ?>ajax/obtener_detalle_cuotas_venta.php?id_venta=${idVenta}`)
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = html;
                    document.getElementById('modalDetalleVentaFinanciada').style.display = 'block';
                });
        };

        function cerrarModalDetalle() {
            document.getElementById('modalDetalleVentaFinanciada').style.display = 'none';
        }

        window.abrirCobro = function(idCuota, idVenta, saldo) {
            document.getElementById('pago_id_cuota').value = idCuota;
            document.getElementById('pago_id_venta').value = idVenta;
            document.getElementById('display_saldo_cuota').innerText = `$ ${parseFloat(saldo).toFixed(2)}`;
            document.getElementById('monto_cobrar').value = saldo;
            document.getElementById('descuento_ajuste').value = 0;
            document.getElementById('modalPagoCuota').style.display = 'block';
            actualizarImpactoDeuda('init');
        };

        window.actualizarImpactoDeuda = function(origen) {
            const saldoCuota = parseFloat(document.getElementById('display_saldo_cuota').innerText.replace('$ ', '')) || 0;
            let monto = parseFloat(document.getElementById('monto_cobrar').value) || 0;
            let desc = parseFloat(document.getElementById('descuento_ajuste').value) || 0;

            if (origen === 'desc') {
                // Si el usuario ingresa un descuento, restamos ese descuento del efectivo sugerido
                if (desc > saldoCuota) {
                    desc = saldoCuota;
                    document.getElementById('descuento_ajuste').value = desc.toFixed(2);
                }
                monto = Math.max(0, saldoCuota - desc);
                document.getElementById('monto_cobrar').value = monto.toFixed(2);
            } else if (origen === 'monto') {
                // Si el usuario cambia el monto manual, validamos que la suma no exceda el saldo
                if (monto + desc > saldoCuota) {
                    if (monto > saldoCuota) {
                        monto = saldoCuota;
                        document.getElementById('monto_cobrar').value = monto.toFixed(2);
                        desc = 0;
                    } else {
                        desc = saldoCuota - monto;
                    }
                    document.getElementById('descuento_ajuste').value = desc.toFixed(2);
                }
            }

            document.getElementById('preview_impacto').innerHTML = `Reducción total de deuda: <b style="color:#2ecc71;">$ ${(monto + desc).toFixed(2)}</b>`;
        };

        function cerrarModalPago() {
            document.getElementById('modalPagoCuota').style.display = 'none';
        }

        window.reimprimirPago = function(idPago) {
            window.open('vista_previa_ticket_cuota.php?id_pago=' + idPago, '_blank', 'width=400,height=700');
        };

        // Función para limpiar la pantalla de modales antes de mostrar un mensaje importante
        function limpiarModalesDeFondo() {
            const modales = ['modalDetalleVentaFinanciada', 'modalPagoCuota'];
            modales.forEach(id => {
                const m = document.getElementById(id);
                if(m) m.style.display = 'none';
            });
        }

        document.getElementById('formPagoCuota').onsubmit = function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerText = "Procesando...";

            const data = {
                id_cuota: document.getElementById('pago_id_cuota').value,
                id_venta: document.getElementById('pago_id_venta').value,
                monto: document.getElementById('monto_cobrar').value,
                descuento: document.getElementById('descuento_ajuste').value,
                metodo: document.getElementById('metodo_pago_cuota').value
            };

            fetch('<?php echo URL_BASE; ?>ajax/procesar_pago_cuota.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    // ORDEN POR CÓDIGO: Ocultamos los modales de trabajo antes de mostrar el éxito
                    limpiarModalesDeFondo();
                    
                    mostrarMensaje("Operación Exitosa", "✅ El pago se ha registrado correctamente.", "success", () => {
                        // Preguntamos si desea imprimir usando el modal estilizado
                        confirmarAccion(
                            "Imprimir Comprobante",
                            "¿Desea imprimir el ticket para el cliente?",
                            "SÍ, IMPRIMIR",
                            "btn-success",
                            () => {
                                window.open('vista_previa_ticket_cuota.php?id_pago=' + res.id_pago, '_blank', 'width=400,height=700');
                                location.reload();
                            }
                        );
                    });
                } else {
                    btn.disabled = false;
                    btn.innerText = "CONFIRMAR COBRO";
                    mostrarMensaje("Error al Cobrar", "❌ " + res.error, "error");
                }
            });
        };

        window.anularPagoParcial = function(idPago, idVenta) {
            // Al pedir confirmación, ocultamos el detalle de fondo para que no tape el mensaje
            limpiarModalesDeFondo();

            confirmarAccion(
                'Anular Pago Parcial', 
                '¿Estás seguro de anular este pago? Se descontará del total de la cuota y se registrará un egreso en caja para compensar el movimiento.', 
                'SÍ, ANULAR PAGO', 
                'btn-danger', 
                () => {
                    fetch('<?php echo URL_BASE; ?>ajax/anular_pago_cuota.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ id_pago: idPago })
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => { throw new Error(text || 'Error en el servidor'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const nDoc = document.getElementById('n_doc_modal').innerText;
                            // Aseguramos que todo esté limpio antes del éxito
                            limpiarModalesDeFondo();
                            
                            mostrarMensaje("Anulación Exitosa", "✅ El pago parcial ha sido anulado con éxito.", "success", () => {
                                verDetalleCuotas(idVenta, nDoc);
                            });
                        } else {
                            mostrarMensaje("Error", "❌ " + data.error, "error");
                        }
                    })
                    .catch(error => {
                        console.error("Error en anulación:", error);
                        // Si el error contiene HTML (un error 500), mostramos un mensaje amigable
                        const cleanMsg = error.message.includes('<') ? "El servidor encontró un error interno. Revise los logs." : error.message;
                        mostrarMensaje("Error de Sistema", "❌ " + cleanMsg, "error");
                    });
                }
            );
        };
    </script>
</body>
</html>