// Base URL para URLs limpias del router
// Base URL dinámica: usa el primer segmento del path (pos_dev, pos_prod, etc.)
const APP_BASE = (function () {
    var seg = (window.location.pathname || '/').split('/').filter(function (s) { return s !== ''; });
    return window.location.origin + '/' + (seg[0] ? seg[0] + '/' : '');
})();
// archivo: js/presupuestos.js
let items = []; // Array para guardar los productos del presupuesto
document.addEventListener('DOMContentLoaded', () => {
    const buscarCliente = document.getElementById('buscarCliente');
    const listaClientes = document.getElementById('listaClientes');
    const buscarProducto = document.getElementById('buscarProducto');
    const listaProductos = document.getElementById('listaProductos');
    const cuerpoPresupuesto = document.getElementById('cuerpoPresupuesto');
    const totalPresupuestoLabel = document.getElementById('totalPresupuesto');

    

    // --- LÓGICA DE BÚSQUEDA DE CLIENTES ---
    // --- LÓGICA DE BÚSQUEDA IGUAL A VENTAS ---
    buscarCliente.addEventListener('input', () => {
        const query = buscarCliente.value;
        if (query.length > 2) {
            fetch(`${APP_BASE}pages/buscar_cliente_ajax.php?q=${query}`)
                .then(res => res.json())
                .then(data => {
                    listaClientes.innerHTML = '';
                    
                    // Si el JSON viene vacío
                    if (data.length === 0) {
                        listaClientes.innerHTML = '<div class="p-2">No se encontró el cliente</div>';
                        listaClientes.style.display = 'block';
                        return;
                    }

                    // Generamos la lista
                    data.forEach(cliente => {
                        const div = document.createElement('div');
                        div.className = 'item-busqueda'; // Usa la clase de tu CSS de ventas
                        
                        // IMPORTANTE: Usamos los nombres que viste en F12
                        const id = cliente.id_cliente;
                        const nombre = cliente.nombre_completo;
                        const dni = cliente.num_documento;

                        div.innerHTML = `<strong>${nombre}</strong> <small>(${dni})</small>`;
                        
                        // Al hacer click, ejecutamos la selección
                        div.onclick = () => {
                            seleccionarCliente(id, nombre, dni);
                        };
                        listaClientes.appendChild(div);
                    });
                    listaClientes.style.display = 'block';
                });
        } else {
            listaClientes.style.display = 'none';
        }
    });

    // Función de selección (Limpia y reusable)
    function seleccionarCliente(id, nombre, dni) {
        // 1. Asignamos a los campos
        document.getElementById('id_cliente_seleccionado').value = id;
        
        // 2. Mostramos en pantalla (Igual que en ventas)
        document.getElementById('datosCliente').innerHTML = `
            <div class="alert alert-success">
                <strong>Cliente:</strong> ${nombre} | <strong>Documento:</strong> ${dni}
            </div>
        `;
        
        // 3. Limpiamos buscador y ocultamos lista
        document.getElementById('buscarCliente').value = '';
        document.getElementById('listaClientes').style.display = 'none';
    }

    // --- LÓGICA DE BÚSQUEDA DE PRODUCTOS ---
    buscarProducto.addEventListener('input', () => {
        const query = buscarProducto.value;
        if (query.length > 1) {
            fetch(`${APP_BASE}pages/buscar_producto_ajax.php?q=${query}`)
                .then(res => res.json())
                .then(data => {
                    listaProductos.innerHTML = '';
                    data.forEach(prod => {
                        const div = document.createElement('div');
                        div.innerHTML = `[${prod.cod_prod}] ${prod.descripcion} - <strong>$${prod.p_venta}</strong>`;
                        div.onclick = () => agregarProducto(prod);
                        listaProductos.appendChild(div);
                    });
                    listaProductos.style.display = 'block';
                });
        } else {
            listaProductos.style.display = 'none';
        }
    });

    function agregarProducto(p) {
        // Busca usando la misma propiedad que asignas abajo (codigo)
        const existe = items.find(i => i.codigo === p.cod_prod); 
        if (existe) {
            existe.cantidad++;
        } else {
            items.push({
                codigo: p.cod_prod,
                descripcion: p.descripcion,
                precio: parseFloat(p.p_venta),
                precio_actual: parseFloat(p.p_venta),
                variado: false,
                cantidad: 1
            });
        }
        // ... resto de la función
        buscarProducto.value = '';
        listaProductos.style.display = 'none';
        renderizarTabla();
    }

    function renderizarTabla() {
        cuerpoPresupuesto.innerHTML = '';
        let totalGeneral = 0;

        items.forEach((item, index) => {
            const subtotal = item.precio * item.cantidad;
            totalGeneral += subtotal;

            // Determinamos si el precio del presupuesto difiere del precio actual en BD
            const tieneActual = item.precio_actual !== undefined && item.precio_actual !== null;
            const precioActualVal = tieneActual ? parseFloat(item.precio_actual) : null;
            const hayVariacion = tieneActual && Math.abs(precioActualVal - parseFloat(item.precio)) > 0.001;

            let precioActualTd;
            if (tieneActual) {
                precioActualTd = hayVariacion
                    ? `<td style="text-align: right; color:#e67e22; font-weight: bold;" title="Precio actual en BD">
                         <button onclick="actualizarPrecioProducto(${index})" title="Actualizar este producto al precio actual" style="background:none; border:none; color:#2ecc71; cursor:pointer; font-size:0.9rem; margin-right:6px;">
                           <i class="fas fa-rotate-right"></i>
                         </button>⚠ $ ${precioActualVal.toFixed(2)}
                       </td>`
                    : `<td style="text-align: right; color:#2ecc71;" title="Precio actual en BD">$ ${precioActualVal.toFixed(2)}</td>`;
            } else {
                precioActualTd = `<td style="text-align: right; color:#666;">-</td>`;
            }

            const warnPrecio = hayVariacion
                ? `style="color:#e67e22;" title="Variación: presupuesto $${parseFloat(item.precio).toFixed(2)} → actual $${precioActualVal.toFixed(2)}"`
                : '';

            cuerpoPresupuesto.innerHTML += `
                <tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 10px;">${item.codigo}</td>
                    <td><input type="text" class="form-control-custom" value="${item.descripcion}" onchange="editarItem(${index}, 'desc', this.value)"></td>
                    <td><input type="number" class="form-control-custom" value="${item.cantidad}" onchange="editarItem(${index}, 'cant', this.value)"></td>
                    <td><input type="number" class="form-control-custom" value="${item.precio}" onchange="editarItem(${index}, 'precio', this.value)" ${warnPrecio}></td>
                    ${precioActualTd}
                    <td style="text-align: right;">$ ${subtotal.toFixed(2)}</td>
                    <td><button onclick="eliminarItem(${index})" style="background:none; border:none; color:#e74c3c; cursor:pointer;">❌</button></td>
                </tr>
            `;
        });

        totalPresupuestoLabel.innerText = `$ ${totalGeneral.toLocaleString('es-AR', {minimumFractionDigits: 2})}`;
    }

    // Funciones globales para los eventos onchange/onclick de la tabla
    window.editarItem = (index, campo, valor) => {
        if (campo === 'cant') items[index].cantidad = parseFloat(valor);
        if (campo === 'precio') items[index].precio = parseFloat(valor);
        if (campo === 'desc') items[index].descripcion = valor;
        renderizarTabla();
    };

    window.eliminarItem = (index) => {
        items.splice(index, 1);
        renderizarTabla();
    };

    // Actualiza el precio de un producto específico al precio actual de la BD
    window.actualizarPrecioProducto = (index) => {
        const item = items[index];
        if (!item) return;
        if (item.precio_actual === undefined || item.precio_actual === null) {
            mostrarMensaje("Error", "❌ No hay precio actual disponible para este producto.", "error");
            return;
        }
        item.precio = parseFloat(item.precio_actual);
        item.variado = false;
        renderizarTabla();
        mostrarMensaje("Éxito", `✅ Precio de "${item.codigo} - ${item.descripcion}" actualizado a $${item.precio.toFixed(2)}.`);
    };

// Asegúrate de que esta función sea accesible globalmente
window.guardarPresupuesto = async function() {
    const idCliente = document.getElementById('id_cliente_seleccionado').value;
    const campoComentarios = document.getElementById('comentarios');
    
    if (!idCliente || idCliente === "") {
        mostrarMensaje("Atención", "⚠️ Por favor, selecciona un cliente primero.", "error");
        return;
    }
    
    if (items.length === 0) {
        mostrarMensaje("Atención", "⚠️ El presupuesto está vacío. Agrega al menos un producto.", "error");
        return;
    }

    // Preparamos los datos
        const datos = {
        id_cliente: idCliente,
        productos: items,
        total: items.reduce((acc, item) => acc + (item.precio * item.cantidad), 0),
        // Si el elemento existe, toma el valor. Si no, manda un texto vacío.
        observaciones: campoComentarios ? campoComentarios.value : "" 
    };

    try {
        const response = await fetch(APP_BASE + 'pages/guardar_presupuesto_backend.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });

        if (!response.ok) {
            throw new Error(`Error en el servidor: ${response.status}`);
        }

        const resultado = await response.json();

        if (resultado.success) {
            mostrarMensaje("Éxito", "✅ Presupuesto guardado correctamente.", "success", () => {
                // Abrir PDF
                window.open(`generar_pdf_presupuesto.php?id=${resultado.id_presupuesto}`, '_blank');
                // Limpiar todo
                location.reload();
            });
        } else {
            mostrarMensaje("Error", "❌ Error del servidor: " + resultado.error, "error");
        }
    } catch (error) {
        console.error("Error crítico:", error);
        mostrarMensaje("Error", "❌ Error al conectar con el servidor. Revisa la consola.", "error");
    }
};

// Copia los productos de un presupuesto ya emitido al presupuesto actual
window.abrirModalCopiar = function() {
    document.getElementById('modalCopiarPresupuesto').style.display = 'block';
};

window.cerrarModalCopiar = function() {
    document.getElementById('modalCopiarPresupuesto').style.display = 'none';
};

// Mostrar/ocultar el aviso de variaciones de precio
window.cerrarAvisoPrecios = function() {
    document.getElementById('avisoPrecios').style.display = 'none';
};
function mostrarAvisoPrecios(html) {
    document.getElementById('avisoPreciosContenido').innerHTML = html;
    document.getElementById('avisoPrecios').style.display = 'block';
}
function ocultarAvisoPrecios() {
    document.getElementById('avisoPrecios').style.display = 'none';
}

// Cerrar el modal al hacer clic fuera del contenido
function cerrarModalSiClickFuera(event) {
    const modal = document.getElementById('modalCopiarPresupuesto');
    if (event.target == modal) { cerrarModalCopiar(); }
}
document.addEventListener('click', cerrarModalSiClickFuera);

window.copiarPresupuesto = function() {
    const select = document.getElementById('selectPresupuesto');
    const id = select ? select.value : '';
    const usarPrecioActual = document.getElementById('chkPrecioActual').checked;

    if (!id) {
        mostrarMensaje("Atención", "⚠️ Selecciona un presupuesto emitido para copiar sus productos.", "error");
        return;
    }

    // Si ya hay productos cargados, pedimos confirmación
    const ejecutarCopia = function() {
        fetch(`${APP_BASE}ajax/obtener_detalle_presupuesto_json.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                mostrarMensaje("Error", "❌ " + (data.error || "No se pudo copiar el presupuesto."), "error");
                return;
            }

            items.length = 0; // Vaciamos el array actual
            const variaciones = []; // productos cuyo precio cambió en BD

            data.items.forEach(prod => {
                const precioPres = parseFloat(prod.precio);
                const precioAct = (prod.precio_actual !== null && prod.precio_actual !== undefined)
                                  ? parseFloat(prod.precio_actual) : null;

                if (prod.variacion === true) {
                    variaciones.push({
                        codigo: prod.codigo,
                        descripcion: prod.descripcion,
                        precioPres,
                        precioAct
                    });
                }

                items.push({
                    codigo: prod.codigo,
                    descripcion: prod.descripcion,
                    // Si el usuario marcó la casilla, cargamos el precio actual (BD); si no, el del presupuesto
                    precio: (usarPrecioActual && precioAct !== null) ? precioAct : precioPres,
                    precio_actual: precioAct,
                    variado: prod.variacion === true,
                    cantidad: parseFloat(prod.cantidad)
                });
            });

            renderizarTabla();

            // Mostramos el aviso de variaciones de precio
            if (variaciones.length > 0) {
                const lineas = variaciones.slice(0, 8).map(v =>
                    `<b>${v.codigo}</b> - ${v.descripcion}: <span style="color:#e67e22;">$${v.precioPres.toFixed(2)}</span> → <span style="color:#2ecc71;">$${v.precioAct !== null ? v.precioAct.toFixed(2) : 'N/D'}</span>`
                ).join('<br>');
                const resto = variaciones.length > 8 ? `<br><i>... y ${variaciones.length - 8} más.</i>` : '';
                mostrarAvisoPrecios(lineas + resto);
            } else {
                ocultarAvisoPrecios();
            }

            cerrarModalCopiar();
            select.value = "";
            if (variaciones.length > 0) {
                mostrarMensaje("Aviso", `⚠️ ${variaciones.length} producto(s) cambiaron de precio. Revisa la tabla.`, "success");
            } else {
                mostrarMensaje("Éxito", `✅ Se copiaron ${items.length} producto(s) (sin variaciones de precio).`, "success");
            }
        })
        .catch(error => {
            console.error("Error al copiar presupuesto:", error);
            mostrarMensaje("Error", "❌ Error al conectar con el servidor.", "error");
        });
    };

    if (items.length > 0) {
        confirmarAccion("Copiar Presupuesto", "⚠️ Esto reemplazará los productos actualmente cargados. ¿Deseas continuar?", "SÍ, CONTINUAR", "btn-primary", ejecutarCopia);
    } else {
        ejecutarCopia();
    }
};

});
