// js/ventarapida.js — Venta Rápida Supermarket PRO
let carrito = [];
let pagoSeleccionado = 'efectivo';
let pagoEfectivo = 0;
let pagoTransf = 0;

let productoInput = null;
let resultadosProd = null;
let inputBuscarCli = null;
let resultadosCli = null;

// --- DÓLAR OPERATIVO ---
(function initDolarOperativo() {
    try {
        if (typeof window.dolar_operativo === 'number' && window.dolar_operativo > 0) return;
        fetch(APP_BASE + 'cache/dolar_cache.json', { cache: 'no-store' })
            .then(r => r.json())
            .then(d => {
                if (d && typeof d.venta === 'number' && d.venta > 0) {
                    window.dolar_operativo = d.venta * 1.02;
                }
            })
            .catch(() => {});
    } catch (e) {}
})();

// --- INICIALIZACIÓN ---
document.addEventListener('DOMContentLoaded', function() {
    productoInput = document.getElementById('producto_input');
    resultadosProd = document.getElementById('resultadosBusqueda');
    inputBuscarCli = document.getElementById('buscar_cliente');
    resultadosCli = document.getElementById('resultadosBusquedaClientes');

    const efInput = document.getElementById('monto_efectivo_input');
    const trInput = document.getElementById('monto_transferencia_input');
    if (efInput) efInput.value = '0.00';
    if (trInput) trInput.value = '0.00';

    actualizarCarritoDisplay();
    setPago('efectivo');

    // --- INPUT UNIFICADO: ESCANEO + BÚSQUEDA ---
    if (productoInput) {
        productoInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                const codigo = productoInput.value.trim().toUpperCase();
                if (codigo) buscarYAgregarPorCodigo(codigo);
            }
        });

        let debounce;
        productoInput.addEventListener('input', function() {
            const q = productoInput.value.trim();
            clearTimeout(debounce);
            if (q.length < 1) {
                if (resultadosProd) resultadosProd.innerHTML = '';
                return;
            }
            debounce = setTimeout(() => buscarProductosPorNombre(q), 300);
        });

        productoInput.addEventListener('blur', function() {
            setTimeout(() => { if (resultadosProd) resultadosProd.innerHTML = ''; }, 200);
        });
    }

    // --- BÚSQUEDA DE CLIENTES ---
    if (inputBuscarCli && resultadosCli) {
        inputBuscarCli.addEventListener('input', function() {
            const busqueda = this.value.toLowerCase().trim();
            resultadosCli.innerHTML = '';
            if (busqueda.length < 2) {
                resultadosCli.style.display = 'none';
                return;
            }
            resultadosCli.style.display = 'block';

            const filtrados = clientesData.filter(c =>
                (c.nombre_completo && c.nombre_completo.toLowerCase().includes(busqueda)) ||
                (c.num_documento && c.num_documento.includes(busqueda))
            );

            filtrados.forEach(cliente => {
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.innerHTML =
                    '<span class="prod-code">' + cliente.nombre_completo + '</span>' +
                    '<span class="prod-desc">' + (cliente.num_documento || '') + '</span>';
                div.onclick = () => seleccionarCliente(cliente);
                resultadosCli.appendChild(div);
            });
        });

        inputBuscarCli.addEventListener('blur', function() {
            setTimeout(() => {
                resultadosCli.innerHTML = '';
                resultadosCli.style.display = 'none';
            }, 200);
        });
    }

    // --- VALIDACIÓN DE VENTA ---
    const formVenta = document.getElementById('formVenta');
    if (formVenta) {
        formVenta.addEventListener('submit', function(e) {
            if (carrito.length === 0) {
                e.preventDefault();
                mostrarToast('Agregue productos al carrito antes de finalizar.', 'error');
                if (productoInput) productoInput.focus();
                return;
            }

            sincronizarPagos();

            const condicion = document.getElementById('cond_pago').value;
            const idCliente = document.getElementById('id_cliente_hidden').value;
            if (condicion === 'CUENTA CORRIENTE' && (!idCliente || idCliente == '0')) {
                e.preventDefault();
                mostrarToast('Para CUENTA CORRIENTE seleccione un cliente.', 'error');
                return;
            }

            const total = parseFloat(document.getElementById('total_venta_input').value) || 0;
            if (condicion !== 'CUENTA CORRIENTE' && (pagoEfectivo + pagoTransf) < total - 0.01) {
                e.preventDefault();
                mostrarToast('El pago ingresado no cubre el total.', 'error');
                return;
            }

            const sinStock = carrito.filter(item => (parseFloat(item.cant) || 0) > (item.stock_actual || 0));
            if (sinStock.length > 0) {
                const nombres = sinStock.map(i => i.descripcion).join(', ');
                if (!confirm('⚠️ Los siguientes productos tienen stock insuficiente: ' + nombres + '\n\n¿Continuar de todos modos?')) {
                    e.preventDefault();
                    return;
                }
            }
        });
    }
});

// --- BUSCAR Y AGREGAR POR CÓDIGO DE BARRA (Enter en el input unificado) ---
function buscarYAgregarPorCodigo(codigo) {
    const url = APP_BASE + 'pages/buscar_producto_codigo_ajax.php?codigo=' + encodeURIComponent(codigo);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.producto) {
                const prod = data.producto;
                agregarAlCarrito({
                    cod_prod: prod.cod_prod,
                    descripcion: prod.descripcion,
                    p_venta: prod.p_venta,
                    p_compra: prod.p_compra,
                    p_venta_pesos: prod.p_venta_pesos,
                    moneda: prod.moneda,
                    stock_actual: parseFloat(prod.stock || 0)
                });
                productoInput.value = '';
                productoInput.focus();
            } else {
                productoInput.value = '';
                productoInput.classList.add('error');
                productoInput.placeholder = '❌ ' + (data.error || 'Producto no encontrado');
                setTimeout(() => {
                    productoInput.classList.remove('error');
                    productoInput.placeholder = 'Posicioná el cursor, escaneá código y presioná ENTER...';
                }, 2000);
                productoInput.focus();
                mostrarToast(data.error || 'Producto no encontrado', 'error');
            }
        })
        .catch(() => {
            console.error('Error en buscarYAgregarPorCodigo');
            mostrarToast('Error de conexión al buscar el producto.', 'error');
        });
}

// --- BUSCAR POR NOMBRE/DESCRIPCIÓN (debounced input) ---
function buscarProductosPorNombre(q) {
    const url = APP_BASE + 'pages/buscar_producto_ajax.php?q=' + encodeURIComponent(q);

    fetch(url)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!resultadosProd) return;
            resultadosProd.innerHTML = '';
            if (!Array.isArray(data) || data.length === 0) {
                resultadosProd.innerHTML = '<div class="search-result-item" style="cursor:default; color:#667;">No se encontraron productos.</div>';
                return;
            }

            data.forEach(prod => {
                const stock = parseInt(prod.stock) || 0;
                const stockTxt = stock <= 0 ? 'SIN STOCK' : 'Stock: ' + stock;

                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.innerHTML =
                    '<span class="prod-code">' + prod.cod_prod + '</span>' +
                    '<span class="prod-desc">' + prod.descripcion + '</span>' +
                    '<span class="prod-stock">' + stockTxt + '</span>';
                div.onclick = () => {
                    agregarAlCarrito({
                        cod_prod: prod.cod_prod,
                        descripcion: prod.descripcion,
                        p_venta: prod.p_venta,
                        p_compra: prod.p_compra,
                        p_venta_pesos: prod.p_venta_pesos,
                        moneda: prod.moneda,
                        stock_actual: parseFloat(prod.stock || 0)
                    });
                    productoInput.value = '';
                    resultadosProd.innerHTML = '';
                    productoInput.focus();
                };
                resultadosProd.appendChild(div);
            });
        })
        .catch(err => {
            console.error('Error en buscarProductosPorNombre:', err);
            if (resultadosProd) {
                resultadosProd.innerHTML = '<div class="search-result-item" style="cursor:default; color:#d32;">Error de conexión.</div>';
            }
        });
}

// --- AGREGAR AL CARRITO (el precio llega y se guarda en PESOS) ---
function agregarAlCarrito(prod) {
    let pVenta = parseFloat(prod.p_venta) || 0;
    let pCosto = parseFloat(prod.p_compra) || 0;

    if (prod.moneda === 'dolar') {
        const enPesos = parseFloat(prod.p_venta_pesos) || 0;
        if (enPesos > 0) {
            const factor = pVenta > 0 ? enPesos / pVenta : 1;
            pVenta = enPesos;
            if (pCosto > 0) pCosto = pCosto * factor;
        } else {
            const dolarOp = (typeof window.dolar_operativo === 'number' && window.dolar_operativo > 0)
                ? window.dolar_operativo : null;
            if (dolarOp) {
                pVenta = pVenta * dolarOp;
                pCosto = pCosto * dolarOp;
            }
        }
    }

    const existe = carrito.find(item => item.cod_prod === prod.cod_prod);
    if (existe) {
        existe.cant += 1;
        existe.total = existe.cant * existe.p_unit;
    } else {
        carrito.push({
            cod_prod: prod.cod_prod,
            descripcion: prod.descripcion,
            p_unit: pVenta,
            p_costo_venta: pCosto,
            cant: 1,
            desc: 0,
            total: pVenta,
            stock_actual: prod.stock_actual || 0
        });
    }

    actualizarCarritoDisplay();
    productoInput.value = '';
    productoInput.focus();
}

// --- RENDERIZAR CARRITO ---
function actualizarCarritoDisplay() {
    const tbody = document.getElementById('carrito_body');
    const countEl = document.getElementById('cart-count');
    if (countEl) countEl.textContent = carrito.length;
    if (!tbody) return;

    if (carrito.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-cart"><i class="fas fa-barcode"></i><p>Escanee o busque productos para comenzar.</p></td></tr>';
    } else {
        tbody.innerHTML = '';
        carrito.forEach((item, index) => {
            const tr = document.createElement('tr');
            const stockActual = item.stock_actual || 0;
            const stockWarn = item.cant > stockActual ? ' style="color:#d32f2f;"' : '';

            tr.innerHTML =
                '<td class="prod-code">' + item.cod_prod + '</td>' +
                '<td class="prod-desc">' + item.descripcion + '</td>' +
                '<td class="prod-precio">$' + parseFloat(item.p_unit).toFixed(2) + '</td>' +
                '<td class="prod-cant"><input type="number" min="1" step="1" value="' + item.cant + '" class="cant-input"' +
                ' onchange="cambiarCant(' + index + ', this.value)"' +
                ' onkeypress="return event.keyCode>=48 && event.keyCode<=57"></td>' +
                '<td class="prod-total"' + stockWarn + '>$' + parseFloat(item.total).toFixed(2) + '</td>' +
                '<td style="text-align:center; width:30px;">' +
                '<button type="button" class="remove-item" onclick="eliminarItem(' + index + ')" title="Eliminar">✕</button></td>';
            tbody.appendChild(tr);
        });
    }

    actualizarTotal();
}

window.cambiarCant = function(index, valor) {
    let n = parseInt(valor);
    if (isNaN(n) || n <= 0) n = 1;
    carrito[index].cant = n;
    carrito[index].total = n * carrito[index].p_unit;
    actualizarCarritoDisplay();
};

window.eliminarItem = function(index) {
    carrito.splice(index, 1);
    actualizarCarritoDisplay();
    if (productoInput) productoInput.focus();
};

window.vaciarCarrito = function() {
    if (carrito.length === 0) return;
    if (confirm('¿Vaciar el carrito?')) {
        carrito = [];
        actualizarCarritoDisplay();
        if (productoInput) productoInput.focus();
    }
};

// --- CÁLCULO DE TOTALES ---
function actualizarTotal() {
    const itemsTotal = carrito.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
    const totalDisplay = document.getElementById('total_venta_display');
    const totalInput = document.getElementById('total_venta_input');
    const detalleInput = document.getElementById('detalle_productos_input');

    if (totalDisplay) totalDisplay.innerText = '$ ' + formatMoney(itemsTotal);
    if (totalInput) totalInput.value = itemsTotal.toFixed(2);
    if (detalleInput) detalleInput.value = JSON.stringify(carrito);

    // Auto-llenar SOLO el método de pago activo (si no fue editado manualmente)
    const efInput = document.getElementById('monto_efectivo_input');
    const trInput = document.getElementById('monto_transferencia_input');
    if (pagoSeleccionado === 'efectivo') {
        if (efInput && !efInput.classList.contains('manual-edit')) efInput.value = itemsTotal.toFixed(2);
    } else {
        if (trInput && !trInput.classList.contains('manual-edit')) trInput.value = itemsTotal.toFixed(2);
    }

    sincronizarPagos();
    calcularVuelto();
}

// --- PAGO: seleccionar método ---
window.setPago = function(tipo) {
    pagoSeleccionado = tipo;

    const btnEfectivo = document.querySelector('#paymentMethods .payment-method');
    if (btnEfectivo) btnEfectivo.classList.toggle('active', tipo === 'efectivo');
    const btnTransferencia = document.querySelector('#paymentMethods .payment-method:nth-child(2)');
    if (btnTransferencia) btnTransferencia.classList.toggle('active', tipo === 'transferencia');

    const efWrapper = document.getElementById('efectivoInputGroup');
    const trWrapper = document.getElementById('transferenciaInputGroup');
    if (efWrapper) efWrapper.style.display = tipo === 'efectivo' ? 'block' : 'none';
    if (trWrapper) trWrapper.style.display = tipo === 'transferencia' ? 'block' : 'none';

    const total = parseFloat(document.getElementById('total_venta_input').value) || 0;
    const efInput = document.getElementById('monto_efectivo_input');
    const trInput = document.getElementById('monto_transferencia_input');

    if (tipo === 'efectivo') {
        if (efInput) { efInput.classList.remove('manual-edit'); efInput.value = total.toFixed(2); }
        if (trInput) { trInput.value = '0.00'; trInput.classList.add('manual-edit'); }
    } else {
        if (trInput) { trInput.classList.remove('manual-edit'); trInput.value = total.toFixed(2); }
        if (efInput) { efInput.value = '0.00'; efInput.classList.add('manual-edit'); }
    }

    sincronizarPagos();
    calcularVuelto();
};

// --- LECTURA DE INPUTS DE PAGO ---
window.calcularPago = function(el) {
    if (el) el.classList.add('manual-edit');
    sincronizarPagos();
    calcularVuelto();
};

// --- SINCRONIZAR HIDDEN INPUTS DE PAGO ---
function sincronizarPagos() {
    const efInput = document.getElementById('monto_efectivo_input');
    const trInput = document.getElementById('monto_transferencia_input');
    pagoEfectivo = parseFloat(efInput?.value || 0) || 0;
    pagoTransf  = parseFloat(trInput?.value || 0) || 0;

    const pagoEfHidden = document.getElementById('pago_efectivo');
    const pagoTraHidden = document.getElementById('pago_transf');
    if (pagoEfHidden) pagoEfHidden.value = pagoEfectivo.toFixed(2);
    if (pagoTraHidden) pagoTraHidden.value = pagoTransf.toFixed(2);
}

// --- CÁLCULO DE VUELTO ---
function calcularVuelto() {
    const totalEl = document.getElementById('total_venta_input');
    const condEl = document.getElementById('cond_pago');
    const vueltoDisplay = document.getElementById('vueltoDisplay');
    if (!totalEl || !condEl || !vueltoDisplay) return;

    const total = parseFloat(totalEl.value) || 0;
    const condicion = condEl.value;

    if (condicion === 'CUENTA CORRIENTE') {
        vueltoDisplay.innerText = '$ 0.00';
        vueltoDisplay.style.color = '#667085';
        return;
    }

    const pagoEfe = parseFloat(document.getElementById('pago_efectivo').value) || 0;
    const pagoTra = parseFloat(document.getElementById('pago_transf').value) || 0;
    const vuelto = (pagoEfe + pagoTra) - total;

    if (total === 0) {
        vueltoDisplay.innerText = '$ 0.00';
        vueltoDisplay.style.color = '#667085';
    } else if (vuelto > 0.01) {
        vueltoDisplay.innerText = '$ ' + formatMoney(vuelto);
        vueltoDisplay.style.color = '#00d897';
    } else if (vuelto < -0.01) {
        vueltoDisplay.innerText = 'Falta $ ' + formatMoney(Math.abs(vuelto));
        vueltoDisplay.style.color = '#d32f2f';
    } else {
        vueltoDisplay.innerText = '$ 0.00';
        vueltoDisplay.style.color = '#667085';
    }
}

// --- SELECCIONAR CLIENTE ---
function seleccionarCliente(cliente) {
    const idHidden = document.getElementById('id_cliente_hidden');
    const nombreDisplay = document.getElementById('nombre_cliente_display');
    if (idHidden) idHidden.value = cliente.id_cliente;
    if (nombreDisplay) nombreDisplay.textContent = cliente.nombre_completo || 'Venta Genérica';

    if (inputBuscarCli) inputBuscarCli.value = '';
    if (resultadosCli) {
        resultadosCli.innerHTML = '';
        resultadosCli.style.display = 'none';
    }
}

// --- TOAST ---
function mostrarToast(mensaje, tipo = 'success') {
    const oldToast = document.querySelector('.toast');
    if (oldToast) oldToast.remove();

    const toast = document.createElement('div');
    toast.className = 'toast';
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle' };
    toast.innerHTML = '<i class="fas ' + (icons[tipo] || 'fa-info-circle') + '"></i> ' + mensaje;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// --- FORMATO DE MONTO ---
function formatMoney(n) {
    return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}