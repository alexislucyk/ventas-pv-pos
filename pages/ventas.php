<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

// 1. Control de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

require '../config/db_config.php'; 
$mensaje = '';

// --- Bloque para obtener clientes ---
try {
    $sql_clientes = "SELECT 
                        id AS id_cliente,
                        CONCAT(apellido, ', ', nombre) AS nombre_completo,
                        cuit AS num_documento 
                      FROM clientes 
                      ORDER BY nombre_completo ASC";
                      
    $stmt_clientes = $pdo->query($sql_clientes);
    $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) {
    error_log("Error al cargar clientes: " . $e->getMessage());
    $clientes = []; 
}
// --- Fin bloque clientes ---

// --- Bloque para obtener el Siguiente N° de Documento (Factura) ---
$siguiente_n_documento = 1; 

try {
    // Asume que n_documento en la tabla 'ventas' es único y se incrementa.
    $sql_ultimo_doc = "SELECT MAX(n_documento) AS ultimo_doc FROM ventas";
    $stmt_ultimo_doc = $pdo->query($sql_ultimo_doc);
    $resultado = $stmt_ultimo_doc->fetch(PDO::FETCH_ASSOC);

    if ($resultado && $resultado['ultimo_doc'] !== null) {
        $siguiente_n_documento = $resultado['ultimo_doc'] + 1;
    }
} catch (Exception $e) {
    error_log("Error al buscar el último N° de Documento: " . $e->getMessage());
    // Valor de respaldo alto en caso de fallo de DB
    $siguiente_n_documento = 999999; 
}
// --- Fin Bloque N° Documento ---

// ----------------------------------------------------
// LÓGICA DE PROCESAMIENTO DE VENTA (TRANSACCIÓN PDO)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_venta'])) {
    
    // 1. Obtener y sanitizar datos de la cabecera
    $id_cliente = (int)$_POST['cliente_id'];
    $cond_pago = trim($_POST['cond_pago']); 
    $n_documento = (int)$_POST['n_documento'];
    $total_venta = (float)$_POST['total_venta'];
    $pago_efectivo = (float)$_POST['pago_efectivo']; 
    $pago_transf = (float)$_POST['pago_transf']; 
    $fecha_venta = date('Y-m-d'); 

    $detalle_productos = json_decode($_POST['detalle_productos'], true); 

    if (empty($detalle_productos)) {
        $mensaje = "❌ Error: No se puede registrar una venta sin productos.";
    } else {
        try {
            $pdo->beginTransaction(); // === INICIA LA TRANSACCIÓN ===

            // 2. INSERTAR CABECERA (Tabla 'ventas')
            $sql_venta = "INSERT INTO ventas (id_cliente, cond_pago, n_documento, total_venta, pago_efectivo, pago_transf, fecha_venta) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt_venta = $pdo->prepare($sql_venta);
            $stmt_venta->execute([
                $id_cliente, 
                $cond_pago, 
                $n_documento, 
                $total_venta, 
                $pago_efectivo, 
                $pago_transf, 
                $fecha_venta
            ]);
            
            // NOTE: No necesitamos $id_venta = $pdo->lastInsertId() para el detalle, 
            // ya que usaremos $n_documento para el enlace.

            // 3. INSERTAR DETALLE y ACTUALIZAR STOCK
            // CORREGIDO: Usamos la tabla 'ventas_detalle' y sus columnas correctas, 
            // y ELIMINAMOS 'id_venta' ya que el enlace será 'n_documento'.
            $sql_detalle = "INSERT INTO ventas_detalle (cod_prod, descripcion, cant, p_unit, total, n_documento, fecha) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                            
            $sql_stock_update = "UPDATE productos SET stock = stock - ? WHERE cod_prod = ?";

            foreach ($detalle_productos as $item) {
                // Insertar detalle
                $stmt_detalle = $pdo->prepare($sql_detalle);
                // 7 parámetros en el execute, correspondientes a los 7 campos en el INSERT
                $stmt_detalle->execute([
                    $item['cod_prod'],
                    $item['descripcion'], 
                    $item['cant'],        
                    $item['p_unit'],      
                    $item['total'],       
                    $n_documento,         
                    $fecha_venta          
                ]);

                // Actualizar stock
                $stmt_stock = $pdo->prepare($sql_stock_update);
                $stmt_stock->execute([
                    $item['cant'], 
                    $item['cod_prod']
                ]);
            }
            
            $pdo->commit(); // === CONFIRMA LA TRANSACCIÓN ===
            $mensaje = "✅ Venta (Documento {$n_documento}) registrada y stock actualizado con éxito.";
            // Opcional: Recargar el documento para limpiar el POST y obtener el siguiente N° de Factura
            header("Location: ventas.php"); exit();

        } catch (Exception $e) {
            $pdo->rollBack(); // === REVierte la transacción si algo falló ===
            $mensaje = "❌ Error al procesar la venta. La transacción fue revertida. Mensaje: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Venta | Mi Negocio POS</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .venta-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        #carrito tbody tr:hover { background-color: #333; }
        .producto-encontrado, .resultado-cliente-item { cursor: pointer; padding: 5px; border-bottom: 1px solid #444; }
        .producto-encontrado:hover, .resultado-cliente-item:hover { background-color: #555; }
        #resultadosBusqueda { max-height: 200px; overflow-y: auto; background: #222; border: 1px solid #444; position: absolute; width: 45%; z-index: 10; }
        #resultadosBusquedaClientes { max-height: 200px; overflow-y: auto; background: #222; border: 1px solid #444; position: absolute; width: 300px; z-index: 10; }
        .text-right { text-align: right; }
        .alert-error { background-color: #f44336; }
        .alert-success { background-color: #4caf50; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 5px; color: white; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 

    <div class="content">
        <h1>Nueva Venta</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="venta-grid">
            
            <div class="card">
                <h2>Detalle de Productos</h2>
                
                <label for="buscar_producto">Buscar Producto (Código o Descripción)</label>
                <input type="text" id="buscar_producto" class="input-field" placeholder="Escriba aquí el código o nombre del producto">
                <div id="resultadosBusqueda"></div>

                <hr>

                <h3>Carrito de Venta</h3>
                <table id="carrito" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th class="text-right">Precio</th>
                            <th style="width: 10%;">Cant.</th>
                            <th class="text-right">Subtotal</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                </table>
            </div>
            
            <div class="card">
                <form id="formVenta" method="POST" action="ventas.php">
                    <input type="hidden" name="guardar_venta" value="1">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">

                    <h2>Datos de la Venta</h2>

                    <div class="contenedor-busqueda-cliente" style="margin-bottom: 20px; position: relative;"> 
                        <label for="buscar_cliente">Buscar Cliente (Nombre o CUIT)</label>
                        <input type="text" id="buscar_cliente" class="input-field" placeholder="Venta Genérica">
                        <div id="resultadosBusquedaClientes" style="left: 0;"></div>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        Cliente Actual: <strong id="nombre_cliente_display">Venta Genérica</strong>
                    </div>

                    <input type="hidden" name="cliente_id" id="cliente_id_hidden" value="0">

                    <label for="num_documento_display">CUIT/Documento</label>
                    <input type="text" id="num_documento_display" class="input-field" value="" readonly>

                    <label for="n_documento">N° Documento (Factura)*</label>
                    <input 
                        type="number" 
                        id="n_documento" 
                        name="n_documento" 
                        class="input-field" 
                        value="<?php echo htmlspecialchars($siguiente_n_documento); ?>" 
                        readonly 
                        required>

                    <hr>

                    <h3>Totales y Pago</h3>

                    <label for="cond_pago">Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field" required>
                        <option value="Contado" selected>Contado</option>
                        <option value="Cuenta Corriente">Cuenta Corriente</option>
                    </select>

                    <div id="contenedor_pagos"> 
                        
                        <div style="display: flex; justify-content: space-between; font-size: 1.2em; margin-bottom: 10px;">
                            <strong>TOTAL VENTA:</strong> 
                            <strong id="total_venta_display" style="color: lightgreen;">$0.00</strong>
                        </div>

                        <input type="hidden" name="total_venta" id="total_venta_input" value="0.00">

                        <label for="pago_efectivo">Pago en Efectivo</label>
                        <input type="number" step="0.01" id="pago_efectivo" name="pago_efectivo" class="input-field pago-input" value="0.00" min="0">

                        <label for="pago_transf">Pago con Transferencia</label>
                        <input type="number" step="0.01" id="pago_transf" name="pago_transf" class="input-field pago-input" value="0.00" min="0">
                        
                        <div style="display: flex; justify-content: space-between; font-size: 1.1em; margin-top: 15px;">
                            <strong>Cambio / Saldo:</strong> 
                            <strong id="cambio_saldo_display">$0.00</strong> 
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                        Finalizar y Guardar Venta
                    </button>
                </form>
            </div>
            
        </div>
    </div>
</body>
</html>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        let carrito = []; 

        // ===========================================
        // 1. FUNCIONALIDAD DEL CARRITO Y CÁLCULOS
        // ===========================================

        function calcularTotales() {
            const selectCondPago = document.getElementById('cond_pago');
            const pagoEfectivoInput = document.getElementById('pago_efectivo');
            const pagoTransfInput = document.getElementById('pago_transf');
            const cambioSaldoStrong = document.getElementById('cambio_saldo_display');
            const totalVentaInputHidden = document.getElementById('total_venta_input');
            const totalVentaDisplay = document.getElementById('total_venta_display');

            // 1. Calcular el Total de la Venta 
            let totalVenta = carrito.reduce((sum, item) => sum + item.total, 0);

            // Actualizar el display y el campo oculto
            totalVentaDisplay.textContent = '$' + totalVenta.toFixed(2);
            totalVentaInputHidden.value = totalVenta.toFixed(2); 

            // 2. LÓGICA DE CONDICIÓN DE PAGO
            if (selectCondPago.value === 'Cuenta Corriente') {
                cambioSaldoStrong.textContent = 'Cta. Cte.';
                cambioSaldoStrong.style.color = '#00bcd4'; 
                
                // Forzar los valores de pago a 0.00 para el POST
                pagoEfectivoInput.value = '0.00';
                pagoTransfInput.value = '0.00';
                return; 
            }
            
            // Si es Contado, sumar ambos pagos
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
                        <input type="number" min="1" step="any" value="${item.cant}" data-cod-prod="${item.cod_prod}"
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

        // Eventos para actualizar cantidad y remover item
        document.querySelector('#carrito').addEventListener('change', function(e) {
            if (e.target.classList.contains('update-cantidad')) {
                const cod_prod = e.target.dataset.codProd;
                const nuevaCantidad = parseFloat(e.target.value);
                
                if (nuevaCantidad > 0) {
                    const index = carrito.findIndex(item => item.cod_prod === cod_prod);
                    if (index !== -1) {
                        if (nuevaCantidad > carrito[index].stock_disponible) {
                            alert(`Stock insuficiente. Stock disponible: ${carrito[index].stock_disponible}`);
                            e.target.value = carrito[index].cant; 
                            return;
                        }

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
        const pagoEfectivoInput = document.getElementById('pago_efectivo');
        const pagoTransfInput = document.getElementById('pago_transf');
        const selectCondPago = document.getElementById('cond_pago');
        const contenedorPagos = document.getElementById('contenedor_pagos');
        
        pagoEfectivoInput.addEventListener('input', calcularTotales);
        pagoTransfInput.addEventListener('input', calcularTotales);
        
        selectCondPago.addEventListener('change', function() {
            if (this.value === 'Cuenta Corriente') {
                contenedorPagos.style.display = 'none'; 
                pagoEfectivoInput.value = '0.00'; 
                pagoTransfInput.value = '0.00'; 
            } else { 
                contenedorPagos.style.display = 'block';
            }
            calcularTotales(); 
        });


        // ===========================================
        // 2. BÚSQUEDA DE PRODUCTOS (AJAX)
        // ===========================================

        const inputBuscar = document.getElementById('buscar_producto');
        const resultadosDiv = document.getElementById('resultadosBusqueda');

        inputBuscar.addEventListener('input', function() {
            const busqueda = inputBuscar.value.trim();
            if (busqueda.length < 3) {
                resultadosDiv.innerHTML = '';
                return;
            }

            const xhr = new XMLHttpRequest();
            // Asegúrate de que 'buscar_producto_ajax.php' esté usando sentencias preparadas
            xhr.open('GET', 'buscar_producto_ajax.php?q=' + encodeURIComponent(busqueda), true); 
            xhr.onload = function() {
                if (this.status == 200) {
                    try {
                        const productos = JSON.parse(this.responseText);
                        mostrarResultados(productos);
                    } catch (e) {
                        resultadosDiv.innerHTML = 'Error al procesar la respuesta.';
                    }
                } else {
                    resultadosDiv.innerHTML = 'Error en la búsqueda.';
                }
            };
            xhr.send();
        });

        function mostrarResultados(productos) {
            resultadosDiv.innerHTML = '';
            if (productos.length === 0) {
                resultadosDiv.innerHTML = '<div style="padding: 10px;">No se encontraron productos.</div>';
                return;
            }

            productos.forEach(producto => {
                const div = document.createElement('div');
                div.classList.add('producto-encontrado');
                div.textContent = `[${producto.cod_prod}] ${producto.descripcion} ($${parseFloat(producto.p_venta).toFixed(2)}) - Stock: ${producto.stock}`;
                div.dataset.producto = JSON.stringify(producto);
                
                div.addEventListener('click', function() {
                    agregarACarrito(JSON.parse(this.dataset.producto));
                    inputBuscar.value = ''; 
                    resultadosDiv.innerHTML = ''; 
                });
                resultadosDiv.appendChild(div);
            });
        }

        function agregarACarrito(producto) {
            const index = carrito.findIndex(item => item.cod_prod === producto.cod_prod);
            
            const stock_disponible = parseFloat(producto.stock);

            if (stock_disponible <= 0) {
                 alert('Stock agotado para este producto.');
                 return;
            }

            if (index !== -1) {
                if (carrito[index].cant + 1 > stock_disponible) {
                     alert(`No hay suficiente stock. Stock actual: ${stock_disponible}`);
                     return;
                }
                carrito[index].cant += 1; 
                carrito[index].total = carrito[index].cant * carrito[index].p_unit; 
            } else {
                carrito.push({
                    cod_prod: producto.cod_prod,
                    descripcion: producto.descripcion,
                    p_unit: parseFloat(producto.p_venta), 
                    cant: 1, 
                    total: parseFloat(producto.p_venta), 
                    stock_disponible: stock_disponible
                });
            }
            renderizarCarrito();
        }


        // ===========================================
        // 3. PREPARAR EL FORMULARIO PARA EL POST
        // ===========================================
        document.getElementById('formVenta').addEventListener('submit', function(e) {
            if (carrito.length === 0) {
                alert('No puedes guardar una venta sin productos.');
                e.preventDefault();
                return;
            }

            // Validación de pago si es Contado
            const totalVenta = parseFloat(document.getElementById('total_venta_input').value);
            const condPago = document.getElementById('cond_pago').value;

            if (condPago === 'Contado') {
                const pagoEfectivo = parseFloat(pagoEfectivoInput.value) || 0;
                const pagoTransferencia = parseFloat(pagoTransfInput.value) || 0;
                const totalPagado = pagoEfectivo + pagoTransferencia;

                if (totalPagado < totalVenta) {
                    alert(`El total pagado ($${totalPagado.toFixed(2)}) es menor al total de la venta ($${totalVenta.toFixed(2)}). Por favor, ingrese el pago completo o cambie a Cuenta Corriente.`);
                    e.preventDefault();
                    return;
                }
            }

            // Serializar el array carrito a JSON y ponerlo en el input oculto
            document.getElementById('detalle_productos_input').value = JSON.stringify(carrito);
        });

        
        // ===========================================
        // 4. BÚSQUEDA DE CLIENTES (AJAX)
        // ===========================================

        const inputBuscarCliente = document.getElementById('buscar_cliente');
        const resultadosDivClientes = document.getElementById('resultadosBusquedaClientes');
        const clienteIdHidden = document.getElementById('cliente_id_hidden');
        const nombreClienteDisplay = document.getElementById('nombre_cliente_display');
        const numDocumentoDisplay = document.getElementById('num_documento_display');

        inputBuscarCliente.addEventListener('input', function() {
            const busqueda = inputBuscarCliente.value.trim();
            if (busqueda.length < 3) {
                resultadosDivClientes.innerHTML = '';
                return;
            }

            const xhr = new XMLHttpRequest();
            // Asegúrate de que 'buscar_cliente_ajax.php' exista y use sentencias preparadas
            xhr.open('GET', 'buscar_cliente_ajax.php?q=' + encodeURIComponent(busqueda), true); 
            
            xhr.onload = function() {
                if (this.status == 200) {
                    try {
                        const clientes = JSON.parse(this.responseText);
                        mostrarResultadosClientes(clientes);
                    } catch (e) {
                        resultadosDivClientes.innerHTML = '<div style="padding: 5px; color: #ff5757;">Error al procesar la respuesta de clientes.</div>';
                    }
                }
            };
            xhr.send();
        });

        function mostrarResultadosClientes(clientes) {
            resultadosDivClientes.innerHTML = '';
            if (clientes.length === 0) {
                resultadosDivClientes.innerHTML = '<div style="padding: 10px;">No se encontraron clientes.</div>';
                return;
            }

            clientes.forEach(cliente => {
                const div = document.createElement('div');
                div.classList.add('resultado-cliente-item');
                div.textContent = `${cliente.nombre_completo} (CUIT: ${cliente.num_documento})`;
                
                div.dataset.clienteId = cliente.id_cliente;
                div.dataset.nombre = cliente.nombre_completo;
                div.dataset.documento = cliente.num_documento;
                
                div.addEventListener('click', function() {
                    seleccionarCliente(this.dataset);
                });
                resultadosDivClientes.appendChild(div);
            });
        }
        
        function seleccionarCliente(datos) {
            clienteIdHidden.value = datos.clienteId;
            nombreClienteDisplay.textContent = datos.nombre;
            numDocumentoDisplay.value = datos.documento;
            
            inputBuscarCliente.value = '';
            resultadosDivClientes.innerHTML = '';
        }

        // Listener para limpiar resultados de cliente
        inputBuscarCliente.addEventListener('blur', function() {
            setTimeout(() => { 
                  resultadosDivClientes.innerHTML = '';
            }, 200);
        });

        // Resetear a cliente genérico si se borra el input
        inputBuscarCliente.addEventListener('keyup', function() {
            if (this.value === '' && clienteIdHidden.value !== '0') {
                clienteIdHidden.value = 0;
                nombreClienteDisplay.textContent = 'Venta Genérica';
                numDocumentoDisplay.value = '';
            }
        });


        // Inicializar cálculos y estado de la condición de pago
        selectCondPago.dispatchEvent(new Event('change'));
        calcularTotales();

    });
</script>