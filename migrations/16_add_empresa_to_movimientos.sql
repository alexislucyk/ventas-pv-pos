-- Migration: agregar empresa_id y sucursal_id a movimientos
-- Fecha: 2026-06-30

ALTER TABLE movimientos 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER empresa_id,
ADD CONSTRAINT fk_movimientos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_movimientos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE;