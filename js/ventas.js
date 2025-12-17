// Este código asume que tienes acceso a las variables globales:
// const clientesData; 
// const ticketDocImprimir;
// que se definieron justo antes de incluir este archivo JS.

// =========================================================================
// ************ VARIABLES Y FUNCIONES GLOBALES (ACCESIBLES) ************
// =========================================================================
let carrito = [];
const pendientesModal = document.getElementById('pendientesModal');


function renderizarCarrito() {
        const tbody = document.querySelector('#carrito tbody');
        tbody.innerHTML = '';

        carrito.forEach((item, index) => {
            const row = tbody.insertRow();
            row.dataset.index = index;

            row.innerHTML = `
                <td>${item.cod_prod}</td>
                <td>${item.descripcion}</td>
                <td class="text-right">$${item.p_unit.toFixed(2)}</td>
                <td>
                    <input type="number" min="0.01" step="any" value="${item.cant}" data-cod-prod="${item.cod_prod}"
                        class="input-field update-cantidad" style="width: 60px; padding: 5px;">
                </td>
                <td class="text-right">$${item.total.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm remover-item" data-cod-prod="${item.cod_prod}">X</button>
                </td>
            `;
        });
        calcularTotales();
    }

function calcularTotales() {
        const cambioSaldoStrong = document.getElementById('cambio_saldo_display');
        const totalVentaInputHidden = document.getElementById('total_venta_input');
        const totalVentaDisplay = document.getElementById('total_venta_display');
        const selectCondPago = document.getElementById('cond_pago');
        const pagoEfectivoInput = document.getElementById('pago_efectivo');
        const pagoTransfInput = document.getElementById('pago_transf');
        // 1. Calcular el Total de la Venta
        let totalVenta = carrito.reduce((sum, item) => sum + item.total, 0);

        // Actualizar el display y el campo oculto
        totalVentaDisplay.textContent = '$' + totalVenta.toFixed(2);
        totalVentaInputHidden.value = totalVenta.toFixed(2);

        // 2. LÓGICA DE CONDICIÓN DE PAGO
        if (selectCondPago.value === 'CUENTA CORRIENTE') {
            // Manejar saldo para Cuenta Corriente
            const pagoEfectivo = parseFloat(pagoEfectivoInput.value) || 0;
            const pagoTransferencia = parseFloat(pagoTransfInput.value) || 0;
            const saldoDeuda = totalVenta - (pagoEfectivo + pagoTransferencia);

            cambioSaldoStrong.textContent = `$${saldoDeuda.toFixed(2)} (Deuda)`;
            cambioSaldoStrong.style.color = '#ff9800'; // Naranja: Saldo a CC

            if (saldoDeuda <= 0) {
                 cambioSaldoStrong.textContent = 'Pago Completo';
                 cambioSaldoStrong.style.color = '#4caf50';
            }

        } else {
            // Si es Contado, sumar ambos pagos y calcular cambio
            const pagoEfectivo = parseFloat(pagoEfectivoInput.value) || 0;
            const pagoTransferencia = parseFloat(pagoTransfInput.value) || 0;
            const totalPagado = pagoEfectivo + pagoTransferencia;

            const cambio = totalPagado - totalVenta;

            // 3. Mostrar el Cambio / Saldo
            cambioSaldoStrong.textContent = `$${cambio.toFixed(2)}`;

            if (cambio < 0) {
                cambioSaldoStrong.style.color = '#f44336'; // Rojo: Saldo Pendiente
            } else {
                cambioSaldoStrong.style.color = '#4caf50'; // Verde: Cambio a Devolver
            }
        }
    }


// --- Funciones Globales para el onclick del HTML (Evita ReferenceError) ---

function cerrarModalPendientes() {
    if(pendientesModal) {
        pendientesModal.style.display = 'none';
        document.body.classList.remove('modal-open-fix'); 
    }
}

function reanudarVenta(n_documento) {
    // 1. Cerrar el modal de ventas pendientes
    cerrarModalPendientes();

    // 2. Obtener referencias de elementos
    const nDocumentoInput = document.getElementById('n_documento');
    const pagoEfectivoInput = document.getElementById('pago_efectivo');
    const pagoTransfInput = document.getElementById('pago_transf');
    const idVentaExistenteInput = document.getElementById('id_venta_existente_input');
    
    //alert(`Cargando venta N° ${n_documento}. Espere un momento...`);

    // 3. Llamada AJAX al nuevo archivo PHP que devuelve JSON
    // Usamos la ruta relativa correcta desde el archivo principal (ventas.php)
    fetch(`../ajax/cargar_venta_pendiente_ajax.php?n_documento=${n_documento}`)
        .then(response => {
            if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
            return response.json(); 
        })
        .then(data => {
            if (data.error) {
                alert('Error al cargar la venta: ' + data.error);
                return;
            }

            const cabecera = data.cabecera;
            
            // A) Cargar la Cabecera (CRÍTICO para saber que es una venta existente)
            idVentaExistenteInput.value = cabecera.id;
            nDocumentoInput.value = cabecera.n_documento;
            
            pagoEfectivoInput.value = parseFloat(cabecera.pago_efectivo).toFixed(2);
            pagoTransfInput.value = parseFloat(cabecera.pago_transf).toFixed(2);
            
            // B) Cargar el Cliente y Condición de Pago
            seleccionarCliente({
                id_cliente: cabecera.id_cliente,
                nombre_completo: cabecera.nombre_cliente, 
                num_documento: cabecera.num_documento,    
                cond_pago: cabecera.cond_pago
            });

            // C) Cargar el detalle al carrito (reemplaza el carrito actual)
            carrito = data.detalle.map(item => ({
                cod_prod: item.cod_prod,
                descripcion: item.descripcion,
                p_unit: parseFloat(item.p_unit),
                cant: parseFloat(item.cant),
                total: parseFloat(item.total),
            }));

            // D) Re-renderizar el carrito
            renderizarCarrito(); 

            alert(`Venta N° ${n_documento} cargada con éxito. ¡Lista para modificar o finalizar!`);

        })
        .catch(error => {
            console.error('Error al reanudar la venta:', error);
            alert('❌ Error al intentar reanudar la venta: ' + error.message);
        });
}

// =========================================================================
// ************ LÓGICA DE INICIO (DENTRO DE DOMContentLoaded) ************
// =========================================================================

document.addEventListener('DOMContentLoaded', function() {
    // Referencias de elementos que necesitan ser inicializados después de que el DOM esté listo
    const inputBuscar = document.getElementById('buscar_producto');
    const resultadosDiv = document.getElementById('resultadosBusqueda');
    const inputBuscarCliente = document.getElementById('buscar_cliente');
    const resultadosDivClientes = document.getElementById('resultadosBusquedaClientes');
    const nombreClienteDisplay = document.getElementById('nombre_cliente_display');
    const clienteIdHidden = document.getElementById('cliente_id_hidden');
    const numDocumentoDisplay = document.getElementById('num_documento_display');
    const pagoEfectivoInput = document.getElementById('pago_efectivo');
    const pagoTransfInput = document.getElementById('pago_transf');
    const selectCondPago = document.getElementById('cond_pago');
    const contenedorPagos = document.getElementById('contenedor_pagos');
    const formVenta = document.getElementById('formVenta');
    const detalleProductosInput = document.getElementById('detalle_productos_input');
    const btnFinalizarVenta = document.getElementById('btnFinalizarVenta');
    const btnGuardarPendiente = document.getElementById('btnGuardarPendiente');
    const ventaActionInput = document.getElementById('venta_action_input');
    const btnVerPendientes = document.getElementById('btnVerPendientes');
    const listaPendientesDiv = document.getElementById('listaPendientes');


    // ===========================================
    // 1. FUNCIONALIDAD DEL CARRITO Y CÁLCULOS
    // ===========================================

    

    

    // Eventos para actualizar cantidad y remover item
    document.querySelector('#carrito').addEventListener('change', function(e) {
        if (e.target.classList.contains('update-cantidad')) {
            const cod_prod = e.target.dataset.codProd;
            const nuevaCantidad = parseFloat(e.target.value);

            if (nuevaCantidad > 0) {
                const index = carrito.findIndex(item => item.cod_prod === cod_prod);
                if (index !== -1) {
                    carrito[index].cant = nuevaCantidad;
                    carrito[index].total = nuevaCantidad * carrito[index].p_unit;
                    renderizarCarrito();
                }
            } else {
                e.target.value = 1;
            }
        }
    });

    document.querySelector('#carrito').addEventListener('click', function(e) {
        if (e.target.classList.contains('remover-item')) {
            const cod_prod = e.target.dataset.codProd;
            carrito = carrito.filter(item => item.cod_prod !== cod_prod);
            renderizarCarrito();
        }
    });

    // Eventos para pagos y condición
    pagoEfectivoInput.addEventListener('input', calcularTotales);
    pagoTransfInput.addEventListener('input', calcularTotales);

    selectCondPago.addEventListener('change', function() {
        if (this.value === 'CUENTA CORRIENTE') {
            // No ocultamos el contenedor de pagos para permitir pagos parciales a CC
            // contenedorPagos.style.display = 'block'; 
            // Pero podríamos resetear los valores para evitar confusión
            pagoEfectivoInput.value = '0.00';
            pagoTransfInput.value = '0.00';
        } else {
            // Contado: Limpiar para forzar la entrada del pago total si es necesario
            pagoEfectivoInput.value = '';
            pagoTransfInput.value = '';
        }
        calcularTotales();
    });


    // ===========================================
    // 2. BÚSQUEDA DE PRODUCTOS (AJAX)
    // ===========================================

    inputBuscar.addEventListener('input', function() {
        const busqueda = inputBuscar.value.trim();
        if (busqueda.length < 3) {
            resultadosDiv.innerHTML = '';
            resultadosDiv.style.display = 'none'; 
            return;
        }

        const xhr = new XMLHttpRequest();
        // Usamos la ruta relativa correcta: "../ajax/"
        xhr.open('GET', 'buscar_producto_ajax.php?q=' + encodeURIComponent(busqueda), true); 
        xhr.onload = function() {
            if (this.status == 200) {
                try {
                    const productos = JSON.parse(this.responseText);
                    mostrarResultados(productos);
                } catch (e) {
                    resultadosDiv.innerHTML = 'Error al procesar la respuesta JSON.';
                    resultadosDiv.style.display = 'block';
                }
            } else {
                resultadosDiv.innerHTML = 'Error en la búsqueda (HTTP ' + this.status + ').';
                resultadosDiv.style.display = 'block';
            }
        };
        xhr.send();
    });

    function mostrarResultados(productos) {
        resultadosDiv.innerHTML = '';
        if (productos.length === 0) {
            resultadosDiv.innerHTML = '<div style="padding: 10px;">No se encontraron productos.</div>';
            resultadosDiv.style.display = 'block';
            return;
        }

        productos.forEach(producto => {
            const div = document.createElement('div');
            div.classList.add('producto-encontrado');
            div.textContent = `[${producto.cod_prod}] ${producto.descripcion} ($${parseFloat(producto.p_venta).toFixed(2)}) - Stock: ${producto.stock}`;
            div.dataset.producto = JSON.stringify(producto);
            resultadosDiv.appendChild(div);
        });
        resultadosDiv.style.display = 'block';
    }

    resultadosDiv.addEventListener('click', function(e) {
        if (e.target.classList.contains('producto-encontrado')) {
            const producto = JSON.parse(e.target.dataset.producto);
            const index = carrito.findIndex(item => item.cod_prod === producto.cod_prod);

            if (index !== -1) {
                const stock_actual = parseInt(producto.stock);
                if (carrito[index].cant + 1 <= stock_actual) {
                    carrito[index].cant += 1;
                    carrito[index].total = carrito[index].cant * carrito[index].p_unit;
                } else {
                    alert("Stock insuficiente.");
                }
            } else {
                if (parseInt(producto.stock) > 0) {
                    carrito.push({
                        cod_prod: producto.cod_prod,
                        descripcion: producto.descripcion,
                        p_unit: parseFloat(producto.p_venta),
                        cant: 1,
                        total: parseFloat(producto.p_venta),
                        stock_disponible: parseInt(producto.stock)
                    });
                } else {
                    alert("El producto no tiene stock disponible.");
                }
            }

            inputBuscar.value = '';
            resultadosDiv.innerHTML = '';
            resultadosDiv.style.display = 'none'; // Ocultar después de seleccionar
            renderizarCarrito();
        }
    });

    document.addEventListener('click', function(e) {
        if (!inputBuscar.contains(e.target) && !resultadosDiv.contains(e.target)) {
            resultadosDiv.style.display = 'none';
        }
    });


    // ===========================================
    // 3. BÚSQUEDA DE CLIENTES
    // ===========================================
    
    // Esta función debe ser GLOBAL para ser usada por reanudarVenta
    window.seleccionarCliente = function(cliente) {
        // Usa las referencias locales o búscalas si la función es global
        const nombreClienteDisplay = document.getElementById('nombre_cliente_display');
        const clienteIdHidden = document.getElementById('cliente_id_hidden');
        const numDocumentoDisplay = document.getElementById('num_documento_display');
        const condPago = document.getElementById('cond_pago');
        
        nombreClienteDisplay.textContent = cliente.nombre_completo || 'Venta Genérica';
        clienteIdHidden.value = cliente.id_cliente || 0;
        numDocumentoDisplay.value = cliente.num_documento || '';
        
        if (cliente.cond_pago) {
            condPago.value = cliente.cond_pago;
        }
        
        // Esto es crucial para actualizar la vista de pagos
        condPago.dispatchEvent(new Event('change'));
    }


    inputBuscarCliente.addEventListener('input', function() {
        const busqueda = inputBuscarCliente.value.trim().toLowerCase();
        resultadosDivClientes.innerHTML = '';

        if (busqueda.length < 2) {
            resultadosDivClientes.style.display = 'none';
            return;
        }

        const resultados = clientesData.filter(cliente =>
            cliente.nombre_completo.toLowerCase().includes(busqueda) ||
            cliente.num_documento.includes(busqueda)
        );

        if (resultados.length > 0) {
            resultados.forEach(cliente => {
                const div = document.createElement('div');
                div.classList.add('resultado-cliente-item');
                div.textContent = `${cliente.nombre_completo} (${cliente.num_documento})`;
                div.dataset.cliente = JSON.stringify(cliente);
                resultadosDivClientes.appendChild(div);
            });
            resultadosDivClientes.style.display = 'block';
        } else {
            resultadosDivClientes.style.display = 'none';
        }
    });

    // Asignación de cliente al hacer clic
    resultadosDivClientes.addEventListener('click', function(e) {
        if (e.target.classList.contains('resultado-cliente-item')) {
            const cliente = JSON.parse(e.target.dataset.cliente);

            seleccionarCliente(cliente); // Usa la función global/reutilizable

            inputBuscarCliente.value = '';
            resultadosDivClientes.style.display = 'none';
        }
    });

    // ===========================================
    // 4. ENVÍO DE FORMULARIO
    // ===========================================

    function prepararEnvio(accion) {
        if (carrito.length === 0) {
            alert("Debe agregar al menos un producto al carrito para guardar la venta.");
            return false;
        }

        detalleProductosInput.value = JSON.stringify(carrito);
        ventaActionInput.value = accion;

        return true;
    }

    btnFinalizarVenta.addEventListener('click', function(e) {
        if (!prepararEnvio('Finalizar')) {
            e.preventDefault();
        }
    });

    btnGuardarPendiente.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm("¿Desea guardar la venta como Pendiente? No se descontará stock ni se creará Cta.Cte.")) {
            if (prepararEnvio('Pendiente')) {
                formVenta.submit();
            }
        }
    });


    // ===========================================
    // 5. LÓGICA DE TICKET
    // ===========================================
    const ticketModal = document.getElementById('ticketModal');
    const ticketVistaPrevia = document.getElementById('ticket-vista-previa');
    const errorTicket = document.getElementById('errorTicket');
    const btnImprimirTicket = document.getElementById('btnImprimirTicket');

    window.cerrarModalTicket = function() {
        ticketModal.style.display = 'none';
        document.body.classList.remove('modal-open-fix');
    }
    
// Función para Reimprimir (Abre una nueva ventana/pestaña con el ticket)
	function imprimirTicket(nDocumento) {
		// ⚠️ RUTA CRÍTICA: Debe coincidir con la ubicación de tu proyecto.
		//const nDocumento = document.getElementById('n_documento');
        // Asumiendo que /pos/ es el directorio raíz.
		const url = '/pos/pages/vista_previa_ticket.php?n_documento=' + nDocumento;
		
		// Abrir en una nueva ventana con tamaño de ticket, la cual se encargará de forzar la impresión
		window.open(url, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
		
		// Si la función se llama desde el modal de detalle, ciérralo
		const detalleModal = document.getElementById('detalleModal');
		if(detalleModal && detalleModal.style.display === 'block') {
			detalleModal.style.display='none';
		}
	}

/*     function imprimirTicket(htmlContent) {
    // Abrimos la ventana de impresión
    const printWindow = window.open('', '_blank', 'width=400,height=600'); 
    
    // Escribimos el HEAD con la ruta absoluta (¡Asegúrate que '/pos/css/ticket_print.css' sea correcto!)
    printWindow.document.write('<html><head><title>Ticket de Venta</title>');
    
    // Usamos la ruta absoluta
    printWindow.document.write('<link rel="stylesheet" href="../../pos/css/ticket_print.css">'); 
    
    // Escribimos el cuerpo (el onload lo agregamos después de close())
    printWindow.document.write('</head><body>'); 
    printWindow.document.write(htmlContent);
    printWindow.document.write('</body></html>');
    
    printWindow.document.close();

    // ------------------------------------------------------------------
    // *** CLAVE: Usamos el evento onload de la ventana para imprimir ***
    // Esto garantiza que el DOM esté listo y el CSS se haya procesado.
    // ------------------------------------------------------------------
        printWindow.onload = function() {
            // Un pequeño delay adicional (10ms) para Firefox si es necesario
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 10); 
        };

        // Si el onload no se dispara por alguna razón, usar un fallback con setTimeout
        // Esto es solo un seguro si onload falla
        setTimeout(() => {
            if (!printWindow.printed) {
                printWindow.print();
                printWindow.close();
            }
        }, 1500); // 1.5 segundos de espera máxima
    } */

    function cargarTicket(n_documento) {
        ticketVistaPrevia.innerHTML = 'Cargando vista previa...';
        errorTicket.style.display = 'none';
        btnImprimirTicket.style.display = 'none';

        const ticketUrl = `../ajax/generar_ticket.php?n_documento=${n_documento}`; 

        fetch(ticketUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                return response.text();
            })
            .then(htmlContent => {
                ticketVistaPrevia.innerHTML = htmlContent;
                btnImprimirTicket.style.display = 'block';
                ticketModal.style.display = 'block';
                document.body.classList.add('modal-open-fix');

                btnImprimirTicket.onclick = function() {
                    imprimirTicket(htmlContent);
                };
            })
            .catch(error => {
                console.error("Error al cargar el ticket:", error);
                ticketVistaPrevia.innerHTML = 'Error al cargar la vista previa del Ticket.';
                errorTicket.textContent = `Error: ${error.message}`;
                errorTicket.style.display = 'block';
                ticketModal.style.display = 'block';
            });
    }

    // Ejecutar si hay un documento a imprimir al cargar la página
    if (ticketDocImprimir) {
        //cargarTicket(ticketDocImprimir);
        imprimirTicket(ticketDocImprimir);
    }


    // ===========================================
    // 6. LÓGICA DE VENTAS PENDIENTES (MODAL y REANUDAR)
    // ===========================================

    // FUNCIÓN PARA CARGAR LAS VENTAS PENDIENTES (Usada por el botón Ver Pendientes)
    function cargarVentasPendientes() {
        listaPendientesDiv.innerHTML = 'Cargando ventas pendientes...';
        
        // Usamos la ruta relativa correcta: "../ajax/"
        const url = '../ajax/ventas_pendientes_ajax.php'; 

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    // Si falla por 404 u otro error HTTP
                    throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
                }
                return response.text(); // Devuelve HTML con el botón que llama a reanudarVenta()
            })
            .then(htmlContent => {
                listaPendientesDiv.innerHTML = htmlContent;
            })
            .catch(error => {
                listaPendientesDiv.innerHTML = `<p style="color: red;">❌ Error al cargar ventas pendientes: ${error.message}. Revise la ruta '../ajax/ventas_pendientes_ajax.php'.</p>`;
                console.error('Error al cargar ventas pendientes:', error);
            });
    }

    // EVENTO: Abrir modal
    btnVerPendientes.addEventListener('click', function() {
        cargarVentasPendientes();
        pendientesModal.style.display = 'block';
    });

    // EVENTO: Cerrar modal haciendo clic fuera
    window.addEventListener('click', function(event) {
        if (event.target === pendientesModal) {
            cerrarModalPendientes();
        }
    });

}); // Fin de DOMContentLoaded