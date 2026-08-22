// Base URL para URLs limpias del router
// Base URL dinámica: usa el primer segmento del path (pos_dev, pos_prod, etc.)
const APP_BASE = (function () {
    var seg = (window.location.pathname || '/').split('/').filter(function (s) { return s !== ''; });
    return window.location.origin + '/' + (seg[0] ? seg[0] + '/' : '');
})();
// js/ventas.js
console.log("Ventas.js cargado correctamente");

let carrito = [];

// Selectores (se inicializarán cuando el DOM esté listo)
let inputBuscarProd, resultadosProd, inputBuscarCli, resultadosCli;
let productosSugeridos = [];
let resultadoIdx = -1;

// --- 0. COTIZACIÓN DÓLAR OPERATIVO (para mostrar productos en USD en pesos) ---
(function initDolarOperativo() {
    try {
        // topbar.php calcula operativo como venta * 1.02
        // Igual lo obtenemos desde la caché para no depender del DOM.
        const cache = window.dolar_operativo_cache || null;
        if (typeof window.dolar_operativo === 'number' && window.dolar_operativo > 0) return;

        // Intentar leer cache/dolar_cache.json (contiene {compra, venta})
        fetch(APP_BASE + 'cache/dolar_cache.json', { cache: 'no-store' })
            .then(r => r.json())
            .then(d => {
                if (d && typeof d.venta === 'number' && d.venta > 0) {
                    // Operativo = venta * 1.02 (como indica topbar.php)
                    window.dolar_operativo = d.venta * 1.02;
                }
            })
            .catch(()=>{});
    } catch (e) {}
})();


// --- 3. FUNCIONES DEL CARRITO ---
// Unidades a granel (Kg / Mt / Lt): permiten vender por importe
const ABREVIATURAS = { 'Kilogramo': 'Kg', 'Metro': 'Mt', 'Litro': 'Lt' };
function esGranel(item) {
    return !!ABREVIATURAS[item.unidad_medida];
}
function abrevUnidad(item) {
    return ABREVIATURAS[item.unidad_medida] || 'u';
}
function esc(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
}

function agregarAlCarrito(prod) {
    const existe = carrito.find(item => item.cod_prod === prod.cod_prod);
let pVenta = parseFloat(prod.p_venta) || 0;
    let pCosto = parseFloat(prod.p_compra) || parseFloat(prod.p_costo) || 0;

    // Si el producto viene marcado en dólares, convertimos para mostrar en pesos.
    // (Moneda esperada: prod.moneda === 'dolar')
    if (prod.moneda === 'dolar') {
        const dolarOperativo = (typeof window.dolar_operativo === 'number' && window.dolar_operativo > 0)
            ? window.dolar_operativo
            : null;

        if (dolarOperativo) {
            pVenta = pVenta * dolarOperativo;
            pCosto = pCosto * dolarOperativo;
        }
    }


    if (existe) {
        // Si existe, aumentamos cantidad y lo movemos al principio para que sea visible
        existe.cant++;
        existe.total = existe.cant * existe.p_unit;
        const index = carrito.indexOf(existe);
        carrito.splice(index, 1);
        carrito.unshift(existe);
    } else {
        carrito.unshift({ // unshift agrega al inicio del array
            cod_prod: prod.cod_prod,
            descripcion: prod.descripcion,
            unidad_medida: prod.unidad_medida || 'Unidad',
            p_unit: pVenta,
            p_costo: pCosto,
            cant: 1,
            desc: 0,
            total: pVenta
        });
    }
    renderizarCarrito();
}

// Inicializar selectores cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded ejecutado');
    
    // Inicializar selectores del DOM
    inputBuscarProd = document.getElementById('buscar_producto');
    resultadosProd = document.getElementById('resultadosBusqueda');
    inputBuscarCli = document.getElementById('buscar_cliente');
    resultadosCli = document.getElementById('resultadosBusquedaClientes');
    
    console.log('inputBuscarCli:', inputBuscarCli);
    console.log('resultadosCli:', resultadosCli);
    console.log('clientesData:', typeof clientesData !== 'undefined' ? 'definido' : 'NO definido');

    // --- 1. BUSCADOR DE PRODUCTOS ---
    if (inputBuscarProd) {
        inputBuscarProd.addEventListener('input', function() {
            const q = this.value.trim();
            if (q.length < 2) {
                resultadosProd.innerHTML = '';
                productosSugeridos = [];
                resultadoIdx = -1;
                return;
            }

            fetch(APP_BASE + 'pages/buscar_producto_ajax.php?q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    resultadosProd.innerHTML = '';
                    productosSugeridos = [];
                    data.forEach(prod => {
                        productosSugeridos.push(prod);
                        const listIdx = productosSugeridos.length - 1;
                        const div = document.createElement('div');
                        div.className = 'resultado-item';
                        div.dataset.idx = listIdx;
                        div.style.cursor = 'pointer';
                        div.style.padding = '8px';
                        div.style.borderBottom = '1px solid #333';

                        const stockColor = prod.stock <= 0 ? 'red' : 'green';
                        const stockTexto = prod.stock <= 0 ? 'SIN STOCK' : prod.stock;

                        // Preparamos display de precios:
                        // - Si moneda=dolar: mostrar USD a la izquierda y Pesos a la derecha/abajo.
                        // - Si moneda=pesos: mostrar solo Pesos (sin referencia a USD).
                        const precioVentaUSD = parseFloat(prod.p_venta) || 0;
                        const precioVentaARS = (prod.p_venta_pesos !== null && prod.p_venta_pesos !== undefined)
                            ? parseFloat(prod.p_venta_pesos)
                            : null;
                        // (si moneda=dolar) en la lista se muestra USD y Pesos
                        const esDolar = (prod.moneda === 'dolar');

                        div.innerHTML = `
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span><strong>${prod.cod_prod}</strong> - ${prod.descripcion}</span>
                                <span style="font-size: 0.9em; text-align:right; display:flex; gap:10px; align-items:flex-start; justify-content:flex-end;">
                                    ${esDolar ? `<span style="white-space:nowrap;color:#2ecc71">$${precioVentaUSD.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (USD)</span>` : ''}
                                    <span style="white-space:nowrap;color:#3498db">$${(precioVentaARS !== null ? precioVentaARS : precioVentaUSD).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (Pesos)</span>

                                    <b style="color: ${stockColor};">Stock: ${stockTexto}</b>
                                </span>
                            </div>
                        `;

                        div.addEventListener('mouseenter', () => {
                            resultadoIdx = listIdx;
                            resaltarResultadosVentas();
                        });
                        div.onclick = () => {
                            agregarAlCarrito(prod);
                            inputBuscarProd.value = '';
                            resultadosProd.innerHTML = '';
                            productosSugeridos = [];
                            resultadoIdx = -1;
                        };
                        resultadosProd.appendChild(div);
                    });
                    if (productosSugeridos.length > 0) {
                        resultadoIdx = 0;
                        resaltarResultadosVentas();
                    }
                })
                .catch(err => console.error("Error en Fetch Productos:", err));
        });

        inputBuscarProd.addEventListener('keydown', function(e) {
            const items = resultadosProd.querySelectorAll('.resultado-item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (items.length === 0) return;
                resultadoIdx = (resultadoIdx + 1) % items.length;
                resaltarResultadosVentas();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length === 0) return;
                resultadoIdx = (resultadoIdx - 1 + items.length) % items.length;
                resaltarResultadosVentas();
            } else if (e.key === 'Enter') {
                if (productosSugeridos.length > 0 && resultadoIdx >= 0 && resultadoIdx < productosSugeridos.length) {
                    e.preventDefault();
                    agregarAlCarrito(productosSugeridos[resultadoIdx]);
                    inputBuscarProd.value = '';
                    resultadosProd.innerHTML = '';
                    productosSugeridos = [];
                    resultadoIdx = -1;
                }
            }
        });
    }

    // --- 1b. HIGHLIGHT DE RESULTADOS (teclado) ---
    function resaltarResultadosVentas() {
        const items = resultadosProd.querySelectorAll('.resultado-item');
        items.forEach((it, i) => {
            it.classList.toggle('resaltado', i === resultadoIdx);
        });
        const actual = items[resultadoIdx];
        if (actual) actual.scrollIntoView({ block: 'nearest' });
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
                    
                    // Mostrar solo el nombre y documento (sin saldo en la lista)
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
                const condPagoSelect = document.getElementById('cond_pago');
                
                if (idHidden) idHidden.value = cliente.id_cliente;
                
                // Validar si el cliente tiene habilitada la cuenta corriente
                const habilitaCta = (cliente.habilita_cta || '').toUpperCase();
                const esCuentaCorriente = condPagoSelect && condPagoSelect.value === 'CUENTA CORRIENTE';
                
                if (esCuentaCorriente && habilitaCta === 'NO') {
                    mostrarToast("⛔ Este cliente no tiene habilitada la cuenta corriente.", "error");
                    // Limpiar selección
                    if (idHidden) idHidden.value = '0';
                    if (nombreDisplay) {
                        if (nombreDisplay.tagName === 'INPUT') {
                            nombreDisplay.value = '';
                        } else {
                            nombreDisplay.innerHTML = 'Venta Genérica';
                        }
                    }
                    inputBuscarCli.value = '';
                    resultadosCli.innerHTML = '';
                    resultadosCli.style.display = 'none';
                    return;
                }
                
                if (nombreDisplay) {
                    // Mostrar nombre + saldo deudor si existe
                    const saldo = parseFloat(cliente.saldo_deudor) || 0;
                    let nombreMostrado = cliente.nombre_completo;
                    
                    if (saldo > 0) {
                        nombreMostrado += ` <span style="color: #e74c3c;"><i class="fas fa-exclamation-circle"></i> Saldo: -$${saldo.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>`;
                    }
                    
                    if (nombreDisplay.tagName === 'INPUT') {
                        nombreDisplay.value = cliente.nombre_completo;
                    } else {
                        nombreDisplay.innerHTML = nombreMostrado;
                    }
                }

                inputBuscarCli.value = '';
                resultadosCli.innerHTML = '';
                resultadosCli.style.display = 'none';
            }
        });
    }

    // Listeners para recalcular todo cuando cambian los valores de financiación
    const ids = ['cuotas_selector', 'intervalo_cuotas', 'interes_manual', 'pago_efectivo', 'pago_transf', 'cond_pago', 'desc_global_tipo', 'desc_global_valor', 'cobrar_primera_hoy'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', actualizarTotal);
            el.addEventListener('change', actualizarTotal);
        }
    });

    // Sincronizar el textarea de observaciones (debajo del carrito) con el campo hidden del formulario
    const obsTextarea = document.getElementById('observaciones');
    const obsHidden = document.getElementById('observaciones_hidden');
    if (obsTextarea && obsHidden) {
        const sincronizarObservaciones = function() {
            obsHidden.value = obsTextarea.value;
        };
        obsTextarea.addEventListener('input', sincronizarObservaciones);
        obsTextarea.addEventListener('change', sincronizarObservaciones);
        sincronizarObservaciones();
    }

    // Validar cuenta corriente cuando cambia la condición de pago
    const condPagoSelect = document.getElementById('cond_pago');
    if (condPagoSelect) {
        condPagoSelect.addEventListener('change', function() {
            const condicion = this.value;
            const idCliente = document.getElementById('id_cliente_hidden').value;
            const nombreDisplay = document.getElementById('nombre_cliente_display');
            
            // Si cambia a CUENTA CORRIENTE y hay un cliente seleccionado
            if (condicion === 'CUENTA CORRIENTE' && idCliente && idCliente != "0") {
                const clienteSeleccionado = clientesData.find(c => c.id_cliente == idCliente);
                if (clienteSeleccionado) {
                    const habilitaCta = (clienteSeleccionado.habilita_cta || '').toUpperCase();
                    if (habilitaCta === 'NO') {
                        mostrarToast("⛔ Este cliente no tiene habilitada la cuenta corriente.", "error");
                        // Limpiar selección del cliente
                        document.getElementById('id_cliente_hidden').value = '0';
                        if (nombreDisplay) {
                            if (nombreDisplay.tagName === 'INPUT') {
                                nombreDisplay.value = '';
                            } else {
                                nombreDisplay.innerHTML = 'Venta Genérica';
                            }
                        }
                        document.getElementById('buscar_cliente').value = '';
                        document.getElementById('resultadosBusquedaClientes').innerHTML = '';
                        document.getElementById('resultadosBusquedaClientes').style.display = 'none';
                    }
                }
            }
        });
    }

    // Botón "Ver Plan de Cuotas" - usar onclick directo para asegurar funcionamiento
    const btnVerPlan = document.getElementById('btnVerPlanCuotas');
    if (btnVerPlan) {
        btnVerPlan.onclick = function(e) {
            e.preventDefault();
            window.mostrarPlanCuotas();
        };
    }

    // --- LÓGICA DE VUELTO ---
    const inputEfectivo = document.getElementById('pago_efectivo');
    const inputTransf = document.getElementById('pago_transf');
    const selectCond = document.getElementById('cond_pago');

    if (inputEfectivo) inputEfectivo.addEventListener('input', calcularVuelto);
    if (inputTransf) inputTransf.addEventListener('input', calcularVuelto);
    if (selectCond) selectCond.addEventListener('change', calcularVuelto);

    // --- GESTIÓN DE PENDIENTES ---
    const btnPendiente = document.getElementById('btnGuardarPendiente');
    if (btnPendiente) {
        btnPendiente.onclick = () => {
            const form = document.getElementById('formVenta');
            if (carrito.length === 0) { 
                mostrarMensaje("Carrito Vacío", "Debe agregar productos antes de guardar la venta.", "error");
                return; 
            }
            document.getElementById('venta_action_input').value = 'Pendiente';
            form.submit();
        };
    }

    // Botón ver pendientes
    const btnVerPendiente = document.getElementById('btnVerPendiente');
    if (btnVerPendiente) {
        btnVerPendiente.addEventListener('click', function() {
            const modal = document.getElementById('pendientesModal');
            const lista = document.getElementById('listaPendientes');
            modal.style.display = 'block';
            lista.innerHTML = '<p>Cargando ventas...</p>';

            fetch(APP_BASE + 'ajax/ventas_pendientes_ajax.php')
                .then(response => response.text())
                .then(html => { lista.innerHTML = html; })
                .catch(error => { lista.innerHTML = 'Error al cargar los datos.'; });
        });
    }
});

function renderizarCarrito() {
    const tbody = document.querySelector('#carrito tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    carrito.forEach((item, index) => {
        const tr = document.createElement('tr');

        // Flecha indicadora de variación de precio (solo para items copiados y aún no corregidos)
        let arrowHTML = '';
        if (typeof item.precio_presupuesto === 'number' && typeof item.precio_actual === 'number' && !item.precio_corregido) {
            if (item.precio_actual > item.precio_presupuesto) {
                arrowHTML = `<span style="cursor:pointer; margin-right:6px;" onclick="actualizarPrecioCarrito(${index})" title="Subió el precio: presupuesto $${item.precio_presupuesto.toFixed(2)} → actual $${item.precio_actual.toFixed(2)}. Clic para actualizar."><i class="fas fa-arrow-up" style="color:#3498db;"></i></span>`;
            } else if (item.precio_actual < item.precio_presupuesto) {
                arrowHTML = `<span style="cursor:pointer; margin-right:6px;" onclick="actualizarPrecioCarrito(${index})" title="Bajó el precio: presupuesto $${item.precio_presupuesto.toFixed(2)} → actual $${item.precio_actual.toFixed(2)}. Clic para actualizar."><i class="fas fa-arrow-down" style="color:#e67e22;"></i></span>`;
            }
        }
        let precioHTML = arrowHTML + `$${item.p_unit.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        if (esGranel(item)) precioHTML += `<span style="color:#f1c40f; font-size:.8em;">/${abrevUnidad(item)}</span>`;

        tr.innerHTML = `
            <td>${item.cod_prod}</td>
            <td>${item.descripcion}${esGranel(item) ? ` <span style="color:#f1c40f; font-size:.8em;">(${abrevUnidad(item)})</span>` : ''}</td>
            <td>${precioHTML}</td>

            <td><input type="number" value="${item.cant}" min="1" step="any" style="width: 60px !important; padding: 6px !important; margin: 0 !important; text-align: center;" onchange="cambiarCant(${index}, this.value)" ${esGranel(item) ? 'ondblclick="abrirModalImporte(' + index + ')" title="F2 o doble clic para vender por importe"' : ''}></td>
            <td>$${item.total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>

            <td><input type="number" value="${item.desc || 0}" min="0" max="100" step="1" style="width: 45px !important; padding: 6px !important; margin: 0 !important; text-align: center;" onchange="cambiarDesc(${index}, this.value)"></td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="eliminarItem(${index})">X</button></td>
        `;
        tbody.appendChild(tr);
    });
    actualizarTotal();
}

window.cambiarCant = function(index, valor) {
    let n = parseFloat(valor);
    if (isNaN(n) || n <= 0) {
        n = 1;
    }
    carrito[index].cant = n;
    let subtotal = n * carrito[index].p_unit;
    let descuentoMonto = subtotal * ((carrito[index].desc || 0) / 100);
    carrito[index].total = subtotal - descuentoMonto;
    renderizarCarrito();
}

// ================= MODAL IMPORTE (F2) =================
// F2 abre el modal para vender por importe el producto a granel
// (si hay varios en el carrito, permite elegir cuál). Doble clic en
// la celda de cantidad de un producto granel también lo abre.
let importeModalIdx = null;

function asegurarModalImporte() {
    if (document.getElementById('modalImporteOverlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'modalImporteOverlay';
    overlay.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:9999; align-items:center; justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#1a1a1a; border:1px solid #444; border-radius:10px; padding:25px; width:360px; box-shadow:0 10px 40px rgba(0,0,0,.6);">
            <h3 style="margin:0 0 5px 0; color:#00bcd4; font-size:1.05rem;"><i class="fas fa-balance-scale"></i> Vender por importe</h3>
            <div id="miProducto" style="color:#ccc; font-size:.85rem; margin-bottom:15px;"></div>
            <label style="display:block; color:#3498db; font-weight:bold; font-size:.85em; margin-bottom:5px;">Importe a cobrar ($)</label>
            <input type="number" id="miImporteInput" min="0" step="0.01" placeholder="0.00"
                   style="width:100%; padding:12px; font-size:1.3rem; border-radius:6px; border:1px solid #444; background:#222; color:#fff; box-sizing:border-box; text-align:center;">
            <div id="miCantidadCalc" style="color:#f1c40f; font-size:.85rem; margin-top:8px; min-height:1.2em;"></div>
            <div style="display:flex; gap:10px; margin-top:18px;">
                <button type="button" id="miBtnConfirmar" class="btn btn-primary" style="flex:2;">✔ Aplicar (Enter)</button>
                <button type="button" id="miBtnCancelar" class="btn btn-secondary" style="flex:1;">Cancelar (Esc)</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => { if (e.target === overlay) cerrarModalImporte(); });
    document.getElementById('miBtnCancelar').onclick = cerrarModalImporte;
    document.getElementById('miBtnConfirmar').onclick = confirmarModalImporte;
    document.getElementById('miImporteInput').addEventListener('input', recalcularPreviewImporte);
    document.getElementById('miImporteInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); confirmarModalImporte(); }
        if (e.key === 'Escape') { e.preventDefault(); cerrarModalImporte(); }
    });
}

function abrirModalImporte(idx) {
    // Si no se indica índice, usar el primer producto granel del carrito
    if (idx === undefined || idx === null) {
        for (let i = 0; i < carrito.length; i++) {
            if (esGranel(carrito[i])) { idx = i; break; }
        }
    }
    if (idx === undefined || idx === null || !carrito[idx] || !esGranel(carrito[idx])) {
        alert('No hay productos a granel (Kg/Mt/Lt) en el carrito.');
        return;
    }
    importeModalIdx = idx;
    asegurarModalImporte();

    // Lista de índices de productos granel (para navegar con flechas)
    window.granelesModal = carrito.map((it, i) => ({ it, i })).filter(x => esGranel(x.it)).map(x => x.i);

    renderizarInfoModal();

    const input = document.getElementById('miImporteInput');
    input.value = '';
    document.getElementById('miCantidadCalc').textContent = '';
    document.getElementById('modalImporteOverlay').style.display = 'flex';
    input.focus();
}
window.abrirModalImporte = abrirModalImporte;

// Muestra producto actual + selector (si hay varios) en el modal
function renderizarInfoModal() {
    const idx = importeModalIdx;
    if (idx === null || !carrito[idx]) return;
    const graneles = window.granelesModal || [];
    let selectorHTML = '';
    if (graneles.length > 1) {
        selectorHTML = '<select id="miSelectorProd" style="width:100%; padding:8px; border-radius:6px; border:1px solid #444; background:#222; color:#fff; margin-bottom:10px;">' +
            graneles.map(i => `<option value="${i}" ${i === idx ? 'selected' : ''}>${esc(carrito[i].descripcion)} ($${carrito[i].p_unit.toFixed(2)}/${abrevUnidad(carrito[i])})</option>`).join('') +
            '</select>' +
            '<div style="color:#888; font-size:.75rem; margin:-6px 0 8px 0;">↑/↓ para cambiar de producto</div>';
    }
    const item = carrito[idx];
    document.getElementById('miProducto').innerHTML = selectorHTML +
        `<b style="color:#fff;">${esc(item.descripcion)}</b><br>` +
        `Precio: $${item.p_unit.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / ${abrevUnidad(item)}` +
        (item.desc > 0 ? ` &middot; Desc: ${item.desc}%` : '');
    const sel = document.getElementById('miSelectorProd');
    if (sel) sel.onchange = function () {
        importeModalIdx = parseInt(this.value, 10);
        renderizarInfoModal();
        recalcularPreviewImporte();
        document.getElementById('miImporteInput').focus();
    };
}

// Cambia al producto granel anterior/siguiente con las flechas
function ciclarProductoGranel(direccion) {
    const lista = window.granelesModal || [];
    if (lista.length < 2) return;
    const pos = lista.indexOf(importeModalIdx);
    const nuevo = lista[(pos + direccion + lista.length) % lista.length];
    importeModalIdx = nuevo;
    renderizarInfoModal();
    recalcularPreviewImporte();
    document.getElementById('miImporteInput').focus();
}

// Recalcula la vista previa de cantidad según lo tipeado
function recalcularPreviewImporte() {
    const item = carrito[importeModalIdx];
    const monto = parseFloat(document.getElementById('miImporteInput').value);
    const calc = document.getElementById('miCantidadCalc');
    if (item && !isNaN(monto) && monto > 0 && item.p_unit > 0) {
        const descFactor = 1 - ((item.desc || 0) / 100);
        const cant = Math.round((monto / (item.p_unit * descFactor)) * 1000) / 1000;
        calc.textContent = '→ Cantidad: ' + cant + ' ' + abrevUnidad(item);
    } else {
        calc.textContent = '';
    }
}

function confirmarModalImporte() {
    const item = carrito[importeModalIdx];
    const monto = parseFloat(document.getElementById('miImporteInput').value);
    if (!item || isNaN(monto) || monto <= 0 || item.p_unit <= 0) { cerrarModalImporte(); return; }
    const descFactor = 1 - ((item.desc || 0) / 100);
    item.cant = Math.round((monto / (item.p_unit * descFactor)) * 1000) / 1000;
    item.total = item.cant * item.p_unit * descFactor;
    cerrarModalImporte();
    renderizarCarrito();
}

function cerrarModalImporte() {
    const overlay = document.getElementById('modalImporteOverlay');
    if (overlay) overlay.style.display = 'none';
    importeModalIdx = null;
    const inp = document.getElementById('buscar_producto');
    if (inp) inp.focus();
}
window.cerrarModalImporte = cerrarModalImporte;

// Manejo de teclado del modal: F2 abre; dentro funcionan ↑/↓ (cambiar producto),
// Enter (aplicar), Esc (cerrar) — sin importar dónde esté el foco.
document.addEventListener('keydown', function (e) {
    const overlay = document.getElementById('modalImporteOverlay');
    const abierto = overlay && overlay.style.display === 'flex';

    if (!abierto) {
        if (e.key === 'F2') {
            e.preventDefault();
            abrirModalImporte();
        }
        return;
    }

    if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === 'ArrowRight') {
        e.preventDefault();
        ciclarProductoGranel(1);
    } else if (e.key === 'ArrowUp' || e.key === 'PageUp' || e.key === 'ArrowLeft') {
        e.preventDefault();
        ciclarProductoGranel(-1);
    } else if (e.key === 'Enter') {
        // Si el foco está en el select, dejar que el usuario elija con flechas; no aplicar
        if (document.activeElement && document.activeElement.id !== 'miSelectorProd') {
            e.preventDefault();
            confirmarModalImporte();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        cerrarModalImporte();
    }
});

window.cambiarDesc = function(index, valor) {
    let d = parseFloat(valor);
    if (isNaN(d) || d < 0) d = 0;
    if (d > 100) d = 100;
    carrito[index].desc = d;
    let subtotal = carrito[index].cant * carrito[index].p_unit;
    carrito[index].total = subtotal * (1 - (d / 100));
    renderizarCarrito();
}

window.eliminarItem = function(index) {
    carrito.splice(index, 1);
    renderizarCarrito();
}

// Corrige el precio de un producto (copiado de presupuesto) al precio actual de la BD.
// Se dispara al hacer clic en la flecha azul (subió) o naranja (bajó).
window.actualizarPrecioCarrito = function(index) {
    const item = carrito[index];
    if (!item) return;
    if (item.precio_actual === null || item.precio_actual === undefined) {
        alert("No hay precio actual disponible para este producto.");
        return;
    }
    item.p_unit = parseFloat(item.precio_actual);
    item.precio_corregido = true;
    // Recalcular total respetando el descuento aplicado
    let subtotal = item.cant * item.p_unit;
    let descuentoMonto = subtotal * ((item.desc || 0) / 100);
    item.total = subtotal - descuentoMonto;
    renderizarCarrito();
    mostrarToast("✅ Precio de \"" + item.descripcion + "\" actualizado a $" + item.p_unit.toFixed(2) + ".");
}

function actualizarTotal() {
    let itemsTotal = carrito.reduce((sum, item) => sum + item.total, 0);

    // Aplicar Descuento Global
    const tipoDesc = document.getElementById('desc_global_tipo').value;
    const valorDesc = parseFloat(document.getElementById('desc_global_valor').value) || 0;
    let montoDescGlobal = (tipoDesc === 'porcentaje') ? (itemsTotal * (valorDesc / 100)) : valorDesc;
    
    let finalTotal = Math.max(0, itemsTotal - montoDescGlobal);

    const condPago = document.getElementById('cond_pago');
    const panelCuotas = document.getElementById('panel_financiacion');
    
    if (condPago && condPago.value === 'FINANCIADO') {
        if (panelCuotas) panelCuotas.style.display = 'block';
        
        const interesPorc = parseFloat(document.getElementById('interes_manual').value) || 0;
        const efe = parseFloat(document.getElementById('pago_efectivo').value) || 0;
        const tra = parseFloat(document.getElementById('pago_transf').value) || 0;
        const entrega = efe + tra;
        const cantCuotas = parseInt(document.getElementById('cuotas_selector').value) || 1;

        // El interés se aplica sobre el saldo real (Total con descuento global - Entrega inicial)
        const saldo = Math.max(0, finalTotal - entrega);
        const montoInteres = saldo * (interesPorc / 100);
        const montoAFinanciar = saldo + montoInteres;
        
        const valorCuota = montoAFinanciar / cantCuotas;

        const displayCuota = document.getElementById('info_valor_cuota');
        if (displayCuota) displayCuota.innerText = `$ ${valorCuota.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        
        // El total final de la venta es lo que ya pagó + lo que debe pagar en cuotas
        finalTotal = entrega + montoAFinanciar;
    } else {
        if (panelCuotas) panelCuotas.style.display = 'none';
    }

    const display = document.getElementById('total_venta_display');
    const inputTotal = document.getElementById('total_venta_input');
    const inputDetalle = document.getElementById('detalle_productos_input');
    
    if (display) display.innerText = `$ ${finalTotal.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    if (inputTotal) inputTotal.value = finalTotal.toFixed(2);
    if (inputDetalle) inputDetalle.value = JSON.stringify(carrito);

    calcularVuelto();
}

// --- LÓGICA DE VUELTO ---
function calcularVuelto() {
    const inputTotal = document.getElementById('total_venta_input');
    const condicion = document.getElementById('cond_pago').value;
    if (!inputTotal) return;

    const total = parseFloat(inputTotal.value) || 0;
    const pagoEfe = parseFloat(document.getElementById('pago_efectivo').value) || 0;
    const pagoTra = parseFloat(document.getElementById('pago_transf').value) || 0;
    
    const totalPagado = pagoEfe + pagoTra;
    const vueltoContainer = document.getElementById('vuelto_contenedor');
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
window.reanudarVenta = function(nDocumento) {
    fetch(`${APP_BASE}ajax/obtener_detalle_venta.php?n_documento=${nDocumento}&formato=json`)
        .then(res => res.json())
        .then(data => {
            if (!data.productos || data.productos.length === 0) {
                mostrarMensaje("Error", "La venta seleccionada no tiene productos registrados.", "error");
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
                    desc: parseFloat(p.descuento_unitario) || 0,
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

            // Restaurar observaciones guardadas en la venta pendiente
            const obsValor = data.cabecera.observaciones || '';
            const obsTextareaRea = document.getElementById('observaciones');
            const obsHiddenRea = document.getElementById('observaciones_hidden');
            if (obsTextareaRea) obsTextareaRea.value = obsValor;
            if (obsHiddenRea) obsHiddenRea.value = obsValor;

            renderizarCarrito();
            cerrarModalPendientes();
        })
        .catch(err => {
            console.error("Error al reanudar:", err);
            mostrarMensaje("Error de Conexión", "No se pudieron recuperar los datos de la venta.", "error");
        });
};

function cerrarModalPendientes() {
    const modal = document.getElementById('pendientesModal');
    if (modal) modal.style.display = 'none';
}

// --- PLAN DE CUOTAS DETALLADO ---
window.mostrarPlanCuotas = function() {
    const itemsTotal = carrito.reduce((sum, item) => sum + item.total, 0);
    const tipoDesc = document.getElementById('desc_global_tipo').value;
    const valorDesc = parseFloat(document.getElementById('desc_global_valor').value) || 0;
    const montoDescGlobal = (tipoDesc === 'porcentaje') ? (itemsTotal * (valorDesc / 100)) : valorDesc;
    const finalTotal = Math.max(0, itemsTotal - montoDescGlobal);

    const efe = parseFloat(document.getElementById('pago_efectivo').value) || 0;
    const tra = parseFloat(document.getElementById('pago_transf').value) || 0;
    const entrega = efe + tra;
    const cantCuotas = parseInt(document.getElementById('cuotas_selector').value) || 1;
    const intervaloDias = parseInt(document.getElementById('intervalo_cuotas').value) || 30;
    const interesPorc = parseFloat(document.getElementById('interes_manual').value) || 0;
    const cobrarHoy = document.getElementById('cobrar_primera_hoy').checked;

    const saldo = Math.max(0, finalTotal - entrega);
    const montoInteres = saldo * (interesPorc / 100);
    const montoAFinanciar = saldo + montoInteres;
    const valorCuota = montoAFinanciar / cantCuotas;

    // Construir tabla HTML
    let html = `
        <div style="margin-bottom: 20px; padding: 15px; background: #252525; border-radius: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div><strong style="color: #aaa;">Total Venta:</strong> <span style="color: #4caf50;">$${finalTotal.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
            <div><strong style="color: #aaa;">Entrega Inicial:</strong> <span style="color: #f1c40f;">$${entrega.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
            <div><strong style="color: #aaa;">Saldo a Financiar:</strong> <span style="color: #e0e0e0;">$${saldo.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
            <div><strong style="color: #aaa;">Interés (${interesPorc}%):</strong> <span style="color: #e74c3c;">$${montoInteres.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
            <div><strong style="color: #aaa;">Total Financiado:</strong> <span style="color: #3498db;">$${montoAFinanciar.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
            <div><strong style="color: #aaa;">Valor por Cuota:</strong> <span style="color: #2ecc71; font-size: 1.1em;">$${valorCuota.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span></div>
        </div>
        <table class="table-full">
            <thead>
                <tr>
                    <th>N° Cuota</th>
                    <th>Fecha Vencimiento</th>
                    <th>Monto</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
    `;

    for (let i = 1; i <= cantCuotas; i++) {
        let fechaVto;
        let estado;
        let estadoColor;

        if (cobrarHoy && i === 1) {
            fechaVto = new Date().toLocaleDateString('es-AR', { year: 'numeric', month: '2-digit', day: '2-digit' });
            estado = 'Pagada (Hoy)';
            estadoColor = '#2ecc71';
        } else {
            const diasSumar = cobrarHoy ? (i - 1) * intervaloDias : i * intervaloDias;
            const fecha = new Date();
            fecha.setDate(fecha.getDate() + diasSumar);
            fechaVto = fecha.toLocaleDateString('es-AR', { year: 'numeric', month: '2-digit', day: '2-digit' });
            estado = 'Pendiente';
            estadoColor = '#f1c40f';
        }

        html += `
            <tr>
                <td><strong>${i}</strong></td>
                <td>${fechaVto}</td>
                <td>$${valorCuota.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
                <td style="color: ${estadoColor}; font-weight: bold;">${estado}</td>
            </tr>
        `;
    }

    html += `
            </tbody>
        </table>
        <div style="margin-top: 15px; padding: 10px; background: #1a1a1a; border-radius: 6px; text-align: center; color: #888;">
            <i class="fas fa-info-circle"></i> 
            ${cobrarHoy 
                ? 'La primera cuota se cobra hoy. Las restantes ' + (cantCuotas - 1) + ' cuotas vencen cada ' + intervaloDias + ' días.'
                : 'Las ' + cantCuotas + ' cuotas vencen cada ' + intervaloDias + ' días a partir de hoy.'}
        </div>
    `;

    document.getElementById('contenidoPlanCuotas').innerHTML = html;
    document.getElementById('modalPlanCuotas').style.display = 'block';
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
                mostrarMensaje("Cliente Requerido", "⛔ Para vender en CUENTA CORRIENTE debes seleccionar un cliente real.", "error");
                return false;
            }
            
            // Validar que el cliente tenga habilitada la cuenta corriente
            const clienteSeleccionado = clientesData.find(c => c.id_cliente == idCliente);
            if (clienteSeleccionado) {
                const habilitaCta = (clienteSeleccionado.habilita_cta || '').toUpperCase();
                if (habilitaCta === 'NO') {
                    e.preventDefault();
                    mostrarMensaje("Cuenta Corriente No Habilitada", "⛔ Este cliente no tiene habilitada la cuenta corriente. Selecciona otro cliente o cambia la condición de pago.", "error");
                    return false;
                }
            }
        }
    });
}
