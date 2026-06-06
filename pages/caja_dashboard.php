<?php
// pages/caja_dashboard.php
include 'infosesion.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$hoy = date('Y-m-d');

try {
    // 1. Totales por Método de Pago (Solo ingresos de hoy)
    $sql_resumen = "SELECT 
                        SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) as efectivo,
                        SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as transferencia,
                        SUM(CASE WHEN metodo_pago = 'MIXTO' THEN monto ELSE 0 END) as mixto
                    FROM movimientos 
                    WHERE tipo = 'INGRESO' AND DATE(fecha) = ?";
    $stmt = $pdo->prepare($sql_resumen);
    $stmt->execute([$hoy]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

    // PHP 8.1 Fix: number_format no acepta null. Coalescemos a 0 y aseguramos tipo float.
    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['mixto'] = (float)($resumen['mixto'] ?? 0);

    // 2. Total de Egresos (Gastos)
    $sql_egresos = "SELECT SUM(monto) as total_egresos FROM movimientos WHERE tipo = 'EGRESO' AND DATE(fecha) = ?";
    $stmt_eg = $pdo->prepare($sql_egresos);
    $stmt_eg->execute([$hoy]);
    $egresos = $stmt_eg->fetchColumn() ?: 0;

    // 3. Últimos 10 movimientos del día
    $sql_movs = "SELECT tipo, metodo_pago, detalle, monto, fecha, usuario 
             FROM movimientos 
             WHERE DATE(fecha) = ? 
             ORDER BY id DESC LIMIT 10";
    $stmt_m = $pdo->prepare($sql_movs);
    $stmt_m->execute([$hoy]);
    $lista_movimientos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // Cálculo de caja física esperada (Efectivo + parte proporcional de mixtos - egresos)
    // Nota: Para ser exactos con 'MIXTO', lo ideal sería desglosarlo, 
    // pero por ahora sumamos el neto reportado.
    $total_caja_fisica = ($resumen['efectivo'] + $resumen['mixto']) - $egresos;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja del Día | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #1a1a1a;
            padding: 20px;
            border-radius: 10px;
            border-left: 5px solid #007bff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .stat-card.efectivo { border-color: #28a745; }
        .stat-card.transferencia { border-color: #17a2b8; }
        .stat-card.egreso { border-color: #dc3545; }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        .label { color: #888; font-size: 0.9rem; text-transform: uppercase; }
        
        .btn-cierre {
            background: #ffc107;
            color: #000;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            display: inline-block;
            transition: 0.3s;
        }
        .btn-cierre:hover { background: #e0a800; transform: translateY(-2px); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; gap: 10px;">
            <h1>Estado de Caja (Hoy)</h1>
            <div>
                <a href="movimiento_manual.php" class="btn" style="background: #6f42c1; color: white; padding: 15px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-right: 10px;">
                    <i class="fas fa-plus-circle"></i> Nuevo Movimiento
                </a>
                <a href="cierre_caja.php" class="btn-cierre">
                    <i class="fas fa-lock"></i> Ir a Cerrar Caja
                </a>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card efectivo">
                <span class="label">Efectivo en Caja</span>
                <span class="stat-value">$ <?php echo number_format($total_caja_fisica, 2, ',', '.'); ?></span>
            </div>
            <div class="stat-card transferencia">
                <span class="label">Transferencias</span>
                <span class="stat-value">$ <?php echo number_format($resumen['transferencia'], 2, ',', '.'); ?></span>
            </div>
            <div class="stat-card egreso">
                <span class="label">Egresos / Gastos</span>
                <span class="stat-value">$ <?php echo number_format($egresos, 2, ',', '.'); ?></span>
            </div>
        </div>

        <div class="card">
            <h3>Últimos Movimientos</h3>
            <table class="table-full">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Responsable</th> <th>Tipo</th>
                        <th>Método</th>
                        <th>Detalle</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_movimientos as $m): ?>
                    <tr>
                        <td><?php echo date('H:i', strtotime($m['fecha'])); ?></td>
                        
                        <td style="color: #aaa; font-size: 0.85rem;">
                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($m['usuario']); ?>
                        </td>

                        <td>
                            <span class="badge <?php echo $m['tipo'] == 'INGRESO' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo $m['tipo']; ?>
                            </span>
                        </td>
                        <td><?php echo $m['metodo_pago'] ?: '-'; ?></td>
                        <td><?php echo htmlspecialchars($m['detalle']); ?></td>
                        <td style="font-weight: bold;">
                            $ <?php echo number_format($m['monto'], 2, ',', '.'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>