<?php
// 1. CORRECCIÃ“N: Zona Horaria
date_default_timezone_set('America/Argentina/Buenos_Aires'); 
// ventas_pendientes_ajax.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo "Debe iniciar sesiÃ³n para acceder a esta informaciÃ³n.";
    exit();
}

if (!isset($_SESSION['usuario_id'])) {
    echo "Debe iniciar sesiÃ³n para acceder a esta informaciÃ³n.";
    exit();
}

require '../config/db_config.php'; 

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "Error crÃ­tico: ConexiÃ³n a la base de datos no disponible.";
    exit();
}

try {
    $sql = "SELECT 
                v.id AS id_venta,
                v.n_documento,
                v.fecha_venta,
                v.total_venta,
                c.nombre,
                c.apellido
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id1
            WHERE v.estado = 'Pendiente' AND v.empresa_id = :empresa_id2
            ORDER BY v.fecha_venta DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id]);
    $ventas_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error al cargar ventas pendientes: " . $e->getMessage());
    echo "Error interno al consultar la base de datos.";
    exit();
}

// 2. Generar la tabla HTML
if (empty($ventas_pendientes)) {
    echo '<p style="padding: 15px; background: #333; border-radius: 5px;">ðŸŽ‰ No hay ventas en espera actualmente.</p>';
    exit();
}
?>

<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th>NÂ° Doc.</th>
            <th>Cliente</th>
            <th>Fecha</th>
            <th style="text-align: right;">Total</th>
            <th>AcciÃ³n</th>
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
                    echo 'Venta GenÃ©rica';
                }
                ?>
            </td>
            <td><?php echo date('d/m', strtotime($venta['fecha_venta'])); ?></td>
            <td style="text-align: right;">$<?php echo number_format($venta['total_venta'], 2, ',', '.'); ?></td>
            <td>
                <?php
                // CORRECCIÃ“N: Usar concatenaciÃ³n para asegurar que el nÃºmero de documento se pasa como argumento JS
                echo "<button 
                        type='button' 
                        class='btn btn-success' 
                        onclick='reanudarVenta(" . $venta['n_documento'] . ")' 
                        data-venta-id='" . htmlspecialchars($venta['id_venta']) . "'>
                    Reanudar
                </button>
                <button 
                        type='button' 
                        class='btn btn-danger' 
                        onclick='eliminarVentaPendiente(" . $venta['n_documento'] . ")' 
                        data-venta-id='" . htmlspecialchars($venta['id_venta']) . "' 
                        style='margin-left:5px;'>
                    <i class='fas fa-trash'></i> Eliminar
                </button>";
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
