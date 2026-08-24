-- Migración: Desglose de ventas mixtas en movimientos
-- Fecha: 08/03/2026
-- Propósito: Separar el monto de efectivo y transferencia en ventas mixtas

-- Agregar campos para desglose de pagos
ALTER TABLE movimientos 
ADD COLUMN monto_efectivo DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Monto pagado en efectivo (para ventas mixtas)',
ADD COLUMN monto_transferencia DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Monto pagado por transferencia (para ventas mixtas)';

-- Actualizar registros existentes: si metodo_pago = 'EFECTIVO', monto_efectivo = monto
UPDATE movimientos 
SET monto_efectivo = monto 
WHERE metodo_pago = 'EFECTIVO' 
  AND monto_efectivo = 0;

-- Actualizar registros existentes: si metodo_pago = 'TRANSFERENCIA', monto_transferencia = monto
UPDATE movimientos 
SET monto_transferencia = monto 
WHERE metodo_pago = 'TRANSFERENCIA' 
  AND monto_transferencia = 0;

-- Actualizar registros existentes: buscar el detalle real de cada venta
-- Para ventas MIXTAS, extraer el número de venta del campo detalle y buscar en tabla ventas
UPDATE movimientos m
INNER JOIN ventas v ON (
    -- Extraer número de venta del detalle (formato: "VENTA CONTADO N° 123" o "ENTREGA/PAGO - VENTA N° 123")
    m.empresa_id = v.empresa_id 
    AND m.sucursal_id = v.sucursal_id
    AND CAST(REGEXP_SUBSTR(m.detalle, '[0-9]+') AS UNSIGNED) = v.n_documento
    AND m.metodo_pago = 'MIXTO'
)
SET 
    m.monto_efectivo = v.pago_efectivo,
    m.monto_transferencia = v.pago_transf
WHERE m.metodo_pago = 'MIXTO' 
  AND m.monto_efectivo = 0 
  AND m.monto_transferencia = 0
  AND (v.pago_efectivo > 0 OR v.pago_transf > 0);

-- Para registros que no se pudieron actualizar (porque no tienen venta asociada o no hay detalle)
-- Marcar como advertencia: dejar en 0 y el usuario debe ajustar manualmente
-- NOTA: Revisar los movimientos con metodo_pago = 'MIXTO' y monto_efectivo = 0
-- Estos requieren ajuste manual porque no se pudo determinar el desglose

-- Agregar índice para mejorar consultas por método de pago
-- NOTA: metodo_pago es TEXT, por lo que debemos especificar una longitud para el índice
ALTER TABLE movimientos 
ADD INDEX idx_movimientos_metodo_pago (metodo_pago(50), cerrado, empresa_id, sucursal_id);

-- Comentario: A partir de ahora, las ventas mixtas deben generar dos movimientos separados
-- o completar los campos monto_efectivo y monto_transferencia en el movimiento único