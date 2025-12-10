<?php
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}
require '../config/db_config.php'; // Asegúrate de que $pdo esté disponible

// Cargar todas las ventas para la tabla
try {
    $sql_ventas = "SELECT 
                        v.id AS id_venta,
                        v.n_documento,
                        v.fecha_venta,
                        v.total_venta,
                        CONCAT(c.apellido, ', ', c.nombre) AS nombre_cliente
                    FROM ventas v
                    LEFT JOIN clientes c ON v.id_cliente = c.id
                    ORDER BY v.n_documento DESC";
    $stmt_ventas = $pdo->query($sql_ventas);
    $ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error al cargar ventas: " . $e->getMessage());
    $ventas = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Ventas</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        .btn-action { margin-right: 5px; padding: 5px 10px; cursor: pointer; }
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; 
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8);
        }
        .modal-content-lg {
            background-color: #333; margin: 5% auto; padding: 20px; border: 1px solid #888; 
            width: 80%; max-width: 900px; color: white;
        }
        .close-button { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        #detalleBody { min-height: 100px; }
        #ticketContainer { width: 320px; margin: 0 auto; background: white; color: black; padding: 10px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1>📊 Resumen Histórico de Ventas</h1>
        
        <div class="card">
            <table id="tablaVentas" style="width: 100%;">
                <thead>
                    <tr>
                        <th>N° Doc.</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Total</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventas as $venta): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($venta['n_documento']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($venta['fecha_venta'])); ?></td>
                            <td><?php echo htmlspecialchars($venta['nombre_cliente'] ?: 'Público General'); ?></td>
                            <td class="text-right">$<?php echo number_format($venta['total_venta'], 2); ?></td>
                            <td>
                                <button class="btn btn-primary btn-action" onclick="mostrarDetalle(<?php echo $venta['n_documento']; ?>)">Detalle</button>
                                <button class="btn btn-success btn-action" onclick="imprimirTicket(<?php echo $venta['n_documento']; ?>)">Reimprimir</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="detalleModal" class="modal">
        <div class="modal-content-lg">
            <span class="close-button" onclick="document.getElementById('detalleModal').style.display='none';">&times;</span>
            <h2>Detalle de Venta #<span id="detalleNdocumento"></span></h2>
            
            <div id="detalleBody">
                </div>
            
            <button class="btn btn-success" onclick="imprimirTicket(document.getElementById('detalleNdocumento').textContent)" style="margin-top: 20px;">
                🖨️ Reimprimir desde Detalle
            </button>
        </div>
    </div>

    <div id="ticketModal" class="modal">
        <div class="modal-content-lg" style="width: 350px;">
            <span class="close-button" onclick="document.getElementById('ticketModal').style.display='none';">&times;</span>
            <h3 style="margin-top: 5px; margin-bottom: 10px;">Ticket de Venta</h3>
            <div id="ticketContainer" style="background: white; color: black; padding: 10px;">
                Cargando...
            </div>
            <button id="printModalButton" class="btn btn-primary" style="width: 100%; margin-top: 15px;" onclick="iniciarImpresion()">
                🖨️ Imprimir
            </button>
        </div>
    </div>
<script>
    const detalleModal = document.getElementById('detalleModal');
    const detalleBody = document.getElementById('detalleBody');
    const detalleNdocumento = document.getElementById('detalleNdocumento');
    
    const ticketModal = document.getElementById('ticketModal');
    const ticketContainer = document.getElementById('ticketContainer');
    const printModalButton = document.getElementById('printModalButton');

    // Muestra el detalle de la venta en un modal
    function mostrarDetalle(nDocumento) {
        detalleNdocumento.textContent = nDocumento;
        detalleBody.innerHTML = 'Cargando detalle...';
        detalleModal.style.display = 'block';

        fetch('../../pos/ajax/obtener_detalle_venta.php?n_documento=' + nDocumento)
            .then(response => {
                if (!response.ok) throw new Error('Error al cargar la respuesta.');
                return response.text();
            })
            .then(html => {
                detalleBody.innerHTML = html;
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                detalleBody.innerHTML = '<p style="color: red;">❌ Error al cargar el detalle de la venta.</p>';
            });
    }

    // Muestra el modal de ticket (reimpresión)
// ELIMINAR O COMENTAR la función imprimirTicket antigua y todo lo relacionado con ticketModal

function imprimirTicket(nDocumento) {
    // LLAMADA MODIFICADA: Abre la nueva página de vista previa e impresión
    window.open('../../pos/pages/vista_previa_ticket.php?n_documento=' + nDocumento, '_blank', 'width=400,height=600');
    
    // Si la función se llama desde el modal de detalle, ciérralo
    const detalleModal = document.getElementById('detalleModal');
    if(detalleModal && detalleModal.style.display === 'block') {
        detalleModal.style.display='none';
    }
}

    // Función que realiza la impresión del contenido del ticketContainer
    function iniciarImpresion() {
        // Necesitas el mismo código de impresión que tienes en ventas.php
        // para manejar la apertura de la ventana temporal y la impresión.
        
        const contentToPrint = ticketContainer.innerHTML;
        const printWindow = window.open('', '_blank', 'width=300,height=300');
        
        printWindow.document.write('<html><head><title>Reimprimir Ticket</title>');
        // Debes incluir los estilos de impresión que tienes en ventas.php aquí:
        printWindow.document.write(`
            <style>
                body { font-family: monospace; margin: 0; padding: 0; width: 80mm; }
                .ticket { width: 100%; margin: 0 auto; font-size: 10px; }
                .center { text-align: center; }
                .right { text-align: right; }
                .sep { border-bottom: 1px dashed #000; margin: 5px 0; }
            </style>
        `); 
        
        printWindow.document.write('</head><body>');
        printWindow.document.write(contentToPrint);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
            setTimeout(function(){
                ticketModal.style.display = 'none';
                printWindow.close();
            }, 500);
        };
    }
</script>
</body>
</html>