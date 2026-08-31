<?php
include 'infosesion.php';
// VALIDACIÓN CRÍTICA:
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
date_default_timezone_set('America/Argentina/Buenos_Aires');

// -----------------------------------------------------
// 1. CONTROL DE ACCESO Y CONFIGURACIÓN
// -----------------------------------------------------

require '../config/db_config.php'; 

$mensaje = '';
$error = false;
$id_compra_generada = null; // Para mostrar en el mensaje de éxito

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    $error = true;
    $mensaje = '❌ ERROR CRÍTICO: Falta empresa_id en sesión.';
    $pdo = null;
}

// -----------------------------------------------------
// 2. BLOQUE DE PROCESAMIENTO DE REGISTRO DE COMPRA (POST)
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_compra'])) {
    
    // 2.1. Recibir y Sanitizar Datos del Formulario
    $id_proveedor   = filter_var($_POST['proveedor_id'] ?? 0, FILTER_VALIDATE_INT);
    $cond_pago      = htmlspecialchars($_POST['cond_pago'] ?? 'CONTADO');
    $documento_tipo = htmlspecialchars($_POST['documento'] ?? 'OTROS');
    $n_documento    = htmlspecialchars($_POST['n_documento'] ?? '');
    $total_compra   = filter_var($_POST['total_compra'] ?? 0, FILTER_VALIDATE_FLOAT);
    $fecha_compra   = htmlspecialchars($_POST['fecha_compra'] ?? date('Y-m-d'));
    $detalle_json   = $_POST['detalle_productos'] ?? '[]'; // El JSON del carrito
    $descuento_global = filter_var($_POST['descuento_factura'] ?? 0, FILTER_VALIDATE_FLOAT); // Descuento global factura
    $fecha_operacion = date('Y-m-d H:i:s'); // Fecha de registro en el sistema
    $usuario_id     = $_SESSION['usuario_id'] ?? 0;

    // 2.2. Validaciones Críticas
    if (!$id_proveedor || $total_compra <= 0 || empty($n_documento) || empty($detalle_json)) {
        $error = true;
        $mensaje = "❌ ERROR: Faltan datos críticos (Proveedor, N° Documento o Carrito vacío).";
    }

    if (!$error) {
        $productos_detalle = json_decode((string)$detalle_json, true);

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
            $sql_cabecera = "INSERT INTO compras (cod_proveedor, cond_pago, documento, n_documento, total_compra, fecha_compra, fecha_operacion, usuario_id, empresa_id, sucursal_id) 
                             VALUES (:prov, :cond, :doc_tipo, :n_doc, :total, :f_compra, :f_op, :user, :empresa_id, :sucursal_id)";
            $stmt_cabecera = $pdo->prepare($sql_cabecera);
            $stmt_cabecera->execute([
                ':prov' => $id_proveedor,
                ':cond' => $cond_pago,
                ':doc_tipo' => $documento_tipo,
                ':n_doc' => $n_documento,
                ':total' => $total_compra,
                ':f_compra' => $fecha_compra,
                ':f_op' => $fecha_operacion,
                ':user' => $usuario_id,
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id
            ]);
            
            $id_compra_generada = $pdo->lastInsertId();

            // ---------------------------------------------------------
            // B. PROCESAR DETALLE E INVENTARIO (Último Costo de Compra)
            // ---------------------------------------------------------
            $sql_detalle = "INSERT INTO compras_detalle (cod_prod, descripcion, cant, p_unit, total, n_documento, fecha, empresa_id) 
                            VALUES (:cod, :desc, :cant, :punit, :total_linea, :n_doc, :fecha, :empresa_id)";
            $stmt_detalle = $pdo->prepare($sql_detalle);

            // 1. Preparamos las consultas que se usarán en el bucle
            // A) Consulta para obtener el stock actual por sucursal
            $sql_get_stock = "SELECT stock_actual FROM stocks WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND cod_prod = :cod"; 
            $stmt_get_stock = $pdo->prepare($sql_get_stock);

            // B) Upsert stock por sucursal en tabla stocks
            $upsert_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                              ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)";
            $stmt_upsert_stock = $pdo->prepare($upsert_stock);

            // C) Actualizar costo de compra (predeterminado)
            $sql_update_prod = "UPDATE productos SET 
                                    p_compra = :nuevo_costo_unit
                                WHERE cod_prod = :cod AND empresa_id = :empresa_id";
            $stmt_update_prod = $pdo->prepare($sql_update_prod);

            foreach ($productos_detalle as $item) {
                $cod_prod = htmlspecialchars($item['cod_prod'] ?? '');
                $descripcion = htmlspecialchars($item['descripcion'] ?? '');
                $cant = (float)($item['cant'] ?? 0);
                $p_unit = (float)($item['p_unit'] ?? 0);
                $total_linea = (float)($item['total'] ?? ($cant * $p_unit));

                // 1. Insertar en compras_detalle
                $stmt_detalle->execute([
                    ':cod' => $cod_prod,
                    ':desc' => $descripcion,
                    ':cant' => $cant,
                    ':punit' => $p_unit,
                    ':total_linea' => $total_linea,
                    ':n_doc' => $n_documento,
                    ':fecha' => $fecha_compra,
                    ':empresa_id' => $empresa_id
                ]);

                // 2. Actualizar stock en tabla stocks (por sucursal) y costo en productos
                $stmt_upsert_stock->execute([
                    $empresa_id,
                    $sucursal_id,
                    $cod_prod,
                    $cant
                ]);

                $stmt_update_prod->execute([
                    ':nuevo_costo_unit' => $p_unit,
                    ':cod' => $cod_prod,
                    ':empresa_id' => $empresa_id
                ]);
            }
            
            // ... (Continúa el resto del código: C. CUENTA CORRIENTE, D. FINALIZAR)
            
            // ---------------------------------------------------------
            // C. CUENTA CORRIENTE DE PROVEEDORES
            // ---------------------------------------------------------
            if ($cond_pago === 'CRÉDITO') {
                $sql_ctacte = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                               VALUES (:id_prov, :mov, 0, :total, :n_doc, :fecha_op, :user, :compra, :empresa_id)";
                $stmt_ctacte = $pdo->prepare($sql_ctacte);
                $stmt_ctacte->execute([
                    ':id_prov' => $id_proveedor,
                    ':mov' => 'FACTURA COMPRA', 
                    ':total' => $total_compra, // El total va en HABER (deuda para nosotros)
                    ':n_doc' => $n_documento,
                    ':fecha_op' => $fecha_operacion,
                    ':user' => $usuario_id,
                    ':compra' => $id_compra_generada,
                    ':empresa_id' => $empresa_id
                ]);
            }
            
            // ---------------------------------------------------------
            // D. FINALIZAR
            // ---------------------------------------------------------
            $pdo->commit();
            $mensaje = "✅ Compra N° $id_compra_generada (Doc: $n_documento) registrada con éxito. Stock y Costos actualizados.";
            // Limpiar variables de POST para evitar recarga accidental
            unset($_POST);
            
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
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
                            WHERE empresa_id = :empresa_id
                            ORDER BY razon ASC";
        $stmt_proveedores = $pdo->prepare($sql_proveedores);
        $stmt_proveedores->execute([':empresa_id' => $empresa_id]);
        $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC); 

        $rubros_list = $pdo->query("SELECT nombre FROM rubros ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

        $proveedores_list = $pdo->prepare("SELECT razon FROM proveedores WHERE empresa_id = :empresa_id ORDER BY razon ASC");
        $proveedores_list->execute([':empresa_id' => $empresa_id]);
        $proveedores_list = $proveedores_list->fetchAll(PDO::FETCH_ASSOC);

        $stmt_cod_prov = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE empresa_id = :empresa_id ORDER BY (cod_prov + 0) DESC LIMIT 1");
        $stmt_cod_prov->execute([':empresa_id' => $empresa_id]);
        $ult_prov = $stmt_cod_prov->fetch();
        $nuevo_cod_prov_sugerido = $ult_prov ? (intval($ult_prov['cod_prov']) + 1) : 1;

        $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
        $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);

        // Últimas compras registradas (para widget de referencia rápida)
        $sql_ultimas_compras = "SELECT c.id, c.n_documento, c.documento, c.total_compra, c.fecha_compra, p.razon AS proveedor
                               FROM compras c
                               LEFT JOIN proveedores p ON c.cod_proveedor = p.cod_prov AND p.empresa_id = c.empresa_id
                               WHERE c.empresa_id = :empresa_id
                               ORDER BY c.fecha_operacion DESC, c.id DESC
                               LIMIT 10";
        $stmt_ultimas = $pdo->prepare($sql_ultimas_compras);
        $stmt_ultimas->execute([':empresa_id' => $empresa_id]);
        $ultimas_compras = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log("Error al cargar proveedores: " . $e->getMessage());
        $mensaje = "⚠️ Advertencia: No se pudieron cargar los proveedores.";
        $proveedores = [];
        $rubros_list = [];
        $proveedores_list = [];
        $nuevo_cod_prov_sugerido = 1;
        $ultimas_compras = [];
        $ganancia_config = 60;
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Compra | <?php echo $nombre_empresa_sistema; ?></title>
<link rel="stylesheet" href="<?php echo url('css/style.css'); ?>"> 
    <link rel="stylesheet" href="<?php echo url('css/pages/compras.css'); ?>"> 
</head>
<body>

    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?> 
    
    <div class="content">
        <?php include 'topbar.php'; ?>
        <div class="compra-header">
            <h1>Registro de Compra a Proveedores</h1>
            <a href="compras_rapidas.php" class="btn btn-yellow"><i class="fas fa-bolt"></i> Carga Rápida (Sin Detalle)</a>
            <a href="<?php echo url('ajax/exportar_compras_csv.php'); ?>" class="btn btn-secondary"><i class="fas fa-file-csv"></i> Exportar CSV</a>
        </div>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="compra-grid">
            
            <div class="card">
                <h2>Detalle de Productos Comprados</h2>
                
                <div class="busqueda-header">
                    <label for="buscar_producto"><i class="fas fa-search"></i> Buscar Producto</label>
                    <button type="button" class="btn btn-success" onclick="abrirModalNuevoProducto()" title="Agregar nuevo producto">+ Nuevo</button>
                </div>
                <div class="busqueda-producto-container">
                    <input type="text" id="buscar_producto" class="input-field" placeholder="Escriba el código o nombre del producto">
                    <div id="resultadosBusqueda"></div>
                </div>

                <hr>

                <h3>Carrito de Compra</h3>
<table id="carrito" class="tabla-carrito">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th class="text-right">Costo Unit.</th>
                                <th class="cant-col">Cant.</th>
                                <th class="text-right">Dto.</th>
                                <th class="text-right">Total</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <form id="formCompra" method="POST" action="<?php echo url('compras'); ?>">
                    <input type="hidden" name="registrar_compra" value="1">
                    <input type="hidden" name="detalle_productos" id="detalle_productos_input">

                    <h2>Datos del Proveedor y Factura</h2>

                    <div class="contenedor-busqueda-proveedor"> 
                        <div class="busqueda-header">
                            <label for="buscar_proveedor"><i class="fas fa-truck"></i> Buscar Proveedor</label>
                            <button type="button" class="btn btn-success" onclick="abrirModalNuevoProveedor()" title="Agregar nuevo proveedor">+ Nuevo</button>
                        </div>
                        <div class="busqueda-container">
                            <input type="text" id="buscar_proveedor" class="input-field" placeholder="Seleccionar Proveedor">
                            <div id="resultadosBusquedaProveedores"></div>
                        </div>
                    </div>
                    
                    <div class="proveedor-display">
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
                    
                    <div class="total-row">
                        <strong>Subtotal:</strong>
                        <strong id="subtotal_display">$0.00</strong>
                    </div>
                    <div class="total-row">
                        <strong>Descuento Total:</strong>
                        <strong id="descuento_total_display" class="total-amt-desc">$0.00</strong>
                    </div>
                    
                    <div class="total-divider"></div>
                    <div class="total-row total-final">
                        <strong>TOTAL COMPRA:</strong>
                        <strong id="total_compra_display" class="total-amt">$0.00</strong>
                    </div>

                    <input type="hidden" name="total_compra" id="total_compra_input" value="0.00">
                    <input type="hidden" name="detalle_descuentos" id="detalle_descuentos_input" value="{}">

                    <label for="descuento_factura">Descuento General Factura ($)</label>
                    <input type="number" step="0.01" min="0" id="descuento_factura" name="descuento_factura" class="input-field" value="0.00">

                    <label for="cond_pago">Condición de Pago</label>
                    <select id="cond_pago" name="cond_pago" class="input-field" required>
                        <option value="CONTADO" selected>CONTADO</option>
                        <option value="CRÉDITO">CRÉDITO (Cta. Cte.)</option>
                    </select>
                    
 <button type="submit" class="btn btn-primary">Registrar Compra y Actualizar Stock</button>
                    
                 </form>
             </div>
             
             <!-- Widget Últimas Compras -->
             <div class="card ultimas-compras-widget">
                 <h2>Últimas Compras</h2>
                 <?php if (!empty($ultimas_compras)): ?>
                 <table>
                     <thead>
                         <tr>
                             <th>Doc</th>
                             <th>Proveedor</th>
                             <th class="text-right">Total</th>
                             <th>Fecha</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach ($ultimas_compras as $c): ?>
                         <tr>
                             <td><?php echo htmlspecialchars($c['documento'] . ' ' . $c['n_documento']); ?></td>
                             <td><?php echo htmlspecialchars($c['proveedor'] ?? 'S/D'); ?></td>
                             <td class="text-right">$<?php echo number_format($c['total_compra'], 2); ?></td>
                             <td><?php echo date('d/m', strtotime($c['fecha_compra'])); ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
                 <?php else: ?>
                 <p class="no-data-msg">No hay compras registradas.</p>
                 <?php endif; ?>
             </div>
         </div>
     </div>
    
    <!-- Modal Nuevo Producto Rápido -->
    <div id="modalNuevoProducto" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Registrar Producto</h2>
                <span onclick="cerrarModalNuevoProducto()" class="close">&times;</span>
            </div>
            <form id="formNuevoProducto">
                <label>Código de Barras / Interno *</label>
                <input type="text" id="np_cod_prod" class="input-field" required>
                <label>Descripción *</label>
                <input type="text" id="np_descripcion" class="input-field" required>
                
                <div class="form-row">
                    <div>
                        <label>Costo (Compra) *</label>
                        <input type="number" step="0.01" id="np_p_compra" class="input-field" required oninput="calcularPrecioVentaSugerido()">
                    </div>
                    <div>
                        <label>Precio Venta ($) *</label>
                        <input type="number" step="0.01" id="np_p_venta" class="input-field" required>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label>Stock Inicial</label>
                        <input type="number" step="0.01" id="np_stock" class="input-field" value="0">
                    </div>
                    <div>
                        <label>Fecha Ult. Compra</label>
                        <input type="date" id="np_fecha_ult_compra" class="input-field" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div>
                    <label>Rubro</label>
                    <select id="np_rubro" class="input-field">
                        <?php foreach ($rubros_list as $r): ?>
                            <option value="<?php echo $r['nombre']; ?>" <?php echo ($r['nombre'] == 'VARIOS') ? 'selected' : ''; ?>><?php echo $r['nombre']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Proveedor Principal</label>
                    <select id="np_proveedor" class="input-field">
                        <?php foreach ($proveedores_list as $p): ?>
                            <option value="<?php echo $p['razon']; ?>" <?php echo ($p['razon'] == 'GENERAL') ? 'selected' : ''; ?>><?php echo $p['razon']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary btn-block" onclick="guardarNuevoProducto()">GUARDAR Y AGREGAR</button>
            </form>
        </div>
    </div>

    <!-- Modal Nuevo Proveedor Rápido -->
    <div id="modalNuevoProveedor" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Registrar Proveedor</h2>
                <span onclick="cerrarModalNuevoProveedor()" class="close">×</span>
            </div>
            <form id="formNuevoProveedor">
                <label>Código Proveedor *</label>
                <input type="text" id="nprov_cod_prov" class="input-field" value="<?php echo $nuevo_cod_prov_sugerido; ?>" required>
                <label>Razón Social *</label>
                <input type="text" id="nprov_razon" class="input-field" required>
                <label>CUIT</label>
                <input type="text" id="nprov_cuit" class="input-field">
                <label>Teléfono</label>
                <input type="text" id="nprov_telefono" class="input-field">
                <button type="button" class="btn btn-primary btn-block" onclick="guardarNuevoProveedor()">GUARDAR Y SELECCIONAR</button>
            </form>
        </div>
    </div>

    <script>
        var proveedoresData = <?php echo json_encode($proveedores); ?>;
        var gananciaConfig = <?php echo $ganancia_config; ?>;
    </script>
    <script src="<?php echo url('js/compras.js'); ?>"></script>
</body>
</html>