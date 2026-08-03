<?php
/**
 * Sistema de Intereses por Mora en Cuentas Corrientes
 * Versión: 1.0
 * Fecha: 08/03/2026
 */

/**
 * Calcula interés por mora sobre un saldo
 * 
 * @param float $saldo - Saldo deudor actual
 * @param int $dias_mora - Días transcurridos desde el vencimiento
 * @param float $tasa_mensual - Tasa de interés mensual (ej: 3.00 para 3%)
 * @return float - Monto de interés calculado
 */
function calcularInteresMora($saldo, $dias_mora, $tasa_mensual = 3.00) {
    if ($saldo <= 0 || $dias_mora <= 0) {
        return 0;
    }
    
    // Fórmula: Saldo × (Tasa / 30) × Días
    $tasa_diaria = $tasa_mensual / 30 / 100;
    $interes = $saldo * $tasa_diaria * $dias_mora;
    
    return round($interes, 2);
}

/**
 * Obtiene la configuración de intereses de la empresa
 * 
 * @param PDO $pdo
 * @param int $empresa_id
 * @return array|false
 */
function obtenerConfiguracionIntereses($pdo, $empresa_id) {
    $sql = "SELECT * FROM configuracion_intereses 
            WHERE empresa_id = :empresa_id AND activo = 1 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe configuración, retornar valores por defecto
    if (!$config) {
        return [
            'tasa_mensual' => 3.00,
            'dias_gracia' => 0,
            'aplicar_automatico' => 0,
            'frecuencia' => 'DIARIA',
            'activo' => 1
        ];
    }
    
    return $config;
}

/**
 * Calcula intereses de todos los movimientos vencidos de un cliente
 * 
 * @param int $id_cliente
 * @param PDO $pdo
 * @param int $empresa_id
 * @param string|null $fecha_calculo - Fecha desde la cual calcular (por defecto: hoy)
 * @return array
 */
function calcularInteresesCliente($id_cliente, $pdo, $empresa_id, $fecha_calculo = null) {
    $fecha_calculo = $fecha_calculo ?? date('Y-m-d');
    
    // 1. Obtener configuración de intereses
    $config = obtenerConfiguracionIntereses($pdo, $empresa_id);
    
    if (!$config || !$config['activo']) {
        return [
            'interes_total' => 0, 
            'detalle' => [],
            'config' => $config
        ];
    }
    
    // 2. Obtener movimientos deudores vencidos
    $sql = "
        SELECT 
            c.id,
            c.fecha,
            c.fecha_vencimiento,
            c.debe,
            c.haber,
            c.movimiento,
            c.n_documento,
            DATEDIFF(:fecha_calc1, c.fecha_vencimiento) as dias_mora
        FROM ctacte c
        WHERE c.id_cliente = :id_cliente
        AND c.empresa_id = :empresa_id
        AND c.debe > 0
        AND c.fecha_vencimiento IS NOT NULL
        AND c.fecha_vencimiento < :fecha_calc2
        ORDER BY c.fecha_vencimiento ASC
    ";
    
    error_log("SQL Consulta movimientos: $sql");
    error_log("Params: id_cliente=$id_cliente, empresa_id=$empresa_id, fecha_calc=$fecha_calculo");
    
    $stmt = $pdo->prepare($sql);
    $params = [
        ':id_cliente' => $id_cliente,
        ':empresa_id' => $empresa_id,
        ':fecha_calc1' => $fecha_calculo,
        ':fecha_calc2' => $fecha_calculo
    ];
    error_log("Array params: " . print_r($params, true));
    
    $stmt->execute($params);
    
    error_log("Movimientos encontrados: " . count($stmt->fetchAll(PDO::FETCH_ASSOC)));
    
    // Re-ejecutar para obtener los datos
    $stmt->execute($params);
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Calcular interés por cada movimiento
    $interes_total = 0;
    $detalle = [];
    
    foreach ($movimientos as $mov) {
        // Aplicar días de gracia
        $dias_mora = max(0, $mov['dias_mora'] - $config['dias_gracia']);
        
        if ($dias_mora > 0) {
            $saldo_pendiente = $mov['debe'] - $mov['haber'];
            
            // Solo calcular si hay saldo pendiente
            if ($saldo_pendiente > 0) {
                $interes = calcularInteresMora($saldo_pendiente, $dias_mora, $config['tasa_mensual']);
                
                if ($interes > 0) {
                    $interes_total += $interes;
                    $detalle[] = [
                        'id_movimiento' => $mov['id'],
                        'n_documento' => $mov['n_documento'],
                        'movimiento' => $mov['movimiento'],
                        'fecha' => $mov['fecha'],
                        'fecha_vencimiento' => $mov['fecha_vencimiento'],
                        'saldo_pendiente' => $saldo_pendiente,
                        'dias_mora' => $dias_mora,
                        'tasa_aplicada' => $config['tasa_mensual'],
                        'interes_calculado' => $interes
                    ];
                }
            }
        }
    }
    
    return [
        'interes_total' => round($interes_total, 2),
        'detalle' => $detalle,
        'config' => $config
    ];
}

/**
 * Genera número único para interés
 * Formato: INT-YYYY-NNNNNN
 * 
 * @param PDO $pdo
 * @param int $empresa_id
 * @return string
 */
function generarNumeroInteres($pdo, $empresa_id) {
    $prefijo = 'INT';
    $anio = date('Y');
    
    $sql = "SELECT COUNT(*) as total FROM ctacte 
            WHERE empresa_id = :empresa_id 
            AND movimiento LIKE :prefijo 
            AND YEAR(fecha) = :anio";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':prefijo' => "INTERÉS POR MORA%",
        ':anio' => $anio
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $numero = str_pad($result['total'] + 1, 6, '0', STR_PAD_LEFT);
    
    return $prefijo . '-' . $anio . '-' . $numero;
}

/**
 * Aplica intereses de mora a la cuenta corriente
 * Genera un movimiento en ctacte por el total de intereses
 * 
 * @param int $id_cliente
 * @param PDO $pdo
 * @param int|null $usuario_id
 * @return array
 */
function aplicarInteresesMora($id_cliente, $pdo, $usuario_id = null) {
    try {
        error_log("=== INICIO aplicarInteresesMora ===");
        error_log("Cliente: $id_cliente");
        
        $pdo->beginTransaction();
        
        // 1. Calcular intereses
        $empresa_id = $_SESSION['empresa_id'] ?? null;
        if (!$empresa_id) {
            throw new Exception('Empresa no definida en sesión');
        }
        
        error_log("Calculando intereses para cliente $id_cliente, empresa $empresa_id");
        $resultado = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);
        error_log("Resultado cálculo: " . print_r($resultado, true));
        
        if ($resultado['interes_total'] <= 0) {
            $pdo->rollBack();
            return [
                'success' => false, 
                'error' => 'No hay intereses pendientes para aplicar'
            ];
        }
        
        // 2. Obtener datos del cliente
        $sql_cliente = "SELECT CONCAT(apellido, ', ', nombre) as nombre 
                        FROM clientes 
                        WHERE id = :id";
        $stmt_cliente = $pdo->prepare($sql_cliente);
        $stmt_cliente->execute([':id' => $id_cliente]);
        $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        
        if (!$cliente) {
            throw new Exception('Cliente no encontrado');
        }
        
        // 3. Insertar movimiento de interés en ctacte
        // Nota: n_documento debe ser INT, usaremos el ID del movimiento como referencia
        $sql_insert = "
            INSERT INTO ctacte 
            (id_cliente, movimiento, n_documento, debe, haber, fecha, fecha_vencimiento, usuario, empresa_id)
            VALUES
            (:id_cliente, :movimiento, 0, :debe, 0, :fecha, :fecha_vencimiento, :usuario, :empresa_id)
        ";
        
        $movimiento_texto = "INTERÉS POR MORA - " . date('Y-m-d');
        $fecha_hoy = date('Y-m-d');
        $fecha_vencimiento = date('Y-m-d', strtotime('+30 days'));
        
        error_log("Insertando en ctacte: $sql_insert");
        
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([
            ':id_cliente' => $id_cliente,
            ':movimiento' => $movimiento_texto,
            ':debe' => $resultado['interes_total'],
            ':fecha' => $fecha_hoy,
            ':fecha_vencimiento' => $fecha_vencimiento,
            ':usuario' => $usuario_id ?: 'Sistema',
            ':empresa_id' => $empresa_id
        ]);
        
        error_log("Insert en ctacte exitoso, ID: " . $pdo->lastInsertId());
        
        $id_interes_generado = $pdo->lastInsertId();
        
        // 5. Registrar en tabla de intereses generados (auditoría)
        $sql_registro = "
            INSERT INTO intereses_generados
            (empresa_id, id_cliente, monto_interes, saldo_utilizado, dias_mora, tasa_aplicada, 
             fecha_calculo, fecha_aplicacion, usuario_id, observaciones)
            VALUES
            (:empresa_id, :id_cliente, :monto_interes, :saldo_utilizado, :dias_mora, :tasa_aplicada, 
             :fecha_calculo, :fecha_aplicacion, :usuario_id, :observaciones)
        ";
        
        $saldo_total = array_sum(array_column($resultado['detalle'], 'saldo_pendiente'));
        $dias_promedio = count($resultado['detalle']) > 0 
            ? round(array_sum(array_column($resultado['detalle'], 'dias_mora')) / count($resultado['detalle']))
            : 0;
        
        error_log("Insertando en intereses_generados: $sql_registro");
        
        $stmt_registro = $pdo->prepare($sql_registro);
        $stmt_registro->execute([
            ':empresa_id' => $empresa_id,
            ':id_cliente' => $id_cliente,
            ':monto_interes' => $resultado['interes_total'],
            ':saldo_utilizado' => $saldo_total,
            ':dias_mora' => $dias_promedio,
            ':tasa_aplicada' => $resultado['config']['tasa_mensual'],
            ':fecha_calculo' => $fecha_hoy,
            ':fecha_aplicacion' => $fecha_hoy,
            ':usuario_id' => $usuario_id,
            ':observaciones' => 'Interés aplicado sobre ' . count($resultado['detalle']) . ' movimiento(s)'
        ]);
        
        error_log("Insert en intereses_generados exitoso");
        
        $pdo->commit();
        
        error_log("=== FIN aplicarInteresesMora - ÉXITO ===");
        
        return [
            'success' => true,
            'monto_aplicado' => $resultado['interes_total'],
            'id_movimiento' => $id_interes_generado,
            'detalle' => $resultado['detalle']
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("ERROR en aplicarInteresesMora: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        return [
            'success' => false, 
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Verifica si un cliente tiene intereses pendientes
 * 
 * @param int $id_cliente
 * @param PDO $pdo
 * @param int $empresa_id
 * @return bool
 */
function tieneInteresesPendientes($id_cliente, $pdo, $empresa_id) {
    $resultado = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);
    return $resultado['interes_total'] > 0;
}

/**
 * Obtiene el resumen de intereses para mostrar en listados
 * 
 * @param PDO $pdo
 * @param int $empresa_id
 * @return array Array con id_cliente => monto_interes
 */
function obtenerResumenInteresesPendientes($pdo, $empresa_id) {
    $sql = "
        SELECT DISTINCT
            c.id_cliente,
            CONCAT(cl.apellido, ', ', cl.nombre) as nombre_cliente,
            SUM(c.debe - c.haber) as saldo_total
        FROM ctacte c
        INNER JOIN clientes cl ON cl.id = c.id_cliente
        WHERE c.empresa_id = :emp_id
        AND c.debe > 0
        AND c.fecha_vencimiento IS NOT NULL
        AND c.fecha_vencimiento < CURDATE()
        GROUP BY c.id_cliente, cl.apellido, cl.nombre
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':emp_id' => $empresa_id]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resumen = [];
    foreach ($clientes as $cliente) {
        $intereses = calcularInteresesCliente($cliente['id_cliente'], $pdo, $empresa_id);
        if ($intereses['interes_total'] > 0) {
            $resumen[] = [
                'id_cliente' => $cliente['id_cliente'],
                'nombre_cliente' => $cliente['nombre_cliente'],
                'saldo_total' => $cliente['saldo_total'],
                'intereses_pendientes' => $intereses['interes_total'],
                'saldo_con_intereses' => $cliente['saldo_total'] + $intereses['interes_total']
            ];
        }
    }
    
    return $resumen;
}

/**
 * Anula intereses generados para una factura específica
 * (Para usar cuando se anula una factura)
 * 
 * @param int $id_factura - ID del movimiento en ctacte
 * @param PDO $pdo
 * @param int $usuario_id
 * @return bool
 */
function anularInteresesFactura($id_factura, $pdo, $usuario_id = null) {
    try {
        $pdo->beginTransaction();
        
        // Obtener información del movimiento
        $sql_mov = "SELECT id_cliente, empresa_id, n_documento FROM ctacte WHERE id = :id";
        $stmt_mov = $pdo->prepare($sql_mov);
        $stmt_mov->execute([':id' => $id_factura]);
        $movimiento = $stmt_mov->fetch(PDO::FETCH_ASSOC);
        
        if (!$movimiento) {
            throw new Exception('Movimiento no encontrado');
        }
        
        // Buscar intereses generados relacionados con este documento
        $sql_intereses = "
            SELECT * FROM intereses_generados 
            WHERE id_cliente = :id_cliente 
            AND empresa_id = :empresa_id
            AND observaciones LIKE CONCAT('%', :n_documento, '%')
        ";
        
        $stmt_intereses = $pdo->prepare($sql_intereses);
        $stmt_intereses->execute([
            ':id_cliente' => $movimiento['id_cliente'],
            ':empresa_id' => $movimiento['empresa_id'],
            ':n_documento' => $movimiento['n_documento']
        ]);
        
        $intereses = $stmt_intereses->fetchAll(PDO::FETCH_ASSOC);
        
        // Marcar intereses como anulados (no eliminarlos por auditoría)
        foreach ($intereses as $interes) {
            $sql_update = "
                UPDATE intereses_generados 
                SET observaciones = CONCAT(observaciones, ' [ANULADO - Factura anulada]')
                WHERE id = :id
            ";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([':id' => $interes['id']]);
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error al anular intereses: " . $e->getMessage());
        return false;
    }
}

/**
 * Formatea el monto para mostrar
 * 
 * @param float $monto
 * @return string
 */
function formatearMontoInteres($monto) {
    return '$ ' . number_format($monto, 2, ',', '.');
}

/**
 * Obtiene estadísticas de intereses generados
 * 
 * @param PDO $pdo
 * @param int $empresa_id
 * @param string|null $fecha_desde
 * @param string|null $fecha_hasta
 * @return array
 */
function obtenerEstadisticasIntereses($pdo, $empresa_id, $fecha_desde = null, $fecha_hasta = null) {
    $fecha_desde = $fecha_desde ?? date('Y-m-01'); // Primer día del mes
    $fecha_hasta = $fecha_hasta ?? date('Y-m-t'); // Último día del mes
    
    $sql = "
        SELECT 
            COUNT(*) as total_intereses_generados,
            SUM(monto_interes) as monto_total_intereses,
            AVG(monto_interes) as promedio_interes,
            COUNT(DISTINCT id_cliente) as clientes_afectados
        FROM intereses_generados
        WHERE empresa_id = :emp_id
        AND fecha_calculo BETWEEN :fecha_desde AND :fecha_hasta
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':emp_id' => $empresa_id,
        ':fecha_desde' => $fecha_desde,
        ':fecha_hasta' => $fecha_hasta
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>