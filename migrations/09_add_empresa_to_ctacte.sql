-- Migration: agregar empresa_id a ctacte
-- Fecha: 2026-06-30

ALTER TABLE ctacte_proveedores 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER compra_id,
ADD CONSTRAINT fk_ctacte_proveedores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;
