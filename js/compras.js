// js/compras.js
// Sistema: POS - Módulo de Compras
console.log("compras.js cargado correctamente");

// Base URL dinámica - usando el patrón del sistema
const APP_BASE = (function () {
    const seg = (window.location.pathname || '/').split('/').filter(s => s !== '');
    return window.location.origin + '/' + (seg[0] ? seg[0] + '/' : '');
})();

// Función debounce para evitar llamadas excesivas
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Inicializar módulo de compras cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    const carrito = [];
    const proveedoresData = window.proveedoresData || [];
    const inputBuscar = document.getElementById('buscar_producto');
    const resultadosDiv = document.getElementById('resultadosBusqueda');
    const inputBuscarProveedor = document.getElementById('buscar_proveedor');
    const resultadosDivProveedores = document.getElementById('resultadosBusquedaProveedores');
    const nombreProveedorDisplay = document.getElementById('nombre_proveedor_display');
    const proveedorIdHidden = document.getElementById('proveedor_id_hidden');
    const cuitProveedorDisplay = document.getElementById('cuit_proveedor_display');
    const descuentoFacturaInput = document.getElementById('descuento_factura');
    const detalleDescuentosInput = document.getElementById('detalle_descuentos_input');

    // ===========================================
    // 1. CÁLCULOS Y RENDER DEL CARRITO
    // ===========================================

    function calcularTotales() {
        const subtotal = carrito.reduce((sum, item) => sum + (item.cant * item.p_unit), 0);
        const descuentosLineales = carrito.reduce((sum, item) => sum + (parseFloat(item.descuento || 0)), 0);
        const descuentoGlobal = parseFloat(descuentoFacturaInput ? descuentoFacturaInput.value : 0) || 0;
        const totalCompra = Math.max(0, subtotal - descuentosLineales - descuentoGlobal);

        const display = document.getElementById('total_compra_display');
        const input = document.getElementById('total_compra_input');
        if (display) display.textContent = '$' + totalCompra.toFixed(2);
        if (input) input.value = totalCompra.toFixed(2);

        const subtotalEl = document.getElementById('subtotal_display');
        if (subtotalEl) subtotalEl.textContent = '$' + subtotal.toFixed(2);

        const descuentoTotal = descuentosLineales + descuentoGlobal;
        const descuentoEl = document.getElementById('descuento_total_display');
        if (descuentoEl) descuentoEl.textContent = '$' + descuentoTotal.toFixed(2);
    }

    function renderizarCarrito() {
        const tbody = document.querySelector('#carrito tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        carrito.forEach((item, index) => {
            if (!item.descuento) item.descuento = 0;
            const lineTotal = (item.cant * item.p_unit) - parseFloat(item.descuento || 0);
            item.total = lineTotal;
            const row = tbody.insertRow();
            row.dataset.index = index;
            row.innerHTML = `
                <td>${item.cod_prod}</td>
                <td>${item.descripcion}</td>
                <td class="text-right">
                    <input type="number" step="0.01" value="${item.p_unit.toFixed(2)}" data-cod-prod="${item.cod_prod}"
                        class="input-field update-costo" style="width: 80px; padding: 5px; text-align: right;">
                </td>
                <td>
                    <input type="number" min="1" step="any" value="${item.cant}" data-cod-prod="${item.cod_prod}"
                        class="input-field update-cantidad" style="width: 60px; padding: 5px;">
                </td>
                <td class="text-right">
                    <input type="number" step="0.01" min="0" value="${parseFloat(item.descuento).toFixed(2)}" data-cod-prod="${item.cod_prod}"
                        class="input-field update-descuento" style="width: 60px; padding: 5px; text-align: right;">
                </td>
                <td class="text-right">$${lineTotal.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm remover-item" data-cod-prod="${item.cod_prod}">X</button>
                </td>
            `;
        });
        calcularTotales();
    }

    // Eventos para actualizar costo y cantidad
    const carritoTable = document.querySelector('#carrito');
     if (carritoTable) {
        carritoTable.addEventListener('change', function(e) {
            const target = e.target;
            const cod_prod = target.dataset.codProd;
            const index = carrito.findIndex(item => item.cod_prod === cod_prod);
            if (index !== -1) {
                if (target.classList.contains('update-cantidad')) {
                    const nuevaCantidad = parseFloat(target.value) || 1;
                    carrito[index].cant = Math.max(1, nuevaCantidad);
                } else if (target.classList.contains('update-costo')) {
                    const nuevoCosto = parseFloat(target.value) || 0;
                    carrito[index].p_unit = Math.max(0, nuevoCosto);
                } else if (target.classList.contains('update-descuento')) {
                    const nuevoDescuento = parseFloat(target.value) || 0;
                    carrito[index].descuento = Math.max(0, nuevoDescuento);
                }
                const lineTotal = (carrito[index].cant * carrito[index].p_unit) - parseFloat(carrito[index].descuento || 0);
                carrito[index].total = lineTotal;
                target.value = (target.classList.contains('update-cantidad')) ? carrito[index].cant :
                               (target.classList.contains('update-descuento')) ? carrito[index].descuento.toFixed(2) :
                               carrito[index].p_unit.toFixed(2);
                renderizarCarrito();
            }
        });

        carritoTable.addEventListener('click', function(e) {
            if (e.target.classList.contains('remover-item')) {
                const cod_prod = e.target.dataset.codProd;
                const index = carrito.findIndex(item => item.cod_prod === cod_prod);
                carrito.splice(index, 1);
                renderizarCarrito();
            }
        });
    }

    // ===========================================
    // 2. BÚSQUEDA DE PRODUCTOS (AJAX con debounce + navegación teclado)
    // ===========================================

    let productosResultados = [];
    let productoSeleccionadoIdx = -1;

    if (inputBuscar) {
        const debouncedSearch = debounce(function() {
            const busqueda = inputBuscar.value.trim();
            productoSeleccionadoIdx = -1;
            if (busqueda.length < 3) {
                resultadosDiv.innerHTML = '';
                resultadosDiv.style.display = 'none';
                return;
            }

            const xhr = new XMLHttpRequest();
            xhr.open('GET', APP_BASE + 'pages/buscar_producto_ajax.php?q=' + encodeURIComponent(busqueda), true);
            xhr.onload = function() {
                if (this.status == 200) {
                    try {
                        const productos = JSON.parse(this.responseText);
                        mostrarResultadosProductos(productos);
                    } catch (e) {
                        resultadosDiv.innerHTML = '<div class="producto-encontrado" style="padding: 10px; color: #ff6b6b;">Error al procesar la respuesta.</div>';
                    }
                }
            };
            xhr.onerror = function() {
                resultadosDiv.innerHTML = '<div class="producto-encontrado" style="padding: 10px; color: #ff6b6b;">Error de conexión.</div>';
            };
            xhr.send();
        }, 150);

        inputBuscar.addEventListener('input', debouncedSearch);

        // Navegación con teclado (flechas y Enter)
        inputBuscar.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                if (resultadosDiv.style.display === 'none' || resultadosDiv.style.display === '') {
                    // Si los resultados no están visibles, disparar búsqueda inmediata
                    debouncedSearch();
                    return;
                }
                if (productosResultados.length === 0) return;
                e.preventDefault();
                productoSeleccionadoIdx = (productoSeleccionadoIdx + 1) % productosResultados.length;
                resaltarProductoSeleccionado();
            } else if (e.key === 'ArrowUp') {
                if (resultadosDiv.style.display === 'none' || resultadosDiv.style.display === '') return;
                if (productosResultados.length === 0) return;
                e.preventDefault();
                productoSeleccionadoIdx = (productoSeleccionadoIdx - 1 + productosResultados.length) % productosResultados.length;
                resaltarProductoSeleccionado();
            } else if (e.key === 'Enter') {
                if (resultadosDiv.style.display === 'none' || resultadosDiv.style.display === '') {
                    // Si no hay resultados visibles, disparar búsqueda y seleccionar el primero
                    debouncedSearch();
                    return;
                }
                e.preventDefault();
                if (productoSeleccionadoIdx >= 0) {
                    seleccionarProducto(productosResultados[productoSeleccionadoIdx]);
                } else if (productosResultados.length > 0) {
                    productoSeleccionadoIdx = 0;
                    seleccionarProducto(productosResultados[0]);
                }
            } else if (e.key === 'Escape') {
                productoSeleccionadoIdx = -1;
                resultadosDiv.style.display = 'none';
            }
        });
    }

    function resaltarProductoSeleccionado() {
        const items = resultadosDiv.querySelectorAll('.producto-encontrado');
        items.forEach((item, i) => {
            item.classList.toggle('producto-activo', i === productoSeleccionadoIdx);
        });
    }

    function mostrarResultadosProductos(productos) {
        resultadosDiv.innerHTML = '';
        productosResultados = productos || [];
        productoSeleccionadoIdx = -1;

        if (productosResultados.length === 0) {
            resultadosDiv.innerHTML = '<div class="producto-encontrado" style="padding: 10px;">No se encontraron productos.</div>';
            resultadosDiv.style.display = 'block';
            return;
        }

        productosResultados.forEach(producto => {
            const div = document.createElement('div');
            div.classList.add('producto-encontrado');
            const stock = producto.stock || 0;
            const stockColor = stock > 0 ? '#2ecc71' : '#e74c3c';
            const costoProm = parseFloat(producto.costo_promedio || producto.p_compra || 0).toFixed(2);
            const badgeConsigna = (parseInt(producto.es_consignacion) === 1)
                ? ' <span style="background: rgba(241,196,15,0.15); color: #f1c40f; padding: 2px 6px; border-radius: 8px; font-size: 0.75em; font-weight: bold;">🤝 Consignación</span>'
                : '';
            div.innerHTML = `<div>
                <strong>[${producto.cod_prod}]</strong> ${producto.descripcion}${badgeConsigna}
                <div style="font-size: 0.85em; color: #aaa;">
                    Stock: <span style="color: ${stockColor};">${stock}</span> |
                    Costo: $${costoProm}
                </div>
            </div>`;
            div.dataset.producto = JSON.stringify(producto);
            resultadosDiv.appendChild(div);
        });
        resultadosDiv.style.display = 'block';
    }

    if (resultadosDiv) {
        resultadosDiv.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        resultadosDiv.addEventListener('click', function(e) {
            const item = e.target.closest('.producto-encontrado');
            if (item) {
                const producto = JSON.parse(item.dataset.producto);
                seleccionarProducto(producto);
            }
        });
    }

    function seleccionarProducto(producto) {
        // Aviso: producto en consignación recibido por remito, no por compra
        if (parseInt(producto.es_consignacion) === 1 && !confirm('⚠️ "' + producto.descripcion + '" está marcado EN CONSIGNACIÓN.\n\nSi esta mercadería llegó como consignación, NO la registres como compra: cargala desde el módulo "Consignaciones: Ingreso de Mercadería".\n\n¿De todas formas querés registrarla como compra (se pagará al proveedor)?')) {
            inputBuscar.value = '';
            resultadosDiv.innerHTML = '';
            resultadosDiv.style.display = 'none';
            return;
        }
        const index = carrito.findIndex(item => item.cod_prod === producto.cod_prod);
        const costo_inicial = parseFloat(producto.costo_promedio || producto.p_compra || 0);

        if (index !== -1) {
            // El producto ya está en el carrito - preguntar cantidad a agregar
            const qtyActual = carrito[index].cant;
            const input = prompt(`Producto ya en carrito (Cant. actual: ${qtyActual}). Ingrese cantidad a AGREGAR:`, '1');
            if (input === null) return; // Cancelado
            const cantAgregar = parseFloat(input);
            if (isNaN(cantAgregar) || cantAgregar <= 0) return;
            
            carrito[index].cant += cantAgregar;
            carrito[index].total = carrito[index].cant * carrito[index].p_unit;
        } else {
            carrito.push({
                cod_prod: producto.cod_prod,
                descripcion: producto.descripcion,
                p_unit: costo_inicial,
                cant: 1,
                total: costo_inicial,
            });
        }

        inputBuscar.value = '';
        resultadosDiv.innerHTML = '';
        resultadosDiv.style.display = 'none';
        productosResultados = [];
        productoSeleccionadoIdx = -1;
        renderizarCarrito();
    }

    // Cerrar resultados al hacer clic afuera
    document.addEventListener('click', function(e) {
        if (inputBuscar && !inputBuscar.contains(e.target) && !resultadosDiv.contains(e.target)) {
            resultadosDiv.style.display = 'none';
        }
    });

    // ===========================================
    // 3. BÚSQUEDA Y SELECCIÓN DE PROVEEDORES
    // ===========================================

    if (inputBuscarProveedor) {
        inputBuscarProveedor.addEventListener('input', function() {
            const busqueda = inputBuscarProveedor.value.trim().toLowerCase();
            resultadosDivProveedores.innerHTML = '';

            if (busqueda.length < 2) {
                resultadosDivProveedores.style.display = 'none';
                return;
            }

            const resultados = proveedoresData.filter(proveedor =>
                proveedor.nombre.toLowerCase().includes(busqueda) ||
                proveedor.cuit.includes(busqueda)
            );

            if (resultados.length > 0) {
                resultados.forEach(proveedor => {
                    const div = document.createElement('div');
                    div.classList.add('resultado-proveedor-item');
                    div.textContent = `${proveedor.nombre} (CUIT: ${proveedor.cuit})`;
                    div.dataset.proveedor = JSON.stringify(proveedor);
                    resultadosDivProveedores.appendChild(div);
                });
                resultadosDivProveedores.style.display = 'block';
            } else {
                resultadosDivProveedores.style.display = 'none';
            }
        });

        resultadosDivProveedores.addEventListener('click', function(e) {
            if (e.target.classList.contains('resultado-proveedor-item')) {
                const proveedor = JSON.parse(e.target.dataset.proveedor);
                nombreProveedorDisplay.textContent = proveedor.nombre;
                proveedorIdHidden.value = proveedor.id_proveedor;
                cuitProveedorDisplay.value = proveedor.cuit;
                inputBuscarProveedor.value = proveedor.nombre;
                resultadosDivProveedores.style.display = 'none';
                // Verificar si ya existe documento para este proveedor
                verificarDocumentoDuplicado();
            }
        });

        document.addEventListener('click', function(e) {
            if (!inputBuscarProveedor.contains(e.target) && !resultadosDivProveedores.contains(e.target)) {
                resultadosDivProveedores.style.display = 'none';
            }
        });
    }

    // ===========================================
    // 5. VALIDACIÓN DE DOCUMENTO DUPLICADO
    // ===========================================

    const nDocumentoInput = document.getElementById('n_documento');
    const documentoErrorDiv = document.createElement('div');
    documentoErrorDiv.id = 'documento-error';
    documentoErrorDiv.style.cssText = 'color: #e74c3c; font-size: 0.85em; margin-top: 5px; display: none;';
    if (nDocumentoInput && nDocumentoInput.parentNode) {
        nDocumentoInput.parentNode.appendChild(documentoErrorDiv);
    }

    function verificarDocumentoDuplicado() {
        const proveedorId = proveedorIdHidden ? proveedorIdHidden.value : '0';
        const nDoc = nDocumentoInput ? nDocumentoInput.value.trim() : '';

        if (!proveedorId || proveedorId === '0' || nDoc.length < 4) {
            documentoErrorDiv.style.display = 'none';
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('GET', APP_BASE + 'ajax/verificar_documento_compra_ajax.php?proveedor_id=' + encodeURIComponent(proveedorId) + '&n_documento=' + encodeURIComponent(nDoc), true);
        xhr.onload = function() {
            if (this.status == 200) {
                try {
                    const resp = JSON.parse(this.responseText);
                    if (resp.existe) {
                        documentoErrorDiv.innerHTML = '⚠️ ' + resp.mensaje;
                        documentoErrorDiv.style.display = 'block';
                    } else {
                        documentoErrorDiv.style.display = 'none';
                    }
                } catch (e) {
                    documentoErrorDiv.style.display = 'none';
                }
            }
        };
        xhr.send();
    }

    if (nDocumentoInput) {
        const debouncedDocCheck = debounce(verificarDocumentoDuplicado, 500);
        nDocumentoInput.addEventListener('input', debouncedDocCheck);
    }

    // Recalcular totales cuando cambia el descuento global
    if (descuentoFacturaInput) {
        descuentoFacturaInput.addEventListener('input', function() {
            calcularTotales();
        });
    }

    // ===========================================
    // 4. ENVÍO DE FORMULARIO
    // ===========================================

    const formCompra = document.getElementById('formCompra');
    const detalleProductosInput = document.getElementById('detalle_productos_input');

    if (formCompra) {
        formCompra.addEventListener('submit', function(e) {
            if (proveedorIdHidden.value === '0' || proveedorIdHidden.value === '') {
                mostrarMensaje("Faltan Datos", "Debe seleccionar un proveedor para registrar la compra.", "error");
                e.preventDefault();
                return;
            }

            if (carrito.length === 0) {
                mostrarMensaje("Carrito Vacío", "Debe agregar al menos un producto para registrar la compra.", "error");
                e.preventDefault();
                return;
            }

            // Serializar descuentos por línea
            const descuentos = {};
            carrito.forEach(item => {
                const descuento = parseFloat(item.descuento || 0);
                if (descuento > 0) {
                    descuentos[item.cod_prod] = descuento;
                }
            });
            if (detalleDescuentosInput) {
                detalleDescuentosInput.value = JSON.stringify(descuentos);
            }

            detalleProductosInput.value = JSON.stringify(carrito);
        });
    }

    // ===========================================
    // 5. MODALES RÁPIDOS (PRODUCTO Y PROVEEDOR)
    // ===========================================

    window.abrirModalNuevoProducto = function() {
        const modal = document.getElementById('modalNuevoProducto');
        if (modal) {
            modal.style.display = 'block';
            const busq = inputBuscar.value.trim();
            if (busq !== "") document.getElementById('np_cod_prod').value = busq;
            document.getElementById('np_cod_prod').focus();
        }
    };

    window.cerrarModalNuevoProducto = function() {
        const modal = document.getElementById('modalNuevoProducto');
        if (modal) {
            modal.style.display = 'none';
            document.getElementById('formNuevoProducto').reset();
        }
    };

    window.guardarNuevoProducto = function() {
        const formData = new FormData();
        formData.append('cod_prod', document.getElementById('np_cod_prod').value.trim());
        formData.append('descripcion', document.getElementById('np_descripcion').value.trim());
        formData.append('p_compra', document.getElementById('np_p_compra').value);
        formData.append('p_venta', document.getElementById('np_p_venta').value);
        formData.append('stock', document.getElementById('np_stock').value);
        formData.append('fecha_ult_compra', document.getElementById('np_fecha_ult_compra').value);
        formData.append('rubro', document.getElementById('np_rubro').value);
        formData.append('proveedor', document.getElementById('np_proveedor').value);
        if (document.getElementById('np_es_consignacion') && document.getElementById('np_es_consignacion').checked) {
            formData.append('es_consignacion', '1');
            formData.append('comision_proveedor', document.getElementById('np_comision_proveedor').value);
        }

        fetch(APP_BASE + 'ajax/agregar_producto_rapido.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    carrito.push({
                        cod_prod: data.producto.cod_prod,
                        descripcion: data.producto.descripcion,
                        p_unit: parseFloat(data.producto.p_compra),
                        cant: 1,
                        total: parseFloat(data.producto.p_compra)
                    });
                    renderizarCarrito();
                    cerrarModalNuevoProducto();
                    mostrarMensaje("Éxito", "Producto agregado y añadido al carrito.", "success");
                } else {
                    mostrarMensaje("Error", data.error || "Ocurrió un error.", "error");
                }
            })
            .catch(err => {
                mostrarMensaje("Error", "Error de conexión: " + err.message, "error");
            });
    };

    window.abrirModalNuevoProveedor = function() {
        const modal = document.getElementById('modalNuevoProveedor');
        if (modal) {
            modal.style.display = 'block';
            const razonInput = document.getElementById('nprov_razon');
            if (razonInput) razonInput.focus();
        }
    };

    window.cerrarModalNuevoProveedor = function() {
        const modal = document.getElementById('modalNuevoProveedor');
        if (modal) {
            modal.style.display = 'none';
            document.getElementById('formNuevoProveedor').reset();
        }
    };

    window.guardarNuevoProveedor = function() {
        const formData = new FormData();
        formData.append('cod_prov', document.getElementById('nprov_cod_prov').value.trim());
        formData.append('razon', document.getElementById('nprov_razon').value.trim());
        formData.append('cuit', document.getElementById('nprov_cuit').value.trim());
        formData.append('telefono', document.getElementById('nprov_telefono').value.trim());

        fetch(APP_BASE + 'ajax/agregar_proveedor_rapido.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    proveedoresData.push({
                        id_proveedor: data.id_proveedor,
                        nombre: data.nombre,
                        cuit: data.cuit
                    });
                    nombreProveedorDisplay.textContent = data.nombre;
                    proveedorIdHidden.value = data.id_proveedor;
                    cuitProveedorDisplay.value = data.cuit;
                    cerrarModalNuevoProveedor();
                    mostrarMensaje("Éxito", "Proveedor creado y seleccionado.", "success");
                } else {
                    mostrarMensaje("Error", data.error || "Ocurrió un error.", "error");
                }
            })
            .catch(err => {
                mostrarMensaje("Error", "Error de conexión: " + err.message, "error");
            });
    };

    // Calcular precio de venta sugerido
    window.calcularPrecioVentaSugerido = function() {
        const pCompraInput = document.getElementById('np_p_compra');
        const pVentaInput = document.getElementById('np_p_venta');
        const pCompra = parseFloat(pCompraInput.value.replace(',', '.')) || 0;
        if (pCompra > 0) {
            const multiplicador = 1 + (window.gananciaConfig / 100);
            pVentaInput.value = (pCompra * multiplicador).toFixed(2);
        } else {
            pVentaInput.value = '';
        }
    };

    // Cerrar modales al hacer clic fuera
    window.addEventListener('click', function(e) {
        if (e.target.id === 'modalNuevoProducto') cerrarModalNuevoProducto();
        if (e.target.id === 'modalNuevoProveedor') cerrarModalNuevoProveedor();
    });
});