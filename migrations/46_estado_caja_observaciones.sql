-- ============================================================
-- Migración 46: Observaciones en la apertura de caja
--
-- Agrega la columna `observaciones` a `estado_caja` para guardar
-- el texto que se carga en el formulario de apertura de caja
-- (pages/abrir_caja.php).
--
-- Antes, ajax/abrir_caja.php leía el campo `observaciones` pero
-- nunca se persistía (la tabla no tenía la columna), por lo que
-- la observación del usuario se descartaba silenciosamente.
--
-- Idempotente: si la columna ya existe, no hace nada.
-- ============================================================

SET @columnExists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'estado_caja'
      AND COLUMN_NAME = 'observaciones'
);
SET @sql = IF(@columnExists = 0,
    'ALTER TABLE estado_caja ADD COLUMN observaciones TEXT NULL COMMENT ''Observaciones de la apertura de caja'' AFTER saldo_inicial',
    'SELECT ''Columna observaciones ya existe en estado_caja'' as mensaje'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Registrar migración aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '46')
    ON DUPLICATE KEY UPDATE valor = '46';
