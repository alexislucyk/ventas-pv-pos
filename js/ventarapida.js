// js/ventarapida.js - Caja Registradora Supermercado
const BASE = (typeof APP_BASE !== 'undefined') ? APP_BASE : '/';
const clientes = (typeof clientesData !== 'undefined') ? clientesData : [];

let carrito = [];
let gridProductos = [];
let pagoMetodo = 'efectivo';
let clienteSeleccionado = null;
let resultadoIndex = -1;

const redondear = n => Math.round((n + Number.EPSILON) * 100) / 100;
const formatear = n => {
    const r = redondear(n);
    const neg = r < 0 ? '-' : '';
    const abs = Math.abs(r).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return neg + '$ ' + abs;
};

document.addEventListener('DOMContentLoaded', iniciar);

function iniciar() {
    const input = document.getElementById('producto_input');
    const resultados = document.getElementById('resultadosBusqueda');

    fetch(BASE + 'cache/dolar_cache.json', { cache: 'no-store' })
        .then(r => r.json())
        .then(d => { if (d && typeof d.venta === 'number' && d.venta > 0) window.dolarOperativo = d.venta * 1.02; })
        .catch(() => {});

    setInterval(reloj, 1000);
    reloj();

    input.addEventListener('keydown', function (e) {
        const items = resultados.querySelectorAll('.search-result-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (items.length === 0) return;
            resultadoIndex = (resultadoIndex + 1) % items.length;
            resaltarResultado(resultadoIndex);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (items.length === 0) return;
            resultadoIndex = (resultadoIndex - 1 + items.length) % items.length;
            resaltarResultado(resultadoIndex);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (items.length > 0 && resultadoIndex >= 0) {
                const item = items[resultadoIndex];
                const idx = parseInt(item.dataset.idx, 10);
                agregarDesdeGrid(idx);
                input.value = '';
                resultados.innerHTML = '';
                resultadoIndex = -1;
                input.focus();
                return;
            }
            const cod = input.value.trim();
            if (!cod) return;
            // Formato cantidad*código (ej: 3*7790123 → agrega 3 unidades)
            const m = cod.match(/^(\d+(?:[.,]\d+)?)\*(.+)$/);
            if (m) {
                const cant = parseFloat(m[1].replace(',', '.'));
                const codigo = m[2].trim();
                if (!isNaN(cant) && cant > 0 && codigo) {
                    buscarPorCodigo(codigo, resultados, cant);
                    return;
                }
            }
            buscarPorCodigo(cod, resultados);
        }
    });

    let debounce = null;
    input.addEventListener('input', function () {
        const q = input.value.trim();
        clearTimeout(debounce);
        if (q.length < 2) {
            resultados.innerHTML = '';
            resultadoIndex = -1;
            if (q === '') input.classList.remove('error');
            return;
        }
        debounce = setTimeout(() => {
            resultadoIndex = -1;
            sugerencias(q, resultados);
        }, 300);
    });

    input.addEventListener('blur', function () {
        setTimeout(() => {
            resultados.innerHTML = '';
            resultadoIndex = -1;
        }, 180);
    });

    document.getElementById('btnLimpiarBusqueda').addEventListener('click', limpiarBusqueda);

    document.addEventListener('keydown', function (e) {
        if ((e.key === '/' || (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') && document.activeElement !== input) {
            e.preventDefault();
            input.focus();
        }
        if (e.key === 'Escape') cerrarModalCobro();
    });

    const btnCobrar = document.getElementById('btnCobrar');
    btnCobrar.disabled = true;
    document.getElementById('btnVaciar').disabled = true;

    const icliente = document.getElementById('buscar_cliente');
    icliente.addEventListener('input', function () {
        const box = document.getElementById('resultadosBusquedaClientes');
        const q = icliente.value.toLowerCase().trim();
        box.innerHTML = '';
        if (q.length < 2) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        const filtrados = clientes.filter(c =>
            (c.nombre_completo || '').toLowerCase().includes(q) ||
            (c.num_documento || '').includes(q)
        );
        if (filtrados.length === 0) {
            box.innerHTML = '<div class="sr-empty">Sin clientes que coincidan</div>';
            return;
        }
        filtrados.forEach(c => {
            const d = document.createElement('div');
            d.className = 'search-result-item';
            const badge = (c.habilita_cta === 'SI') ? '<span class="sr-stock">Cta.Cte.</span>' : '';
            d.innerHTML =
                '<span><span class="sr-name">' + esc(c.nombre_completo) + '</span>' +
                '<div class="sr-meta">' + esc(c.num_documento || 'Sin documento') + '</div></span>' +
                badge;
            d.onclick = () => seleccionarCliente(c);
            box.appendChild(d);
        });
    });

    icliente.addEventListener('blur', function () {
        setTimeout(() => {
            const box = document.getElementById('resultadosBusquedaClientes');
            box.innerHTML = '';
            box.style.display = 'none';
        }, 200);
    });
}

function reloj() {
    const ahora = new Date();
    const h = document.getElementById('relojHora');
    const f = document.getElementById('relojFecha');
    if (h) h.textContent = ahora.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    if (f) f.textContent = ahora.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function esc(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
}

// ================= PRODUCTOS =================
function agregarDesdeGrid(idx) {
    if (idx >= 0 && idx < gridProductos.length) {
        agregarProducto(gridProductos[idx]);
    }
}

function precioPesos(p) {
    if ((p.moneda || '') === 'dolar' && parseFloat(p.p_venta_pesos) > 0) {
        return parseFloat(p.p_venta_pesos);
    }
    return parseFloat(p.p_venta) || 0;
}

// ================= UNIDADES GRANEL (Kg / Mt / Lt) =================
const ABREVIATURAS = { 'Kilogramo': 'Kg', 'Metro': 'Mt', 'Litro': 'Lt' };
function esGranel(item) {
    return !!ABREVIATURAS[item.unidad_medida];
}
function abrevUnidad(item) {
    return ABREVIATURAS[item.unidad_medida] || 'u';
}

// ================= BÚSQUEDA / SCANNER =================
function buscarPorCodigo(codigo, resultados, cantidad) {
    const input = document.getElementById('producto_input');
    fetch(BASE + 'pages/buscar_producto_codigo_ajax.php?codigo=' + encodeURIComponent(codigo))
        .then(r => r.json())
        .then(data => {
            if (data.success && data.producto) {
                agregarProducto(data.producto, cantidad);
                input.value = '';
                input.focus();
                toast('Agregado: ' + data.producto.descripcion + (cantidad && cantidad !== 1 ? ' x' + cantidad : ''), 'ok');
            } else {
                const primerSugerencia = resultados && resultados.querySelector('.search-result-item');
                if (primerSugerencia && primerSugerencia.dataset.idx !== undefined) {
                    agregarDesdeGrid(parseInt(primerSugerencia.dataset.idx, 10));
                    input.value = '';
                    input.focus();
                    return;
                }
                input.value = '';
                input.classList.add('error');
                setTimeout(() => input.classList.remove('error'), 1500);
                toast(data.error || 'Producto no encontrado', 'error');
                input.focus();
            }
        })
        .catch(() => {
            toast('Error de conexión al buscar el producto', 'error');
        });
}

function sugerencias(q, contenedor) {
    fetch(BASE + 'pages/buscar_producto_ajax.php?q=' + encodeURIComponent(q))
        .then(r => { if (!r.ok) throw new Error(); return r.json(); })
        .then(data => {
            if (!contenedor) return;
            contenedor.innerHTML = '';
            if (!Array.isArray(data) || data.length === 0) {
                contenedor.innerHTML = '<div class="sr-empty">No se encontraron productos</div>';
                return;
            }
            data.slice(0, 8).forEach((p, listIndex) => {
                const idx = gridProductos.length;
                gridProductos.push(p);
                const stock = parseFloat(p.stock) || 0;
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.dataset.idx = idx;
                div.innerHTML =
                    '<span><span class="sr-name">' + esc(p.descripcion) + (parseInt(p.es_consignacion) === 1 ? ' <span style="background: rgba(241,196,15,0.15); color: #f1c40f; padding: 2px 6px; border-radius: 8px; font-size: 0.75em; font-weight: bold;">🤝</span>' : '') + '</span>' +
                    '<div class="sr-meta">' + esc(p.cod_prod) + ' &middot; ' + esc(p.rubro || '') + '</div></span>' +
                    '<span style="text-align:right;"><span class="sr-precio">' + formatear(precioPesos(p)) + '</span><br>' +
                    '<span class="sr-stock ' + (stock <= 0 ? 'agotado' : '') + '">' + (stock <= 0 ? 'SIN STOCK' : 'Stock: ' + stock) + '</span></span>';
                div.addEventListener('mouseenter', () => resaltarResultado(listIndex));
                div.onclick = () => {
                    agregarDesdeGrid(idx);
                    document.getElementById('producto_input').value = '';
                    contenedor.innerHTML = '';
                    resultadoIndex = -1;
                    document.getElementById('producto_input').focus();
                };
                contenedor.appendChild(div);
            });
            resultadoIndex = 0;
            resaltarResultado(0);
        })
        .catch(() => {
            if (contenedor) contenedor.innerHTML = '<div class="sr-empty">Error de conexión</div>';
        });
}

function resaltarResultado(idx) {
    const items = document.querySelectorAll('#resultadosBusqueda .search-result-item');
    items.forEach((it, i) => it.classList.toggle('resaltado', i === idx));
    const actual = items[idx];
    if (actual) actual.scrollIntoView({ block: 'nearest' });
}

function limpiarBusqueda() {
    const input = document.getElementById('producto_input');
    input.value = '';
    input.classList.remove('error');
    document.getElementById('resultadosBusqueda').innerHTML = '';
    resultadoIndex = -1;
    input.focus();
}

// --- PERSISTENCIA DEL CARRITO ---
// El carrito se guarda en localStorage para que al navegar a otra página y
// volver a Venta Rápida (recarga completa de PHP) siga cargado.
const CARRITO_STORAGE_KEY = 'pos_carrito_ventarapida';

function guardarCarritoStorage() {
    try {
        if (!carrito || carrito.length === 0) {
            localStorage.removeItem(CARRITO_STORAGE_KEY);
        } else {
            localStorage.setItem(CARRITO_STORAGE_KEY, JSON.stringify(carrito));
        }
    } catch (e) { /* almacenamiento no disponible */ }
}

function restaurarCarritoStorage() {
    try {
        const raw = localStorage.getItem(CARRITO_STORAGE_KEY);
        if (!raw) return;
        const data = JSON.parse(raw);
        if (Array.isArray(data)) {
            carrito = data.filter(i => i && i.cod_prod);
            if (carrito.length > 0) renderCarrito();
            else localStorage.removeItem(CARRITO_STORAGE_KEY);
        }
    } catch (e) {
        // JSON corrupto o storage inaccesible: descartamos lo guardado
        try { localStorage.removeItem(CARRITO_STORAGE_KEY); } catch (x) {}
    }
}

// Se registra después del listener principal (iniciar), así la restauración
// ocurre con toda la UI inicializada. Si el backend marcó que la venta se
// procesó correctamente (window.LIMPIAR_CARRITO), el carrito arranca vacío.
document.addEventListener('DOMContentLoaded', function () {
    if (window.LIMPIAR_CARRITO === true) {
        try { localStorage.removeItem(CARRITO_STORAGE_KEY); } catch (e) {}
    } else {
        restaurarCarritoStorage();
    }
});

// ================= CARRITO =================
function agregarProducto(prod, cantidad) {
    const cant = (cantidad !== undefined && !isNaN(cantidad) && cantidad > 0) ? cantidad : 1;
    let pv = parseFloat(prod.p_venta) || 0;
    let pc = parseFloat(prod.p_compra) || 0;

    if ((prod.moneda || '') === 'dolar') {
        const enPesos = parseFloat(prod.p_venta_pesos) || 0;
        if (enPesos > 0) {
            const factor = pv > 0 ? enPesos / pv : 1;
            pv = enPesos;
            if (pc > 0) pc = pc * factor;
        } else if (window.dolarOperativo) {
            pv = pv * window.dolarOperativo;
            pc = pc * window.dolarOperativo;
        }
    }

    const idx = carrito.findIndex(i => i.cod_prod === prod.cod_prod);
    if (idx > -1) {
        carrito[idx].cant = redondear(carrito[idx].cant + cant);
        carrito[idx].total = redondear(carrito[idx].p_unit * carrito[idx].cant);
    } else {
        carrito.push({
            cod_prod: prod.cod_prod,
            descripcion: prod.descripcion,
            unidad_medida: prod.unidad_medida || 'Unidad',
            p_unit: redondear(pv),
            p_costo_venta: redondear(pc),
            cant: cant,
            desc: 0,
            total: redondear(pv * cant),
            stock_actual: parseFloat(prod.stock) || 0
        });
    }
    renderCarrito();
}

function renderCarrito() {
    const body = document.getElementById('carritoBody');
    const vacio = document.querySelector('.cart-empty');
    if (carrito.length === 0) {
        body.innerHTML = '<div class="cart-empty"><i class="fas fa-cart-plus"></i><p>Escaneá o tocá un producto para comenzar</p></div>';
    } else {
        body.innerHTML = '';
        carrito.forEach((item, idx) => {
            const sinStock = item.cant > (item.stock_actual || 0);
            const granel = esGranel(item);
            const row = document.createElement('div');
            row.className = 'cart-item';
            row.innerHTML =
                '<div class="ci-info">' +
                    '<div class="ci-nombre">' + esc(item.descripcion) + (granel ? ' <span style="color:var(--warn);font-size:.75rem;">(' + abrevUnidad(item) + ')</span>' : '') + '</div>' +
                    '<div class="ci-cod">' + esc(item.cod_prod) + ' &middot; ' + formatear(item.p_unit) + (granel ? '/' + abrevUnidad(item) : ' c/u') + '</div>' +
                '</div>' +
                '<div class="ci-qty">' +
                    '<input type="number" min="' + (granel ? '0.001' : '1') + '" step="' + (granel ? '0.001' : '1') + '" value="' + item.cant + '" onchange="setCantidad(' + idx + ', this.value)" title="Cantidad en ' + abrevUnidad(item) + '"' + (granel ? ' ondblclick="abrirModalImporte(' + idx + ')"' : '') + '>' +
                '</div>' +
                '<span class="ci-total' + (sinStock ? ' sin-stock' : '') + '" title="' + (sinStock ? 'Stock insuficiente' : '') + '">' + formatear(item.total) + '</span>' +
                '<button type="button" class="ci-remove" title="Quitar" onclick="quitarItem(' + idx + ')">&times;</button>';
            body.appendChild(row);
        });
    }
    renderTotales();
    guardarCarritoStorage();
}

function setCantidad(idx, valor) {
    if (idx < 0 || idx >= carrito.length) return;
    const granel = esGranel(carrito[idx]);
    const n = parseFloat(valor);
    if (isNaN(n) || n <= 0 || (!granel && parseInt(valor, 10) !== n)) { renderCarrito(); return; }
    carrito[idx].cant = granel ? Math.round(n * 1000) / 1000 : Math.round(n);
    carrito[idx].total = redondear(carrito[idx].p_unit * carrito[idx].cant);
    renderCarrito();
}

window.setCantidad = setCantidad;

// ================= MODAL IMPORTE (F2) =================
// F2 abre el modal para vender por importe el producto a granel
// (si hay varios en el carrito, se navega con ↑/↓). Doble clic en la
// cantidad de un producto granel también lo abre.
let importeModalIdx = null;

function asegurarModalImporte() {
    if (document.getElementById('modalImporteOverlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'modalImporteOverlay';
    overlay.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:9999; align-items:center; justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#1a1a1a; border:1px solid #444; border-radius:10px; padding:25px; width:360px; box-shadow:0 10px 40px rgba(0,0,0,.6);">
            <h3 style="margin:0 0 5px 0; color:var(--accent); font-size:1.05rem;"><i class="fas fa-balance-scale"></i> Vender por importe</h3>
            <div id="miProducto" style="color:#ccc; font-size:.85rem; margin-bottom:15px;"></div>
            <label style="display:block; color:#3498db; font-weight:bold; font-size:.85em; margin-bottom:5px;">Importe a cobrar ($)</label>
            <input type="number" id="miImporteInput" min="0" step="0.01" placeholder="0.00"
                   style="width:100%; padding:12px; font-size:1.3rem; border-radius:6px; border:1px solid #444; background:#222; color:#fff; box-sizing:border-box; text-align:center;">
            <div id="miCantidadCalc" style="color:var(--warn); font-size:.85rem; margin-top:8px; min-height:1.2em;"></div>
            <div style="display:flex; gap:10px; margin-top:18px;">
                <button type="button" id="miBtnConfirmar" class="btn-vaciar" style="flex:2; background:var(--success); border-color:var(--success);">✔ Aplicar (Enter)</button>
                <button type="button" id="miBtnCancelar" class="btn-vaciar" style="flex:1;">Cancelar (Esc)</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => { if (e.target === overlay) cerrarModalImporte(); });
    document.getElementById('miBtnCancelar').onclick = cerrarModalImporte;
    document.getElementById('miBtnConfirmar').onclick = confirmarModalImporte;
    document.getElementById('miImporteInput').addEventListener('input', recalcularPreviewImporte);
}

function abrirModalImporte(idx) {
    if (idx === undefined || idx === null) {
        for (let i = 0; i < carrito.length; i++) {
            if (esGranel(carrito[i])) { idx = i; break; }
        }
    }
    if (idx === undefined || idx === null || !carrito[idx] || !esGranel(carrito[idx])) {
        toast('No hay productos a granel (Kg/Mt/Lt) en el carrito', 'warn');
        return;
    }
    importeModalIdx = idx;
    asegurarModalImporte();
    window.granelesModal = carrito.map((it, i) => ({ it, i })).filter(x => esGranel(x.it)).map(x => x.i);

    renderizarInfoModal();

    const input = document.getElementById('miImporteInput');
    input.value = '';
    document.getElementById('miCantidadCalc').textContent = '';
    document.getElementById('modalImporteOverlay').style.display = 'flex';
    input.focus();
}
window.abrirModalImporte = abrirModalImporte;

function renderizarInfoModal() {
    const idx = importeModalIdx;
    if (idx === null || !carrito[idx]) return;
    const graneles = window.granelesModal || [];
    let selectorHTML = '';
    if (graneles.length > 1) {
        selectorHTML = '<select id="miSelectorProd" style="width:100%; padding:8px; border-radius:6px; border:1px solid #444; background:#222; color:#fff; margin-bottom:10px;">' +
            graneles.map(i => `<option value="${i}" ${i === idx ? 'selected' : ''}>${esc(carrito[i].descripcion)} (${formatear(carrito[i].p_unit)}/${abrevUnidad(carrito[i])})</option>`).join('') +
            '</select>' +
            '<div style="color:#888; font-size:.75rem; margin:-6px 0 8px 0;">↑/↓ para cambiar de producto</div>';
    }
    const item = carrito[idx];
    document.getElementById('miProducto').innerHTML = selectorHTML +
        `<b style="color:#fff;">${esc(item.descripcion)}</b><br>` +
        `Precio: ${formatear(item.p_unit)} / ${abrevUnidad(item)}`;
    const sel = document.getElementById('miSelectorProd');
    if (sel) sel.onchange = function () {
        importeModalIdx = parseInt(this.value, 10);
        renderizarInfoModal();
        recalcularPreviewImporte();
        document.getElementById('miImporteInput').focus();
    };
}

function ciclarProductoGranel(direccion) {
    const lista = window.granelesModal || [];
    if (lista.length < 2) return;
    const pos = lista.indexOf(importeModalIdx);
    importeModalIdx = lista[(pos + direccion + lista.length) % lista.length];
    renderizarInfoModal();
    recalcularPreviewImporte();
    document.getElementById('miImporteInput').focus();
}

function recalcularPreviewImporte() {
    const item = carrito[importeModalIdx];
    const monto = parseFloat(document.getElementById('miImporteInput').value);
    const calc = document.getElementById('miCantidadCalc');
    if (item && !isNaN(monto) && monto > 0 && item.p_unit > 0) {
        const cant = Math.round((monto / item.p_unit) * 1000) / 1000;
        calc.textContent = '→ Cantidad: ' + cant + ' ' + abrevUnidad(item);
    } else {
        calc.textContent = '';
    }
}

function confirmarModalImporte() {
    const item = carrito[importeModalIdx];
    const monto = parseFloat(document.getElementById('miImporteInput').value);
    if (!item || isNaN(monto) || monto <= 0 || item.p_unit <= 0) { cerrarModalImporte(); return; }
    item.cant = Math.round((monto / item.p_unit) * 1000) / 1000;
    item.total = redondear(item.p_unit * item.cant);
    cerrarModalImporte();
    renderCarrito();
}

function cerrarModalImporte() {
    const overlay = document.getElementById('modalImporteOverlay');
    if (overlay) overlay.style.display = 'none';
    importeModalIdx = null;
    const inp = document.getElementById('producto_input');
    if (inp) inp.focus();
}
window.cerrarModalImporte = cerrarModalImporte;

// Teclado del modal: F2 abre; dentro: ↑/↓ cambia producto, Enter aplica, Esc cierra.
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
        if (document.activeElement && document.activeElement.id !== 'miSelectorProd') {
            e.preventDefault();
            confirmarModalImporte();
        }
    } else if (e.key === 'Escape') {
        // Evitar que el Escape cierre también el modal de cobro de fondo
        e.preventDefault();
        e.stopPropagation();
        cerrarModalImporte();
    }
}, true);

function quitarItem(idx) {
    if (idx >= 0 && idx < carrito.length) {
        carrito.splice(idx, 1);
        renderCarrito();
        document.getElementById('producto_input').focus();
    }
}

window.quitarItem = quitarItem;

function vaciarCarrito() {
    if (carrito.length === 0) return;
    confirmarAccion('Vaciar Carrito', '¿Seguro que deseas vaciar el carrito?', 'VACIAR', 'btn-danger', function() {
        carrito = [];
        renderCarrito();
        document.getElementById('producto_input').focus();
    });
}

window.vaciarCarrito = vaciarCarrito;

function totalCarrito() {
    return redondear(carrito.reduce((s, i) => s + (parseFloat(i.total) || 0), 0));
}

function renderTotales() {
    const items = carrito.reduce((s, i) => s + (parseInt(i.cant) || 0), 0);
    const total = totalCarrito();
    document.getElementById('cart-count').textContent = items;
    document.getElementById('totalItems').textContent = items;
    document.getElementById('subtotalDisplay').textContent = formatear(total);
    document.getElementById('total_venta_display').textContent = formatear(total);
    document.getElementById('modalTotal').textContent = formatear(total);
    document.getElementById('btnCobrar').disabled = carrito.length === 0;
    document.getElementById('btnVaciar').disabled = carrito.length === 0;
}

// ================= CLIENTE =================
function seleccionarCliente(c) {
    clienteSeleccionado = c;
    const display = document.getElementById('clienteDisplay');
    const quitar = document.getElementById('btnQuitarCliente');
    if ((c.habilita_cta || 'NO') === 'SI') {
        display.className = 'cliente-display';
        display.innerHTML = '<span><i class="fas fa-user-check"></i> ' + esc(c.nombre_completo) + '</span>' + (parseFloat(c.saldo) > 0 ? '<span style="color:var(--warn);font-size:.8rem;">Saldo: ' + formatear(c.saldo) + '</span>' : '');
    } else {
        display.className = 'cliente-display generico';
        display.innerHTML = '<span><i class="fas fa-user"></i> ' + esc(c.nombre_completo) + '</span>';
    }
    quitar.style.display = 'inline-block';
    document.getElementById('buscar_cliente').value = '';
    document.getElementById('resultadosBusquedaClientes').style.display = 'none';
    if (pagoMetodo === 'ctacte' && (c.habilita_cta || 'NO') !== 'SI') {
        toast('Este cliente no tiene cuenta corriente habilitada', 'error');
        seleccionarPago('efectivo');
    } else if (pagoMetodo === 'ctacte') {
        seleccionarPago('ctacte');
    }
}

function quitarCliente() {
    clienteSeleccionado = null;
    const display = document.getElementById('clienteDisplay');
    display.className = 'cliente-display generico';
    display.innerHTML = '<span><i class="fas fa-user"></i> Venta genérica (Consumidor final)</span>';
    document.getElementById('btnQuitarCliente').style.display = 'none';
    if (pagoMetodo === 'ctacte') seleccionarPago('efectivo');
}

// ================= MODAL DE COBRO =================
function abrirModalCobro() {
    if (carrito.length === 0) { toast('El carrito está vacío', 'error'); return; }
    document.getElementById('modalTotal').textContent = formatear(totalCarrito());
    document.getElementById('obsInput').value = '';
    seleccionarPago('efectivo');
    document.getElementById('modalCobro').classList.add('open');
    const rec = document.getElementById('recibidoInput');
    rec.value = totalCarrito().toFixed(2);
    calcularVuelto();
    document.getElementById('buscar_cliente').focus();
}

function cerrarModalCobro() {
    document.getElementById('modalCobro').classList.remove('open');
    document.getElementById('producto_input').focus();
}

window.cerrarModalCobro = cerrarModalCobro;

function seleccionarPago(metodo) {
    pagoMetodo = metodo;
    const total = totalCarrito();

    document.querySelectorAll('.pago-chip').forEach(c => c.classList.toggle('active', c.dataset.metodo === metodo));

    const gEf = document.getElementById('grupoEfectivo');
    const gMix = document.getElementById('grupoMixto');
    const gInfo = document.getElementById('grupoInfo');
    const info = document.getElementById('infoPago');

    gEf.classList.toggle('active', metodo === 'efectivo');
    gMix.classList.toggle('active', metodo === 'mixto');
    gInfo.classList.toggle('active', metodo !== 'efectivo' && metodo !== 'mixto');

    if (metodo === 'efectivo') {
        document.getElementById('recibidoInput').value = total.toFixed(2);
        calcularVuelto();
    } else if (metodo === 'mixto') {
        document.getElementById('mixtoEfectivo').value = '0.00';
        document.getElementById('mixtoDigital').value = '0.00';
        calcularMixto();
    } else if (metodo === 'tarjeta') {
        info.textContent = 'Se cobrará ' + formatear(total) + ' por tarjeta.';
    } else if (metodo === 'transferencia') {
        info.textContent = 'Se cobrará ' + formatear(total) + ' por transferencia / QR.';
    } else if (metodo === 'ctacte') {
        if (!clienteSeleccionado) {
            info.className = 'vuelto-box falta';
            info.textContent = 'Seleccioná un cliente para cobrar en cuenta corriente.';
            info.closest('.pago-grupo').classList.add('active');
        } else if ((clienteSeleccionado.habilita_cta || 'NO') !== 'SI') {
            info.className = 'vuelto-box falta';
            info.textContent = 'El cliente no tiene habilitada la cuenta corriente.';
        } else {
            info.className = 'vuelto-box info';
            info.textContent = 'Se registrará ' + formatear(total) + ' a cuenta corriente de ' + clienteSeleccionado.nombre_completo + '.';
        }
    }
}

window.seleccionarPago = seleccionarPago;

function calcularVuelto() {
    const total = totalCarrito();
    const rec = parseFloat(document.getElementById('recibidoInput').value) || 0;
    const box = document.getElementById('vueltoBox');
    const dif = redondear(rec - total);
    if (dif >= 0) {
        box.className = 'vuelto-box ok';
        box.textContent = 'Vuelto: ' + formatear(dif);
    } else {
        box.className = 'vuelto-box falta';
        box.textContent = 'Faltan: ' + formatear(Math.abs(dif));
    }
}

function calcularMixto() {
    const total = totalCarrito();
    const ef = parseFloat(document.getElementById('mixtoEfectivo').value) || 0;
    const dig = parseFloat(document.getElementById('mixtoDigital').value) || 0;
    const suma = redondear(ef + dig);
    const box = document.getElementById('mixtoEstado');
    if (suma >= total - 0.005 && suma <= total + 0.005) {
        box.className = 'vuelto-box ok';
        box.textContent = 'Pago completo: ' + formatear(suma);
    } else if (suma > total + 0.005) {
        box.className = 'vuelto-box falta';
        box.textContent = 'El pago supera el total por ' + formatear(redondear(suma - total));
    } else {
        box.className = 'vuelto-box falta';
        box.textContent = 'Falta: ' + formatear(redondear(total - suma));
    }
}

function confirmarVenta() {
    if (carrito.length === 0) { toast('El carrito está vacío', 'error'); return; }

    const total = totalCarrito();
    let cond = 'CONTADO';
    let pe = 0;
    let pt = 0;

    if (pagoMetodo === 'efectivo') {
        const rec = parseFloat(document.getElementById('recibidoInput').value) || 0;
        if (total > 0 && rec < total - 0.005) {
            toast('El dinero recibido no cubre el total', 'error');
            return;
        }
        pe = total;
    } else if (pagoMetodo === 'tarjeta' || pagoMetodo === 'transferencia') {
        pt = total;
    } else if (pagoMetodo === 'mixto') {
        const ef = parseFloat(document.getElementById('mixtoEfectivo').value) || 0;
        const dig = parseFloat(document.getElementById('mixtoDigital').value) || 0;
        const suma = redondear(ef + dig);
        if (suma < total - 0.005) {
            toast('El pago no cubre el total', 'error');
            return;
        }
        if (suma > total + 0.005) {
            toast('El pago supera el total', 'error');
            return;
        }
        pe = redondear(ef);
        pt = redondear(dig);
    } else if (pagoMetodo === 'ctacte') {
        if (!clienteSeleccionado || (clienteSeleccionado.habilita_cta || 'NO') !== 'SI') {
            toast('Seleccioná un cliente con cuenta corriente habilitada', 'error');
            return;
        }
        cond = 'CUENTA CORRIENTE';
    }

    const sinStock = carrito.filter(i => i.cant > (i.stock_actual || 0));

    const finalizarEnvio = function() {
        document.getElementById('detalle_productos_input').value = JSON.stringify(carrito);
        document.getElementById('cond_pago').value = cond;
        document.getElementById('id_cliente_hidden').value = clienteSeleccionado ? clienteSeleccionado.id_cliente : 0;
        document.getElementById('pago_efectivo_hidden').value = pe.toFixed(2);
        document.getElementById('pago_transf_hidden').value = pt.toFixed(2);
        document.getElementById('observaciones_hidden').value = document.getElementById('obsInput').value.trim();

        const btn = document.getElementById('btnFinalizarVenta');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Procesando...';
        document.getElementById('formVenta').submit();
    };

    if (sinStock.length > 0) {
        const nombres = sinStock.map(i => i.descripcion).join(', ');
        confirmarAccion('Stock Insuficiente', 'El stock es insuficiente en: ' + nombres + '\n\n¿Continuar de todos modos?', 'CONTINUAR', 'btn-primary', finalizarEnvio);
        return;
    }
    finalizarEnvio();
}

// ================= UI =================
function toast(msg, tipo) {
    const viejo = document.querySelector('.toast');
    if (viejo) viejo.remove();
    const t = document.createElement('div');
    t.className = 'toast ' + (tipo || 'ok');
    const icon = tipo === 'error' ? 'fa-exclamation-circle' : (tipo === 'warn' ? 'fa-exclamation-triangle' : 'fa-check-circle');
    t.innerHTML = '<i class="fas ' + icon + '"></i> ' + esc(msg);
    document.body.appendChild(t);
    setTimeout(() => {
        t.style.transition = 'opacity .3s';
        t.style.opacity = '0';
        setTimeout(() => t.remove(), 350);
    }, 2800);
}