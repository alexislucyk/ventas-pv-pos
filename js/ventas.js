// js/ventas.js
console.log("Ventas.js cargado correctamente");

let carrito = [];

// Selectores
const inputBuscarProd = document.getElementById('buscar_producto');
const resultadosProd = document.getElementById('resultadosBusqueda');
const inputBuscarCli = document.getElementById('buscar_cliente');
const resultadosCli = document.getElementById('resultadosBusquedaClientes');

// --- 1. BUSCADOR DE PRODUCTOS ---
if (inputBuscarProd) {
    inputBuscarProd.addEventListener('input', function() {
        const q = this.value.trim();
        if (q.length < 2) {
            resultadosProd.innerHTML = '';
            return;
        }

        fetch('../pages/buscar_producto_ajax.php?q=' + encodeURIComponent(q))
            .then(res => res.json())
            .then(data => {
                resultadosProd.innerHTML = '';
                data.forEach(prod => {
                    const div = document.createElement('div');
                    div.className = 'resultado-item';
                    div.style.cursor = 'pointer';
                    div.style.padding = '8px';
                    div.style.borderBottom = '1px solid #eee';

                    const stockColor = prod.stock <= 0 ? 'red' : 'green';
                    const stockTexto = prod.stock <= 0 ? 'SIN STOCK' : prod.stock;

                    div.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span><strong>${prod.cod_prod}</strong> - ${prod.descripcion}</span>
                            <span style="font-size: 0.9em;">
                                $${parseFloat(prod.p_venta).toFixed(2)} | 
                                <b style="color: ${stockColor};">Stock: ${stockTexto}</b>
                            </span>
                        </div>
                    `;

                    div.onclick = () => {
                        if (prod.stock <= 0) {
                            alert("No hay stock disponible de este producto.");
                            return;
                        }
                        agregarAlCarrito(prod);
                        inputBuscarProd.value = '';
                        resultadosProd.innerHTML = '';
                    };
                    resultadosProd.appendChild(div);
                });
            })
            .catch(err => console.error("Error en Fetch Productos:", err));
    });
}

// --- 2. BUSCADOR DE CLIENTES ---
if (inputBuscarCli) {
    inputBuscarCli.addEventListener('input', function() {
        const busqueda = this.value.toLowerCase().trim();
        resultadosCli.innerHTML = '';

        if (typeof clientesData === 'undefined') {
            console.error("Error: clientesData no está definido");
            return;
        }

        if (busqueda.length < 2) {
            resultadosCli.style.display = 'none';
            return;
        }

        const filtrados = clientesData.filter(c => 
            (c.nombre_completo && c.nombre_completo.toLowerCase().includes(busqueda)) || 
            (c.num_documento && c.num_documento.includes(busqueda))
        );

        if (filtrados.length > 0) {
            resultadosCli.style.display = 'block';
            filtrados.forEach(cliente => {
                const div = document.createElement('div');
                div.className = 'resultado-cliente-item';
                div.style.padding = '8px';
                div.style.cursor = 'pointer';
                div.style.borderBottom = '1px solid #eee';
                div.dataset.cliente = JSON.stringify(cliente);
                div.innerHTML = `<strong>${cliente.nombre_completo}</strong> <small>(${cliente.num_documento})</small>`;
                resultadosCli.appendChild(div);
            });
        }
    });

    resultadosCli.addEventListener('click', function(e) {
        const item = e.target.closest('.resultado-cliente-item');
        if (item) {
            const cliente = JSON.parse(item.dataset.cliente);
            const idHidden = document.getElementById('id_cliente_hidden'); 
            const nombreDisplay = document.getElementById('nombre_cliente_display');
            
            if (idHidden) idHidden.value = cliente.id_cliente;
            
            if (nombreDisplay) {
                if (nombreDisplay.tagName === 'INPUT') {
                    nombreDisplay.value = cliente.nombre_completo;
                } else {
                    nombreDisplay.innerText = cliente.nombre_completo;
                }
            }

            inputBuscarCli.value = '';
            resultadosCli.innerHTML = '';
            resultadosCli.style.display = 'none';
        }
    });
}

// --- 3. FUNCIONES DEL CARRITO ---
function agregarAlCarrito(prod) {
    const existe = carrito.find(item => item.cod_prod === prod.cod_prod);
    const pVenta = parseFloat(prod.p_venta) || 0;
    const pCosto = parseFloat(prod.p_compra) || parseFloat(prod.p_costo) || 0; 

    if (existe) {
        existe.cant++;
        existe.total = existe.cant * existe.p_unit;
    } else {
        carrito.push({
            cod_prod: prod.cod_prod,
            descripcion: prod.descripcion,
            p_unit: pVenta,
            p_costo: pCosto,
            cant: 1,
            total: pVenta
        });
    }
    renderizarCarrito();
}

function renderizarCarrito() {
    const tbody = document.querySelector('#carrito tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    carrito.forEach((item, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.cod_prod}</td>
            <td>${item.descripcion}</td>
            <td>$${item.p_unit.toFixed(2)}</td>
            <td><input type="number" value="${item.cant}" min="1" step="any" style="width:70px" onchange="cambiarCant(${index}, this.value)"></td>
            <td>$${item.total.toFixed(2)}</td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="eliminarItem(${index})">X</button></td>
        `;
        tbody.appendChild(tr);
    });
    actualizarTotal();
}

window.cambiarCant = function(index, valor) {
    let n = parseFloat(valor);
    if (isNaN(n) || n <= 0) n = 1;
    carrito[index].cant = n;
    carrito[index].total = n * carrito[index].p_unit;
    renderizarCarrito();
}

window.eliminarItem = function(index) {
    carrito.splice(index, 1);
    renderizarCarrito();
}

function actualizarTotal() {
    const total = carrito.reduce((sum, item) => sum + item.total, 0);
    const display = document.getElementById('total_venta_display');
    const inputDetalle = document.getElementById('detalle_productos_input');
    
    if (display) display.innerText = `$ ${total.toFixed(2)}`;
    if (inputDetalle) inputDetalle.value = JSON.stringify(carrito);

    calcularVuelto();
}

// --- LÓGICA DE VUELTO ---
const inputEfectivo = document.getElementById('pago_efectivo');
const inputTransf = document.getElementById('pago_transf');
const selectCond = document.getElementById('cond_pago');

if (inputEfectivo) inputEfectivo.addEventListener('input', calcularVuelto);
if (inputTransf) inputTransf.addEventListener('input', calcularVuelto);
if (selectCond) selectCond.addEventListener('change', calcularVuelto);

function calcularVuelto() {
    const displayTotal = document.getElementById('total_venta_display');
    const condicion = document.getElementById('cond_pago').value;
    if (!displayTotal) return;

    const total = parseFloat(displayTotal.innerText.replace('$ ', '').replace(',', '')) || 0;
    const pagoEfe = parseFloat(document.getElementById('pago_efectivo').value) || 0;
    const pagoTra = parseFloat(document.getElementById('pago_transf').value) || 0;
    
    const totalPagado = pagoEfe + pagoTra;
    const vueltoContainer = document.getElementById('vuelto_container');
    const vueltoDisplay = document.getElementById('vuelto_display');

    if (condicion === 'CONTADO' && totalPagado > total && total > 0) {
        const vuelto = totalPagado - total;
        if (vueltoDisplay) {
            vueltoDisplay.innerText = '$ ' + vuelto.toLocaleString('es-AR', { minimumFractionDigits: 2 });
        }
        if (vueltoContainer) vueltoContainer.style.display = 'block';
    } else {
        if (vueltoContainer) vueltoContainer.style.display = 'none';
    }
}

// --- GESTIÓN DE PENDIENTES ---
const btnPendiente = document.getElementById('btnGuardarPendiente');
if (btnPendiente) {
    btnPendiente.onclick = () => {
        const form = document.getElementById('formVenta');
        if (carrito.length === 0) { 
            alert("El carrito está vacío."); 
            return; 
        }
        document.getElementById('venta_action_input').value = 'Pendiente';
        form.submit();
    };
}

window.reanudarVenta = function(nDocumento) {
    fetch(`../ajax/obtener_detalle_venta.php?n_documento=${nDocumento}&formato=json`)
        .then(res => res.json())
        .then(data => {
            if (!data.productos || data.productos.length === 0) {
                alert("La venta no tiene productos.");
                return;
            }
            carrito = [];
            data.productos.forEach(p => {
                carrito.push({
                    cod_prod: p.cod_prod,
                    descripcion: p.descripcion,
                    p_unit: parseFloat(p.p_unit),
                    p_costo: parseFloat(p.p_costo) || 0,
                    cant: parseFloat(p.cant),
                    total: parseFloat(p.total)
                });
            });

            const inputIdVenta = document.getElementById('id_venta_existente');
            if (inputIdVenta) inputIdVenta.value = data.cabecera.id;

            const idHidden = document.getElementById('id_cliente_hidden');
            const nombreDisplay = document.getElementById('nombre_cliente_display');

            if (idHidden) idHidden.value = data.cabecera.id_cliente || "0";

            if (nombreDisplay) {
                const nombre = data.cabecera.cliente_nombre_completo || 'Público General';
                if (nombreDisplay.tagName === 'INPUT') {
                    nombreDisplay.value = nombre;
                } else {
                    nombreDisplay.innerText = nombre;
                }
            }
            renderizarCarrito();
            cerrarModalPendientes();
        })
        .catch(err => {
            console.error("Error al reanudar:", err);
            alert("Error al obtener los datos de la venta.");
        });
};

document.addEventListener('DOMContentLoaded', function() {
    const btnVer = document.getElementById('btnVerPendiente');
    if (btnVer) {
        btnVer.addEventListener('click', function() {
            const modal = document.getElementById('pendientesModal');
            const lista = document.getElementById('listaPendientes');
            modal.style.display = 'block';
            lista.innerHTML = '<p>Cargando ventas...</p>';

            fetch('../ajax/ventas_pendientes_ajax.php')
                .then(response => response.text())
                .then(html => { lista.innerHTML = html; })
                .catch(error => { lista.innerHTML = 'Error al cargar los datos.'; });
        });
    }
});

function cerrarModalPendientes() {
    const modal = document.getElementById('pendientesModal');
    if (modal) modal.style.display = 'none';
}

// --- VALIDACIÓN DE VENTA FINAL ---
const formVenta = document.getElementById('formVenta');
if (formVenta) {
    formVenta.addEventListener('submit', function(e) {
        const condicion = document.getElementById('cond_pago').value; 
        const idCliente = document.getElementById('id_cliente_hidden').value;
        const action = document.getElementById('venta_action_input').value;

        if (action === 'Finalizar' && condicion === 'CUENTA CORRIENTE') {
            if (idCliente == "0" || idCliente === "" || !idCliente) {
                e.preventDefault();
                alert("⛔ Error: Para vender en CUENTA CORRIENTE debes seleccionar un cliente real.");
                return false;
            }
        }
    });
}