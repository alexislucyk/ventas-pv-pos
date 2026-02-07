<?php
session_start();

// 1. GUARDIA DE SEGURIDAD: Si no hay sesión, redirigir al login
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
// Obtener la fecha actual
$hoy = date('Y-m-d');
require 'config/db_config.php';
try {
    // 1. Total Contado y Transferencias (Línea 20)
    $sql_efectivo = "SELECT SUM(total_venta) as total FROM ventas 
                     WHERE fecha_venta = ? 
                     AND estado != 'Anulada' 
                     AND (cond_pago = 'Contado' OR cond_pago = 'Transferencia')";
    
    $stmt_efectivo = $pdo->prepare($sql_efectivo);
    $stmt_efectivo->execute(array($hoy));
    $res_efectivo = $stmt_efectivo->fetch(PDO::FETCH_ASSOC);
    $total_contado = ($res_efectivo && $res_efectivo['total'] !== null) ? $res_efectivo['total'] : 0;

    // 2. Total Cuenta Corriente (Línea 28)
    $sql_ctacte = "SELECT SUM(total_venta) as total FROM ventas 
                   WHERE fecha_venta = ? 
                   AND estado != 'Anulada' 
                   AND cond_pago = 'Cuenta Corriente'";
    
    $stmt_ctacte = $pdo->prepare($sql_ctacte);
    $stmt_ctacte->execute(array($hoy));
    $res_ctacte = $stmt_ctacte->fetch(PDO::FETCH_ASSOC);
    $total_ctacte = ($res_ctacte && $res_ctacte['total'] !== null) ? $res_ctacte['total'] : 0;

    // 3. Cantidad de ventas (Línea 33 - CORREGIDA)
    $sql_cant = "SELECT COUNT(*) as cantidad FROM ventas WHERE fecha_venta = ? AND estado != 'Anulada'";
    $stmt_cant = $pdo->prepare($sql_cant);
    $stmt_cant->execute(array($hoy));
    $res_cant = $stmt_cant->fetch(PDO::FETCH_ASSOC);
    // Cambiamos el ?? 0 por un operador ternario compatible:
    $cant_ventas = ($res_cant && isset($res_cant['cantidad'])) ? $res_cant['cantidad'] : 0;

} catch (PDOException $e) {
    echo "Error en el Dashboard: " . $e->getMessage();
}
// Datos de la sesión para mostrar
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre']);
$rol = htmlspecialchars($_SESSION['usuario_rol']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Electricidad Lucyk</title>
    <link rel="stylesheet" href="css\style.css">
</head>
<body>
<button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
<?php include 'pages/sidebar.php'; ?>

<div class="content">
    <div class="dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
    
        <div class="card" style="border-left: 5px solid #4CAF50;">
            <h3 style="color: #666; font-size: 0.9em; margin-bottom: 10px;">EFECTIVO / TRANSFERENCIAS (HOY)</h3>
            <p style="font-size: 1.8em; font-weight: bold; color: #2e7d32;">
                $<?php echo number_format($total_contado, 2, ',', '.'); ?>
            </p>
            <span style="font-size: 0.8em; color: #888;">Dinero ingresado hoy</span>
        </div>

        <div class="card" style="border-left: 5px solid #FF9800;">
            <h3 style="color: #666; font-size: 0.9em; margin-bottom: 10px;">CUENTA CORRIENTE (HOY)</h3>
            <p style="font-size: 1.8em; font-weight: bold; color: #ef6c00;">
                $<?php echo number_format($total_ctacte, 2, ',', '.'); ?>
            </p>
            <span style="font-size: 0.8em; color: #888;">Total facturado al fiado</span>
        </div>

        <div class="card" style="border-left: 5px solid #2196F3;">
            <h3 style="color: #666; font-size: 0.9em; margin-bottom: 10px;">OPERACIONES DEL DÍA</h3>
            <p style="font-size: 1.8em; font-weight: bold; color: #1565c0;">
                <?php echo $cant_ventas; ?> Ventas
            </p>
            <span style="font-size: 0.8em; color: #888;">Total de tickets emitidos</span>
        </div>

    </div>
</div>

</body>
</html>