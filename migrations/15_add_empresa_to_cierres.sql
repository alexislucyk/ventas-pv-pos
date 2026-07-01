-- Migration: agregar empresa_id y sucursal_id a cierres_caja
-- Fecha: 2026-06-30

ALTER TABLE cierres_caja 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER empresa_id,
ADD CONSTRAINT fk_cierres_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_cierres_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE;