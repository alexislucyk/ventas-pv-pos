<?php
// pages/consultar_presupuestos.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$sql = "SELECT p.*, CONCAT(c.apellido, ' ', c.nombre) AS cliente_nombre 
        FROM presupuestos p 
        LEFT JOIN clientes c ON p.id_cliente = c.id AND c.empresa_id = ?
        WHERE p.empresa_id = ?
        ORDER BY p.id DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute([$empresa_id, $empresa_id]);
$presupuestos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consultar Presupuestos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>🔍 Historial de Presupuestos</h1>
            <a href="presupuestos.php" class="btn-secondary" style="text-decoration: none; padding: 10px 20px; background: #34495e; color: white; border-radius: 5px; font-weight: bold; border: 1px solid #444;">
                + Nuevo Presupuesto
            </a>
        </div>

        <div class="table-container">
            <table style="width: 100%;">
                <thead>
                    <tr style="background: #1a1a1a;">
                        <th>N°</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Total</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($presupuestos as $pres): ?>
                    <tr>
                        <td>#<?php echo $pres['id']; ?></td>
                        <td><?php echo $pres['fecha_presupuesto'] ? date('d/m/Y', strtotime($pres['fecha_presupuesto'])) : '-'; ?></td>
                        <td><?php echo htmlspecialchars($pres['cliente_nombre']); ?></td>
                        <td style="color: #2ecc71; font-weight: bold;">$<?php echo number_format($pres['total_presupuesto'], 2); ?></td>
                        <td>
                            <a href="generar_pdf_presupuesto.php?id=<?php echo $pres['id']; ?>" target="_blank" title="Ver PDF">
                                <i class="fas fa-file-pdf" style="color: #e74c3c; font-size: 1.2em;"></i>
                            </a>
                            <button onclick="verPresupuesto(<?php echo $pres['id']; ?>)" title="Vista Previa" style="background:none; border:none; cursor:pointer;">
                                <i class="fas fa-eye" style="color: #3498db; font-size: 1.2em;"></i>
                            </button>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div id="modalPresupuesto" class="modal" style="display:none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8);">
    <div style="background-color: #252525; margin: 2% auto; padding: 20px; border: 1px solid #444; width: 70%; border-radius: 8px; color: white; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 20px;">
            <h2 id="modalTitulo" style="margin: 0;">Presupuesto #</h2>
            <span onclick="cerrarModal()" style="cursor: pointer; font-size: 28px; font-weight: bold;">&times;</span>
        </div>
        
        <div id="contenidoPresupuesto" style="overflow-y: auto; flex-grow: 1; padding-right: 5px;">
            <p>Cargando detalles...</p>
        </div>

        <div style="margin-top: 25px; text-align: right; border-top: 1px solid #444; padding-top: 15px;">
            <button onclick="cerrarModal()" class="btn-secondary" style="padding: 10px 20px; cursor:pointer;">Cerrar</button>
            <a id="btnDescargarPDF" href="#" target="_blank" class="btn-success" style="padding: 10px 20px; text-decoration:none; margin-left:10px;">🖨️ Imprimir PDF</a>
        </div>
    </div>
</div>
<script>
    function verPresupuesto(id) {
    const modal = document.getElementById('modalPresupuesto');
    const contenido = document.getElementById('contenidoPresupuesto');
    const titulo = document.getElementById('modalTitulo');
    const btnPDF = document.getElementById('btnDescargarPDF');

    modal.style.display = 'block';
    titulo.innerText = "Cargando Presupuesto #" + id + "...";
    btnPDF.href = "generar_pdf_presupuesto.php?id=" + id;

    // Llamada AJAX para obtener los items del presupuesto
    fetch('../ajax/obtener_detalle_presupuesto.php?id=' + id)
        .then(response => response.text())
        .then(data => {
            titulo.innerText = "Detalle de Presupuesto #" + id;
            contenido.innerHTML = data;
        })
        .catch(error => {
            contenido.innerHTML = "<p style='color:red;'>Error al cargar los datos.</p>";
        });
}

function cerrarModal() {
    document.getElementById('modalPresupuesto').style.display = 'none';
}

// Cerrar si hace clic fuera del contenido
window.onclick = function(event) {
    const modal = document.getElementById('modalPresupuesto');
    if (event.target == modal) { cerrarModal(); }
}
</script>
</body>
</html>