-- Migración: Cambiar n_documento de INT a VARCHAR en ctacte
-- Fecha: 2026-08-31
-- Versión: 1.0
--
-- Problema: La columna n_documento en ctacte es tipo INT, pero la función
-- generarNumeroIntereses() intenta insertar valores alfanuméricos como
-- 'INT-2026-000001' para identificar movimientos de intereses.
-- Solución: Cambiar el tipo de dato a VARCHAR(50) para permitir formatos variados.

-- 1. Verificar si la columna existe y es de tipo INT
SET @columnType := (
    SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ctacte'
      AND COLUMN_NAME = 'n_documento'
);

-- 2. Cambiar el tipo de dato a VARCHAR(50) si es necesario
SET @sql = IF(@columnType = 'int',
    'ALTER TABLE ctacte MODIFY COLUMN n_documento VARCHAR(50) NOT NULL COMMENT ''Número de documento o referencia (factura, recibo, interés)''',
    'SELECT ''La columna n_documento ya no es de tipo INT'' as mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Verificar el cambio
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'ctacte' 
  AND COLUMN_NAME = 'n_documento';

-- 4. Registrar migración aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '41')
    ON DUPLICATE KEY UPDATE valor = '41';
