<?php
// 1. CORRECCIÓN: Zona Horaria
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
// ventas_pendientes_ajax.php
session_start();
// 1. Control de Conexión y Sesión
if (!isset($_SESSION['usuario_id'])) {
    echo "Debe iniciar sesión para acceder a esta información.";
    exit();
}

require '../config/db_config.php'; 

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "Error crítico: Conexión a la base de datos no disponible.";
    exit();
}

try {
    // Consulta para obtener todas las ventas con estado 'Pendiente'
    $sql = "SELECT 
                v.id AS id_venta,
                v.n_documento,
                v.fecha_venta,
                v.total_venta,
                c.nombre,
                c.apellido
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id
            WHERE v.estado = 'Pendiente'
            ORDER BY v.fecha_venta DESC";

    $stmt = $pdo->query($sql);
    $ventas_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error al cargar ventas pendientes: " . $e->getMessage());
    echo "Error interno al consultar la base de datos.";
    exit();
}

// 2. Generar la tabla HTML
if (empty($ventas_pendientes)) {
    echo '<p style="padding: 15px; background: #333; border-radius: 5px;">🎉 No hay ventas en espera actualmente.</p>';
    exit();
}
?>

<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th>N° Doc.</th>
            <th>Cliente</th>
            <th>Fecha</th>
            <th style="text-align: right;">Total</th>
            <th>Acción</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ventas_pendientes as $venta): ?>
        <tr>
            <td><?php echo htmlspecialchars($venta['n_documento']); ?></td>
            <td>
                <?php 
                if (!empty($venta['nombre']) || !empty($venta['apellido'])) {
                    echo htmlspecialchars(trim($venta['apellido'] . ', ' . $venta['nombre']));
                } else {
                    echo 'Venta Genérica';
                }
                ?>
            </td>
            <td><?php echo date('d/m H:i', strtotime($venta['fecha_venta'])); ?></td>
            <td style="text-align: right;">$<?php echo number_format($venta['total_venta'], 2, ',', '.'); ?></td>
            <td>
                <button type="button" class="btn btn-info btn-sm btn-reanudar-venta" 
                        data-venta-id="<?php echo htmlspecialchars($venta['id_venta']); ?>">
                    Reanudar
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>