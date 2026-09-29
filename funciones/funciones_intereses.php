<?php
/**
 * funciones/funciones_intereses.php
 *
 * Intereses por mora de cuentas corrientes de CLIENTES.
 *
 * Modelo vigente (v2, cálculo sobre SALDO DEUDOR):
 *   1) El interés se calcula sobre el SALDO DEUDOR de la cuenta, no factura por
 *      factura. Es el "saldo negativo" que ve el cliente en su cuenta corriente.
 *   2) La mora corre desde el vencimiento de la factura impaga más antigua. Para
 *      saber qué quedó impago se aplican los pagos/devoluciones por antigüedad
 *      (FIFO): el dinero cancela primero la deuda más vieja. Todo se calcula con
 *      UNA consulta al historial; no depende de las tablas de imputaciones.
 *   3) El capital NUNCA incluye los intereses ya aplicados (no hay anatocismo).
 *   4) Al aplicar el interés se crea un movimiento en ctacte con es_interes = 1 y
 *      fecha = hoy. Esa fecha pasa a ser el nuevo corte, así que los días ya
 *      cobrados no se vuelven a cobrar: aplicar dos veces el mismo día da $0.
 *
 * Migración requerida: 50_intereses_saldo_ctacte.sql (columna ctacte.es_interes).
 */

// Helpers compartidos de cuenta corriente (saldo disponible, imputaciones y el
// filtro condicionSqlNoEsInteresCc). Se mantiene el require por compatibilidad:
// varias páginas cargan sólo este archivo.
require_once __DIR__ . '/funciones_pagos_ctacte.php';

/** Texto del movimiento de interés en ctacte (la fecha real va en la columna fecha). */
const INTERES_CC_MOVIMIENTO = 'INTERÉS POR MORA';

/** Tasa mensual por defecto cuando la empresa no tiene fila en configuracion_intereses. */
const INTERES_CC_TASA_DEFECTO = 3.00;

/** Plazo de fiado por defecto (días) al vender en cuenta corriente. */
const INTERES_CC_PLAZO_DEFECTO = 30;

/**
 * Controla que la migración 50 esté aplicada (columna ctacte.es_interes).
 */
function tablaEsInteresCcExiste(PDO $pdo): bool {
    try {
        $pdo->query('SELECT es_interes FROM ctacte LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Interés por mora del modo DIARIO (prorrateo por día).
 * Fórmula: Saldo × (Tasa mensual / 30 / 100) × días.
 *
 * @param float $saldo
 * @param int   $dias_mora
 * @param float $tasa_mensual
 * @return float
 */
function calcularInteresMora($saldo, $dias_mora, $tasa_mensual = INTERES_CC_TASA_DEFECTO) {
    $saldo = (float)$saldo;
    $dias_mora = (int)$dias_mora;

    if ($saldo <= 0 || $dias_mora <= 0) {
        return 0.0;
    }

    return round($saldo * ((float)$tasa_mensual / 30 / 100) * $dias_mora, 2);
}

/**
 * Interés del período según el modo configurado por la empresa.
 *   DIARIO  → prorratea por día (calcularInteresMora)
 *   MENSUAL → sólo períodos mensuales completos (30 días = 1 mes)
 *
 * @param float  $saldo
 * @param int    $dias_mora      días efectivos (ya descontados los de gracia)
 * @param float  $tasa_mensual
 * @param string $modo_calculo   DIARIO | MENSUAL
 * @return float
 */
function calcularInteresPeriodo($saldo, $dias_mora, $tasa_mensual = INTERES_CC_TASA_DEFECTO, $modo_calculo = 'DIARIO') {
    $saldo = (float)$saldo;
    $dias = max(0, (int)$dias_mora);

    if ($saldo <= 0 || $dias <= 0) {
        return 0.0;
    }

    if (strtoupper((string)$modo_calculo) === 'MENSUAL') {
        $meses = intdiv($dias, 30);
        if ($meses <= 0) {
            return 0.0;
        }
        return round($saldo * ((float)$tasa_mensual / 100) * $meses, 2);
    }

    return calcularInteresMora($saldo, $dias, $tasa_mensual);
}

/**
 * Obtiene la configuración de intereses de la empresa.
 * Siempre devuelve todas las claves con valores utilizables.
 *
 * @param PDO $pdo
 * @param int $empresa_id
 * @return array
 */
function obtenerConfiguracionIntereses($pdo, $empresa_id) {
    $default = [
        'tasa_mensual'     => INTERES_CC_TASA_DEFECTO,
        'dias_gracia'      => 0,
        'plazo_fiado_dias' => INTERES_CC_PLAZO_DEFECTO,
        'modo_calculo'     => 'DIARIO',
        'fecha_vigencia'   => null,
        'activo'           => 1,
    ];

    $config = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM configuracion_intereses WHERE empresa_id = :empresa_id LIMIT 1");
        $stmt->execute([':empresa_id' => $empresa_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $config = null;
    }

    if (!$config) {
        return $default;
    }

    if (empty($config['modo_calculo'])) {
        $config['modo_calculo'] = 'DIARIO';
    }
    if (empty($config['plazo_fiado_dias']) || (int)$config['plazo_fiado_dias'] <= 0) {
        $config['plazo_fiado_dias'] = INTERES_CC_PLAZO_DEFECTO;
    }
    if (!array_key_exists('fecha_vigencia', $config)) {
        $config['fecha_vigencia'] = null;
    }

    // Completar claves faltantes (p. ej. base sin la migración 50 aplicada).
    return $config + $default;
}
/**
 * Historial de CAPITAL de la cuenta (excluye los intereses ya aplicados) hasta
 * la fecha de cálculo, ordenado por cliente y fecha.
 *
 * @param PDO      $pdo
 * @param int      $empresa_id
 * @param int|null $id_cliente  null = todos los clientes de la empresa
 * @param string   $fecha_calculo  Y-m-d
 * @return array
 */
function obtenerMovimientosCapitalCc(PDO $pdo, int $empresa_id, ?int $id_cliente, string $fecha_calculo): array {
    $sql = "SELECT id, id_cliente, debe, haber, fecha,
                   COALESCE(fecha_vencimiento, fecha) AS vencimiento
            FROM ctacte
            WHERE empresa_id = :empresa_id
              AND es_interes = 0
              AND fecha <= :fecha_calculo";
    $params = [
        ':empresa_id'    => $empresa_id,
        ':fecha_calculo' => $fecha_calculo,
    ];
    if ($id_cliente !== null) {
        $sql .= " AND id_cliente = :id_cliente";
        $params[':id_cliente'] = $id_cliente;
    }
    $sql .= " ORDER BY id_cliente, fecha, id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Última fecha en que se aplicó un interés (el "corte").
 * Desde esa fecha, y no antes, se vuelven a contar los días de mora.
 *
 * @return string|null Y-m-d o null si nunca se aplicó un interés.
 */
function obtenerCorteInteresesCliente(PDO $pdo, int $empresa_id, int $id_cliente, ?string $fecha_calculo = null): ?string {
    $stmt = $pdo->prepare(
        "SELECT MAX(fecha) FROM ctacte
         WHERE empresa_id = ? AND id_cliente = ? AND es_interes = 1 AND fecha <= ?"
    );
    $stmt->execute([$empresa_id, $id_cliente, substr($fecha_calculo ?: date('Y-m-d'), 0, 10)]);
    $corte = $stmt->fetchColumn();
    return $corte ? substr((string)$corte, 0, 10) : null;
}

/**
 * Cortes de interés de todos los clientes de la empresa (1 sola consulta).
 *
 * @return array<int, string> id_cliente => Y-m-d
 */
function obtenerCortesInteresesEmpresa(PDO $pdo, int $empresa_id, ?string $fecha_calculo = null): array {
    $stmt = $pdo->prepare(
        "SELECT id_cliente, MAX(fecha) AS corte
         FROM ctacte
         WHERE empresa_id = ? AND es_interes = 1 AND fecha <= ?
         GROUP BY id_cliente"
    );
    $stmt->execute([$empresa_id, substr($fecha_calculo ?: date('Y-m-d'), 0, 10)]);

    $cortes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $cortes[(int)$fila['id_cliente']] = substr((string)$fila['corte'], 0, 10);
    }
    return $cortes;
}
/**
 * Cálculo puro del estado de mora a partir de los movimientos de una cuenta.
 *
 * Pasos:
 *   1) Los créditos (pagos, devoluciones, saldos a favor) cancelan la deuda más
 *      antigua (FIFO). Lo que queda sin cancelar es el saldo deudor.
 *   2) La mora corre desde el vencimiento más antiguo de las deudas impagas que
 *      ya están vencidas.
 *   3) Se cuenta desde max(ese vencimiento, corte anterior, fecha de vigencia).
 *
 * @param array       $movimientos   filas con debe, haber, vencimiento
 * @param string      $fecha_calculo Y-m-d
 * @param array       $config        configuración de intereses
 * @param string|null $fecha_corte   fecha del último interés aplicado
 * @return array
 */
function calcularMoraCcDesdeMovimientos(array $movimientos, string $fecha_calculo, array $config, ?string $fecha_corte = null): array {
    $hoy = substr($fecha_calculo, 0, 10);
    $gracia = max(0, (int)($config['dias_gracia'] ?? 0));
    $tasa = (float)($config['tasa_mensual'] ?? INTERES_CC_TASA_DEFECTO);
    $modo = strtoupper((string)($config['modo_calculo'] ?? 'DIARIO'));
    $vigencia = !empty($config['fecha_vigencia']) ? substr((string)$config['fecha_vigencia'], 0, 10) : null;

    // 1) FIFO: el dinero cancela primero la deuda más antigua
    $creditos = 0.0;
    $deudas = [];
    foreach ($movimientos as $mov) {
        $debe = round((float)$mov['debe'], 2);
        $haber = round((float)$mov['haber'], 2);

        if ($debe > 0) {
            $deudas[] = ['vencimiento' => substr((string)$mov['vencimiento'], 0, 10), 'saldo' => $debe];
        }
        if ($haber > 0) {
            $creditos = round($creditos + $haber, 2);
        }
    }

    foreach ($deudas as &$deuda) {
        if ($creditos <= 0.005) {
            break;
        }
        $aplicar = min($creditos, $deuda['saldo']);
        $deuda['saldo'] = round($deuda['saldo'] - $aplicar, 2);
        $creditos = round($creditos - $aplicar, 2);
    }
    unset($deuda);

    // 2) Saldo impago y vencimiento más antiguo entre lo ya vencido
    $saldo_deudor = 0.0;
    $saldo_vencido = 0.0;
    $inicio_mora = null;
    foreach ($deudas as $deuda) {
        if ($deuda['saldo'] <= 0.005) {
            continue;
        }
        $saldo_deudor = round($saldo_deudor + $deuda['saldo'], 2);
        if ($deuda['vencimiento'] < $hoy) {
            $saldo_vencido = round($saldo_vencido + $deuda['saldo'], 2);
            if ($inicio_mora === null || $deuda['vencimiento'] < $inicio_mora) {
                $inicio_mora = $deuda['vencimiento'];
            }
        }
    }

    $resultado = [
        'saldo_deudor'   => max(0.0, $saldo_deudor),
        'saldo_vencido'  => max(0.0, $saldo_vencido),
        'inicio_mora'    => $inicio_mora,
        'fecha_corte'    => $fecha_corte,
        'fecha_base'     => null,
        'dias_mora'      => 0,
        'dias_gracia'    => $gracia,
        'dias_efectivos' => 0,
        'tasa_aplicada'  => $tasa,
        'modo_calculo'   => $modo,
        'interes_total'  => 0.0,
        'motivo'         => 'sin_mora',
    ];

    if ($saldo_deudor <= 0.005) {
        $resultado['motivo'] = 'sin_saldo_deudor';
        return $resultado;
    }

    if ($inicio_mora === null) {
        // Debe, pero nada venció todavía: está dentro del plazo del fiado.
        $resultado['motivo'] = 'dentro_del_plazo';
        return $resultado;
    }

    // 3) Fecha base: nunca antes del corte anterior ni de la vigencia configurada
    $base = $inicio_mora;
    if ($fecha_corte !== null && $fecha_corte > $base) {
        $base = $fecha_corte;
    }
    if ($vigencia !== null && $vigencia > $base) {
        $base = $vigencia;
    }

    $dias = (int)floor((strtotime($hoy) - strtotime($base)) / 86400);

    $resultado['fecha_base'] = $base;
    $resultado['dias_mora'] = max(0, $dias);
    $resultado['dias_efectivos'] = max(0, $dias - $gracia);
    $resultado['interes_total'] = calcularInteresPeriodo($saldo_deudor, $resultado['dias_efectivos'], $tasa, $modo);
    $resultado['motivo'] = $resultado['interes_total'] > 0 ? 'con_interes' : 'dias_insuficientes';

    return $resultado;
}
/**
 * Calcula el interés por mora devengado por un cliente a una fecha dada.
 * Función de sólo lectura: no modifica la base de datos.
 *
 * @param int         $id_cliente
 * @param PDO         $pdo
 * @param int         $empresa_id
 * @param string|null $fecha_calculo Y-m-d (por defecto hoy)
 * @return array
 *   interes_total, saldo_deudor, saldo_vencido, inicio_mora, fecha_corte,
 *   fecha_base, dias_mora, dias_efectivos, tasa_aplicada, modo_calculo,
 *   motivo, detalle, config
 */
function calcularInteresesCliente($id_cliente, $pdo, $empresa_id, $fecha_calculo = null) {
    $id_cliente = (int)$id_cliente;
    $empresa_id = (int)$empresa_id;
    $fecha_calculo = substr($fecha_calculo ?: date('Y-m-d'), 0, 10);

    if (!tablaEsInteresCcExiste($pdo)) {
        throw new RuntimeException('Falta aplicar la migración 50 de intereses por saldo de cuenta corriente.');
    }

    $config = obtenerConfiguracionIntereses($pdo, $empresa_id);

    $vacio = [
        'interes_total'  => 0.0,
        'saldo_deudor'   => 0.0,
        'saldo_vencido'  => 0.0,
        'inicio_mora'    => null,
        'fecha_corte'    => null,
        'fecha_base'     => null,
        'dias_mora'      => 0,
        'dias_efectivos' => 0,
        'dias_gracia'    => max(0, (int)$config['dias_gracia']),
        'tasa_aplicada'  => (float)$config['tasa_mensual'],
        'modo_calculo'   => strtoupper((string)$config['modo_calculo']),
        'motivo'         => 'inactivo',
        'detalle'        => [],
        'config'         => $config,
    ];

    if (empty($config['activo'])) {
        return $vacio;
    }

    $movimientos = obtenerMovimientosCapitalCc($pdo, $empresa_id, $id_cliente, $fecha_calculo);
    $corte = obtenerCorteInteresesCliente($pdo, $empresa_id, $id_cliente, $fecha_calculo);

    $resultado = calcularMoraCcDesdeMovimientos($movimientos, $fecha_calculo, $config, $corte);
    $resultado['fecha_calculo'] = $fecha_calculo;
    $resultado['config'] = $config;

    // Fila única de desglose (para mostrar en pantalla / tickets)
    $resultado['detalle'] = $resultado['saldo_deudor'] > 0
        ? [[
            'concepto'          => 'Saldo deudor de la cuenta',
            'fecha_base'        => $resultado['fecha_base'],
            'saldo_pendiente'   => $resultado['saldo_deudor'],
            'saldo_vencido'     => $resultado['saldo_vencido'],
            'dias_mora'         => $resultado['dias_efectivos'],
            'interes_calculado' => $resultado['interes_total'],
        ]]
        : [];

    return $resultado;
}

/**
 * Genera el número de documento del interés. Formato: INT-YYYY-NNNNNN
 *
 * @param PDO $pdo
 * @param int $empresa_id
 * @return string
 */
function generarNumeroInteres($pdo, $empresa_id) {
    $anio = date('Y');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ctacte
         WHERE empresa_id = :empresa_id AND es_interes = 1 AND YEAR(fecha) = :anio"
    );
    $stmt->execute([':empresa_id' => $empresa_id, ':anio' => $anio]);
    $numero = str_pad((int)$stmt->fetchColumn() + 1, 6, '0', STR_PAD_LEFT);

    return 'INT-' . $anio . '-' . $numero;
}

/** Mensaje de error para una aplicación sin interés a cobrar. */
function mensajeSinInteresesCc(array $resultado): string {
    switch ($resultado['motivo'] ?? '') {
        case 'inactivo':
            return 'El sistema de intereses está pausado en la configuración.';
        case 'sin_saldo_deudor':
            return 'El cliente no tiene saldo deudor.';
        case 'dentro_del_plazo':
            return 'La deuda todavía está dentro del plazo de fiado: no hay mora.';
        case 'dias_insuficientes':
            return 'No hay días de mora suficientes para generar intereses.';
        default:
            return 'No hay intereses pendientes para aplicar.';
    }
}
/**
 * Aplica el interés devengado al saldo deudor de un cliente.
 * Inserta UN movimiento en ctacte (debe, es_interes = 1, fecha = hoy) que además
 * pasa a ser el nuevo corte: los días ya cobrados no se vuelven a cobrar.
 *
 * @param int        $id_cliente
 * @param PDO        $pdo
 * @param int|string $usuario_id
 * @return array{success:bool, monto_aplicado?:float, id_movimiento?:int, error?:string, detalle?:array}
 */
function aplicarInteresesMora($id_cliente, $pdo, $usuario_id = null) {
    $id_cliente = (int)$id_cliente;
    $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

    if ($id_cliente <= 0 || $empresa_id <= 0) {
        return ['success' => false, 'error' => 'Cliente o empresa no válidos'];
    }

    $propia = !$pdo->inTransaction();
    if ($propia) {
        $pdo->beginTransaction();
    }

    try {
        // Bloqueo del último movimiento de la cuenta: evita que dos aplicaciones
        // simultáneas calculen el mismo interés y lo dupliquen.
        $pdo->prepare("SELECT id FROM ctacte WHERE id_cliente = ? AND empresa_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE")
            ->execute([$id_cliente, $empresa_id]);

        $resultado = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);

        if ($resultado['interes_total'] <= 0) {
            if ($propia) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => mensajeSinInteresesCc($resultado)];
        }

        $usuario = $_SESSION['usuario_nombre'] ?? ($usuario_id ? ('Usuario #' . $usuario_id) : 'Sistema');
        $n_documento = generarNumeroInteres($pdo, $empresa_id);

        $pdo->prepare(
            "INSERT INTO ctacte
                (id_cliente, movimiento, n_documento, debe, haber, fecha, fecha_vencimiento, usuario, empresa_id, es_interes)
             VALUES (?, ?, ?, ?, 0, ?, NULL, ?, ?, 1)"
        )->execute([
            $id_cliente,
            INTERES_CC_MOVIMIENTO,
            $n_documento,
            $resultado['interes_total'],
            date('Y-m-d'),
            $usuario,
            $empresa_id,
        ]);

        $id_movimiento = (int)$pdo->lastInsertId();

        if ($propia) {
            $pdo->commit();
        }

        return [
            'success'        => true,
            'monto_aplicado' => $resultado['interes_total'],
            'id_movimiento'  => $id_movimiento,
            'n_documento'    => $n_documento,
            'detalle'        => $resultado['detalle'],
            'dias_mora'      => $resultado['dias_mora'],
            'fecha_base'     => $resultado['fecha_base'],
            'saldo_deudor'   => $resultado['saldo_deudor'],
        ];

    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error en aplicarInteresesMora: ' . $e->getMessage());
        if (!$propia) {
            throw $e;
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Indica si el cliente tiene intereses pendientes de aplicar.
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
 * Interés devengado por TODOS los clientes de la empresa (para listados).
 * Usa 2 consultas en total (historial + cortes), sin importar la cantidad de
 * clientes.
 *
 * @param PDO         $pdo
 * @param int         $empresa_id
 * @param string|null $fecha_calculo Y-m-d
 * @return array<int, array<string, mixed>> id_cliente => ['interes', 'dias_mora', 'saldo_deudor', 'fecha_base', 'motivo']
 */
function obtenerResumenInteresesPendientes($pdo, $empresa_id, $fecha_calculo = null) {
    $empresa_id = (int)$empresa_id;
    $fecha_calculo = substr($fecha_calculo ?: date('Y-m-d'), 0, 10);

    if (!tablaEsInteresCcExiste($pdo)) {
        throw new RuntimeException('Falta aplicar la migración 50 de intereses por saldo de cuenta corriente.');
    }

    $config = obtenerConfiguracionIntereses($pdo, $empresa_id);
    if (empty($config['activo'])) {
        return [];
    }

    $movimientos = obtenerMovimientosCapitalCc($pdo, $empresa_id, null, $fecha_calculo);
    $cortes = obtenerCortesInteresesEmpresa($pdo, $empresa_id, $fecha_calculo);

    // Agrupar el historial por cuenta
    $porCliente = [];
    foreach ($movimientos as $mov) {
        $porCliente[(int)$mov['id_cliente']][] = $mov;
    }

    $resumen = [];
    foreach ($porCliente as $id_cliente => $movs) {
        $mora = calcularMoraCcDesdeMovimientos($movs, $fecha_calculo, $config, $cortes[$id_cliente] ?? null);
        if ($mora['interes_total'] <= 0) {
            continue;
        }
        $resumen[$id_cliente] = [
            'interes'      => $mora['interes_total'],
            'dias_mora'    => $mora['dias_efectivos'],
            'saldo_deudor' => $mora['saldo_deudor'],
            'fecha_base'   => $mora['fecha_base'],
            'motivo'       => $mora['motivo'],
        ];
    }

    return $resumen;
}

/**
 * Formatea un monto como moneda.
 *
 * @param float $monto
 * @return string
 */
function formatearMontoInteres($monto) {
    return '$ ' . number_format((float)$monto, 2, ',', '.');
}

/**
 * Estadísticas de intereses aplicados (desde ctacte, que es la fuente de verdad).
 *
 * @param PDO         $pdo
 * @param int         $empresa_id
 * @param string|null $fecha_desde
 * @param string|null $fecha_hasta
 * @return array
 */
function obtenerEstadisticasIntereses($pdo, $empresa_id, $fecha_desde = null, $fecha_hasta = null) {
    $fecha_desde = $fecha_desde ?: date('Y-m-01');
    $fecha_hasta = $fecha_hasta ?: date('Y-m-t');

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_intereses_generados,
                COALESCE(SUM(debe), 0) AS monto_total_intereses,
                COALESCE(AVG(debe), 0) AS promedio_interes,
                COUNT(DISTINCT id_cliente) AS clientes_afectados
         FROM ctacte
         WHERE empresa_id = :empresa_id
           AND es_interes = 1
           AND fecha BETWEEN :fecha_desde AND :fecha_hasta"
    );
    $stmt->execute([
        ':empresa_id'  => $empresa_id,
        ':fecha_desde' => $fecha_desde,
        ':fecha_hasta' => $fecha_hasta,
    ]);

    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    return $stats ?: [
        'total_intereses_generados' => 0,
        'monto_total_intereses'     => 0,
        'promedio_interes'          => 0,
        'clientes_afectados'        => 0,
    ];
}
