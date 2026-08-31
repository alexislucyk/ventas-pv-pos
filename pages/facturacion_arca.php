<?php
include 'infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if (!tiene_permiso('pages/facturacion_arca.php')) {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

try {
    $sql = "
        SELECT 
            af.*, 
            v.n_documento as n_interno, 
            v.total_venta,
            v.fecha_venta,
            CONCAT(c.apellido, ', ', c.nombre) as nombre_cliente
        FROM ventas_afip af
        JOIN ventas v ON af.id_venta = v.id AND v.empresa_id = :empresa_id1
        LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id2
        ORDER BY af.fecha_proceso DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id]);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $facturas = [];
    $error = "Error al cargar comprobantes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobantes ARCA | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/misc.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h1><i class="fas fa-file-invoice-dollar" style="color: #00bcd4;"></i> Gestión de Facturas Electrónicas</h1>
            <a href="resumen_ventas.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a Ventas
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <p class="text-muted"><i class="fas fa-info-circle"></i> Aquí se listan únicamente las ventas que ya han obtenido el CAE.</p>
            <table style="width: 100%; margin-top: 15px;">
                <thead>
                    <tr>
                        <th>Comprobante Legal</th>
                        <th>Fecha Emisión</th>
                        <th>Cliente</th>
                        <th>Ref. Interna</th>
                        <th>CAE / Vencimiento</th>
                        <th style="text-align: right;">Importe</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facturas)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 30px; color: #666;">No hay facturas electrónicas emitidas en este periodo.</td></tr>
                    <?php else: ?>
                        <?php foreach ($facturas as $f): ?>
                            <tr>
                                <td>
                                    <strong style="color: #00bcd4;">
                                        Fac. <?php echo ($f['tipo_comprobante'] == 11) ? 'C' : 'B'; ?> 
                                        <?php echo str_pad($f['punto_venta'], 5, "0", STR_PAD_LEFT); ?>-<?php echo str_pad($f['n_comprobante'], 8, "0", STR_PAD_LEFT); ?>
                                    </strong>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($f['fecha_proceso'])); ?></td>
                                <td><?php echo htmlspecialchars($f['nombre_cliente'] ?: 'Público General'); ?></td>
                                <td>#<?php echo $f['n_interno']; ?></td>
                                <td>
                                    <span class="badge-cae"><?php echo $f['cae']; ?></span><br>
                                    <small class="text-muted">Vto: <?php echo date('d/m/Y', strtotime($f['cae_vto'])); ?></small>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #2ecc71;">
                                    $<?php echo number_format($f['total_venta'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn btn-primary btn-sm" onclick="descargarFactura(<?php echo $f['n_interno']; ?>)" title="Descargar PDF Oficial">
                                        <i class="fas fa-download"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function descargarFactura(nDoc) {
            window.location.href = 'generar_pdf_ticket.php?n_documento=' + nDoc + '&download=1';
        }
    </script>
</body>
</html>