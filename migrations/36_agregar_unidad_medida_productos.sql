-- Migration: agregar columna unidad_medida a productos
-- Fecha: 2026-08-22
-- Valores: Unidad (por defecto), Kilogramo, Metro, Litro

ALTER TABLE productos
  ADD COLUMN unidad_medida ENUM('Unidad','Kilogramo','Metro','Litro') NOT NULL DEFAULT 'Unidad' AFTER stock;
