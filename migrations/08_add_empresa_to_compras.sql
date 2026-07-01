-- Migration: agregar empresa_id y sucursal_id a compras
-- Fecha: 2026-06-30

ALTER TABLE compras 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER empresa_id,
ADD CONSTRAINT fk_compras_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_compras_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE;

ALTER TABLE compras_detalle 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id;