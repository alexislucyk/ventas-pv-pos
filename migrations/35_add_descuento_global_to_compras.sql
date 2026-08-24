-- Migration: agregar columna descuento_global a compras
-- Fecha: 2026-08-21

ALTER TABLE compras 
ADD COLUMN descuento_global DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_compra;
