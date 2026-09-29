-- Migracion 50: intereses por mora calculados sobre el SALDO DEUDOR de la cuenta
-- Fecha: 29/09/2026
--
-- Motivo:
--   El sistema anterior (migracion 21) calculaba interes factura por factura,
--   replayeando pagos e imputaciones, y al aplicar el interes NO movia la base de
--   calculo: aplicar dos veces volvia a cobrar toda la mora desde el vencimiento.
--   El modelo nuevo calcula sobre el SALDO DEUDOR de la cuenta y marca el
--   movimiento de interes con es_interes = 1, de modo que la fecha del ultimo
--   interes aplicado pasa a ser el nuevo corte: los dias ya cobrados no se repiten.
--
--   Ademas se deja de detectar el interes por texto libre (LIKE 'inter%por%mora%')
--   y se pasa a usar la columna ctacte.es_interes, que es confiable.
--
-- Reversion:
--   ALTER TABLE ctacte DROP INDEX idx_ctacte_interes;
--   ALTER TABLE ctacte DROP COLUMN es_interes;
--   ALTER TABLE configuracion_intereses DROP COLUMN modo_calculo;
--   ALTER TABLE configuracion_intereses DROP COLUMN fecha_vigencia;
--
-- Obsoleto a partir de esta migracion (se limpia en el punto 6):
--   - tabla `intereses_generados`: era el registro de auditoria del calculo
--     factura-por-factura. Ahora la auditoria es el propio movimiento de ctacte
--     con es_interes = 1 (las estadisticas del panel se leen de ahi).
--   - columnas `configuracion_intereses.aplicar_automatico` y `.frecuencia`:
--     nunca se usaron en el codigo (no existia el calculo automatico) y la
--     pantalla de configuracion ya no las muestra.
--   - la columna `ctacte.fecha_vencimiento` de los movimientos de interes se
--     pone NULL en el punto 3: un interes no es una factura y no debe aparecer
--     como imputable en la pantalla de pagos.
--
-- REQUISITO DE DESPLIEGUE: hay que desplegar el codigo NUEVO antes de aplicar
-- esta migracion. El modulo de actualizaciones (aplicar_actualizacion) ya lo
-- hace en ese orden: backup -> git reset --hard -> migraciones. Si alguien
-- aplica este .sql a mano sobre una base con el codigo VIEJO, la pantalla de
-- configuracion de intereses y el boton "Aplicar Intereses" van a fallar porque
-- el codigo viejo escribe en aplicar_automatico/frecuencia y en
-- `intereses_generados`.
--
-- Idempotente: puede ejecutarse en cualquier entorno (chequeo INFORMATION_SCHEMA).

-- 1. Columna ctacte.es_interes
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ctacte' AND COLUMN_NAME = 'es_interes');
SET @sql := IF(@existe = 0,
    'ALTER TABLE ctacte ADD COLUMN es_interes TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = movimiento de interes por mora'' AFTER usuario',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Indice para el historial por cuenta y para los cortes de interes
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ctacte' AND INDEX_NAME = 'idx_ctacte_interes');
SET @sql := IF(@existe = 0,
    'ALTER TABLE ctacte ADD INDEX idx_ctacte_interes (empresa_id, id_cliente, es_interes, fecha)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Marcar los intereses ya existentes y quitarles la fecha de vencimiento:
--    un interes no es una factura, no vence ni debe aparecer como pagable.
UPDATE ctacte SET es_interes = 1 WHERE es_interes = 0 AND LOWER(movimiento) LIKE 'inter%por%mora%';
UPDATE ctacte SET fecha_vencimiento = NULL WHERE es_interes = 1 AND fecha_vencimiento IS NOT NULL;

-- 4. Modo de calculo (DIARIO = prorrateo por dia, MENSUAL = solo periodos completos)
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracion_intereses' AND COLUMN_NAME = 'modo_calculo');
SET @sql := IF(@existe = 0,
    'ALTER TABLE configuracion_intereses ADD COLUMN modo_calculo ENUM(''DIARIO'',''MENSUAL'') NOT NULL DEFAULT ''DIARIO'' COMMENT ''DIARIO = prorrateo por dia; MENSUAL = solo periodos de 30 dias completos'' AFTER plazo_fiado_dias',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Fecha de vigencia opcional (NULL = calcular desde el vencimiento mas antiguo impago)
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracion_intereses' AND COLUMN_NAME = 'fecha_vigencia');
SET @sql := IF(@existe = 0,
    'ALTER TABLE configuracion_intereses ADD COLUMN fecha_vigencia DATE NULL COMMENT ''Desde cuando se devengan intereses (NULL = desde el vencimiento mas antiguo impago)'' AFTER modo_calculo',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Limpiar la estructura del modelo anterior.
--    6a) `intereses_generados`: solo se elimina si esta VACIA. Si algun entorno
--        tiene historico de intereses generados, se conserva intacto y se avisa,
--        para no perder datos de intereses ya facturados a alguien.
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'intereses_generados');
SET @sql := IF(@existe = 1,
    'SELECT COUNT(*) INTO @filas_intereses FROM intereses_generados',
    'SELECT 0 INTO @filas_intereses');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Si tiene filas no se borra. El aviso se guarda en una variable y se muestra al
-- final del archivo, porque el modulo de actualizaciones ejecuta el .sql con
-- PDO::exec() y ese camino solo lee el primer juego de resultados del script.
SET @aviso_historico := IF(@filas_intereses > 0, @filas_intereses, 0);
SET @sql := IF(@filas_intereses = 0, 'DROP TABLE IF EXISTS intereses_generados', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

--    6b) Columnas de configuracion que el sistema nunca llego a usar
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracion_intereses' AND COLUMN_NAME = 'aplicar_automatico');
SET @sql := IF(@existe = 1,
    'ALTER TABLE configuracion_intereses DROP COLUMN aplicar_automatico',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracion_intereses' AND COLUMN_NAME = 'frecuencia');
SET @sql := IF(@existe = 1,
    'ALTER TABLE configuracion_intereses DROP COLUMN frecuencia',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Registrar migracion aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '50')
    ON DUPLICATE KEY UPDATE valor = '50';

-- 8. Unico SELECT del archivo, al final para no interferir con lo anterior.
SELECT IF(@aviso_historico > 0,
    CONCAT('Migracion aplicada; intereses_generados tiene ', @aviso_historico,
           ' registro(s) historicos y NO se borro: revisarla y eliminarla a mano'),
    'Migracion completada: intereses sobre saldo deudor') AS mensaje;
