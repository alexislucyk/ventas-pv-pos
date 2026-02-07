<?php
session_start();
require '../config/db_config.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$mensaje = '';

// Procesar la anulación si se envía el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_anulacion'])) {
    $n_doc = $_POST['n_documento_anular'];
    $motivo = trim($_POST['motivo']);
    $fecha_hoy = date('Y-m-d'); // Fecha para el movimiento de ctacte

    try {
        $pdo->beginTransaction();

        // 1. Obtener datos de la venta (incluyendo tipo de pago y cliente)
        // Asegúrate de que el campo en tu tabla 'ventas' sea 'tipo_pago' o 'cond_pago'
        $stmt = $pdo->prepare("SELECT estado, id_cliente, total_venta, cond_pago FROM ventas WHERE n_documento = ?");
        $stmt->execute([$n_doc]);
        $venta = $stmt->fetch();

        if (!$venta) {
            throw new Exception("La venta no existe.");
        }
        if ($venta['estado'] === 'Anulada') {
            throw new Exception("Esta venta ya ha sido anulada anteriormente.");
        }

        // 2. Obtener detalle para devolver stock (Campos: cod_prod, cant según tu imagen)
        $stmtDetalle = $pdo->prepare("SELECT cod_prod, cant FROM ventas_detalle WHERE n_documento = ?");
        $stmtDetalle->execute([$n_doc]);
        $productos = $stmtDetalle->fetchAll();

        foreach ($productos as $prod) {
            $updateStock = $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE cod_prod = ?");
            $updateStock->execute([$prod['cant'], $prod['cod_prod']]);
        }

        // 3. Actualizar estado de la venta
        $updateVenta = $pdo->prepare("UPDATE ventas SET estado = 'Anulada' WHERE n_documento = ?");
        $updateVenta->execute([$n_doc]);

        // 4. Lógica de Cuenta Corriente (Ajustada a tu imagen de tabla 'ctacte')
        // Si la venta fue a cuenta corriente, neutralizamos la deuda original
        if ($venta['cond_pago'] === 'Cuenta Corriente') {
            
            // Insertamos movimiento inverso: el total va al HABER para restar del DEBE original
            $sql_ctacte = "INSERT INTO ctacte (id_cliente, movimiento, n_documento, debe, haber, fecha) 
                           VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt_ctacte = $pdo->prepare($sql_ctacte);
            $stmt_ctacte->execute([
                $venta['id_cliente'],
                "ANULACIÓN VENTA N° $n_doc - MOTIVO: $motivo",
                $n_doc,
                0,                  // debe
                $venta['total_venta'], // haber (cancela la deuda)
                $fecha_hoy
            ]);
        }
        
        $pdo->commit();
        $mensaje = "✅ Venta N° $n_doc anulada correctamente. Stock restituido y Cuenta Corriente actualizada.";
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
        .search-box { margin-bottom: 20px; display: flex; gap: 10px; }
        .detalle-anulacion { display: none; margin-top: 20px; }
        .status-anulada { color: red; font-weight: bold; }
        .card-danger { border-top: 5px solid #f44336; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <?php include 'infosesion.php'; ?>

    <div class="content">
        <h1>Anulación de Ventas</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Buscar Venta para Anular</h2>
            <div class="search-box">
                <input type="number" id="input_buscar_doc" class="input-field" placeholder="Ingrese N° de Documento / Factura">
                <button type="button" class="btn btn-primary" onclick="buscarVenta()">Buscar Venta</button>
            </div>
        </div>

        <div id="contenedor_detalle" class="card detalle-anulacion card-danger">
            <form method="POST" onsubmit="return confirmarAnulacion()">
                <h2>Detalle de la Venta N° <span id="span_n_doc"></span></h2>
                <input type="hidden" name="n_documento_anular" id="hidden_n_doc">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <p><strong>Cliente:</strong> <span id="det_cliente"></span></p>
                        <p><strong>Fecha:</strong> <span id="det_fecha"></span></p>
                    </div>
                    <div>
                        <p><strong>Total:</strong> <span id="det_total"></span></p>
                        <p><strong>Estado Actual:</strong> <span id="det_estado"></span></p>
                    </div>
                </div>

                <table class="table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th>Cantidad</th>
                            <th>P. Unit</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="tabla_articulos"></tbody>
                </table>

                <div style="margin-top: 20px;">
                    <label>Motivo de la Anulación:</label>
                    <textarea name="motivo" class="input-field" required placeholder="Ej: Error en carga, devolución de mercadería..."></textarea>
                </div>

                <button type="submit" name="confirmar_anulacion" class="btn btn-danger" style="width: 100%; margin-top: 20px;">
                    CONFIRMAR ANULACIÓN Y REINTEGRAR STOCK
                </button>
            </form>
        </div>
    </div>

    <script>
    function buscarVenta() {
        const nDoc = document.getElementById('input_buscar_doc').value;
        if (!nDoc) return alert("Ingrese un número");

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
                    document.getElementById('det_fecha').textContent = data.cabecera.fecha_venta;
                    document.getElementById('det_total').textContent = '$' + data.cabecera.total_venta;
                    document.getElementById('det_estado').textContent = data.cabecera.estado;

                    let html = '';
                    data.detalle.forEach(item => {
                        html += `<tr>
                            <td>${item.cod_prod}</td>
                            <td>${item.descripcion}</td>
                            <td>${item.cant}</td>
                            <td>$${item.p_unit}</td>
                            <td>$${item.total}</td>
                        </tr>`;
                    });
                    document.getElementById('tabla_articulos').innerHTML = html;
                    document.getElementById('contenedor_detalle').style.display = 'block';
                }
            });
    }

    function confirmarAnulacion() {
        return confirm("⚠️ ¿ESTÁ SEGURO? Esta acción devolverá los productos al stock y anulará el comprobante.");
    }
    </script>
</body>
</html>