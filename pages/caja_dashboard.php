<?php
// pages/caja_dashboard.php
include 'infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Obtener estado de caja
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
$caja_abierta = $estado && $estado['estado'] === 'ABIERTA';

// Si la caja está cerrada, redirigir
if (!$caja_abierta) {
    header("Location: " . url('abrir-caja'));
    exit();
}

$hoy = date('Y-m-d');

try {
    // Modelo por sesión: la caja puede estar abierta varios días. Se muestran
    // todos los movimientos sin cerrar desde la apertura de la sesión actual.
    // movimientos.fecha es DATE (sin hora), por lo que el filtro arranca a las
    // 00:00 del día de apertura (consistente con el cierre de caja).
    $fecha_apertura_db = $estado['fecha_apertura'] ?? date('Y-m-d H:i:s');
    $apertura = date('Y-m-d', strtotime($fecha_apertura_db)) . ' 00:00:00';

    // USAR cerrado = 0 en lugar de DATE(fecha) = ?
    $sql_resumen = "SELECT 
                        SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) as efectivo,
                        SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as transferencia,
                        SUM(CASE WHEN metodo_pago = 'MIXTO' THEN monto ELSE 0 END) as mixto
                    FROM movimientos 
                    WHERE tipo = 'INGRESO' 
                      AND cerrado = 0 
                      AND fecha >= ?
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    $stmt = $pdo->prepare($sql_resumen);
    $stmt->execute([$apertura, $empresa_id, $sucursal_id]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['mixto'] = (float)($resumen['mixto'] ?? 0);

    $sql_egresos = "SELECT SUM(monto) as total_egresos 
                    FROM movimientos 
                    WHERE tipo = 'EGRESO' 
                      AND cerrado = 0 
                      AND fecha >= ?
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    $stmt_eg = $pdo->prepare($sql_egresos);
    $stmt_eg->execute([$apertura, $empresa_id, $sucursal_id]);
    $egresos = $stmt_eg->fetchColumn() ?: 0;

    $sql_movs = "SELECT tipo, metodo_pago, detalle, monto, fecha, usuario 
                 FROM movimientos 
                 WHERE cerrado = 0 
                   AND fecha >= ?
                   AND empresa_id = ? 
                   AND sucursal_id = ?
                 ORDER BY id DESC LIMIT 10";
    $stmt_m = $pdo->prepare($sql_movs);
    $stmt_m->execute([$apertura, $empresa_id, $sucursal_id]);
    $lista_movimientos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // Incluir saldo inicial en el cálculo del total de caja física
    $saldo_inicial = (float)($estado['saldo_inicial'] ?? 0);
    $total_caja_fisica = $saldo_inicial + ($resumen['efectivo'] + $resumen['mixto']) - $egresos;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja del Día | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
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
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <!-- Mostrar estado de caja -->
        <div class="alert alert-success" style="background-color: #28a745; border-left: 4px solid #1e7e34; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i>
            <strong>Caja Abierta</strong> - 
            Apertura: <?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?> -
            Usuario: <?php echo htmlspecialchars($estado['usuario_apertura']); ?>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; gap: 10px;">
            <h1>Estado de Caja (Hoy)</h1>
            <div>
                <a href="movimiento_manual.php" class="btn" style="background: #6f42c1; color: white; padding: 15px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-right: 10px;">
                    <i class="fas fa-plus-circle"></i> Nuevo Movimiento
                </a>
                <a href="cierre_caja.php" class="btn-cierre">
                    <i class="fas fa-lock"></i> Cerrar Caja
                </a>
            </div>
        </div>

        <!-- Botones de acceso para Developer (Cierres Históricos) -->
        <?php if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer'): ?>
        <div class="card" style="background: #252525; border-left: 3px solid #666; margin-bottom: 20px; padding: 15px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <i class="fas fa-tools" style="color: #666; font-size: 0.9rem;"></i>
                <span style="color: #888; font-size: 0.85rem; text-transform: uppercase; font-weight: bold;">Dev Tools</span>
            </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="verificar_cajas_historicas.php" 
                   class="btn btn-secondary" 
                   style="padding: 6px 12px; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;"
                   title="Verificar cajas abiertas anteriores al 05/08/2026 (SOLO LECTURA)">
                     <i class="fas fa-search"></i> Verificar Cajas Históricas
                </a>
                <a href="cerrar_cajas_historicas.php" 
                   class="btn btn-danger" 
                   style="padding: 6px 12px; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;"
                   title="Cerrar todas las cajas abiertas anteriores al 05/08/2026 (MODIFICA BD)">
                     <i class="fas fa-exclamation-triangle"></i> Cerrar Cajas Históricas
                </a>
                <a href="<?php echo url('diagnostico_cierre.php'); ?>" 
                   class="btn btn-warning" 
                   style="padding: 6px 12px; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;"
                   title="Diagnóstico de cierres de caja - SOLO LECTURA">
                     <i class="fas fa-stethoscope"></i> Diagnóstico Cierre
                </a>
                <span style="color: #666; font-size: 0.75rem; font-style: italic;">
                     * Solo para desarrollo - Ejecutar una sola vez
                </span>
            </div>
        </div>
        <?php endif; ?>

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
            <p style="color: #888; font-size: 0.85rem; margin-bottom: 15px; font-style: italic;">
                <i class="fas fa-info-circle"></i> Mostrando movimientos de la caja abierta desde su apertura (<?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?>). Los movimientos de cierres anteriores no se muestran aquí.
            </p>
            <table class="table-full">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Responsable</th> <th>Tipo</th>
                        <th>Método</th>
                        <th>Detalle</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_movimientos as $m): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($m['fecha'])); ?></td>
                        
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