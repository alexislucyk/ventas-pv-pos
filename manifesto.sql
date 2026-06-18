-- MANIFESTO DE CAMBIOS EN LA BASE DE DATOS
-- Proyecto: POS Electricidad Lucyk

-- [2024-05-20] Creación de tablas para registro histórico de devoluciones con detalle de productos.
CREATE TABLE IF NOT EXISTS devoluciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    op_n INT NOT NULL,                  -- Número de operación (correlativo calculado)
    n_documento_venta INT NOT NULL,     -- Referencia al N° de documento de la venta original
    id_cliente INT DEFAULT 0,
    total_reintegrado DECIMAL(15,2) NOT NULL,
    motivo TEXT,
    fecha DATETIME NOT NULL,
    usuario VARCHAR(100),
    cond_pago VARCHAR(50)               -- CONTADO / CUENTA CORRIENTE
);

CREATE TABLE IF NOT EXISTS devoluciones_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_devolucion INT NOT NULL,         -- FK a devoluciones.id
    cod_prod VARCHAR(50) NOT NULL,
    descripcion VARCHAR(255),
    cantidad DECIMAL(15,2),
    p_unit DECIMAL(15,2),
    subtotal DECIMAL(15,2),
    INDEX (id_devolucion)
);

-- [2024-05-21] Tabla para vincular ventas con Factura Electrónica ARCA
CREATE TABLE IF NOT EXISTS ventas_afip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_venta INT NOT NULL,              -- FK a ventas.id
    cae VARCHAR(20) NOT NULL,           -- Código de Autorización Electrónico
    cae_vto DATE NOT NULL,              -- Vencimiento del CAE
    punto_venta INT NOT NULL,           -- Ej: 0001
    n_comprobante INT NOT NULL,         -- Número correlativo de AFIP
    tipo_comprobante INT NOT NULL,      -- 1=A, 6=B, 11=C, etc.
    fecha_proceso DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(id_venta)
);

-- [2024-05-21] Campos faltantes en tabla clientes para soporte AFIP/ARCA
ALTER TABLE clientes ADD COLUMN dni VARCHAR(20) DEFAULT NULL AFTER apellido;
ALTER TABLE clientes ADD COLUMN id_tipo_iva INT DEFAULT 99 AFTER dni; -- 99 = Consumidor Final

-- [2024-05-22] Soporte para Descuentos en Ventas
ALTER TABLE ventas ADD COLUMN descuento_global DECIMAL(15,2) DEFAULT 0.00 AFTER total_venta;
ALTER TABLE ventas ADD COLUMN tipo_descuento_global ENUM('fijo', 'porcentaje') DEFAULT 'fijo' AFTER descuento_global;

ALTER TABLE ventas_detalle ADD COLUMN descuento_unitario DECIMAL(15,2) DEFAULT 0.00 AFTER p_unit;

-- [2024-05-23] Agregar trazabilidad de usuario en compras
ALTER TABLE compras 
ADD COLUMN usuario_id INT(11) NULL AFTER fecha_operacion,
ADD CONSTRAINT fk_compras_usuario 
FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- [2024-05-24] Soporte para facturas de compra sin detalle (Carga rápida)
ALTER TABLE compras ADD COLUMN fecha_vencimiento DATE NULL AFTER fecha_compra,
                    ADD COLUMN observaciones TEXT NULL AFTER total_compra,
                    ADD COLUMN es_sin_detalle TINYINT(1) DEFAULT 0;

-- [2024-05-24] Registro del nuevo módulo para habilitar el acceso y visualización en el menú
INSERT INTO modulos (nombre, archivo, icono, seccion) 
VALUES ('Factura Rápida (Compras)', 'pages/compras_rapidas.php', 'fas fa-bolt', 'Transacciones');

-- [2024-05-25] Agregar trazabilidad de usuario en Cta. Cte. Proveedores
ALTER TABLE ctacte_proveedores ADD COLUMN usuario_id INT(11) NULL AFTER fecha,
                               ADD COLUMN compra_id INT(11) NULL AFTER usuario_id;

ALTER TABLE ctacte_proveedores ADD CONSTRAINT fk_ctacte_compra 
FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE SET NULL;