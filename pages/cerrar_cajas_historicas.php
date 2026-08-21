<?php
// pages/cerrar_cajas_historicas.php - Vista con estilo de la app
include 'infosesion.php';
require '../config/db_config.php';

// Verificar que el usuario sea developer
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'developer') {
    header('Location: ' . route('caja.dashboard'));
    exit();
}

// Variables de control
$inicio_ejecucion = microtime(true);
$log = [];
$errores = [];
$cierres_generados = 0;
$movimientos_cerrados = 0;
$proceso_completado = false;

// Procesar fecha límite personalizada
$fecha_limite_default = date('Y-m-d');
$fecha_limite = isset($_POST['fecha_limite']) ? $_POST['fecha_limite'] : $fecha_limite_default;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_cierre'])) {
    try {
        // Usar la conexión PDO existente
        $log[] = "==========================================";
        $log[] = "SCRIPT DE CIERRE MASIVO DE CAJAS";
        $log[] = "==========================================";
        $log[] = "Fecha/Hora de ejecución: " . date('Y-m-d H:i:s');
        $log[] = "Usuario: " . $_SESSION['usuario_nombre'];
        $log[] = "Fecha límite: $fecha_limite";
        $log[] = "==========================================";
        $log[] = "";
        
        // Verificar que existe la tabla estado_caja
        $sql_check_tabla = "SHOW TABLES LIKE 'estado_caja'";
        $stmt_check = $pdo->query($sql_check_tabla);
        
        if (!$stmt_check->fetch()) {
            throw new Exception("ERROR: La tabla 'estado_caja' no existe. Debe ejecutar la migración primero.");
        }
        
        $log[] = "✓ Tabla estado_caja encontrada";
        
        // Verificar que existe la tabla cierres_caja
        $sql_check_cierres = "SHOW TABLES LIKE 'cierres_caja'";
        $stmt_check_cierres = $pdo->query($sql_check_cierres);
        
        if (!$stmt_check_cierres->fetch()) {
            throw new Exception("ERROR: La tabla 'cierres_caja' no existe.");
        }
        
        $log[] = "✓ Tabla cierres_caja encontrada";
        
        // PASO 1: Obtener todas las fechas con movimientos sin cerrar anteriores a la fecha límite
        $log[] = "";
        $log[] = "PASO 1: Buscando movimientos sin cerrar anteriores a $fecha_limite...";
        
        $sql_cajas_abiertas = "SELECT 
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
        
        $log[] = "SQL: " . str_replace("\n", " ", $sql_cajas_abiertas);
        
        $stmt_cajas = $pdo->prepare($sql_cajas_abiertas);
        $stmt_cajas->execute([':fecha_limite' => $fecha_limite]);
        $cajas_abiertas = $stmt_cajas->fetchAll();
        
        $total_cajas = count($cajas_abiertas);
        $log[] = "✓ Cajas abiertas encontradas: $total_cajas";
        
        if ($total_cajas === 0) {
            $log[] = "";
            $log[] = "✓ No hay cajas abiertas para cerrar. Proceso completado.";
            $proceso_completado = true;
        } else {
            // Mostrar detalle de cajas encontradas
            $log[] = "";
            $log[] = "Detalle de cajas a cerrar:";
            foreach ($cajas_abiertas as $caja) {
                $log[] = sprintf(
                    "  - Empresa: %s | Sucursal: %s | Fecha: %s | Saldo Inicial: $%s",
                    $caja['empresa_nombre'],
                    $caja['sucursal_nombre'],
                    $caja['fecha'],
                    number_format($caja['saldo_inicial'], 2)
                );
            }
            
            // PASO 2: Procesar cada caja
            $log[] = "";
            $log[] = "PASO 2: Procesando cierres...";
            $log[] = str_repeat("-", 60);
            
            $pdo->beginTransaction();
            
            foreach ($cajas_abiertas as $caja) {
                try {
                    $empresa_id = $caja['empresa_id'];
                    $sucursal_id = $caja['sucursal_id'];
                    $fecha_caja = $caja['fecha'];
                    $saldo_inicial = (float)$caja['saldo_inicial'];
                    
                    $log[] = "";
                    $log[] = "Procesando: {$caja['empresa_nombre']} | {$caja['sucursal_nombre']} | $fecha_caja";
                    
                    // Calcular totales de movimientos para esa fecha
                    $sql_totales = "SELECT 
                        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                                 THEN monto ELSE 0 END) as ingresos_efectivo,
                        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                                 THEN monto ELSE 0 END) as ingresos_transf,
                        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
                    FROM movimientos 
                    WHERE empresa_id = :empresa_id 
                      AND sucursal_id = :sucursal_id
                      AND DATE(fecha) = :fecha
                      AND cerrado = 0";
                    
                    $stmt_totales = $pdo->prepare($sql_totales);
                    $stmt_totales->execute([
                        ':empresa_id' => $empresa_id,
                        ':sucursal_id' => $sucursal_id,
                        ':fecha' => $fecha_caja
                    ]);
                    
                    $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
                    $ing_efectivo = (float)($totales['ingresos_efectivo'] ?? 0);
                    $ing_transf = (float)($totales['ingresos_transf'] ?? 0);
                    $egresos = (float)($totales['egresos'] ?? 0);
                    $saldo_esperado = $ing_efectivo - $egresos;
                    
                    $log[] = sprintf(
                        "  Totales: Ing.Efectivo=$%s | Ing.Transf=$%s | Egresos=$%s | Saldo Esperado=$%s",
                        number_format($ing_efectivo, 2),
                        number_format($ing_transf, 2),
                        number_format($egresos, 2),
                        number_format($saldo_esperado, 2)
                    );
                    
                    // Obtener número de cierre
                    $sql_num_cierre = "SELECT COALESCE(MAX(numero_cierre), 0) + 1 as numero 
                                      FROM cierres_caja 
                                      WHERE empresa_id = :empresa_id 
                                        AND sucursal_id = :sucursal_id";
                    
                    $stmt_num = $pdo->prepare($sql_num_cierre);
                    $stmt_num->execute([
                        ':empresa_id' => $empresa_id,
                        ':sucursal_id' => $sucursal_id
                    ]);
                    $numero_cierre = (int)$stmt_num->fetchColumn();
                    
                    // Fecha de cierre: usar el final del día de la caja
                    $fecha_cierre = $fecha_caja . ' 23:59:59';
                    $usuario_sistema = 'Sistema (Cierre Histórico)';
                    
                    // Insertar en cierres_caja
                    $sql_cierre = "INSERT INTO cierres_caja 
                                  (empresa_id, sucursal_id, fecha_cierre, saldo_inicial, 
                                   ingresos_efectivo, ingresos_transf, egresos, 
                                   saldo_esperado_efectivo, saldo_real_efectivo, diferencia,
                                   fondo_reservado_vuelto, tipo_cierre, numero_cierre, usuario)
                                  VALUES (:empresa_id, :sucursal_id, :fecha_cierre, :saldo_inicial,
                                          :ingresos_efectivo, :ingresos_transf, :egresos,
                                          :saldo_esperado, :saldo_real, :diferencia,
                                          :fondo_vuelto, 'DIARIO', :numero_cierre, :usuario)";
                    
                    $stmt_cierre = $pdo->prepare($sql_cierre);
                    $stmt_cierre->execute([
                        ':empresa_id' => $empresa_id,
                        ':sucursal_id' => $sucursal_id,
                        ':fecha_cierre' => $fecha_cierre,
                        ':saldo_inicial' => $saldo_inicial,
                        ':ingresos_efectivo' => $ing_efectivo,
                        ':ingresos_transf' => $ing_transf,
                        ':egresos' => $egresos,
                        ':saldo_esperado' => $saldo_esperado,
                        ':saldo_real' => $saldo_esperado,
                        ':diferencia' => 0,
                        ':fondo_vuelto' => 0,
                        ':numero_cierre' => $numero_cierre,
                        ':usuario' => $usuario_sistema
                    ]);
                    
                    $cierre_id = $pdo->lastInsertId();
                    $cierres_generados++;
                    
                    $log[] = sprintf("  ✓ Cierre #%s generado (ID: %s)", $numero_cierre, $cierre_id);
                    
                    // Marcar movimientos como cerrados
                    $sql_update_mov = "UPDATE movimientos 
                                      SET cerrado = 1 
                                      WHERE empresa_id = :empresa_id 
                                        AND sucursal_id = :sucursal_id
                                        AND DATE(fecha) = :fecha
                                        AND cerrado = 0";
                    
                    $stmt_update = $pdo->prepare($sql_update_mov);
                    $stmt_update->execute([
                        ':empresa_id' => $empresa_id,
                        ':sucursal_id' => $sucursal_id,
                        ':fecha' => $fecha_caja
                    ]);
                    
                    $movimientos_afectados = $stmt_update->rowCount();
                    $movimientos_cerrados += $movimientos_afectados;
                    
                    $log[] = sprintf("  ✓ %s movimientos marcados como cerrados", $movimientos_afectados);
                    
                    // Nota: No actualizamos estado_caja porque:
                    // 1. Los movimientos ya están marcados como cerrados
                    // 2. El dashboard filtra por fecha actual y cerrado=0
                    // 3. La tabla estado_caja puede no tener registros históricos
                    
                    // Registrar en log de auditoría (si existe la tabla)
                    try {
                        $sql_audit = "INSERT INTO cierres_caja_audit 
                                     (cierre_id, accion, usuario, datos_nuevos)
                                     VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
                        
                        $datos_audit = json_encode([
                            'empresa_id' => $empresa_id,
                            'sucursal_id' => $sucursal_id,
                            'fecha_cierre' => $fecha_cierre,
                            'ingresos_efectivo' => $ing_efectivo,
                            'ingresos_transf' => $ing_transf,
                            'egresos' => $egresos,
                            'saldo_esperado' => $saldo_esperado,
                            'fondo_vuelto' => 0,
                            'origen' => 'CIERRE_HISTORICO'
                        ]);
                        
                        $stmt_audit = $pdo->prepare($sql_audit);
                        $stmt_audit->execute([
                            ':cierre_id' => $cierre_id,
                            ':usuario' => $usuario_sistema,
                            ':datos' => $datos_audit
                        ]);
                        
                        $log[] = "  ✓ Auditoría registrada";
                    } catch (Exception $e) {
                        $log[] = "  - Tabla de auditoría no disponible (opcional)";
                    }
                    
                } catch (Exception $e) {
                    $errores[] = "Error en caja {$caja['fecha']} - {$caja['empresa_nombre']}: " . $e->getMessage();
                    $log[] = "  ✗ ERROR: " . $e->getMessage();
                }
            }
            
            // Confirmar transacción
            $pdo->commit();
            
            $log[] = "";
            $log[] = str_repeat("-", 60);
            $log[] = "✓ Transacción confirmada";
            $proceso_completado = true;
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = "Error general: " . $e->getMessage();
        $log[] = "";
        $log[] = "✗ ERROR CRÍTICO: " . $e->getMessage();
        $log[] = "✗ Transacción revertida (rollback)";
    }
}

$fin_ejecucion = microtime(true);
$tiempo_total = round($fin_ejecucion - $inicio_ejecucion, 2);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cerrar Cajas Históricas | <?php echo $nombre_empresa_sistema; ?></title>
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
        .log-container {
            background: #1a1a1a;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            line-height: 1.6;
            max-height: 500px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-container .success { color: #28a745; }
        .log-container .error { color: #dc3545; }
        .log-container .info { color: #17a2b8; }
        .log-container .warning { color: #ffc107; }
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
        .confirm-box {
            background: #3d2e0f;
            border: 2px solid #ffc107;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1><i class="fas fa-exclamation-triangle"></i> Cerrar Cajas Históricas</h1>
            <a href="<?php echo route_file('pages/caja_dashboard.php'); ?>" class="btn btn-secondary">
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
                    <i class="fas fa-filter"></i> Actualizar
                </button>
                <span style="color: #aaa; font-size: 0.9rem;">
                    Cerrará cajas abiertas anteriores a esta fecha
                </span>
            </form>
        </div>

        <div class="info-box">
            <strong><i class="fas fa-info-circle"></i> Modo Ejecución</strong><br>
            Este script MODIFICA la base de datos. Cierra todas las cajas abiertas anteriores al <?php echo date('d/m/Y', strtotime($fecha_limite)); ?>.
        </div>

        <?php if (!$proceso_completado && empty($errores)): ?>
        <div class="confirm-box">
            <h3 style="color: #ffc107; margin-top: 0;">
                <i class="fas fa-exclamation-triangle"></i> ¿Está seguro de continuar?
            </h3>
            <p style="color: #fff; margin-bottom: 20px;">
                Esta acción cerrará <strong>TODAS</strong> las cajas abiertas anteriores al <?php echo date('d/m/Y', strtotime($fecha_limite)); ?>.
                Se marcarán todos los movimientos como cerrados y se generarán registros en la tabla <code>cierres_caja</code>.
            </p>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="confirmar_cierre" value="1">
                <button type="submit" class="btn btn-danger" style="padding: 12px 25px; font-size: 1rem;">
                    <i class="fas fa-check"></i> Sí, Ejecutar Cierre Masivo
                </button>
            </form>
            <a href="<?php echo route_file('pages/caja_dashboard.php'); ?>" class="btn btn-secondary" style="padding: 12px 25px; font-size: 1rem; margin-left: 10px;">
                <i class="fas fa-times"></i> Cancelar
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
        <div class="error-box">
            <strong><i class="fas fa-exclamation-circle"></i> ERRORES ENCONTRADOS:</strong><br>
            <?php foreach ($errores as $error): ?>
                - <?php echo htmlspecialchars($error); ?><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($proceso_completado || !empty($log)): ?>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Cierres Generados</div>
                <div class="value"><?php echo $cierres_generados; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Movimientos Cerrados</div>
                <div class="value"><?php echo $movimientos_cerrados; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Errores</div>
                <div class="value" style="color: <?php echo count($errores) > 0 ? '#dc3545' : '#28a745'; ?>">
                    <?php echo count($errores); ?>
                </div>
            </div>
            <div class="stat-box">
                <div class="label">Tiempo de Ejecución</div>
                <div class="value"><?php echo $tiempo_total; ?>s</div>
            </div>
        </div>

        <div class="table-container">
            <h3 style="margin-top: 0; color: #00bcd4;">
                <i class="fas fa-terminal"></i> Log de Ejecución
            </h3>
            <div class="log-container">
                <?php foreach ($log as $linea): ?>
                    <?php
                    $clase = '';
                    if (strpos($linea, '✓') !== false) $clase = 'success';
                    elseif (strpos($linea, '✗') !== false) $clase = 'error';
                    elseif (strpos($linea, 'PASO') !== false) $clase = 'info';
                    elseif (strpos($linea, 'ADVERTENCIA') !== false) $clase = 'warning';
                    ?>
                    <span class="<?php echo $clase; ?>"><?php echo htmlspecialchars($linea); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($cierres_generados > 0): ?>
        <div class="warning-box" style="margin-top: 20px;">
            <strong><i class="fas fa-exclamation-triangle"></i> IMPORTANTE:</strong><br>
            Se cerraron <strong><?php echo $cierres_generados; ?></strong> cajas históricas.<br>
            Verifique los resultados en el sistema antes de continuar.<br>
            <strong>Recuerde ELIMINAR estos scripts después de verificar.</strong>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>