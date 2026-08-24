-- Migración: Observaciones en ventas
-- Fecha: 13/08/2026
-- Propósito: Permitir registrar observaciones opcionales en cada venta.
-- Las observaciones se muestran también en el ticket cuando tienen datos.

ALTER TABLE ventas
ADD COLUMN observaciones TEXT NULL COMMENT 'Observaciones opcionales de la venta'
AFTER usuario;
