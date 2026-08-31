<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$mensaje = '';
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_factura'])) {
    $id_prov         = filter_var($_POST['proveedor_id'] ?? 0, FILTER_VALIDATE_INT);
    $total           = filter_var($_POST['total_compra'] ?? 0, FILTER_VALIDATE_FLOAT);
    $n_doc           = htmlspecialchars($_POST['n_documento'] ?? '');
    $tipo_doc        = htmlspecialchars($_POST['documento_tipo'] ?? 'FACTURA');
    $f_factura       = $_POST['fecha_factura'] ?? date('Y-m-d');
    $f_vencimiento   = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
    $cond_pago       = $_POST['cond_pago'] ?? 'CONTADO';
    $obs             = htmlspecialchars($_POST['observaciones'] ?? '');
    $f_operacion     = (!empty($_POST['fecha_operacion']) ? $_POST['fecha_operacion'] : date('Y-m-d')) . ' ' . date('H:i:s');
    $user_id         = $_SESSION['usuario_id'] ?? 0;

    if (!$id_prov || $total <= 0 || empty($n_doc)) {
        $error = true;
        $mensaje = "❌ Error: Debe completar Proveedor, N° de Documento y Monto.";
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO compras (cod_proveedor, cond_pago, documento, n_documento, total_compra, fecha_compra, fecha_vencimiento, fecha_operacion, usuario_id, observaciones, es_sin_detalle, empresa_id, sucursal_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id_prov, $cond_pago, $tipo_doc, $n_doc, $total, $f_factura, $f_vencimiento, $f_operacion, $user_id, $obs, $empresa_id, $sucursal_id]);
            
            $id_generado = $pdo->lastInsertId();
            
            if ($cond_pago === 'CRÉDITO') {
                $sql_cc = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                           VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_cc)->execute([$id_prov, "$tipo_doc S/DETALLE", $total, $n_doc, $f_operacion, $user_id, $id_generado, $empresa_id]);
            } 
            else {
                $sql_cc_fact = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                                VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_cc_fact)->execute([$id_prov, "$tipo_doc S/DETALLE (CONTADO)", $total, $n_doc, $f_operacion, $user_id, $id_generado, $empresa_id]);

                $sql_cc_pago = "INSERT INTO ctacte_proveedores (id_proveedor, movimiento, debe, haber, n_documento, fecha, usuario_id, compra_id, empresa_id) 
                                VALUES (?, 'PAGO CONTADO', ?, 0, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_cc_pago)->execute([$id_prov, $total, $n_doc, $f_operacion, $user_id, $id_generado, $empresa_id]);

                $sql_mov = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) 
                            VALUES ('EGRESO', ?, 'EFECTIVO', ?, ?, ?, 0, ?, ?)";
                $pdo->prepare($sql_mov)->execute([$total, "PAGO COMPRA S/DETALLE: $tipo_doc $n_doc", $f_operacion, $_SESSION['usuario_nombre'], $empresa_id, $sucursal_id]);
            }

            $pdo->commit();
            $mensaje = "✅ Compra rápida registrada con éxito (ID Sistema: $id_generado).";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "❌ Error en el registro: " . $e->getMessage();
        }
    }
}

// 2. CARGA DE PROVEEDORES
$stmt_p = $pdo->query("SELECT cod_prov as id, razon as nombre, cuit FROM proveedores ORDER BY razon ASC");
$proveedores = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Compra Rápida | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/compras_rapidas.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Registrar Compra Rápida (Sin Detalle)</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '✅') !== false ? 'alert-success' : 'alert-error'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card container-factura">
            <form method="POST">
                <input type="hidden" name="registrar_factura" value="1">
                
                <label>1. Seleccionar Proveedor</label>
                <div style="position: relative; margin-bottom: 15px;">
                    <input type="text" id="busq_prov" class="input-field" placeholder="Buscar por nombre o CUIT..." autocomplete="off">
                    <div id="resBusquedaProv"></div>
                    <input type="hidden" name="proveedor_id" id="proveedor_id" required>
                </div>
                <div id="prov_seleccionado" style="background: #111; padding: 10px; border-radius: 5px; margin-bottom: 20px; display: none; border-left: 4px solid #00bcd4;">
                    <strong>Proveedor:</strong> <span id="txt_prov_nombre"></span><br>
                    <small>CUIT: <span id="txt_prov_cuit"></span></small>
                </div>

                <div class="grid-fechas">
                    <div>
                        <label>Tipo de Comprobante</label>
                        <select name="documento_tipo" class="input-field">
                            <option value="FACTURA A">FACTURA A</option>
                            <option value="FACTURA B">FACTURA B</option>
                            <option value="FACTURA C">FACTURA C</option>
                            <option value="REMITO">REMITO</option>
                            <option value="RECIBO">RECIBO</option>
                        </select>
                    </div>
                    <div>
                        <label>N° de Factura / Documento</label>
                        <input type="text" name="n_documento" class="input-field" required placeholder="0001-00001234">
                    </div>
                </div>

                <div class="grid-fechas">
                    <div>
                        <label>Fecha de Factura</label>
                        <input type="date" name="fecha_factura" class="input-field" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label>Fecha de Vencimiento</label>
                        <input type="date" name="fecha_vencimiento" class="input-field">
                        <small style="color: #888;">(Deje vacío si es contado)</small>
                    </div>
                </div>

                <label>Importe Total Facturado ($)</label>
                <input type="number" step="0.01" name="total_compra" class="input-field input-monto-global" placeholder="0.00" required>

                <div class="grid-fechas">
                    <div>
                        <label>Condición de Pago</label>
                        <select name="cond_pago" id="cond_pago" class="input-field">
                            <option value="CONTADO">CONTADO (Sale de Caja)</option>
                            <option value="CRÉDITO">CRÉDITO (Cta. Cte. Proveedor)</option>
                        </select>
                    </div>
                    <div>
                        <label>Operación (Sistema)</label>
                        <input type="date" name="fecha_operacion" class="input-field" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <label>Observaciones / Detalle General</label>
                <textarea name="observaciones" class="input-field" rows="3" placeholder="Ej: Compra de cables y térmicas s/detalle stock..."></textarea>

                <button type="submit" class="btn btn-primary btn-block" style="height: 50px; font-weight: bold; margin-top: 15px;">
                    REGISTRAR COMPRA RÁPIDA
                </button>
                <a href="compras.php" class="btn btn-secondary btn-block" style="text-align: center; display: block; margin-top: 10px;">Volver a Compras con Detalle</a>
            </form>
        </div>
    </div>

    <script>
        const provs = <?php echo json_encode($proveedores); ?>;
        const inputBusq = document.getElementById('busq_prov');
        const resDiv = document.getElementById('resBusquedaProv');

        inputBusq.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            resDiv.innerHTML = '';
            if (q.length < 2) { resDiv.style.display = 'none'; return; }

            const filtrados = provs.filter(p => 
                p.nombre.toLowerCase().includes(q) || (p.cuit && p.cuit.includes(q))
            );

            if (filtrados.length > 0) {
                resDiv.style.display = 'block';
                filtrados.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'prov-item';
                    div.innerHTML = `<strong>${p.nombre}</strong> <small>(CUIT: ${p.cuit || 'S/D'})</small>`;
                    div.onclick = () => {
                        document.getElementById('proveedor_id').value = p.id;
                        document.getElementById('txt_prov_nombre').innerText = p.nombre;
                        document.getElementById('txt_prov_cuit').innerText = p.cuit || 'S/D';
                        document.getElementById('prov_seleccionado').style.display = 'block';
                        inputBusq.value = '';
                        resDiv.style.display = 'none';
                    };
                    resDiv.appendChild(div);
                });
            } else { resDiv.style.display = 'none'; }
        });

        document.addEventListener('click', (e) => {
            if (!inputBusq.contains(e.target) && !resDiv.contains(e.target)) resDiv.style.display = 'none';
        });
    </script>
</body>
</html>