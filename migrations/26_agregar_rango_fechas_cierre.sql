-- Migración: Agregar soporte para cierres de múltiples días
-- Fecha: 08/08/2026
-- Descripción: Agrega campos fecha_desde y fecha_hasta a cierres_caja

-- Agregar columnas para rango de fechas en cierres_caja
ALTER TABLE cierres_caja 
ADD COLUMN fecha_desde DATETIME NULL AFTER sucursal_id,
ADD COLUMN fecha_hasta DATETIME NULL AFTER fecha_desde;

-- Actualizar registros existentes: fecha_desde = inicio del día, fecha_hasta = fin del día
UPDATE cierres_caja 
SET fecha_desde = DATE(fecha_cierre),
    fecha_hasta = DATE(fecha_cierre) + INTERVAL 1 DAY - INTERVAL 1 SECOND
WHERE fecha_desde IS NULL;

-- Hacer las columnas NOT NULL después de actualizar
ALTER TABLE cierres_caja 
MODIFY COLUMN fecha_desde DATETIME NOT NULL,
MODIFY COLUMN fecha_hasta DATETIME NOT NULL;

-- Agregar índice para búsquedas por rango de fechas
ALTER TABLE cierres_caja 
ADD INDEX idx_fecha_rango (empresa_id, sucursal_id, fecha_desde, fecha_hasta);

-- Eliminar columna tipo_cierre (ya no es necesaria)
ALTER TABLE cierres_caja 
DROP COLUMN tipo_cierre;
