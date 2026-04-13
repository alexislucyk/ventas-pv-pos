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
                // Busca esta sección en tu ventas.js (Buscador de Productos)
                data.forEach(prod => {
                    const div = document.createElement('div');
                    div.className = 'resultado-item';
                    div.style.cursor = 'pointer';
                    div.style.padding = '8px';
                    div.style.borderBottom = '1px solid #eee';

                    // Lógica para el color del stock
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
                        // Validación opcional: evitar agregar si no hay stock
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
            
            // CORRECCIÓN: Usar 'id_cliente_hidden' para que coincida con tu PHP y tu validación
            const idHidden = document.getElementById('id_cliente_hidden'); 
            const nombreDisplay = document.getElementById('nombre_cliente_display');
            
            if (idHidden) {
                idHidden.value = cliente.id_cliente;
                console.log("ID Cliente asignado:", idHidden.value); // Debug para que veas que funciona
            }
            
            if (nombreDisplay) {
                // Si es un div/span usa innerText, si es input usa value
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
    
    // VALIDACIÓN CRÍTICA: Aseguramos que p_compra y p_venta sean números y no null/undefined
    const pVenta = parseFloat(prod.p_venta) || 0;
    const pCosto = parseFloat(prod.p_compra) || parseFloat(prod.p_costo) || 0; 

    if (pCosto === 0) {
        console.warn("Advertencia: El producto no tiene precio de costo definido.");
    }

    if (existe) {
        existe.cant++;
        existe.total = existe.cant * existe.p_unit;
    } else {
        carrito.push({
            cod_prod: prod.cod_prod,
            descripcion: prod.descripcion,
            p_unit: pVenta,
            p_costo: pCosto, // Si esto llega en 0, la DB no dará error (pero cuidado con la ganancia)
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
    if (inputDetalle) {
        // Esto envía el JSON a PHP
        inputDetalle.value = JSON.stringify(carrito);
    }
}

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

// 1. Ver la tabla que ya hiciste (HTML)
function verVistaPrevia(nDoc) {
    fetch(`ajax/obtener_detalle_venta.php?n_documento=${nDoc}&formato=html`)
        .then(res => res.text())
        .then(html => {
            // Aquí puedes volcar el HTML en un div de "Detalle" dentro del mismo modal
            document.getElementById('divDetalleVistaPrevia').innerHTML = html;
        });
}

// 2. Reanudar la venta (JSON)
window.reanudarVenta = function(nDocumento) {
    fetch(`../ajax/obtener_detalle_venta.php?n_documento=${nDocumento}&formato=json`)
        .then(res => res.json())
        .then(data => {
            console.log("Datos para reanudar:", data);

            if (!data.productos || data.productos.length === 0) {
                alert("La venta no tiene productos.");
                return;
            }

            // 1. Vaciar el carrito actual
            carrito = [];

            // 2. Mapear y cargar los productos
            data.productos.forEach(p => {
                carrito.push({
                    cod_prod: p.cod_prod,
                    descripcion: p.descripcion,
                    p_unit: parseFloat(p.p_unit),
                    p_costo: parseFloat(p.p_costo) || 0, // Mantenemos el costo si viene en el JSON
                    cant: parseFloat(p.cant),
                    total: parseFloat(p.total)
                });
            });

            // 3. Cargar el ID de la venta para saber cuál estamos editando
            const inputIdVenta = document.getElementById('id_venta_existente');
            if (inputIdVenta) inputIdVenta.value = data.cabecera.id;

            // --- NUEVO: CARGAR DATOS DEL CLIENTE ---
            const idHidden = document.getElementById('id_cliente_hidden');
            const nombreDisplay = document.getElementById('nombre_cliente_display');

            // Seteamos el ID del cliente (v.id_cliente del PHP)
            if (idHidden && data.cabecera.id_cliente) {
                idHidden.value = data.cabecera.id_cliente;
            } else if (idHidden) {
                idHidden.value = "0"; // Si no hay cliente, es venta genérica
            }

            // Seteamos el nombre en el display azul
            if (nombreDisplay) {
                const nombre = data.cabecera.cliente_nombre_completo || 'Público General';
                if (nombreDisplay.tagName === 'INPUT') {
                    nombreDisplay.value = nombre;
                } else {
                    nombreDisplay.innerText = nombre;
                }
            }
            // ---------------------------------------

            // 4. Renderizar y cerrar modal
            renderizarCarrito();

            if (typeof cerrarModalPendientes === 'function') {
                cerrarModalPendientes();
            } else {
                document.getElementById('pendientesModal').style.display = 'none';
            }
            
            console.log("Venta reanudada: Cliente ID", data.cabecera.id_cliente);
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
            console.log("Abriendo modal..."); // Para debug en consola (F12)
            
            const modal = document.getElementById('pendientesModal');
            const lista = document.getElementById('listaPendientes');
            
            // Mostrar modal
            modal.style.display = 'block';
            lista.innerHTML = '<p>Cargando ventas...</p>';

            // Cargar contenido
            fetch('../ajax/ventas_pendientes_ajax.php')
                .then(response => {
                    if (!response.ok) throw new Error('Error en la red');
                    return response.text();
                })
                .then(html => {
                    lista.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error:', error);
                    lista.innerHTML = 'Error al cargar los datos.';
                });
        });
    } else {
        console.error("No se encontró el botón con ID 'btnVerPendiente'");
    }
});

// Función para cerrar (puedes llamarla desde el botón X)
function cerrarModalPendientes() {
    document.getElementById('pendientesModal').style.display = 'none';
}

// --- 4. VALIDACIÓN DE VENTA FINAL (AGREGAR AL FINAL DE VENTAS.JS) ---
// --- VALIDACIÓN DE VENTA FINAL ---
const formVenta = document.getElementById('formVenta');

if (formVenta) {
    formVenta.addEventListener('submit', function(e) {
        const condicion = document.getElementById('cond_pago').value; 
        // Usamos el ID exacto que tienes en tu HTML:
        const idCliente = document.getElementById('id_cliente_hidden').value;
        const action = document.getElementById('venta_action_input').value;

        if (action === 'Finalizar' && condicion === 'CUENTA CORRIENTE') {
            // Validamos si es 0, vacío o el texto por defecto
            if (idCliente == "0" || idCliente === "" || !idCliente) {
                e.preventDefault();
                alert("⛔ Error: Para vender en CUENTA CORRIENTE debes seleccionar un cliente real. 'Venta Genérica' no puede tener deuda.");
                return false;
            }
        }
    });
}