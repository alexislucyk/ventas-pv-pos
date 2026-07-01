-- Migration: agregar empresa_id a proveedores
-- Fecha: 2026-06-30

ALTER TABLE proveedores 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER cod_prov,
ADD CONSTRAINT fk_proveedores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;