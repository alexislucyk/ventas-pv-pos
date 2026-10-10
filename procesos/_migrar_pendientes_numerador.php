<?php
// procesos/_migrar_pendientes_numerador.php
// MIGRACIÓN ÚNICA: renumera las ventas en estado 'Pendiente' que quedaron con
// el numerador normal de ventas (n_documento < VENTA_PENDIENTE_BASE) para que
// pasen a usar el numerador propio de pendientes (>= VENTA_PENDIENTE_BASE).
//
// También re-enlaza su detalle en ventas_detalle (mismo n_documento + empresa_id).
// Es seguro porque una venta PENDIENTE no tiene movimientos de caja, asientos en
// ctacte ni planes de cuotas (todo eso se genera recién al finalizar).
//
// Es idempotente: si no queda ninguna pendiente fuera del rango, no hace nada.
//
// Uso: php procesos/_migrar_pendientes_numerador.php

$_SERVER['SCRIPT_NAME']    = '/pos_dev/procesos/_migrar_pendientes_numerador.php';
$_SERVER['DOCUMENT_ROOT']  = 'c:/laragon/www';

require __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/numeradores.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "[FAIL] Sin conexión a la base de datos.\n";
    exit(1);
}

try {
    $pendientes = $pdo->query(
        "SELECT id, empresa_id, n_documento
         FROM ventas
         WHERE estado = 'Pendiente' AND n_documento < " . (int)VENTA_PENDIENTE_BASE . "
         ORDER BY n_documento"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendientes)) {
        echo "OK: no hay pendientes fuera del numerador propio. Nada que migrar.\n";
        exit(0);
    }

    echo "Pendientes a migrar: " . count($pendientes) . "\n";

    $pdo->beginTransaction();

    $stUpdVenta  = $pdo->prepare("UPDATE ventas SET n_documento = ? WHERE id = ?");
    $stUpdDetalle = $pdo->prepare("UPDATE ventas_detalle SET n_documento = ? WHERE n_documento = ? AND empresa_id = ?");
    // El próximo número de pendientes se calcula con el MISMO helper del runtime
    // para que migración y guardado normal no pisan rangos.
    $stCheckRefs = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM movimientos   WHERE detalle LIKE CONCAT('%VENTA N° ', ?)) AS movs,
            (SELECT COUNT(*) FROM ctacte        WHERE n_documento = ?) AS cc,
            (SELECT COUNT(*) FROM cuotas_seguimiento cs JOIN ventas v ON v.id = cs.id_venta WHERE v.id = ?) AS cuotas"
    );

    foreach ($pendientes as $p) {
        $id    = (int)$p['id'];
        $emp   = (int)$p['empresa_id'];
        $viejo = (int)$p['n_documento'];

        // Salvaguarda: si la pendiente tuviera referencias en caja/ctacte/cuotas
        // (no debería), no se renumera para no romperlas.
        $stCheckRefs->execute([$viejo, $viejo, $id]);
        $refs = $stCheckRefs->fetch();
        if ((int)$refs['movs'] > 0 || (int)$refs['cc'] > 0 || (int)$refs['cuotas'] > 0) {
            echo "  [AVISO] Venta id=$id N° $viejo tiene referencias (movs={$refs['movs']}, cc={$refs['cc']}, cuotas={$refs['cuotas']}); NO se renumera.\n";
            continue;
        }

        $nuevo = venta_siguiente_n($pdo, true); // numerador de pendientes

        $stUpdDetalle->execute([$nuevo, $viejo, $emp]);
        $det = $stUpdDetalle->rowCount();
        $stUpdVenta->execute([$nuevo, $id]);

        echo "  id=$id empresa=$emp: N° $viejo -> $nuevo (detalle: $det fila(s) re-enlazada(s))\n";
    }

    $pdo->commit();
    echo "OK: migración completada.\n";
    exit(0);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "[FAIL] EXCEPCIÓN: " . $e->getMessage() . "\n";
    exit(1);
}
