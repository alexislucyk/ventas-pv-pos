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
            fetch(`buscar_cliente_ajax.php?q=${query}`)
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
            fetch(`buscar_producto_ajax.php?q=${query}`)
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

            cuerpoPresupuesto.innerHTML += `
                <tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 10px;">${item.codigo}</td>
                    <td><input type="text" class="form-control-custom" value="${item.descripcion}" onchange="editarItem(${index}, 'desc', this.value)"></td>
                    <td><input type="number" class="form-control-custom" value="${item.cantidad}" onchange="editarItem(${index}, 'cant', this.value)"></td>
                    <td><input type="number" class="form-control-custom" value="${item.precio}" onchange="editarItem(${index}, 'precio', this.value)"></td>
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
        const response = await fetch('guardar_presupuesto_backend.php', {
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

});
