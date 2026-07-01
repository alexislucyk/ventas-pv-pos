-- Migration: agregar empresa_id a presupuestos
-- Fecha: 2026-06-30

ALTER TABLE presupuestos 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD CONSTRAINT fk_presupuestos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE presupuestos_detalle 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id;