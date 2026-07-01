-- Migration: agregar empresa_id a tablas de permisos
-- Fecha: 2026-06-30

ALTER TABLE permisos_rol 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD CONSTRAINT fk_permisos_rol_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE permisos_usuario 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER modulo_id,
ADD CONSTRAINT fk_permisos_usuario_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;