-- Migracion 52: estado de compras (Activa / Anulada)
-- Fecha: 05/10/2026
--
-- Motivo:
--   El sistema no tenia forma de anular una compra: se podia registrar pero no
--   revertirla. Se agrega la columna `estado` siguiendo la misma convencion que
--   `ventas.estado` ('Activa' | 'Anulada'), de modo que la anulacion MARCA la
--   compra en vez de borrarla (se conserva el historial para auditoria).
--
--   La anulacion de compras rapidas (es_sin_detalle = 1) revierte ademas:
--     - los asientos de ctacte_proveedores vinculados (compra_id)
--     - el EGRESO de caja, mediante un asiento INGRESO inverso
--   Las compras CON detalle (es_sin_detalle = 0) no se pueden anular con esta
--   opcion porque ademas afectan stock y compras_detalle.
--
-- Reversion:
--   ALTER TABLE compras DROP INDEX idx_compras_estado;
--   ALTER TABLE compras DROP COLUMN estado;
--
-- Idempotente: puede ejecutarse en cualquier entorno (chequeo INFORMATION_SCHEMA).

-- 1.1. Columna compras.es_sin_detalle (si no existe; requerida por compras rapidas)
SET @existe_es_sin_detalle := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compras' AND COLUMN_NAME = 'es_sin_detalle');
SET @sql := IF(@existe_es_sin_detalle = 0,
    'ALTER TABLE compras ADD COLUMN es_sin_detalle TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = compra rapida sin detalle. 0 = compra con detalle'' AFTER usuario_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.2. Columna compras.estado (despues de es_sin_detalle)
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compras' AND COLUMN_NAME = 'estado');
SET @sql := IF(@existe = 0,
    'ALTER TABLE compras ADD COLUMN estado ENUM(''Activa'',''Anulada'') NOT NULL DEFAULT ''Activa'' COMMENT ''Activa = vigente. Anulada = revertida, no participa en totales'' AFTER es_sin_detalle',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Indice para filtrar por estado en el historial
SET @existe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compras' AND INDEX_NAME = 'idx_compras_estado');
SET @sql := IF(@existe = 0,
    'ALTER TABLE compras ADD INDEX idx_compras_estado (empresa_id, estado)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Registrar migracion aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '52')
    ON DUPLICATE KEY UPDATE valor = '52';

SELECT 'Migracion 52 aplicada: compras.estado (Activa/Anulada)' AS mensaje;
