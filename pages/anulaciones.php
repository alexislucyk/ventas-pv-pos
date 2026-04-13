<?php
include 'infosesion.php';
require '../config/db_config.php'; 
date_default_timezone_set('America/Argentina/Buenos_Aires');

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_anulacion'])) {
    $n_doc = $_POST['n_documento_anular'];
    $motivo = trim($_POST['motivo']);
    $fecha_hoy = date('Y-m-d');
    $productos_devolver = isset($_POST['devolver_cant']) ? $_POST['devolver_cant'] : array();

    try {
        $pdo->beginTransaction();

        // 1. Obtener datos de la venta
        $stmt = $pdo->prepare("SELECT estado, id_cliente, total_venta, cond_pago FROM ventas WHERE n_documento = ?");
        $stmt->execute([$n_doc]);
        $venta = $stmt->fetch();

        if (!$venta) throw new Exception("La venta no existe.");
        if ($venta['estado'] === 'Anulada') throw new Exception("Esta venta ya ha sido anulada.");

        $monto_a_devolver = 0;

        // 2. Procesar Devolución de Stock y Cálculo de Importe
        foreach ($productos_devolver as $cod_prod => $cant_devuelta) {
            if ($cant_devuelta > 0) {
                // Obtener precio unitario del detalle para calcular el monto exacto a devolver
                $stmtP = $pdo->prepare("SELECT p_unit FROM ventas_detalle WHERE n_documento = ? AND cod_prod = ?");
                $stmtP->execute([$n_doc, $cod_prod]);
                $det = $stmtP->fetch();
                
                $monto_a_devolver += ($det['p_unit'] * $cant_devuelta);

                // Actualizar Stock en la tabla productos
                $updStock = $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE cod_prod = ?");
                $updStock->execute([$cant_devuelta, $cod_prod]);
            }
        }

        // 3. Impacto Financiero (Cuenta Corriente o Movimiento de Caja)
        if ($venta['cond_pago'] === 'Cuenta Corriente') {
            // Se registra en la tabla ctacte (resta deuda del cliente)
            $sql_ctacte = "INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha) VALUES (?, ?, ?, 0, ?, ?)";
            $pdo->prepare($sql_ctacte)->execute([
                $venta['id_cliente'], 
                "ANULACIÓN/DEV. N° $n_doc - MOTIVO: $motivo", 
                $n_doc, 
                $monto_a_devolver, 
                $fecha_hoy
            ]);
        } else {
            // REGISTRO EN TABLA MOVIMIENTO (Dinero físico que sale)
            $sql_mov = "INSERT INTO movimiento (tipo, monto, detalle, fecha) VALUES ('EGRESO', ?, ?, ?)";
            $detalle_mov = "DEVOLUCIÓN EFECTIVO VENTA N° $n_doc - MOTIVO: $motivo";
            
            $stmt_mov = $pdo->prepare($sql_mov);
            $stmt_mov->execute([
                $monto_a_devolver, 
                $detalle_mov, 
                $fecha_hoy
            ]);
        }

        // 4. Actualizar Estado de la Venta
        $nuevo_estado = ($monto_a_devolver >= $venta['total_venta']) ? 'Anulada' : 'Devolución Parcial';
        $updateVenta = $pdo->prepare("UPDATE ventas SET estado = ? WHERE n_documento = ?");
        $updateVenta->execute([$nuevo_estado, $n_doc]);

        $pdo->commit();
        $mensaje = "✅ Proceso completado. Stock actualizado y Egreso de $".number_format($monto_a_devolver,2)." registrado en movimientos.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Anulaciones | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .input-dev { width: 60px; text-align: center; border: 1px solid #00bcd4; background: #222; color: #fff; border-radius: 4px; padding: 5px; }
        .card-danger { border-top: 5px solid #f44336; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; font-weight: bold; }
        .alert-success { background: #2e7d32; color: white; }
        .alert-error { background: #d32f2f; color: white; }
        .table-dev { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table-dev th, .table-dev td { border-bottom: 1px solid #333; padding: 10px; text-align: left; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1>Anulación y Devoluciones</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Buscar Comprobante</h2>
            <div style="display: flex; gap: 10px;">
                <input type="number" id="input_buscar_doc" class="input-field" placeholder="Ingrese N° de Venta">
                <button type="button" class="btn btn-primary" onclick="buscarVenta()">Buscar Venta</button>
            </div>
        </div>

        <div id="contenedor_detalle" class="card detalle-anulacion card-danger" style="display:none; margin-top:20px;">
            <form method="POST" onsubmit="return confirm('¿Confirmar el reingreso de mercadería y el ajuste financiero?')">
                <h2>Detalle Venta N° <span id="span_n_doc"></span></h2>
                <input type="hidden" name="n_documento_anular" id="hidden_n_doc">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; background: #222; padding: 15px; border-radius: 8px;">
                    <p><strong>Cliente:</strong> <span id="det_cliente" style="color:#00bcd4;"></span></p>
                    <p><strong>Condición:</strong> <span id="det_condicion" style="color:#00bcd4;"></span></p>
                </div>

                <table class="table-dev">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Cant. Original</th>
                            <th>Precio Unit.</th>
                            <th style="color: #f44336;">Devolver (Cant)</th>
                        </tr>
                    </thead>
                    <tbody id="tabla_articulos"></tbody>
                </table>

                <div style="margin-top: 25px;">
                    <label><strong>Motivo del reingreso / anulación:</strong></label>
                    <textarea name="motivo" class="input-field" style="height: 80px;" required placeholder="Ej: Mercadería dañada, error en factura..."></textarea>
                </div>

                <button type="submit" name="confirmar_anulacion" class="btn btn-danger" style="width: 100%; margin-top: 20px; font-weight:bold; height: 50px; font-size: 1.1em;">
                    PROCESAR DEVOLUCIÓN Y REGISTRAR EGRESO
                </button>
            </form>
        </div>
    </div>

    <script>
    function buscarVenta() {
        const nDoc = document.getElementById('input_buscar_doc').value;
        if (!nDoc) return alert("Por favor, ingrese un número de documento.");

        fetch(`../ajax/obtener_venta_anulacion.php?n_documento=${nDoc}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    document.getElementById('contenedor_detalle').style.display = 'none';
                } else {
                    document.getElementById('span_n_doc').textContent = data.cabecera.n_documento;
                    document.getElementById('hidden_n_doc').value = data.cabecera.n_documento;
                    document.getElementById('det_cliente').textContent = data.cabecera.cliente_nombre;
                    document.getElementById('det_condicion').textContent = data.cabecera.cond_pago;

                    let html = '';
                    data.detalle.forEach(item => {
                        html += `<tr>
                            <td>${item.descripcion}</td>
                            <td>${item.cant}</td>
                            <td>$${item.p_unit}</td>
                            <td>
                                <input type="number" name="devolver_cant[${item.cod_prod}]" 
                                       class="input-dev" value="${item.cant}" 
                                       min="0" max="${item.cant}">
                            </td>
                        </tr>`;
                    });
                    document.getElementById('tabla_articulos').innerHTML = html;
                    document.getElementById('contenedor_detalle').style.display = 'block';
                }
            })
            .catch(err => {
                console.error(err);
                alert("Error al conectar con el servidor.");
            });
    }
    </script>
</body>
</html>