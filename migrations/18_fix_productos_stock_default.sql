-- Migration: Fix productos.stock default to avoid 1364
-- Fecha: 2026-07-03
-- Problema: `productos.stock` existe como NOT NULL sin DEFAULT en la BD real.
-- El código actual inserta productos sin stock (stock se maneja en tabla `stocks`).
-- Solución: setear DEFAULT 0 a productos.stock.

ALTER TABLE productos
  MODIFY stock double NOT NULL DEFAULT 0;

