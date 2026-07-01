-- Migration: agregar empresa_id y sucursal_id a ventas
-- Fecha: 2026-06-30

ALTER TABLE ventas 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER empresa_id,
ADD CONSTRAINT fk_ventas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_ventas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE;

ALTER TABLE ventas_detalle 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id,
ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER empresa_id,
ADD CONSTRAINT fk_ventas_detalle_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE ventas_afip 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id_venta;

ALTER TABLE ventas_financiacion 
ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id_venta;