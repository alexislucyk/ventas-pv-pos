-- Migration: agregar empresa_id a sucursales
-- Fecha: 2026-06-30

ALTER TABLE sucursales 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD CONSTRAINT fk_sucursales_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
DROP INDEX `id` ON sucursales,
ADD UNIQUE KEY `unique_sucursal_empresa` (`nombre_sucursal`, `empresa_id`);