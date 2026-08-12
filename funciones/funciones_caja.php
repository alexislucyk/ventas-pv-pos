<?php
/**
 * Funciones auxiliares para el sistema de caja
 * Versión: 2.1.0
 * Fecha: 08/03/2026
 */

/**
 * Obtener el estado de caja para una empresa/sucursal.
 * 
 * MODELO POR SESIÓN: una caja permanece ABIERTA hasta que el usuario la cierra
 * (puede abarcar varios días) y puede haber varias aperturas/cierres en un mismo día.
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional). Si no se indica,
 *                      devuelve la última sesión registrada (abierta o cerrada).
 * @return array|false Estado de caja o false si no existe
 */
function obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    if ($fecha) {
        // Compatibilidad: buscar por día específico
        $sql = "SELECT * FROM estado_caja 
                WHERE empresa_id = :empresa_id 
                  AND sucursal_id = :sucursal_id 
                  AND fecha = :fecha
                ORDER BY fecha_apertura DESC, id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha' => $fecha
        ]);
    } else {
        // Sesión actual: la más reciente registrada
        $sql = "SELECT * FROM estado_caja 
                WHERE empresa_id = :empresa_id 
                  AND sucursal_id = :sucursal_id 
                ORDER BY fecha_apertura DESC, id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id
        ]);
    }
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Obtener la sesión de caja actualmente abierta (la única permitida por empresa/sucursal).
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @return array|false Sesión ABIERTA o false si no hay caja abierta
 */
function obtener_caja_abierta($pdo, $empresa_id, $sucursal_id) {
    $sql = "SELECT * FROM estado_caja 
            WHERE empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id 
              AND estado = 'ABIERTA'
            ORDER BY fecha_apertura DESC, id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':sucursal_id' => $sucursal_id
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verificar si la caja está abierta
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional)
 * @return bool True si está abierta, False si está cerrada o no existe
 */
function caja_esta_abierta($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    $estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha);
    return $estado && $estado['estado'] === 'ABIERTA';
}

/**
 * Abrir caja para el día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param float $saldo_inicial Saldo inicial de caja
 * @param string $usuario Usuario que abre la caja
 * @return array Resultado de la operación
 */
function abrir_caja($pdo, $empresa_id, $sucursal_id, $saldo_inicial, $usuario) {
    try {
        $fecha = date('Y-m-d');
        $fecha_apertura = date('Y-m-d H:i:s');
        
        // Modelo por sesión: no se puede abrir si ya existe UNA caja abierta
        // (sin importar el día en que se abrió; la caja queda abierta hasta que
        // el usuario la cierre, pudiendo abarcar varios días).
        $caja_abierta = obtener_caja_abierta($pdo, $empresa_id, $sucursal_id);
        
        if ($caja_abierta) {
            return [
                'success' => false,
                'mensaje' => 'La caja ya está abierta.'
            ];
        }
        
        // Crear nuevo registro de caja abierta
        $sql = "INSERT INTO estado_caja 
                (empresa_id, sucursal_id, fecha, estado, saldo_inicial, usuario_apertura, fecha_apertura)
                VALUES (:empresa_id, :sucursal_id, :fecha, 'ABIERTA', :saldo_inicial, :usuario, :fecha_apertura)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha' => $fecha,
            ':saldo_inicial' => $saldo_inicial,
            ':usuario' => $usuario,
            ':fecha_apertura' => $fecha_apertura
        ]);
        
        // Si hay saldo inicial, crear movimiento de fondo inicial
        if ($saldo_inicial > 0) {
            $sql_mov = "INSERT INTO movimientos 
                        (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, es_fondo_inicial)
                        VALUES (:empresa_id, :sucursal_id, 'INGRESO', :monto, 'EFECTIVO', 'FONDO INICIAL (APERTURA)', :fecha, :usuario, 0, 1)";
            
            $stmt_mov = $pdo->prepare($sql_mov);
            $stmt_mov->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':monto' => $saldo_inicial,
                ':fecha' => $fecha_apertura,
                ':usuario' => $usuario
            ]);
        }
        
        return [
            'success' => true,
            'mensaje' => 'Caja abierta correctamente.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'mensaje' => 'Error al abrir caja: ' . $e->getMessage()
        ];
    }
}

/**
 * Cerrar caja
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $usuario Usuario que cierra la caja
 * @param float $fondo_vuelto Fondo para el día siguiente (opcional)
 * @param string $fecha_desde Fecha/hora de inicio del cierre (formato Y-m-d H:i:s, opcional)
 * @param string $fecha_hasta Fecha/hora de fin del cierre (formato Y-m-d H:i:s, opcional)
 * @return array Resultado de la operación
 */
function cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo_vuelto = 0, $fecha_desde = null, $fecha_hasta = null) {
    try {
        $fecha_cierre = date('Y-m-d H:i:s');
        
        // Modelo por sesión: se cierra la caja actualmente abierta (la caja
        // queda abierta hasta que el usuario la cierra, puede abarcar varios días).
        $estado = obtener_caja_abierta($pdo, $empresa_id, $sucursal_id);
        
        if (!$estado) {
            return [
                'success' => false,
                'mensaje' => 'La caja no está abierta. No se puede cerrar.'
            ];
        }
        
        // Período del cierre: si no se indica, desde el INICIO del día en que se
        // abrió la sesión hasta el momento actual. movimientos.fecha es DATE
        // (sin hora), por lo que se parte de las 00:00 del día de apertura para
        // no dejar afuera movimientos del mismo día de apertura.
        if (!$fecha_desde || !$fecha_hasta) {
            $fecha_apertura = $estado['fecha_apertura'] ?? date('Y-m-d H:i:s');
            $fecha_desde = date('Y-m-d', strtotime($fecha_apertura)) . ' 00:00:00';
            $fecha_hasta = $fecha_cierre;
        } else {
            // Convertir fechas a datetime si vienen en formato date
            if (strlen($fecha_desde) == 10) {
                $fecha_desde = $fecha_desde . ' 00:00:00';
            }
            if (strlen($fecha_hasta) == 10) {
                $fecha_hasta = $fecha_hasta . ' 23:59:59';
            }
        }
        
        // NOTA: Se permite cerrar la caja múltiples veces por día por el mismo usuario
        // No hay validación de cierre único
        
        // Iniciar transacción
        $pdo->beginTransaction();
        
        // Calcular totales del período especificado por método de pago
        $sql_totales = "SELECT 
            SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                     THEN monto ELSE 0 END) as ingresos_efectivo,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                     THEN monto ELSE 0 END) as ingresos_transf,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'CHEQUE' 
                     THEN monto ELSE 0 END) as ingresos_cheques,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TARJETA' 
                     THEN monto ELSE 0 END) as ingresos_tarjetas,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'TARJETA', 'MIXTO') 
                     THEN monto ELSE 0 END) as ingresos_otros,
            SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
        FROM movimientos 
        WHERE cerrado = 0 
          AND empresa_id = :empresa_id 
          AND sucursal_id = :sucursal_id
          AND fecha BETWEEN :fecha_desde AND :fecha_hasta";
        
        $stmt_totales = $pdo->prepare($sql_totales);
        $stmt_totales->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha_desde' => $fecha_desde,
            ':fecha_hasta' => $fecha_hasta
        ]);
        
        $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
        
        $ing_efectivo = (float)($totales['ingresos_efectivo'] ?? 0);
        $ing_transf = (float)($totales['ingresos_transf'] ?? 0);
        $ing_cheques = (float)($totales['ingresos_cheques'] ?? 0);
        $ing_tarjetas = (float)($totales['ingresos_tarjetas'] ?? 0);
        $ing_otros = (float)($totales['ingresos_otros'] ?? 0);
        $egresos = (float)($totales['egresos'] ?? 0);
        
        // Incluir saldo inicial en el cálculo del saldo esperado
        $saldo_inicial = (float)($estado['saldo_inicial'] ?? 0);
        $saldo_esperado = $saldo_inicial + $ing_efectivo - $egresos;
        
        // Obtener número de cierre
        $numero_cierre = obtener_numero_cierre($pdo, $empresa_id, $sucursal_id);
        
        // Insertar en cierres_caja con todos los métodos de pago
        $sql_cierre = "INSERT INTO cierres_caja 
                       (empresa_id, sucursal_id, fecha_cierre, fecha_desde, fecha_hasta, 
                        saldo_inicial, ingresos_efectivo, ingresos_transf, ingresos_cheques,
                        ingresos_tarjetas, ingresos_otros, egresos, 
                        saldo_esperado_efectivo, saldo_real_efectivo, diferencia,
                        fondo_reservado_vuelto, numero_cierre, usuario)
                       VALUES (:empresa_id, :sucursal_id, :fecha_cierre, :fecha_desde, :fecha_hasta,
                               :saldo_inicial, :ingresos_efectivo, :ingresos_transf, :ingresos_cheques,
                               :ingresos_tarjetas, :ingresos_otros, :egresos,
                               :saldo_esperado, :saldo_real, :diferencia,
                               :fondo_vuelto, :numero_cierre, :usuario)";
        
        // Por ahora usamos saldo_esperado como saldo_real (se debe actualizar con el conteo físico)
        $stmt_cierre = $pdo->prepare($sql_cierre);
        $stmt_cierre->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha_cierre' => $fecha_cierre,
            ':fecha_desde' => $fecha_desde,
            ':fecha_hasta' => $fecha_hasta,
            ':saldo_inicial' => $estado['saldo_inicial'],
            ':ingresos_efectivo' => $ing_efectivo,
            ':ingresos_transf' => $ing_transf,
            ':ingresos_cheques' => $ing_cheques,
            ':ingresos_tarjetas' => $ing_tarjetas,
            ':ingresos_otros' => $ing_otros,
            ':egresos' => $egresos,
            ':saldo_esperado' => $saldo_esperado,
            ':saldo_real' => $saldo_esperado, // Se actualiza en el formulario de cierre
            ':diferencia' => 0, // Se calcula en el formulario
            ':fondo_vuelto' => $fondo_vuelto,
            ':numero_cierre' => $numero_cierre,
            ':usuario' => $usuario
        ]);
        
        $cierre_id = $pdo->lastInsertId();
        
        // Marcar movimientos como cerrados (solo los del período)
        $sql_update = "UPDATE movimientos SET cerrado = 1 
                       WHERE cerrado = 0 
                         AND empresa_id = :empresa_id 
                         AND sucursal_id = :sucursal_id
                         AND fecha BETWEEN :fecha_desde AND :fecha_hasta";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha_desde' => $fecha_desde,
            ':fecha_hasta' => $fecha_hasta
        ]);
        
        // Actualizar estado de caja
        $sql_estado = "UPDATE estado_caja 
                       SET estado = 'CERRADA', 
                           usuario_cierre = :usuario,
                           fecha_cierre = :fecha_cierre
                       WHERE id = :id";
        
        $stmt_estado = $pdo->prepare($sql_estado);
        $stmt_estado->execute([
            ':usuario' => $usuario,
            ':fecha_cierre' => $fecha_cierre,
            ':id' => $estado['id']
        ]);
        
        // El fondo reservado de vuelto queda registrado en cierres_caja.fondo_reservado_vuelto.
        // Modelo por sesión: NO se abre automáticamente la caja del día siguiente.
        // La próxima caja se apertura manualmente y pages/abrir_caja.php sugerirá
        // este fondo como saldo inicial.
        
        // Registrar en log de auditoría
        $sql_audit = "INSERT INTO cierres_caja_audit 
                      (cierre_id, accion, usuario, datos_nuevos)
                      VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
        
        $datos_audit = json_encode([
            'empresa_id' => $empresa_id,
            'sucursal_id' => $sucursal_id,
            'fecha_cierre' => $fecha_cierre,
            'fecha_desde' => $fecha_desde,
            'fecha_hasta' => $fecha_hasta,
            'ingresos_efectivo' => $ing_efectivo,
            'ingresos_transf' => $ing_transf,
            'ingresos_cheques' => $ing_cheques,
            'ingresos_tarjetas' => $ing_tarjetas,
            'ingresos_otros' => $ing_otros,
            'egresos' => $egresos,
            'saldo_esperado' => $saldo_esperado,
            'fondo_vuelto' => $fondo_vuelto
        ]);
        
        $stmt_audit = $pdo->prepare($sql_audit);
        $stmt_audit->execute([
            ':cierre_id' => $cierre_id,
            ':usuario' => $usuario,
            ':datos' => $datos_audit
        ]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'mensaje' => 'Caja cerrada correctamente.',
            'cierre_id' => $cierre_id
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'mensaje' => 'Error al cerrar caja: ' . $e->getMessage()
        ];
    }
}

/**
 * Validar que la caja esté abierta antes de permitir una operación
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @throws Exception Si la caja está cerrada
 */
function validar_caja_abierta($pdo, $empresa_id, $sucursal_id) {
    if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
        throw new Exception('ERROR: La caja está cerrada. Debe abrir la caja antes de realizar operaciones.');
    }
}

/**
 * Obtener resumen de caja del día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional)
 * @return array Resumen de caja
 */
function obtener_resumen_caja($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    if (!$fecha) {
        $fecha = date('Y-m-d');
    }
    
    // Solo movimientos abiertos (cerrado = 0)
    $sql = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                 THEN monto ELSE 0 END) as efectivo,
        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                 THEN monto ELSE 0 END) as transferencia,
        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
    FROM movimientos 
    WHERE cerrado = 0 
      AND empresa_id = :empresa_id 
      AND sucursal_id = :sucursal_id
      AND DATE(fecha) = :fecha";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':sucursal_id' => $sucursal_id,
        ':fecha' => $fecha
    ]);
    
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['egresos'] = (float)($resumen['egresos'] ?? 0);
    $resumen['caja_fisica'] = $resumen['efectivo'] - $resumen['egresos'];
    
    return $resumen;
}

/**
 * Obtener el número de cierre para una empresa/sucursal
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @return int Número de cierre
 */
function obtener_numero_cierre($pdo, $empresa_id, $sucursal_id) {
    $sql = "SELECT COALESCE(MAX(numero_cierre), 0) + 1 as numero 
            FROM cierres_caja 
            WHERE empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':sucursal_id' => $sucursal_id
    ]);
    
    return (int)$stmt->fetchColumn();
}
