<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

// -----------------------------------------------------
// 1. CONTROL DE ACCESO Y CONFIGURACIÓN
// -----------------------------------------------------
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php'); 
    exit();
}

require '../config/db_config.php'; 

$mensaje = '';
$error = false;
$id_compra_generada = null; // Para mostrar en el mensaje de éxito

// -----------------------------------------------------
// 2. BLOQUE DE PROCESAMIENTO DE REGISTRO DE COMPRA (POST)
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_compra'])) {
    
    // 2.1. Recibir y Sanitizar Datos del Formulario
    $id_proveedor   = filter_var($_POST['proveedor_id'], FILTER_VALIDATE_INT);
    $cond_pago      = htmlspecialchars($_POST['cond_pago']);
    $documento_tipo = htmlspecialchars($_POST['documento']);
    $n_documento    = htmlspecialchars($_POST['n_documento']);
    $total_compra   = filter_var($_POST['total_compra'], FILTER_VALIDATE_FLOAT);
    $fecha_compra   = htmlspecialchars($_POST['fecha_compra']);
    $detalle_json   = $_POST['detalle_productos']; // El JSON del carrito
    $fecha_operacion = date('Y-m-d H:i:s'); // Fecha de registro en el sistema
    $usuario_id     = $_SESSION['usuario_id']; // Asume que tienes este campo en la tabla 'compras'

    // 2.2. Validaciones Críticas
    if (!$id_proveedor || $total_compra <= 0 || empty($n_documento) || empty($detalle_json)) {
        $error = true;
        $mensaje = "❌ ERROR: Faltan datos críticos (Proveedor, N° Documento o Carrito vacío).";
    }

    if (!$error) {
        $productos_detalle = json_decode($detalle_json, true);

        if (json_last_error() !== JSON_ERROR_NONE || count($productos_detalle) === 0) {
            $error = true;
            $mensaje = "❌ ERROR: El detalle de productos es inválido o está vacío.";
        }
    }
    
    if (!$error) {
        
        try {
            // INICIAR TRANSACCIÓN: Asegura la integridad de las 3 tablas (compras, detalle, productos)
            $pdo->beginTransaction();

            // ---------------------------------------------------------
            // A. INSERTAR CABECERA EN 'compras'
            // ---------------------------------------------------------
            $sql_cabecera = "INSERT INTO compras (cod_proveedor, cond_pago, documento, n_documento, total_compra, fecha_compra, fecha_operacion) 
                             VALUES (:prov, :cond, :doc_tipo, :n_doc, :total, :f_compra, :f_op)";
            $stmt_cabecera = $pdo->prepare($sql_cabecera);
            $stmt_cabecera->execute([
                ':prov' => $id_proveedor,
                ':cond' => $cond_pago,
                ':doc_tipo' => $documento_tipo,
                ':n_doc' => $n_documento,
                ':total' => $total_compra,
                ':f_compra' => $fecha_compra,
                ':f_op' => $fecha_operacion
            ]);
            
            $id_compra_generada = $pdo->lastInsertId();

            // ---------------------------------------------------------
            // B. PROCESAR DETALLE E INVENTARIO (Último Costo de Compra)
            // ---------------------------------------------------------
            $sql_detalle = "INSERT INTO compras_detalle (cod_prod, descripcion, cant, p_unit, total, n_documento, fecha) 
                            VALUES (:cod, :desc, :cant, :punit, :total_linea, :n_doc, :fecha)";
            $stmt_detalle = $pdo->prepare($sql_detalle);

            // 1. Preparamos las consultas que se usarán en el bucle
            // A) Consulta para obtener el stock actual
            $sql_get_stock = "SELECT stock FROM productos WHERE cod_prod = :cod"; 
            $stmt_get_stock = $pdo->prepare($sql_get_stock);

            // B) Consulta para actualizar stock y costo
            $sql_update_prod = "UPDATE productos SET 
                                    stock = stock + :cant_sumada, 
                                    p_compra = :nuevo_costo_unit  /* SOBREESCRIBIMOS con el nuevo precio */
                                WHERE cod_prod = :cod";
            $stmt_update_prod = $pdo->prepare($sql_update_prod);


            foreach ($productos_detalle as $item) {
                $cod_prod = htmlspecialchars($item['cod_prod']);
                $descripcion = htmlspecialchars($item['descripcion']);
                $cant = filter_var($item['cant'], FILTER_VALIDATE_FLOAT);
                $p_unit = filter_var($item['p_unit'], FILTER_VALIDATE_FLOAT);
                $total_linea = $cant * $p_unit; 

                // 1. Insertar en compras_detalle
                $stmt_detalle->execute([
                    ':cod' => $cod_prod,
                    ':desc' => $descripcion,
                    ':cant' => $cant,
                    ':punit' => $p_unit,
                    ':total_linea' => $total_linea,
                    ':n_doc' => $n_documento,
                    ':fecha' => $fecha_compra
                ]);

                // 2. Actualizar Stock y Costo (p_costo) en productos
                
                // NOTA: Para este método, solo necesitamos el UPDATE, 
                // ya que el nuevo costo unitario (p_unit) sobrescribe el anterior.

                $stmt_update_prod->execute([
                    ':cant_sumada' => $cant,
                    ':nuevo_costo_unit' => $p_unit,
                    ':cod' => $cod_prod
                ]);
            }
            
            // ... (Continúa el resto del código: C. CUENTA CORRIENTE, D. FINALIZAR)
            
            // ---------------------------------------------------------
            // C. CUENTA CORRIENTE DE PROVEEDORES
            // ---------------------------------------------------------
            if ($cond_pago === 'CRÉDITO') {
                // Asume la tabla 'ctacte_proveedores'
                $sql_ctacte = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha) 
                               VALUES (:id_prov, :mov, 0, :total, :n_doc, :fecha_op)";
                $stmt_ctacte = $pdo->prepare($sql_ctacte);
                $stmt_ctacte->execute([
                    ':id_prov' => $id_proveedor,
                    ':mov' => 'FACTURA COMPRA', 
                    ':total' => $total_compra, // El total va en HABER (deuda para nosotros)
                    ':n_doc' => $n_documento,
                    ':fecha_op' => $fecha_operacion
                ]);
            }
            
            // ---------------------------------------------------------
            // D. FINALIZAR
            // ---------------------------------------------------------
            $pdo->commit();
            $mensaje = "✅ Compra N° $id_compra_generada (Doc: $n_documento) registrada con éxito. Stock y Costos actualizados.";
            // Limpiar variables de POST para evitar recarga accidental
            unset($_POST);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = true;
            $mensaje = "❌ ERROR CRÍTICO EN LA TRANSACCIÓN: " . $e->getMessage();
            error_log("Error de Compra: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        }
    }
}

// -----------------------------------------------------
// 3. CARGA INICIAL DE PROVEEDORES (Para JavaScript)
// -----------------------------------------------------

$proveedores = [];
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $mensaje_carga = "❌ ERROR CRÍTICO: La conexión a la base de datos no está disponible.";
    error_log($mensaje_carga);
} else {
    try {
        // CORRECCIÓN: Usando cod_prov, razon
        $sql_proveedores = "SELECT 
                                cod_prov AS id_proveedor, 
                                razon AS nombre, 
                                cuit 
                            FROM proveedores 
                            ORDER BY razon ASC";
                            
        $stmt_proveedores = $pdo->query($sql_proveedores);
        $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC); 
    } catch (Exception $e) {
        error_log("Error al cargar proveedores: " . $e->getMessage());
        $mensaje = "⚠️ Advertencia: No se pudieron cargar los proveedores.";
        $proveedores = []; 
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Compra | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Estilos base responsive para la cuadrícula */
        .compra-grid { 
            display: grid; 
            grid-template-columns: 2fr 1fr; 
            gap: 20px; 
        }
        /* Media Query para pantallas pequeñas (apilamiento) */
        @media (max-width: 1366px) {
            .compra-grid {
                grid-template-columns: 1fr; /* Columna única */
            }
        }
        /* Estilos de búsqueda y carrito */
        #carrito tbody tr:hover { background-color: #333; }
        .producto-encontrado, .resultado-proveedor-item { cursor: pointer; padding: 5px; border-bottom: 1px solid #444; }
        .producto-encontrado:hover, .resultado-proveedor-item:hover { background-color: #555; }
        #resultadosBusqueda, #resultadosBusquedaProveedores { 
            max-height: 200px; 
            overflow-y: auto; 
            background: #222; 
            border: 1px solid #444; 
            position: absolute; 
            width: 90%; 
            z-index: 10; 
        }
        .text-right { text-align: right; }
        .alert-error { background-color: #f44336; }
        .alert-success { background-color: #4caf50; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 5px; color: white; }
    </style>
</head>
<body>

    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 
    
    <div class="content">
        <h1>Registro de Compra a Proveedores</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo $error ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="compra-grid">
            
            <div class="card">
                <h2>Detalle de Productos Comprados</h2>
                
                <label for="buscar_producto">Buscar Producto (Código o Descripción)</label>
                <input type="text" id="buscar_producto" class="input-field" placeholder="Escriba el código o nombre del producto">
                <div id="resultadosBusqueda"></div>

                <hr>

                <h3>Carrito de Compra</h3>
                <table id="carrito" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th class="text-right">Costo Unit.</th>
                            <th style="width: 10%;">Cant.</th>
                            <th class="text-right">Total</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <form id="formCompra" method="POST" action="compras.php">
                    <input type="hidden" name="registrar_compra" value="1">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">

                    <h2>Datos del Proveedor y Factura</h2>

                    <div class="contenedor-busqueda-proveedor" style="margin-bottom: 20px; position: relative;"> 
                        <label for="buscar_proveedor">Buscar Proveedor (Nombre o CUIT)</label>
                        <input type="text" id="buscar_proveedor" class="input-field" placeholder="Seleccionar Proveedor">
                        <div id="resultadosBusquedaProveedores" style="left: 0;"></div>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        Proveedor Actual: <strong id="nombre_proveedor_display">No Seleccionado</strong>
                    </div>

                    <input type="hidden" name="proveedor_id" id="proveedor_id_hidden" value="0">
                    
                    <label for="cuit_proveedor_display">CUIT/Documento</label>
                    <input type="text" id="cuit_proveedor_display" class="input-field" value="" readonly>

                    <hr>

                    <label for="documento_tipo">Tipo de Documento</label>
                    <select id="documento_tipo" name="documento" class="input-field" required>
                        <option value="FACTURA A">FACTURA A</option>
                        <option value="FACTURA B">FACTURA B</option>
                        <option value="FACTURA C">FACTURA C</option>
                        <option value="REMITO">REMITO</option>
                        <option value="RECIBO">RECIBO</option>
                        <option value="OTROS">OTROS</option>
                    </select>

                    <label for="n_documento">N° Documento (Factura Proveedor)*</label>
                    <input 
                        type="text" 
                        id="n_documento" 
                        name="n_documento" 
                        class="input-field" 
                        placeholder="N° de Factura"
                        required>
                        
                    <label for="fecha_compra">Fecha del Documento*</label>
                    <input 
                        type="date" 
                        id="fecha_compra" 
                        name="fecha_compra" 
                        class="input-field" 
                        value="<?php echo date('Y-m-d'); ?>" 
                        required>

                    <hr>

                    <h3>Totales y Pago</h3>
                    
                    <div style="display: flex; justify-content: space-between; font-size: 1.2em; margin-bottom: 15px;">
                        <strong>TOTAL COMPRA:</strong> 
                        <strong id="total_compra_display" style="color: yellow;">$0.00</strong>
                    </div>

                    <input type="hidden" name="total_compra" id="total_compra_input" value="0.00">

                    <label for="cond_pago">Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field" required>
                        <option value="CONTADO" selected>CONTADO</option>
                        <option value="CRÉDITO">CRÉDITO (Cta. Cte.)</option>
                    </select>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                        Registrar Compra y Actualizar Stock
                    </button>
                    
                </form>
            </div>
            
        </div>
    </div>
    
</body>
<script src="../js/global.js"></script> 
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Inicialización
        let carrito = []; 
        let proveedoresData = <?php echo json_encode($proveedores); ?>;
        
        // ===========================================
        // 1. FUNCIONALIDAD DEL CARRITO Y CÁLCULOS
        // ===========================================

        function calcularTotales() {
            let totalCompra = carrito.reduce((sum, item) => sum + item.total, 0);

            document.getElementById('total_compra_display').textContent = '$' + totalCompra.toFixed(2);
            document.getElementById('total_compra_input').value = totalCompra.toFixed(2); 
        }

        function renderizarCarrito() {
            const tbody = document.querySelector('#carrito tbody');
            tbody.innerHTML = '';
            
            carrito.forEach((item, index) => {
                const row = tbody.insertRow();
                row.dataset.index = index;
                
                // Nota: Los inputs de Cantidad y Costo usan 'change' para recalcular
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
                    <td class="text-right">$${item.total.toFixed(2)}</td>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm remover-item" data-cod-prod="${item.cod_prod}">X</button>
                    </td>
                `;
            });
            calcularTotales();
        }

        // Eventos para actualizar costo y cantidad
        document.querySelector('#carrito').addEventListener('change', function(e) {
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
                }
                
                // Recalcular el total de la línea
                carrito[index].total = carrito[index].cant * carrito[index].p_unit;
                
                // Asegurar que el input refleje el valor actualizado si se forzó el mínimo
                target.value = (target.classList.contains('update-cantidad')) ? carrito[index].cant : carrito[index].p_unit.toFixed(2);
                
                renderizarCarrito(); 
            }
        });

        // Evento para remover item
        document.querySelector('#carrito').addEventListener('click', function(e) {
            if (e.target.classList.contains('remover-item')) {
                const cod_prod = e.target.dataset.codProd;
                carrito = carrito.filter(item => item.cod_prod !== cod_prod);
                renderizarCarrito();
            }
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
                resultadosDiv.style.display = 'none';
                return;
            }

            // Llamada AJAX (Requiere el archivo buscar_producto_ajax.php)
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'buscar_producto_ajax.php?q=' + encodeURIComponent(busqueda), true); 
            xhr.onload = function() {
                if (this.status == 200) {
                    try {
                        const productos = JSON.parse(this.responseText);
                        mostrarResultados(productos);
                    } catch (e) {
                        resultadosDiv.innerHTML = 'Error al procesar la respuesta JSON.';
                    }
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
                // Se muestra el costo promedio actual como referencia
                div.textContent = `[${producto.cod_prod}] ${producto.descripcion} - Stock: ${producto.stock} - Costo Prom: $${parseFloat(producto.costo_promedio || 0).toFixed(2)}`;
                div.dataset.producto = JSON.stringify(producto);
                resultadosDiv.appendChild(div);
            });
            resultadosDiv.style.display = 'block';
        }

        resultadosDiv.addEventListener('click', function(e) {
            if (e.target.classList.contains('producto-encontrado')) {
                const producto = JSON.parse(e.target.dataset.producto);
                
                const index = carrito.findIndex(item => item.cod_prod === producto.cod_prod);
                // Usamos el costo promedio actual del producto como sugerencia para el p_unit de la compra
                const costo_inicial = parseFloat(producto.costo_promedio || 0); 
                
                if (index !== -1) {
                    carrito[index].cant += 1;
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
                renderizarCarrito();
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!inputBuscar.contains(e.target) && !resultadosDiv.contains(e.target)) {
                resultadosDiv.style.display = 'none';
            }
        });


        // ===========================================
        // 3. BÚSQUEDA Y SELECCIÓN DE PROVEEDORES
        // ===========================================
        
        const inputBuscarProveedor = document.getElementById('buscar_proveedor');
        const resultadosDivProveedores = document.getElementById('resultadosBusquedaProveedores');
        const nombreProveedorDisplay = document.getElementById('nombre_proveedor_display');
        const proveedorIdHidden = document.getElementById('proveedor_id_hidden');
        const cuitProveedorDisplay = document.getElementById('cuit_proveedor_display');

        inputBuscarProveedor.addEventListener('input', function() {
            const busqueda = inputBuscarProveedor.value.trim().toLowerCase();
            resultadosDivProveedores.innerHTML = '';

            if (busqueda.length < 2) {
                resultadosDivProveedores.style.display = 'none';
                return;
            }

            // Filtrar el array cargado por PHP (proveedoresData)
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
                
                // Asignar datos al formulario y display (usando id_proveedor que es el cod_prov)
                nombreProveedorDisplay.textContent = proveedor.nombre;
                proveedorIdHidden.value = proveedor.id_proveedor;
                cuitProveedorDisplay.value = proveedor.cuit;

                inputBuscarProveedor.value = proveedor.nombre; 
                resultadosDivProveedores.style.display = 'none';
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!inputBuscarProveedor.contains(e.target) && !resultadosDivProveedores.contains(e.target)) {
                resultadosDivProveedores.style.display = 'none';
            }
        });
        
        // ===========================================
        // 4. ENVÍO DE FORMULARIO
        // ===========================================

        const formCompra = document.getElementById('formCompra');
        const detalleProductosInput = document.getElementById('detalle_productos_input');

        formCompra.addEventListener('submit', function(e) {
            
            if (proveedorIdHidden.value === '0' || proveedorIdHidden.value === '') {
                alert("Debe seleccionar un proveedor para registrar la compra.");
                e.preventDefault();
                return;
            }
            
            if (carrito.length === 0) {
                alert("Debe agregar al menos un producto al carrito de compra.");
                e.preventDefault();
                return;
            }
            
            // Si todo está bien, serializar el carrito a JSON antes de enviar
            detalleProductosInput.value = JSON.stringify(carrito);
            
            // El resto se procesa en el bloque PHP superior
        });
        
    });
</script>
</html>