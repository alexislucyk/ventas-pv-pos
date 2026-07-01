-- Migration: agregar empresa_id a devoluciones
-- Fecha: 2026-06-30

ALTER TABLE devoluciones 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD CONSTRAINT fk_devoluciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE devoluciones_detalle 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id;