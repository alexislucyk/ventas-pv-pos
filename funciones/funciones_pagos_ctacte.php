<?php
/**
 * Servicios compartidos de cuenta corriente de clientes.
 *
 * Desde v2.15.42 el pago a cuenta corriente se aplica sobre el SALDO de la
 * cuenta: el abono es un movimiento 'Pago Cta.Cte.' con haber, y el saldo
 * (SUM(debe) - SUM(haber)) es lo único que se cobra y lo único sobre el que se
 * devenga el interes por mora (migracion 50).
 *
 * Ya no existe la imputacion pago -> factura: la migracion 51 elimina
 * ctacte_pagos_imputaciones (48) y ctacte_creditos_a_favor_aplicaciones (49),
 * que nunca movieron debe/haber y solo guardaban metadatos.
 */

/**
 * Condicion SQL que deja afuera los movimientos de interes por mora (no son
 * facturas: no se muestran ni se cobran). Usa la columna ctacte.es_interes de la
 * migracion 50 y cae al filtro por texto si la base todavia no esta migrada.
 *
 * @param PDO    $pdo
 * @param string $alias Alias de la tabla ctacte en la consulta (ej. 'c')
 * @return string
 */
function condicionSqlNoEsInteresCc(PDO $pdo, string $alias = ''): string {
    static $tieneColumna = null;

    if ($tieneColumna === null) {
        try {
            $pdo->query('SELECT es_interes FROM ctacte LIMIT 1');
            $tieneColumna = true;
        } catch (PDOException $e) {
            $tieneColumna = false;
        }
    }

    $prefijo = $alias !== '' ? $alias . '.' : '';
    if ($tieneColumna) {
        return $prefijo . 'es_interes = 0';
    }
    return "LOWER(" . $prefijo . "movimiento) NOT LIKE 'inter%por%mora%'";
}

/**
 * Saldo de la cuenta: positivo = el cliente debe, negativo = tiene saldo a favor.
 *
 * @return float
 */
function saldoCuentaCc(PDO $pdo, int $id_cliente, int $empresa_id): float {
    $st = $pdo->prepare('SELECT COALESCE(SUM(debe - haber), 0) FROM ctacte WHERE id_cliente = ? AND empresa_id = ?');
    $st->execute([$id_cliente, $empresa_id]);
    return round((float)$st->fetchColumn(), 2);
}
/**
 * Saldos de todas las cuentas de la empresa en una sola consulta.
 *
 * @return array<int,float> [id_cliente => saldo]
 */
function saldosCuentaCc(PDO $pdo, int $empresa_id): array {
    $st = $pdo->prepare("SELECT id_cliente, COALESCE(SUM(debe - haber), 0) AS saldo
                         FROM ctacte
                         WHERE empresa_id = ?
                         GROUP BY id_cliente");
    $st->execute([$empresa_id]);

    $saldos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $saldos[(int)$fila['id_cliente']] = round((float)$fila['saldo'], 2);
    }
    return $saldos;
}

/**
 * Registra un abono a cuenta corriente. Es transaccional para que el movimiento
 * de ctacte y el ingreso de caja (callback) entren juntos o no entren.
 *
 * El pago solo mueve el saldo de la cuenta: no se elige ni se asocia a ninguna
 * factura. Si el pago supera la deuda, la cuenta queda con saldo a favor y el
 * interes por mora deja de correr hasta que vuelva a estar deudora.
 *
 * @param array $datos id_cliente, empresa_id, monto_pago, n_recibo, fecha, usuario
 * @param callable|null $despuesInsertarPago Recibe el id del movimiento creado
 * @return array{pago_id:int,monto:float,saldo_anterior:float,saldo_final:float,saldo_a_favor:float}
 */
function registrarPagoCuentaCorriente(PDO $pdo, array $datos, ?callable $despuesInsertarPago = null): array {
    $idCliente = (int)($datos['id_cliente'] ?? 0);
    $empresaId = (int)($datos['empresa_id'] ?? 0);
    $montoPago = round((float)($datos['monto_pago'] ?? 0), 2);
    $fechaPago = substr((string)($datos['fecha'] ?? date('Y-m-d')), 0, 10);

    if ($idCliente <= 0 || $empresaId <= 0 || $montoPago <= 0) {
        throw new InvalidArgumentException('Cliente, empresa y monto del pago son obligatorios.');
    }

    $propia = !$pdo->inTransaction();
    if ($propia) { $pdo->beginTransaction(); }

    try {
        $saldoAnterior = saldoCuentaCc($pdo, $idCliente, $empresaId);

        $st = $pdo->prepare("INSERT INTO ctacte
            (id_cliente, movimiento, n_documento, debe, haber, fecha, usuario, empresa_id)
            VALUES (?, 'Pago Cta.Cte.', ?, 0, ?, ?, ?, ?)");
        $st->execute([
            $idCliente,
            (string)($datos['n_recibo'] ?? ''),
            $montoPago,
            $fechaPago,
            (string)($datos['usuario'] ?? 'Sistema'),
            $empresaId
        ]);
        $pagoId = (int)$pdo->lastInsertId();

        if ($despuesInsertarPago) { $despuesInsertarPago($pagoId); }

        $saldoFinal = saldoCuentaCc($pdo, $idCliente, $empresaId);
        if ($propia) { $pdo->commit(); }

        return [
            'pago_id'        => $pagoId,
            'monto'          => $montoPago,
            'saldo_anterior' => $saldoAnterior,
            'saldo_final'    => $saldoFinal,
            'saldo_a_favor'  => max(0, -$saldoFinal),
        ];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
/**
 * Garantiza que los metadatos de imputacion (migraciones 48 y 49) no existan,
 * aunque la base tenga la 50 aplicada a medias o la 51 nunca se haya corrido
 * por el panel de actualizaciones.
 *
 * Es idempotente: si las tablas ya no estan, no toca nada y devuelve array vacio.
 *
 * @return string[] acciones realizadas
 */
function garantizarEliminacionImputacionesCc(PDO $pdo): array {
    $acciones = [];

    foreach (['ctacte_creditos_a_favor_aplicaciones', 'ctacte_pagos_imputaciones'] as $tabla) {
        try {
            $pdo->query("SELECT 1 FROM {$tabla} LIMIT 1");
        } catch (PDOException $e) {
            continue; // la tabla ya no existe: nada que hacer
        }
        $pdo->exec("DROP TABLE IF EXISTS {$tabla}");
        $acciones[] = "tabla {$tabla} eliminada";
    }

    if (!empty($acciones)) {
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '51')
                       ON DUPLICATE KEY UPDATE valor = IF(CAST(valor AS UNSIGNED) < 51, '51', valor)")
            ->execute();
        $acciones[] = 'contador de migraciones fijado en 51';
    }

    return $acciones;
}


