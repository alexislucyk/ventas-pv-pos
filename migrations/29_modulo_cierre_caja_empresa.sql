-- Migración: habilitación del módulo "Cierre de Caja" por empresa
-- Fecha: 08/11/2026
-- 1 = habilitado (default, mantiene comportamiento actual) | 0 = operar sin cierres
--
-- Si una empresa tiene el módulo deshabilitado (0):
--   - No se exige abrir caja para operar (ventas, compras, etc.).
--   - Las opciones de caja desaparecen del menú y las páginas quedan bloqueadas.
-- Los datos históricos de estado_caja / cierres_caja / movimientos NO se tocan.
--
-- Idempotente: no falla si la columna ya existe.

SET @existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'empresas'
      AND COLUMN_NAME  = 'modulo_cierre_caja'
);

SET @sql = IF(@existe = 0,
    'ALTER TABLE `empresas` ADD COLUMN `modulo_cierre_caja` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1=cierre habilitado; 0=operar sin cierres'' AFTER `activa`',
    'SELECT ''La columna modulo_cierre_caja ya existe; no se realiza cambio.'' AS _info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Valor por defecto para las empresas: 1 (habilitado), preserva el comportamiento actual.
-- (Las empresas existentes ya reciben el DEFAULT 1 por el ALTER anterior).

