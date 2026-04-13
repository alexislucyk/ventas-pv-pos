<?php
// pages/cuentas_corrientes.php (REESTRUCTURADO)
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 

// --- 1. CONSULTA PARA OBTENER SALDOS GENERALES ---
try {
    // Calculamos el saldo directamente en la consulta: SUM(debe) - SUM(haber)
    $sql_saldos = "
        SELECT 
            c.id AS id_cliente,
            CONCAT(c.apellido, ', ', c.nombre) AS nombre_completo,
            c.cuit,
            c.telefono,
            SUM(m.debe) AS total_debe,
            SUM(m.haber) AS total_haber,
            (SUM(m.debe) - SUM(m.haber)) AS saldo_actual
        FROM clientes c
        INNER JOIN ctacte m ON c.id = m.id_cliente
        GROUP BY c.id
        HAVING saldo_actual != 0
        ORDER BY saldo_actual DESC;
    ";
    
    $stmt_saldos = $pdo->query($sql_saldos);
    $clientes_cc = $stmt_saldos->fetchAll(PDO::FETCH_ASSOC);

    // Calcular deuda total de la calle
    $deuda_total = 0;
    foreach($clientes_cc as $c) { if($c['saldo_actual'] > 0) $deuda_total += $c['saldo_actual']; }

} catch (Exception $e) {
    error_log("Error en CC: " . $e->getMessage());
    $clientes_cc = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuentas Corrientes | Dashboard</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .card-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #2c2c2c;
            padding: 20px;
            border-radius: 8px;
            border-left: 5px solid #e74c3c;
            color: #fff;
        }
        .stat-card h3 { margin: 0; font-size: 14px; color: #bbb; }
        .stat-card .value { font-size: 24px; font-weight: bold; margin-top: 10px; }
        
        .saldo-deudor { color: #ff5e5e; font-weight: bold; }
        .saldo-favor { color: #2ecc71; font-weight: bold; }
        
        .btn-view {
            background: #3498db;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-view:hover { background: #2980b9; }

        /* Estilo para que se parezca a resumen_ventas */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #1f1f1f; color: #eee; }
        th { background: #333; color: #fff; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #444; }
        tr:hover { background: #292929; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>📊 Cuentas Corrientes</h1>
            <a href="pagos_ctacte.php" class="btn-primary" style="padding: 10px 20px; text-decoration: none; border-radius: 5px;">
                ➕ Registrar Pago / Cobro
            </a>
        </div>

        <div class="card-stats">
            <div class="stat-card">
                <h3>DEUDA TOTAL A COBRAR</h3>
                <div class="value">$ <?php echo number_format($deuda_total, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #3498db;">
                <h3>CLIENTES CON DEUDA</h3>
                <div class="value"><?php echo count($clientes_cc); ?></div>
            </div>
        </div>

        <div class="card">
            <table id="tablaSaldos">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>CUIT / Documento</th>
                        <th>Teléfono</th>
                        <th style="text-align: right;">Saldo Actual</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes_cc)): ?>
                        <tr><td colspan="5" style="text-align: center;">No hay clientes con saldos pendientes.</td></tr>
                    <?php else: ?>
                        <?php foreach ($clientes_cc as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['nombre_completo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['cuit']); ?></td>
                                <td><?php echo htmlspecialchars($c['telefono']); ?></td>
                                <td style="text-align: right;" class="<?php echo $c['saldo_actual'] > 0 ? 'saldo-deudor' : 'saldo-favor'; ?>">
                                    $ <?php echo number_format($c['saldo_actual'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-view" onclick="verDetalle(<?php echo $c['id_cliente']; ?>, '<?php echo $c['nombre_completo']; ?>')">
                                        👁️ Ver Historial
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="modalHistorial" style="display:none; margin-top: 30px;" class="card">
            <div style="display: flex; justify-content: space-between;">
                <h2 id="tituloHistorial">Historial de Movimientos</h2>
                <button onclick="document.getElementById('modalHistorial').style.display='none'" style="background:none; border:none; color:white; cursor:pointer;">✖ Cerrar</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>N° Doc.</th>
                        <th style="text-align: right;">Debe</th>
                        <th style="text-align: right;">Haber</th>
                        <th style="text-align: right;">Saldo Acu.</th>
                    </tr>
                </thead>
                <tbody id="cuerpoHistorial">
                    </tbody>
            </table>
        </div>
    </div>

<!-- 	<div id="detalleModal" class="modal">
		<div class="modal-content-lg">
			<span class="close-button" onclick="document.getElementById('detalleModal').style.display='none';">&times;</span>
			<h2>Detalle de Venta #<span id="detalleNdocumento"></span></h2>
			
			<div id="detalleBody">
			</div>
			
			<button class="btn btn-success" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)" style="margin-top: 20px;">
				🖨️ Reimprimir desde Detalle
			</button>
		</div>
	</div> -->

	<div id="modalFactura" class="modal-custom" style="display:none;">
        <div class="modal-content-custom">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 id="tituloModalFactura">Detalle de Factura #<span id="detalleNdocumento"></span></h2>
                <button onclick="cerrarModalFactura()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            
            <div id="cuerpoDetalleFactura" style="margin-top: 20px;">
                </div>
            
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn-primary" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)" style="background-color: #27ae60; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; color: white;">
                    🖨️ Reimprimir Ticket
                </button>
            </div>
        </div>
    </div>

	<style>
	/* Estilo rápido para el modal (puedes usar el que ya tienes en resumen_ventas) */
	.modal-custom {
		position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%;
		background-color: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;
	}
	.modal-content-custom {
		background: #2c2c2c; padding: 20px; border-radius: 8px; width: 80%; max-width: 700px; color: white;
	}
	.btn-detalle { background: none; border: none; cursor: pointer; color: #3498db; font-size: 14px; }
	</style>

<script>
	const detalleNdocumento = document.getElementById('detalleNdocumento');

    // Función para Reimprimir
    function imprimirTicket(nDocumento) {
        if(!nDocumento || nDocumento === "" || nDocumento === "---") {
            alert("No hay un número de documento válido para imprimir.");
            return;
        }
        const url = '/pos/pages/vista_previa_ticket.php?n_documento=' + nDocumento;
        window.open(url, '_blank', 'width=350,height=600,scrollbars=yes,resizable=yes');
    }

    function verDetalle(id, nombre) {
        const modal = document.getElementById('modalHistorial');
        const cuerpo = document.getElementById('cuerpoHistorial');
        const titulo = document.getElementById('tituloHistorial');
        
        modal.style.display = 'block';
        titulo.innerText = 'Historial: ' + nombre;
        cuerpo.innerHTML = '<tr><td colspan="6" style="text-align:center;">Cargando movimientos...</td></tr>';

        fetch('../ajax/obtener_movimientos_cc.php?id_cliente=' + id)
            .then(res => res.text())
            .then(html => {
                cuerpo.innerHTML = html;
                modal.scrollIntoView({ behavior: 'smooth' });
            })
            .catch(err => {
                cuerpo.innerHTML = '<tr><td colspan="6" style="color:red;">Error al cargar.</td></tr>';
            });
    }

    function verDetalleFactura(idVenta) {
        const modal = document.getElementById('modalFactura');
        const cuerpo = document.getElementById('cuerpoDetalleFactura');
        const labelDoc = document.getElementById('detalleNdocumento'); // CAPTURAMOS EL SPAN
        
        modal.style.display = 'flex';
        if(labelDoc) labelDoc.textContent = idVenta; // ASIGNAMOS EL ID AL SPAN PARA QUE imprimirTicket LO LEA
        
        cuerpo.innerHTML = '<p style="text-align:center;">Cargando detalle...</p>';

        fetch('../ajax/obtener_detalle_venta.php?n_documento=' + idVenta)
            .then(res => res.text())
            .then(html => {
                cuerpo.innerHTML = html;
            })
            .catch(err => {
                cuerpo.innerHTML = '<p style="color:red;">Error al cargar el detalle.</p>';
            });
    }

    function cerrarModalFactura() {
        document.getElementById('modalFactura').style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('modalFactura');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
</script>
</body>
</html>