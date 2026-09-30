<?php
/**
 * core/migraciones_ctacte.php
 *
 * Garantías de esquema de cuenta corriente que el módulo de actualizaciones
 * ejecuta después de aplicar los archivos .sql.
 *
 * Las migraciones PHP del backfill FIFO de imputaciones (48) y de saldos a favor
 * (49) se eliminaron junto con el feature: desde la migración 51 el pago a
 * cuenta corriente se aplica sobre el saldo y esas tablas ya no existen.
 */

/* -------------------------------------------------------------------------
 * Esquema de la migración 50 (intereses por mora sobre el saldo deudor)
 * ---------------------------------------------------------------------- */

/** ¿Existe la columna en la base actual? */
function ccTieneColumna(PDO $pdo, string $tabla, string $columna): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$tabla, $columna]);
    return (int)$s->fetchColumn() > 0;
}

/** ¿Existe el índice en la base actual? */
function ccTieneIndice(PDO $pdo, string $tabla, string $indice): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$tabla, $indice]);
    // Un índice de varias columnas ocupa una fila por columna: se compara > 0.
    return (int)$s->fetchColumn() > 0;
}

/** ¿Existe la tabla en la base actual? */
function ccTieneTabla(PDO $pdo, string $tabla): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$tabla]);
    return (int)$s->fetchColumn() > 0;
}
/**
 * Garantiza el esquema que necesita el cálculo de intereses sobre saldo deudor
 * (migración 50_intereses_saldo_ctacte.sql).
 *
 * El archivo .sql hace exactamente esto, pero desde la consola mysql: si alguien
 * aplicó la 50 a mano a medias, o la base se migró antes de que existieran estas
 * estructuras, el panel no tiene forma de saberlo. Esta función completa lo que
 * falte sentencia por sentencia, sin depender de la consola ni del número de
 * migración registrado en la base.
 *
 * Es idempotente: si el esquema ya está al día no toca nada y devuelve un array
 * vacío. La tabla de auditoría del modelo viejo sólo se elimina si está vacía,
 * para no perder histórico en el entorno que lo tenga.
 *
 * @return string[] acciones realizadas (vacío = ya estaba todo aplicado)
 */
function garantizarEsquemaInteresesCc(PDO $pdo): array {
    $aplicadas = [];

    if (!ccTieneColumna($pdo, 'ctacte', 'es_interes')) {
        $pdo->exec("ALTER TABLE ctacte ADD COLUMN es_interes TINYINT(1) NOT NULL DEFAULT 0 "
                 . "COMMENT '1 = movimiento de interes por mora' AFTER usuario");
        $aplicadas[] = 'ctacte.es_interes creada';
    }

    if (!ccTieneIndice($pdo, 'ctacte', 'idx_ctacte_interes')) {
        $pdo->exec('ALTER TABLE ctacte ADD INDEX idx_ctacte_interes (empresa_id, id_cliente, es_interes, fecha)');
        $aplicadas[] = 'índice idx_ctacte_interes creado';
    }

    // Intereses del modelo anterior: pasarlos a la columna y quitarles la fecha
    // de vencimiento (un interés no es una factura ni debe verse como imputable).
    $n = $pdo->exec("UPDATE ctacte SET es_interes = 1
                     WHERE es_interes = 0 AND LOWER(movimiento) LIKE 'inter%por%mora%'");
    if ($n > 0) {
        $aplicadas[] = "$n interés(es) histórico(s) marcado(s) con es_interes";
    }
    $n = $pdo->exec('UPDATE ctacte SET fecha_vencimiento = NULL
                     WHERE es_interes = 1 AND fecha_vencimiento IS NOT NULL');
    if ($n > 0) {
        $aplicadas[] = "$n interés(es) quedó sin fecha de vencimiento";
    }

    if (ccTieneTabla($pdo, 'configuracion_intereses')) {
        if (!ccTieneColumna($pdo, 'configuracion_intereses', 'modo_calculo')) {
            $pdo->exec("ALTER TABLE configuracion_intereses ADD COLUMN modo_calculo "
                     . "ENUM('DIARIO','MENSUAL') NOT NULL DEFAULT 'DIARIO' "
                     . "COMMENT 'DIARIO = prorrateo por dia; MENSUAL = solo periodos de 30 dias completos' "
                     . "AFTER plazo_fiado_dias");
            $aplicadas[] = 'configuracion_intereses.modo_calculo creada';
        }
        if (!ccTieneColumna($pdo, 'configuracion_intereses', 'fecha_vigencia')) {
            $pdo->exec("ALTER TABLE configuracion_intereses ADD COLUMN fecha_vigencia DATE NULL "
                     . "COMMENT 'Desde cuando se devengan intereses (NULL = desde el vencimiento mas antiguo impago)' "
                     . "AFTER modo_calculo");
            $aplicadas[] = 'configuracion_intereses.fecha_vigencia creada';
        }

        // Columnas que el sistema nunca llegó a usar
        foreach (['aplicar_automatico', 'frecuencia'] as $columna) {
            if (ccTieneColumna($pdo, 'configuracion_intereses', $columna)) {
                $pdo->exec('ALTER TABLE configuracion_intereses DROP COLUMN ' . $columna);
                $aplicadas[] = "configuracion_intereses.$columna eliminada";
            }
        }
    }

    // Auditoría del cálculo factura-por-factura: hoy la auditoría es el propio
    // movimiento de ctacte con es_interes = 1.
    if (ccTieneTabla($pdo, 'intereses_generados')) {
        $filas = (int)$pdo->query('SELECT COUNT(*) FROM intereses_generados')->fetchColumn();
        if ($filas === 0) {
            $pdo->exec('DROP TABLE intereses_generados');
            $aplicadas[] = 'tabla intereses_generados eliminada (estaba vacía)';
        } else {
            $aplicadas[] = "intereses_generados conservada con $filas registro(s) histórico(s): revisarla a mano";
        }
    }

    if (!empty($aplicadas)) {
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '50')
                       ON DUPLICATE KEY UPDATE valor = IF(CAST(valor AS UNSIGNED) < 50, '50', valor)")
            ->execute();
    }

    return $aplicadas;
}

