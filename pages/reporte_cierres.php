<?php
// pages/reporte_cierres.php - Reporte histórico de cierres de caja
include 'infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Parámetros de filtro
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$sucursal_filtro = $_GET['sucursal_id'] ?? $sucursal_id;
$usuario_filtro = $_GET['usuario'] ?? '';

// Obtener lista de sucursales para el filtro
$sucursales = $pdo->prepare("SELECT id, nombre_sucursal FROM sucursales WHERE empresa_id = ? ORDER BY nombre_sucursal");
$sucursales->execute([$empresa_id]);
$sucursales = $sucursales->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de usuarios para el filtro
$usuarios = $pdo->prepare("SELECT DISTINCT usuario FROM cierres_caja WHERE empresa_id = ? ORDER BY usuario");
$usuarios->execute([$empresa_id]);
$usuarios = $usuarios->fetchAll(PDO::FETCH_COLUMN);

// Consulta base de cierres
$sql_cierres = "SELECT 
    c.id,
    c.fecha_cierre,
    c.fecha_desde,
    c.fecha_hasta,
    c.saldo_inicial,
    c.ingresos_efectivo,
    c.ingresos_transf,
    c.ingresos_cheques,
    c.ingresos_tarjetas,
    c.ingresos_otros,
    c.egresos,
    c.saldo_esperado_efectivo,
    c.saldo_real_efectivo,
    c.diferencia,
    c.fondo_reservado_vuelto,
    c.observaciones,
    c.usuario,
    c.numero_cierre,
    s.nombre_sucursal
FROM cierres_caja c
INNER JOIN sucursales s ON c.sucursal_id = s.id
WHERE c.empresa_id = :empresa_id
  AND c.fecha_desde >= :fecha_desde
  AND c.fecha_hasta <= :fecha_hasta";

$params = [
    ':empresa_id' => $empresa_id,
    ':fecha_desde' => $fecha_desde,
    ':fecha_hasta' => $fecha_hasta
];

// Filtro por sucursal
if ($sucursal_filtro && $sucursal_filtro != 'todas') {
    $sql_cierres .= " AND c.sucursal_id = :sucursal_id";
    $params[':sucursal_id'] = $sucursal_filtro;
}

// Filtro por usuario
if ($usuario_filtro) {
    $sql_cierres .= " AND c.usuario LIKE :usuario";
    $params[':usuario'] = "%$usuario_filtro%";
}

$sql_cierres .= " ORDER BY c.fecha_cierre DESC";

$stmt = $pdo->prepare($sql_cierres);
$stmt->execute($params);
$cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totales del reporte
$total_ingresos_efectivo = 0;
$total_ingresos_transf = 0;
$total_ingresos_cheques = 0;
$total_ingresos_tarjetas = 0;
$total_ingresos_otros = 0;
$total_egresos = 0;
$total_diferencias_positivas = 0;
$total_diferencias_negativas = 0;
$total_cierres = count($cierres);

foreach ($cierres as $cierre) {
    $total_ingresos_efectivo += (float)($cierre['ingresos_efectivo'] ?? 0);
    $total_ingresos_transf += (float)($cierre['ingresos_transf'] ?? 0);
    $total_ingresos_cheques += (float)($cierre['ingresos_cheques'] ?? 0);
    $total_ingresos_tarjetas += (float)($cierre['ingresos_tarjetas'] ?? 0);
    $total_ingresos_otros += (float)($cierre['ingresos_otros'] ?? 0);
    $total_egresos += (float)($cierre['egresos'] ?? 0);
    
    $diferencia = (float)($cierre['diferencia'] ?? 0);
    if ($diferencia > 0) {
        $total_diferencias_positivas += $diferencia;
    } else {
        $total_diferencias_negativas += $diferencia;
    }
}

// Exportar a CSV si se solicita
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_cierres_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM para UTF-8
    
    fputcsv($output, [
        'N° Cierre',
        'Período',
        'Fecha Cierre',
        'Sucursal',
        'Usuario',
        'Saldo Inicial',
        'Ingresos Efectivo',
        'Ingresos Transferencia',
        'Ingresos Cheques',
        'Ingresos Tarjetas',
        'Ingresos Otros',
        'Egresos',
        'Saldo Esperado',
        'Saldo Real',
        'Diferencia',
        'Fondo Vuelto',
        'Observaciones'
    ], ';');
    
    foreach ($cierres as $cierre) {
        $periodo = date('d/m/Y H:i', strtotime($cierre['fecha_desde'])) . ' - ' . date('d/m/Y H:i', strtotime($cierre['fecha_hasta']));
            
        fputcsv($output, [
            $cierre['numero_cierre'],
            $periodo,
            date('d/m/Y H:i', strtotime($cierre['fecha_cierre'])),
            $cierre['nombre_sucursal'],
            $cierre['usuario'],
            number_format($cierre['saldo_inicial'] ?? 0, 2, ',', '.'),
            number_format($cierre['ingresos_efectivo'] ?? 0, 2, ',', '.'),
            number_format($cierre['ingresos_transf'] ?? 0, 2, ',', '.'),
            number_format($cierre['ingresos_cheques'] ?? 0, 2, ',', '.'),
            number_format($cierre['ingresos_tarjetas'] ?? 0, 2, ',', '.'),
            number_format($cierre['ingresos_otros'] ?? 0, 2, ',', '.'),
            number_format($cierre['egresos'] ?? 0, 2, ',', '.'),
            number_format($cierre['saldo_esperado_efectivo'] ?? 0, 2, ',', '.'),
            number_format($cierre['saldo_real_efectivo'] ?? 0, 2, ',', '.'),
            number_format($cierre['diferencia'] ?? 0, 2, ',', '.'),
            number_format($cierre['fondo_reservado_vuelto'] ?? 0, 2, ',', '.'),
            $cierre['observaciones'] ?? ''
        ], ';');
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Cierres | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/reportes.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1><i class="fas fa-clipboard-list"></i> Reporte de Cierres de Caja</h1>
            <a href="?exportar=csv&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&sucursal_id=<?php echo $sucursal_filtro; ?>&usuario=<?php echo urlencode($usuario_filtro); ?>" class="btn-export">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </a>
        </div>

        <!-- Filtros -->
        <div class="filtros-box">
            <form method="GET" action="">
                <div class="filtros-grid">
                    <div>
                        <label>Fecha Desde</label>
                        <input type="date" name="fecha_desde" class="input-field" value="<?php echo $fecha_desde; ?>">
                    </div>
                    <div>
                        <label>Fecha Hasta</label>
                        <input type="date" name="fecha_hasta" class="input-field" value="<?php echo $fecha_hasta; ?>">
                    </div>
                    <div>
                        <label>Sucursal</label>
                        <select name="sucursal_id" class="input-field">
                            <option value="todas">Todas</option>
                            <?php foreach ($sucursales as $suc): ?>
                                <option value="<?php echo $suc['id']; ?>" <?php echo ($sucursal_filtro == $suc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($suc['nombre_sucursal']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Usuario</label>
                        <input type="text" name="usuario" class="input-field" value="<?php echo htmlspecialchars($usuario_filtro); ?>" placeholder="Buscar usuario...">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Total Cierres</div>
                <div class="value"><?php echo $total_cierres; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Ingresos Efectivo</div>
                <div class="value">$<?php echo number_format($total_ingresos_efectivo, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Ingresos Transferencia</div>
                <div class="value">$<?php echo number_format($total_ingresos_transf, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Ingresos Cheques</div>
                <div class="value">$<?php echo number_format($total_ingresos_cheques, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Ingresos Tarjetas</div>
                <div class="value">$<?php echo number_format($total_ingresos_tarjetas, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Ingresos Otros</div>
                <div class="value">$<?php echo number_format($total_ingresos_otros, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Egresos</div>
                <div class="value">$<?php echo number_format($total_egresos, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box positivo">
                <div class="label">Sobrante Total</div>
                <div class="value">$<?php echo number_format($total_diferencias_positivas, 2, ',', '.'); ?></div>
            </div>
            <div class="stat-box negativo">
                <div class="label">Faltante Total</div>
                <div class="value">$<?php echo number_format($total_diferencias_negativas, 2, ',', '.'); ?></div>
            </div>
        </div>

        <!-- Tabla de Cierres -->
        <div class="table-container">
            <h3 style="margin-top: 0; color: #00bcd4;">
                <i class="fas fa-list"></i> Cierres Registrados (<?php echo $total_cierres; ?>)
            </h3>
            
            <?php if (empty($cierres)): ?>
                <div class="alert alert-info" style="background-color: #004a54; border-left: 4px solid #00bcd4; padding: 15px; border-radius: 4px;">
                    <i class="fas fa-info-circle"></i> No se encontraron cierres en el período seleccionado.
                </div>
            <?php else: ?>
                <table class="table-full">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Período</th>
                            <th>Fecha Cierre</th>
                            <th>Sucursal</th>
                            <th>Usuario</th>
                            <th>Ing. Efectivo</th>
                            <th>Ing. Transf.</th>
                            <th>Ing. Cheques</th>
                            <th>Ing. Tarjetas</th>
                            <th>Ing. Otros</th>
                            <th>Egresos</th>
                            <th>Saldo Esperado</th>
                            <th>Saldo Real</th>
                            <th>Diferencia</th>
                            <th>Fondo Vuelto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cierres as $index => $cierre): ?>
                            <?php
                                $diferencia = (float)($cierre['diferencia'] ?? 0);
                                $saldo_esperado = (float)($cierre['saldo_esperado_efectivo'] ?? 0);
                                $saldo_real = (float)($cierre['saldo_real_efectivo'] ?? 0);
                                
                                // Determinar badge de diferencia
                                if ($diferencia == 0) {
                                    $badge_diferencia = '<span class="badge badge-success">OK</span>';
                                } elseif ($diferencia > 0) {
                                    $badge_diferencia = '<span class="badge badge-warning">+$' . number_format($diferencia, 2, ',', '.') . '</span>';
                                } else {
                                    $badge_diferencia = '<span class="badge badge-danger">-$' . number_format(abs($diferencia), 2, ',', '.') . '</span>';
                                }
                            ?>
                            <tr>
                            <td><strong>#<?php echo $cierre['numero_cierre']; ?></strong></td>
                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($cierre['fecha_desde'])); ?> - <?php echo date('d/m/Y H:i', strtotime($cierre['fecha_hasta'])); ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($cierre['fecha_cierre'])); ?></td>
                            <td><?php echo htmlspecialchars($cierre['nombre_sucursal']); ?></td>
                            <td><?php echo htmlspecialchars($cierre['usuario']); ?></td>
                            <td>$<?php echo number_format($cierre['ingresos_efectivo'] ?? 0, 2, ',', '.'); ?></td>
                            <td>$<?php echo number_format($cierre['ingresos_transf'] ?? 0, 2, ',', '.'); ?></td>
                            <td>$<?php echo number_format($cierre['ingresos_cheques'] ?? 0, 2, ',', '.'); ?></td>
                            <td>$<?php echo number_format($cierre['ingresos_tarjetas'] ?? 0, 2, ',', '.'); ?></td>
                            <td>$<?php echo number_format($cierre['ingresos_otros'] ?? 0, 2, ',', '.'); ?></td>
                            <td style="color: #ff6b6b;">$<?php echo number_format($cierre['egresos'] ?? 0, 2, ',', '.'); ?></td>
                            <td>$<?php echo number_format($saldo_esperado, 2, ',', '.'); ?></td>
                            <td><strong>$<?php echo number_format($saldo_real, 2, ',', '.'); ?></strong></td>
                            <td><?php echo $badge_diferencia; ?></td>
                            <td>$<?php echo number_format($cierre['fondo_reservado_vuelto'] ?? 0, 2, ',', '.'); ?></td>
                            <td>
                                    <?php if ($diferencia == 0): ?>
                                        <span class="badge badge-success">Sin Diferencias</span>
                                    <?php elseif ($diferencia > 0): ?>
                                        <span class="badge badge-warning">Sobrante</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Faltante</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($cierre['observaciones'])): ?>
                            <tr style="background-color: #252525;">
                                <td colspan="16" style="padding: 10px 20px; font-style: italic; color: #888;">
                                    <i class="fas fa-comment"></i> <strong>Obs:</strong> <?php echo htmlspecialchars($cierre['observaciones']); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>