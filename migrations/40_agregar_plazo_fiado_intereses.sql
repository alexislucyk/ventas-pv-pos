-- Migración: Plazo de vencimiento configurable para ventas al fiado
-- Fecha: 2026-08-31
-- Versión: 1.0
--
-- Problema: Las ventas en CUENTA CORRIENTE (fiado) insertaban movimientos en
-- `ctacte` sin `fecha_vencimiento`, por lo que el sistema de intereses por mora
-- (calcularInteresesCliente) nunca los detectaba como vencidos.
-- Solución: Agregar columna editable `plazo_fiado_dias` a `configuracion_intereses`
-- y usarla al crear movimientos de fiado. También se backfillan registros existentes.

-- 1. Agregar columna plazo_fiado_dias a configuracion_intereses
SET @columnExists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configuracion_intereses'
      AND COLUMN_NAME = 'plazo_fiado_dias'
);
SET @sql = IF(@columnExists = 0,
    'ALTER TABLE configuracion_intereses ADD COLUMN plazo_fiado_dias INT NOT NULL DEFAULT 30 COMMENT ''Dias de plazo para vencimiento de ventas al fiado/cta. cte.''',
    'SELECT ''Columna plazo_fiado_dias ya existe'' as mensaje'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Asignar 30 dias como valor por defecto a las configuraciones existentes sin plazo
UPDATE configuracion_intereses
   SET plazo_fiado_dias = 30
 WHERE plazo_fiado_dias IS NULL OR plazo_fiado_dias = 0;

-- 3. Backfill: Asignar fecha_vencimiento a movimientos FACTURA en ctacte que no la tengan
--    Se usa la configuracion existente (30 dias) o 30 por defecto
UPDATE ctacte c
   SET fecha_vencimiento = DATE_ADD(fecha, INTERVAL 30 DAY)
 WHERE c.fecha_vencimiento IS NULL
   AND c.movimiento = 'FACTURA'
   AND c.debe > 0;

-- 4. Verificar cambios
SELECT 'Migración completada: plazo de vencimiento para fiado' as mensaje;
SELECT COUNT(*) as 'Empresas con plazo_fiado configurado' FROM configuracion_intereses WHERE plazo_fiado_dias > 0;
SELECT COUNT(*) as 'Movimientos ctacte con fecha_vencimiento' FROM ctacte WHERE fecha_vencimiento IS NOT NULL;

-- 5. Registrar migración aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '40')
    ON DUPLICATE KEY UPDATE valor = '40';
