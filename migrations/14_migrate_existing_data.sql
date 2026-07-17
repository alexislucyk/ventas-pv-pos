SET FOREIGN_KEY_CHECKS = 0;

-- 1. Crear la empresa principal asegurando el ID 1
INSERT IGNORE INTO empresas (id, nombre_fantasia, razon_social, cuit, direccion, localidad, telefono) 
VALUES (1, 'POS Electricidad Lucyk', 'POS Electricidad Lucyk SRL', NULL, 'Av. San Martín 698', 'Gregoria Pérez de Denis', '000-0000-000');

-- 2. Definir el ID fijo
SET @empresa_id = 1;

-- 3. Vincular TODO el historial al ID 1
UPDATE usuarios SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE clientes SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE proveedores SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE productos SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE sucursales SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;

UPDATE ventas SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE ventas_detalle SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE ventas_afip SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE ventas_financiacion SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE compras SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE compras_detalle SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE ctacte SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE ctacte_proveedores SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE presupuestos SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE presupuestos_detalle SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE devoluciones SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE devoluciones_detalle SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE permisos_rol SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE permisos_usuario SET empresa_id = @empresa_id WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE cierres_caja SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id IS NULL OR empresa_id = 1;
UPDATE movimientos SET empresa_id = @empresa_id, sucursal_id = 1 WHERE empresa_id IS NULL OR empresa_id = 1;

-- 4. Cerrar el candado quitando los DEFAULT y forzando NOT NULL
ALTER TABLE usuarios MODIFY empresa_id INT NOT NULL;
ALTER TABLE clientes MODIFY empresa_id INT NOT NULL;
ALTER TABLE proveedores MODIFY empresa_id INT NOT NULL;
ALTER TABLE productos MODIFY empresa_id INT NOT NULL;
ALTER TABLE sucursales MODIFY empresa_id INT NOT NULL;
ALTER TABLE ventas MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;
ALTER TABLE ventas_detalle MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;
ALTER TABLE ventas_afip MODIFY empresa_id INT NOT NULL;
ALTER TABLE ventas_financiacion MODIFY empresa_id INT NOT NULL;
ALTER TABLE compras MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;
ALTER TABLE compras_detalle MODIFY empresa_id INT NOT NULL;
ALTER TABLE ctacte MODIFY empresa_id INT NOT NULL;
ALTER TABLE ctacte_proveedores MODIFY empresa_id INT NOT NULL;
ALTER TABLE presupuestos MODIFY empresa_id INT NOT NULL;
ALTER TABLE presupuestos_detalle MODIFY empresa_id INT NOT NULL;
ALTER TABLE devoluciones MODIFY empresa_id INT NOT NULL;
ALTER TABLE devoluciones_detalle MODIFY empresa_id INT NOT NULL;
ALTER TABLE permisos_rol MODIFY empresa_id INT NOT NULL;
ALTER TABLE permisos_usuario MODIFY empresa_id INT NOT NULL;
ALTER TABLE cierres_caja MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;
ALTER TABLE movimientos MODIFY empresa_id INT NOT NULL, MODIFY sucursal_id INT NOT NULL;

-- 5. Crear las Claves Foráneas (Restricciones de integridad con RESTRICT por seguridad)
ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE clientes ADD CONSTRAINT fk_clientes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE proveedores ADD CONSTRAINT fk_proveedores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE productos ADD CONSTRAINT fk_productos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE sucursales ADD CONSTRAINT fk_sucursales_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE ctacte ADD CONSTRAINT fk_ctacte_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;
ALTER TABLE ctacte_proveedores ADD CONSTRAINT fk_ctacte_prov_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT;

SET FOREIGN_KEY_CHECKS = 1;