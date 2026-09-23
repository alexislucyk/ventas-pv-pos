<?php
/**
 * Funciones auxiliares para el sistema de caja
 * Versión: 2.3.0
 * Fecha: 18/09/2026
 */

/**
 * Filtro SQL para EXCLUIR de los totales el movimiento de fondo inicial.
 *
 * Al abrir la caja se registra el saldo inicial en DOS lugares:
 *   1) estado_caja.saldo_inicial  (fuente de la fórmula
 *      "saldo_esperado = saldo_inicial + ingresos_efectivo - egresos")
 *   2) un movimiento INGRESO/EFECTIVO "FONDO INICIAL (APERTURA)" con
 *      es_fondo_inicial = 1 y cerrado = 0 (para trazabilidad en la UI).
 *
 * Si ese movimiento se incluye también en la suma de ingresos, el fondo
 * reservado que se dejó de la caja anterior (sugerido como saldo inicial)
 * se cuenta DOS veces. Por eso todas las sumas de totales deben excluirlo:
 * el saldo inicial se agrega siempre desde estado_caja.saldo_inicial.
 */
if (!defined('SQL_FILTRO_SIN_FONDO_INICIAL')) {
    define('SQL_FILTRO_SIN_FONDO_INICIAL', ' AND COALESCE(es_fondo_inicial, 0) = 0 ');
}

/**
 * FÓRMULA ÚNICA DE EFECTIVO REAL DE CAJA
 * ======================================
 * El "Saldo Esperado" de un cierre sólo puede construirse con el dinero que
 * REALMENTE entra y sale del cajón:
 *
 *   saldo_esperado = saldo_inicial + INGRESOS_EFECTIVO - EGRESOS_EFECTIVO
 *
 * Reglas:
 *  - INGRESO 'EFECTIVO' .......... monto completo (hay flujos históricos que no
 *                                 cargan monto_efectivo, p. ej. cobros de cta.
 *                                 cte. o movimientos manuales).
 *  - INGRESO 'MIXTO' ............. sólo la parte cobrada en efectivo. Se usa
 *                                 monto_efectivo (ventas nuevas) y, si viene 0,
 *                                 se calcula monto - monto_transferencia.
 *  - INGRESO 'TRANSFERENCIA' ..... NO entra al cajón (se informa aparte).
 *  - EGRESO 'EFECTIVO' ........... sí sale del cajón.
 *  - EGRESO 'MIXTO' .............. sólo la parte en efectivo (reintegros de
 *                                 ventas mixtas anuladas).
 *  - EGRESO 'TRANSFERENCIA' /
 *    'TARJETA' / 'CHEQUE' /
 *    'AJUSTE' .................... NO salen del cajón. Antes se restaban del
 *                                 efectivo y generaban faltantes falsos
 *                                 (p. ej. una anulación por transferencia de
 *                                 $100.000 dejaba el esperado en negativo).
 *
 * Estos fragmentos se usan dentro de un SUM(...) en las consultas de cierre,
 * dashboard y cierres históricos: cambiar la fórmula acá la cambia en todos.
 */
if (!defined('SQL_INGRESO_EFECTIVO')) {
    define('SQL_INGRESO_EFECTIVO', "
        SUM(CASE
                WHEN tipo = 'INGRESO' AND metodo_pago = 'EFECTIVO' THEN monto
                WHEN tipo = 'INGRESO' AND metodo_pago = 'MIXTO'
                     THEN COALESCE(NULLIF(monto_efectivo, 0),
                                   GREATEST(monto - COALESCE(monto_transferencia, 0), 0))
                ELSE 0
            END) ");
}

if (!defined('SQL_EGRESO_EFECTIVO')) {
    define('SQL_EGRESO_EFECTIVO', "
        SUM(CASE
                WHEN tipo = 'EGRESO' AND metodo_pago = 'EFECTIVO' THEN monto
                WHEN tipo = 'EGRESO' AND metodo_pago = 'MIXTO'
                     THEN COALESCE(NULLIF(monto_efectivo, 0),
                                   GREATEST(monto - COALESCE(monto_transferencia, 0), 0))
                ELSE 0
            END) ");
}

/** Egresos que NO salen del cajón (transferencia, tarjeta, cheque, ajuste). */
if (!defined('SQL_EGRESO_NO_EFECTIVO')) {
    define('SQL_EGRESO_NO_EFECTIVO', "
        SUM(CASE
                WHEN tipo = 'EGRESO' AND metodo_pago NOT IN ('EFECTIVO', 'MIXTO') THEN monto
                ELSE 0
            END) ");
}

/** Ingresos por transferencia (transferencia pura + parte digital de las mixtas). */
if (!defined('SQL_INGRESO_TRANSFERENCIA')) {
    define('SQL_INGRESO_TRANSFERENCIA', "
        SUM(CASE
                WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' THEN monto
                WHEN tipo = 'INGRESO' AND metodo_pago = 'MIXTO'
                     THEN COALESCE(NULLIF(monto_transferencia, 0),
                                   GREATEST(monto - COALESCE(monto_efectivo, 0), 0))
                ELSE 0
            END) ");
}

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
 * Verificar si estado_caja soporta la columna `observaciones`
 * (migración 46). Se cachea en memoria para no repetir la consulta.
 *
 * Permite que abrir_caja() guarde la observación sin romper las
 * aperturas en instalaciones donde la migración todavía no se aplicó.
 *
 * @param PDO $pdo Conexión a base de datos
 * @return bool
 */
function estado_caja_tiene_observaciones($pdo) {
    static $tiene = null;
    if ($tiene === null) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM estado_caja LIKE 'observaciones'");
            $tiene = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $tiene = false;
        }
    }
    return $tiene;
}

/**
 * Verificar si cierres_caja soporta la columna `observaciones`.
 * Se cachea en memoria para no repetir la consulta.
 *
 * @param PDO $pdo Conexión a base de datos
 * @return bool
 */
function cierres_caja_tiene_observaciones($pdo) {
    static $tiene = null;
    if ($tiene === null) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM cierres_caja LIKE 'observaciones'");
            $tiene = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $tiene = false;
        }
    }
    return $tiene;
}

/**
 * Abrir caja para el día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param float $saldo_inicial Saldo inicial de caja
 * @param string $usuario Usuario que abre la caja
 * @param string $observaciones Observación de la apertura (opcional)
 * @return array Resultado de la operación
 */
function abrir_caja($pdo, $empresa_id, $sucursal_id, $saldo_inicial, $usuario, $observaciones = '') {
    $transaccion_propia = false;
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
        
        // Apertura transaccional: la sesión (estado_caja) y su movimiento de
        // fondo inicial deben grabarse juntos. Si falla uno, no debe quedar el
        // otro (una sesión sin fondo o un fondo sin sesión).
        $transaccion_propia = !$pdo->inTransaction();
        if ($transaccion_propia) {
            $pdo->beginTransaction();
        }
        
        $observaciones = trim((string)$observaciones);
        
        // Crear nuevo registro de caja abierta.
        // La observación se guarda solo si la columna existe (migración 46);
        // así la apertura no falla en instalaciones sin migrar.
        if (estado_caja_tiene_observaciones($pdo)) {
            $sql = "INSERT INTO estado_caja 
                    (empresa_id, sucursal_id, fecha, estado, saldo_inicial, observaciones, usuario_apertura, fecha_apertura)
                    VALUES (:empresa_id, :sucursal_id, :fecha, 'ABIERTA', :saldo_inicial, :observaciones, :usuario, :fecha_apertura)";
            $params = [
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':fecha' => $fecha,
                ':saldo_inicial' => $saldo_inicial,
                ':observaciones' => ($observaciones !== '' ? $observaciones : null),
                ':usuario' => $usuario,
                ':fecha_apertura' => $fecha_apertura
            ];
        } else {
            $sql = "INSERT INTO estado_caja 
                    (empresa_id, sucursal_id, fecha, estado, saldo_inicial, usuario_apertura, fecha_apertura)
                    VALUES (:empresa_id, :sucursal_id, :fecha, 'ABIERTA', :saldo_inicial, :usuario, :fecha_apertura)";
            $params = [
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':fecha' => $fecha,
                ':saldo_inicial' => $saldo_inicial,
                ':usuario' => $usuario,
                ':fecha_apertura' => $fecha_apertura
            ];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Si hay saldo inicial, crear movimiento de fondo inicial.
        // IMPORTANTE: este movimiento es SOLO informativo/trazabilidad (aparece en
        // la lista de movimientos de la caja). El monto NO debe incluirse en las
        // sumas de ingresos: el saldo inicial ya se agrega desde
        // estado_caja.saldo_inicial (ver SQL_FILTRO_SIN_FONDO_INICIAL en
        // funciones_caja.php). Incluirlo duplicaría el fondo reservado de la caja
        // anterior que se dejó como cambio.
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

        if ($transaccion_propia) {
            $pdo->commit();
        }
        
        return [
            'success' => true,
            'mensaje' => 'Caja abierta correctamente.'
        ];
        
    } catch (Exception $e) {
        // Si la apertura inició su propia transacción, se revierte completa:
        // no debe quedar ni la sesión ni el movimiento de fondo por separado.
        if ($transaccion_propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
 * @param float|null $saldo_real Efectivo contado físicamente (opcional). Si se
 *                               informa, se guarda junto con la diferencia en la
 *                               MISMA transacción del cierre: así el esperado, la
 *                               diferencia y los totales salen de una única
 *                               consulta y no pueden quedar incoherentes.
 * @param string $observaciones Observaciones del cierre (opcional)
 * @return array Resultado de la operación
 */
function cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo_vuelto = 0, $fecha_desde = null, $fecha_hasta = null, $saldo_real = null, $observaciones = '') {
    // Se inicializa para que el catch sepa si la transacción es de esta función
    $transaccion_propia = false;
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
        
        // Iniciar transacción (sólo si no hay una activa: permite llamar a
        // cerrar_caja() dentro de una transacción mayor, p. ej. en pruebas)
        $transaccion_propia = !$pdo->inTransaction();
        if ($transaccion_propia) {
            $pdo->beginTransaction();
        }
        
        // Calcular totales del período especificado por método de pago.
        // Se excluye el movimiento de FONDO INICIAL (es_fondo_inicial = 1):
        // el saldo inicial ya se suma aparte desde estado_caja.saldo_inicial.
        // IMPORTANTE: el efectivo y los egresos usan la FÓRMULA ÚNICA de
        // funciones_caja.php (SQL_INGRESO_EFECTIVO / SQL_EGRESO_EFECTIVO):
        // sólo cuenta la plata que realmente entra/sale del cajón (la parte en
        // efectivo de las ventas MIXTAS, y los egresos que no son de caja no se
        // descuentan del efectivo esperado).
        $sql_totales = "SELECT 
            " . SQL_INGRESO_EFECTIVO . " as ingresos_efectivo,
            " . SQL_INGRESO_TRANSFERENCIA . " as ingresos_transf,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'CHEQUE' 
                     THEN monto ELSE 0 END) as ingresos_cheques,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TARJETA' 
                     THEN monto ELSE 0 END) as ingresos_tarjetas,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'TARJETA', 'MIXTO') 
                     THEN monto ELSE 0 END) as ingresos_otros,
            " . SQL_EGRESO_EFECTIVO . " as egresos,
            " . SQL_EGRESO_NO_EFECTIVO . " as egresos_no_efectivo
        FROM movimientos 
        WHERE cerrado = 0 
          AND empresa_id = :empresa_id 
          AND sucursal_id = :sucursal_id"
          . SQL_FILTRO_SIN_FONDO_INICIAL .
          "AND fecha BETWEEN :fecha_desde AND :fecha_hasta";
        
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
        // Egresos que salen del cajón (los demás no afectan el efectivo esperado)
        $egresos = (float)($totales['egresos'] ?? 0);
        $egresos_no_efectivo = (float)($totales['egresos_no_efectivo'] ?? 0);
        
        // Incluir saldo inicial en el cálculo del saldo esperado
        $saldo_inicial = (float)($estado['saldo_inicial'] ?? 0);
        $saldo_esperado = $saldo_inicial + $ing_efectivo - $egresos;
        $saldo_esperado = round($saldo_esperado, 2);
        
        // Conteo físico: si no se informa, se toma el esperado (el formulario de
        // cierre lo envía siempre; el valor queda actualizado más abajo).
        $saldo_real = ($saldo_real === null) ? $saldo_esperado : round((float)$saldo_real, 2);
        $diferencia = round($saldo_real - $saldo_esperado, 2);
        
        // Obtener número de cierre
        $numero_cierre = obtener_numero_cierre($pdo, $empresa_id, $sucursal_id);
        
        // Insertar en cierres_caja con todos los métodos de pago.
        // saldo_real_efectivo y diferencia se guardan ya calculados con el MISMO
        // criterio con el que se obtuvo el esperado (no se recalculan después
        // con otra consulta).
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
        
        $stmt_cierre = $pdo->prepare($sql_cierre);
        $stmt_cierre->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha_cierre' => $fecha_cierre,
            ':fecha_desde' => $fecha_desde,
            ':fecha_hasta' => $fecha_hasta,
            ':saldo_inicial' => $saldo_inicial,
            ':ingresos_efectivo' => $ing_efectivo,
            ':ingresos_transf' => $ing_transf,
            ':ingresos_cheques' => $ing_cheques,
            ':ingresos_tarjetas' => $ing_tarjetas,
            ':ingresos_otros' => $ing_otros,
            ':egresos' => $egresos,
            ':saldo_esperado' => $saldo_esperado,
            ':saldo_real' => $saldo_real,
            ':diferencia' => $diferencia,
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
        
        // Observaciones del cierre (la columna existe desde el inicio, pero se
        // verifica por las dudas para no romper el cierre si falta).
        $observaciones = trim((string)$observaciones);
        if ($observaciones !== '' && cierres_caja_tiene_observaciones($pdo)) {
            $stmt_obs = $pdo->prepare("UPDATE cierres_caja SET observaciones = :obs WHERE id = :id");
            $stmt_obs->execute([
                ':obs' => $observaciones,
                ':id' => $cierre_id
            ]);
        }
        
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
            'saldo_inicial' => $saldo_inicial,
            'ingresos_efectivo' => $ing_efectivo,
            'ingresos_transf' => $ing_transf,
            'ingresos_cheques' => $ing_cheques,
            'ingresos_tarjetas' => $ing_tarjetas,
            'ingresos_otros' => $ing_otros,
            'egresos' => $egresos,
            'egresos_no_efectivo' => $egresos_no_efectivo,
            'saldo_esperado' => $saldo_esperado,
            'saldo_real' => $saldo_real,
            'diferencia' => $diferencia,
            'fondo_vuelto' => $fondo_vuelto,
            'observaciones' => $observaciones
        ]);
        
        try {
            $stmt_audit = $pdo->prepare($sql_audit);
            $stmt_audit->execute([
                ':cierre_id' => $cierre_id,
                ':usuario' => $usuario,
                ':datos' => $datos_audit
            ]);
        } catch (Exception $e) {
            // La auditoría no debe abortar el cierre
            error_log('Aviso: no se pudo registrar auditoría del cierre ' . $cierre_id . ': ' . $e->getMessage());
        }
        
        if ($transaccion_propia) {
            $pdo->commit();
        }
        
        return [
            'success' => true,
            'mensaje' => 'Caja cerrada correctamente.',
            'cierre_id' => $cierre_id,
            'fondo_vuelto' => (float)$fondo_vuelto,
            'saldo_inicial' => $saldo_inicial,
            'ingresos_efectivo' => $ing_efectivo,
            'egresos_efectivo' => $egresos,
            'egresos_no_efectivo' => $egresos_no_efectivo,
            'saldo_esperado' => $saldo_esperado,
            'saldo_real' => $saldo_real,
            'diferencia' => $diferencia
        ];
        
    } catch (Exception $e) {
        // Se revierte sólo si la transacción pertenece a esta función (el error
        // puede haber ocurrido antes de beginTransaction o la transacción puede
        // ser de otro llamador).
        if ($transaccion_propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    
    // Solo movimientos abiertos (cerrado = 0), excluyendo el fondo inicial:
    // el saldo inicial se agrega aparte (estado_caja.saldo_inicial) para no duplicar.
    // El efectivo y los egresos de caja usan la FÓRMULA ÚNICA de este archivo
    // (parte en efectivo de las ventas MIXTAS; los egresos por transferencia,
    // tarjeta, cheque o ajuste no salen del cajón).
    $sql = "SELECT 
        " . SQL_INGRESO_EFECTIVO . " as efectivo,
        " . SQL_INGRESO_TRANSFERENCIA . " as transferencia,
        " . SQL_EGRESO_EFECTIVO . " as egresos,
        " . SQL_EGRESO_NO_EFECTIVO . " as egresos_no_efectivo
    FROM movimientos 
    WHERE cerrado = 0 
      AND empresa_id = :empresa_id 
      AND sucursal_id = :sucursal_id"
      . SQL_FILTRO_SIN_FONDO_INICIAL .
      "AND DATE(fecha) = :fecha";
    
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
    $resumen['egresos_no_efectivo'] = (float)($resumen['egresos_no_efectivo'] ?? 0);
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
/**
 * Detectar cierres de caja afectados por el FONDO INICIAL DUPLICADO.
 *
 * Bug corregido (versión 2.2.0): el movimiento de fondo inicial
 * ("FONDO INICIAL (APERTURA)", es_fondo_inicial = 1) se sumaba en
 * `ingresos_efectivo` además de agregarse el saldo inicial, por lo que el
 * fondo reservado que se dejó de la caja anterior quedaba contado dos veces.
 *
 * Criterios de detección (todos deben cumplirse):
 *   1. saldo_inicial > 0
 *   2. No proviene del cierre histórico masivo ('Sistema (Cierre Histórico)'),
 *      cuyas filas usan otra fórmula y NO deben tocarse.
 *   3. Cumple la firma aritmética del bug: saldo_esperado = saldo_inicial + ingresos - egresos
 *   4. Existe el movimiento de fondo inicial por el mismo monto dentro del período.
 *   5. El descuento real es verificable: los ingresos del período calculados desde
 *      los movimientos (sin el fondo) coinciden con "ingresos_efectivo - fondo".
 *      Este es el criterio que separa una fila afectada de una ya correcta: en una
 *      fila correcta, ingresos_efectivo ya excluye el fondo y no habría coincidencia.
 *
 * Nota: `movimientos.monto` es DECIMAL(10,0), por lo que el movimiento de fondo
 * guarda ROUND(saldo_inicial) y eso es exactamente lo que se sumó de más a
 * `ingresos_efectivo`. Las columnas *_corregido restan ese valor redondeado.
 *
 * @param PDO $pdo Conexión a base de datos
 * @param int|null $empresa_id Filtrar por empresa (opcional)
 * @param int|null $sucursal_id Filtrar por sucursal (opcional)
 * @return array Filas con los valores corregidos (*_corregido)
 */
function detectar_cierres_fondo_inicial_duplicado($pdo, $empresa_id = null, $sucursal_id = null) {
    $sql = "SELECT 
                c.id,
                c.empresa_id,
                c.sucursal_id,
                c.fecha_desde,
                c.fecha_hasta,
                c.fecha_cierre,
                c.saldo_inicial,
                c.ingresos_efectivo,
                c.egresos,
                c.saldo_esperado_efectivo,
                c.saldo_real_efectivo,
                c.diferencia,
                c.usuario,
                (c.ingresos_efectivo - ROUND(c.saldo_inicial)) AS ingresos_efectivo_corregido,
                (c.saldo_esperado_efectivo - ROUND(c.saldo_inicial)) AS saldo_esperado_corregido,
                (c.saldo_real_efectivo - (c.saldo_esperado_efectivo - ROUND(c.saldo_inicial))) AS diferencia_corregida
            FROM cierres_caja c
            WHERE COALESCE(c.saldo_inicial, 0) > 0
              AND (c.usuario IS NULL OR c.usuario <> 'Sistema (Cierre Histórico)')
              AND ABS(c.saldo_esperado_efectivo - (c.saldo_inicial + c.ingresos_efectivo - c.egresos)) < 0.01
              AND EXISTS (
                    SELECT 1
                    FROM movimientos m
                    WHERE m.empresa_id = c.empresa_id
                      AND m.sucursal_id = c.sucursal_id
                      AND COALESCE(m.es_fondo_inicial, 0) = 1
                      AND ABS(m.monto - ROUND(c.saldo_inicial)) < 0.01
                      AND m.fecha BETWEEN c.fecha_desde AND c.fecha_hasta
              )
              AND ROUND(c.saldo_inicial) <= c.ingresos_efectivo
              AND ABS(
                    (SELECT COALESCE(SUM(m2.monto), 0)
                       FROM movimientos m2
                      WHERE m2.empresa_id = c.empresa_id
                        AND m2.sucursal_id = c.sucursal_id
                        AND m2.tipo = 'INGRESO'
                        AND m2.metodo_pago IN ('EFECTIVO', 'MIXTO')
                        AND COALESCE(m2.es_fondo_inicial, 0) = 0
                        AND m2.fecha BETWEEN c.fecha_desde AND c.fecha_hasta)
                    - (c.ingresos_efectivo - ROUND(c.saldo_inicial))
                  ) < 0.01";
    
    $params = [];
    if ($empresa_id !== null) {
        $sql .= " AND c.empresa_id = :empresa_id";
        $params[':empresa_id'] = $empresa_id;
    }
    if ($sucursal_id !== null) {
        $sql .= " AND c.sucursal_id = :sucursal_id";
        $params[':sucursal_id'] = $sucursal_id;
    }
    $sql .= " ORDER BY c.fecha_cierre, c.id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Reparar los cierres detectados por detectar_cierres_fondo_inicial_duplicado().
 *
 * Quita el monto del fondo inicial de `ingresos_efectivo`, recalcula
 * `saldo_esperado_efectivo` y `diferencia` (el saldo real contado NO se toca)
 * y registra cada cambio en `cierres_caja_audit` como 'MODIFICADO'.
 *
 * El UPDATE incluye la firma del bug para que re-ejecutar sea inofensivo.
 *
 * @param PDO $pdo Conexión a base de datos
 * @param array $cierres Filas detectadas (con columnas *_corregido)
 * @param string $usuario Usuario que ejecuta la reparación
 * @return array Resultado con success, reparados, mensaje y detalle
 */
function reparar_cierres_fondo_inicial_duplicado($pdo, array $cierres, $usuario = 'Sistema') {
    $resultado = ['success' => false, 'reparados' => 0, 'mensaje' => '', 'detalle' => []];
    $transaccion_propia = false;
    
    if (empty($cierres)) {
        $resultado['success'] = true;
        $resultado['mensaje'] = 'No hay cierres para reparar.';
        return $resultado;
    }
    
    try {
        $transaccion_propia = !$pdo->inTransaction();
        if ($transaccion_propia) {
            $pdo->beginTransaction();
        }
        
        $stmt_update = $pdo->prepare(
            "UPDATE cierres_caja 
                SET ingresos_efectivo = :ingresos,
                    saldo_esperado_efectivo = :esperado,
                    diferencia = :diferencia
              WHERE id = :id
                AND COALESCE(saldo_inicial, 0) > 0
                AND ABS(saldo_esperado_efectivo - (saldo_inicial + ingresos_efectivo - egresos)) < 0.01"
        );
        
        $stmt_audit = $pdo->prepare(
            "INSERT INTO cierres_caja_audit 
                (cierre_id, accion, usuario, datos_anteriores, datos_nuevos)
                VALUES (:cierre_id, 'MODIFICADO', :usuario, :anteriores, :nuevos)"
        );
        
        foreach ($cierres as $c) {
            $id = (int)$c['id'];
            
            $datos_anteriores = [
                'ingresos_efectivo'       => (float)$c['ingresos_efectivo'],
                'saldo_esperado_efectivo' => (float)$c['saldo_esperado_efectivo'],
                'diferencia'              => (float)$c['diferencia']
            ];
            $datos_nuevos = [
                'ingresos_efectivo'       => (float)$c['ingresos_efectivo_corregido'],
                'saldo_esperado_efectivo' => (float)$c['saldo_esperado_corregido'],
                'diferencia'              => (float)$c['diferencia_corregida'],
                'motivo'                  => 'Fondo inicial sumado dos veces (corregido)'
            ];
            
            $stmt_update->execute([
                ':ingresos'   => $datos_nuevos['ingresos_efectivo'],
                ':esperado'   => $datos_nuevos['saldo_esperado_efectivo'],
                ':diferencia' => $datos_nuevos['diferencia'],
                ':id'         => $id
            ]);
            
            // La auditoría es informativa: si la tabla no existe, no se aborta la reparación
            try {
                $stmt_audit->execute([
                    ':cierre_id'  => $id,
                    ':usuario'    => $usuario,
                    ':anteriores' => json_encode($datos_anteriores),
                    ':nuevos'     => json_encode($datos_nuevos)
                ]);
            } catch (Exception $e) {
                error_log('Aviso: no se pudo registrar auditoría del cierre ' . $id . ': ' . $e->getMessage());
            }
            
            $resultado['reparados']++;
            $resultado['detalle'][] = [
                'cierre_id'               => $id,
                'ingresos_efectivo'       => $datos_anteriores['ingresos_efectivo'],
                'ingresos_efectivo_nuevo' => $datos_nuevos['ingresos_efectivo'],
                'saldo_esperado_nuevo'    => $datos_nuevos['saldo_esperado_efectivo'],
                'diferencia_nueva'        => $datos_nuevos['diferencia']
            ];
        }
        
        if ($transaccion_propia) {
            $pdo->commit();
        }
        
        $resultado['success'] = true;
        $resultado['mensaje'] = 'Se corrigieron ' . $resultado['reparados'] . ' cierre(s) de caja.';
        
    } catch (Exception $e) {
        if ($transaccion_propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $resultado['reparados'] = 0;
        $resultado['detalle'] = [];
        $resultado['mensaje'] = 'Error al reparar cierres: ' . $e->getMessage();
    }
    
    return $resultado;
}

