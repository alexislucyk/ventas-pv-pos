<?php
// pages/verificar_cajas_historicas.php - Vista con estilo de la app
include 'infosesion.php';
require '../config/db_config.php';

// Verificar que el usuario sea developer
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'developer') {
    header('Location: ' . URL_BASE . 'pages/caja_dashboard.php');
    exit();
}

// Procesar fecha límite personalizada
$fecha_limite_default = date('Y-m-d');
$fecha_limite = isset($_POST['fecha_limite']) ? $_POST['fecha_limite'] : $fecha_limite_default;
$cajas_encontradas = [];
$errores = [];
$total_cajas = 0;
$total_movimientos = 0;
$total_saldo_inicial = 0;

try {
    // Verificar que existe la tabla estado_caja
    $sql_check = "SHOW TABLES LIKE 'estado_caja'";
    $stmt_check = $pdo->query($sql_check);
    
    if (!$stmt_check->fetch()) {
        $error = "La tabla 'estado_caja' no existe. Debe ejecutar la migración primero.";
    } else {
    // Obtener todas las fechas con movimientos sin cerrar anteriores a la fecha límite
    $sql_cajas = "SELECT 
        DATE(m.fecha) as fecha,
        m.empresa_id,
        m.sucursal_id,
        COALESCE(SUM(CASE WHEN m.es_fondo_inicial = 1 THEN m.monto ELSE 0 END), 0) as saldo_inicial,
        'Sistema' as usuario_apertura,
        MAX(e.nombre_fantasia) as empresa_nombre,
        MAX(s.nombre_sucursal) as sucursal_nombre
    FROM movimientos m
    INNER JOIN empresas e ON m.empresa_id = e.id
    INNER JOIN sucursales s ON m.sucursal_id = s.id
    WHERE m.cerrado = 0
      AND DATE(m.fecha) < :fecha_limite
    GROUP BY DATE(m.fecha), m.empresa_id, m.sucursal_id
    ORDER BY m.empresa_id, m.sucursal_id, DATE(m.fecha)";
        
        $stmt_cajas = $pdo->prepare($sql_cajas);
        $stmt_cajas->execute([':fecha_limite' => $fecha_limite]);
        $cajas_abiertas = $stmt_cajas->fetchAll();
        
        $total_cajas = count($cajas_abiertas);
        
        if ($total_cajas > 0) {
            foreach ($cajas_abiertas as $caja) {
                // Obtener cantidad de movimientos para esta caja
                $sql_mov = "SELECT COUNT(*) as cantidad, 
                                   SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                                            THEN monto ELSE 0 END) as ing_efectivo,
                                   SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
                            FROM movimientos 
                            WHERE empresa_id = :empresa_id 
                              AND sucursal_id = :sucursal_id
                              AND DATE(fecha) = :fecha
                              AND cerrado = 0";
                
                $stmt_mov = $pdo->prepare($sql_mov);
                $stmt_mov->execute([
                    ':empresa_id' => $caja['empresa_id'],
                    ':sucursal_id' => $caja['sucursal_id'],
                    ':fecha' => $caja['fecha']
                ]);
                $mov_data = $stmt_mov->fetch(PDO::FETCH_ASSOC);
                
                $caja['cant_movimientos'] = (int)($mov_data['cantidad'] ?? 0);
                $caja['ing_efectivo'] = (float)($mov_data['ing_efectivo'] ?? 0);
                $caja['egresos'] = (float)($mov_data['egresos'] ?? 0);
                $caja['saldo_esperado'] = $caja['ing_efectivo'] - $caja['egresos'];
                
                // Calcular fecha_apertura en PHP
                $caja['fecha_apertura'] = $caja['fecha'] . ' 00:00:00';
                
                $total_movimientos += $caja['cant_movimientos'];
                $total_saldo_inicial += (float)$caja['saldo_inicial'];
                
                $cajas_encontradas[] = $caja;
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificar Cajas Históricas | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .info-box {
            background: #1e3a5f;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .warning-box {
            background: #3d2e0f;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .success-box {
            background: #1e3d2e;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .error-box {
            background: #3d1e1e;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: #2c2c2c;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #00bcd4;
        }
        .stat-box .label {
            color: #aaa;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .stat-box .value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #00bcd4;
        }
        .table-container {
            background: #2c2c2c;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
        .badge-success { background-color: #27ae60; color: white; }
        .badge-warning { background-color: #f39c12; color: white; }
        .badge-info { background-color: #17a2b8; color: white; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1><i class="fas fa-search"></i> Verificar Cajas Históricas</h1>
            <a href="caja_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver al Dashboard
            </a>
        </div>

        <div class="info-box" style="margin-bottom: 20px;">
            <form method="POST" action="" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label for="fecha_limite" style="font-weight: bold; margin: 0;">
                    <i class="fas fa-calendar-alt"></i> Fecha Límite:
                </label>
                <input type="date" id="fecha_limite" name="fecha_limite" value="<?php echo htmlspecialchars($fecha_limite); ?>" 
                       style="padding: 8px 12px; border-radius: 4px; border: 1px solid #444; background: #1e1e1e; color: #fff; font-size: 1rem;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 16px;">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <span style="color: #aaa; font-size: 0.9rem;">
                    Mostrará cajas abiertas anteriores a esta fecha
                </span>
            </form>
        </div>

        <?php if (isset($error)): ?>
        <div class="error-box">
            <strong><i class="fas fa-exclamation-circle"></i> ERROR:</strong><br>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php else: ?>
        
        <div class="info-box">
            <strong><i class="fas fa-info-circle"></i> Modo Solo Lectura</strong><br>
            Este script NO modifica la base de datos. Solo muestra información sobre las cajas que se cerrarían.
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Cajas a Cerrar</div>
                <div class="value"><?php echo $total_cajas; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Movimientos Pendientes</div>
                <div class="value"><?php echo $total_movimientos; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Suma Saldos Iniciales</div>
                <div class="value">$<?php echo number_format($total_saldo_inicial, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Fecha Límite</div>
                <div class="value"><?php echo date('d/m/Y', strtotime($fecha_limite)); ?></div>
            </div>
        </div>

        <?php if ($total_cajas === 0): ?>
        <div class="success-box">
            <strong><i class="fas fa-check-circle"></i> Excelente!</strong><br>
            No hay cajas abiertas para cerrar. El sistema está normalizado.
        </div>
        <?php else: ?>
        
        <div class="table-container">
            <h3 style="margin-top: 0; color: #00bcd4;">
                <i class="fas fa-list"></i> Cajas a Cerrar (<?php echo $total_cajas; ?>)
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Empresa</th>
                        <th>Sucursal</th>
                        <th>Fecha</th>
                        <th>Saldo Inicial</th>
                        <th>Usuario Apertura</th>
                        <th>Fecha Apertura</th>
                        <th>Movimientos</th>
                        <th>Ing. Efectivo</th>
                        <th>Egresos</th>
                        <th>Saldo Esperado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cajas_encontradas as $index => $caja): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($caja['empresa_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($caja['sucursal_nombre']); ?></td>
                        <td><strong><?php echo date('d/m/Y', strtotime($caja['fecha'])); ?></strong></td>
                        <td>$<?php echo number_format($caja['saldo_inicial'], 2, ',', '.'); ?></td>
                        <td><?php echo htmlspecialchars($caja['usuario_apertura']); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($caja['fecha_apertura'])); ?></td>
                        <td><span class="badge badge-warning"><?php echo $caja['cant_movimientos']; ?> movs</span></td>
                        <td>$<?php echo number_format($caja['ing_efectivo'], 2, ',', '.'); ?></td>
                        <td>$<?php echo number_format($caja['egresos'], 2, ',', '.'); ?></td>
                        <td><strong>$<?php echo number_format($caja['saldo_esperado'], 2, ',', '.'); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="warning-box" style="margin-top: 20px;">
            <strong><i class="fas fa-exclamation-triangle"></i> Acciones a Realizar:</strong><br>
            1. Verifique que los datos sean correctos<br>
            2. Si todo está bien, ejecute el script de cierre masivo<br>
            3. <strong>IMPORTANTE:</strong> Eliminar los scripts después de usarlos
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>