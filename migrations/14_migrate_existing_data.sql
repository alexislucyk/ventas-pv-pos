-- Migration: migrar datos existentes con empresa_id=1
-- Fecha: 2026-06-30

-- Crear empresa por defecto
INSERT INTO empresas (nombre_fantasia, razon_social, cuit, direccion, localidad, telefono) 
VALUES ('Empresa Principal', 'POS Electricidad Lucyk', NULL, 'Dirección Principal', 'Localidad', '000-0000-000');

-- Obtener el ID de empresa creada
SET @empresa_id = LAST_INSERT_ID();

-- Asignar empresa_id a tablas maestras (ya tiene DEFAULT 1, pero aseguramos)
UPDATE usuarios SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE clientes SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE proveedores SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE productos SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE sucursales SET empresa_id = @empresa_id WHERE empresa_id = 1;

-- Asignar empresa_id a tablas transaccionales
UPDATE ventas SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id = 1;
UPDATE ventas_detalle SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE compras SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id = 1;
UPDATE compras_detalle SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE ctacte SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE ctacte_proveedores SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE presupuestos SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE presupuestos_detalle SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE devoluciones SET empresa_id = @empresa_id WHERE empresa_id = 1;
UPDATE devoluciones_detalle SET empresa_id = @empresa_id WHERE empresa_id = 1;

-- Eliminar DEFAULT 1 de las columnas para forzar especificacion
ALTER TABLE usuarios MODIFY empresa_id INT NOT NULL;
ALTER TABLE clientes MODIFY empresa_id INT NOT NULL;
ALTER TABLE proveedores MODIFY empresa_id INT NOT NULL;
ALTER TABLE productos MODIFY empresa_id INT NOT NULL;
ALTER TABLE sucursales MODIFY empresa_id INT NOT NULL;
ALTER TABLE ventas MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;