-- Migration: agregar empresa_id a usuarios
-- Fecha: 2026-06-30

ALTER TABLE usuarios 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;