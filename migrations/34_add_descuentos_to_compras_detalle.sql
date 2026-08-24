-- Migration: agregar columna descuento a compras_detalle
-- Fecha: 2026-08-21

ALTER TABLE compras_detalle 
ADD COLUMN descuento DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER p_unit;
