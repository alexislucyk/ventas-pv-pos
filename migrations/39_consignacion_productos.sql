-- Migration: 39 - Productos en consignación
-- Fecha: 2026-08
-- Descripción: Diferenciación de productos propios vs. en consignación.
--              1. Flag es_consignacion + comisión configurable por producto (NULL = 50/50 global).
--              2. Tablas de remitos de ingreso de consignación (cabecera + detalle).
--              3. Registro de liquidaciones a proveedores.
-- NOTA: Todos los productos existentes quedan en es_consignacion = 0 (propios).
--       Los productos en consignación se marcan manualmente desde Productos.

-- 1. Columnas en productos
ALTER TABLE productos
  ADD COLUMN es_consignacion TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN comision_proveedor DECIMAL(5,2) NULL DEFAULT NULL COMMENT '% de ganancia para el proveedor. NULL = usa 50/50 global';

-- 2. Cabecera de ingreso de mercadería en consignación
--    proveedor_id guarda cod_prov de la tabla proveedores (PK compuesta con empresa_id)
CREATE TABLE IF NOT EXISTS consignaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  proveedor_id INT NOT NULL,
  n_remito VARCHAR(50) DEFAULT NULL,
  fecha_recepcion DATE NOT NULL,
  estado ENUM('Abierta','Liquidada','Cerrada') NOT NULL DEFAULT 'Abierta',
  observaciones TEXT,
  usuario_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consignaciones_empresa (empresa_id),
  KEY idx_consignaciones_proveedor (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Detalle del remito: qué entró, cuánto se devolvió y a qué costo acordado
CREATE TABLE IF NOT EXISTS consignaciones_detalle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consignacion_id INT NOT NULL,
  cod_prod VARCHAR(64) NOT NULL,
  cantidad_recibida DOUBLE NOT NULL DEFAULT 0,
  cantidad_devuelta DOUBLE NOT NULL DEFAULT 0,
  p_costo_acordado DOUBLE NOT NULL DEFAULT 0,
  UNIQUE KEY uq_consignacion_producto (consignacion_id, cod_prod),
  CONSTRAINT fk_consigna_detalle_cab FOREIGN KEY (consignacion_id) REFERENCES consignaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Liquidaciones registradas (trazabilidad del pago al proveedor)
CREATE TABLE IF NOT EXISTS consignaciones_liquidaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  proveedor_id INT NOT NULL,
  fecha_liquidacion DATE NOT NULL,
  desde DATE NOT NULL,
  hasta DATE NOT NULL,
  total_venta DOUBLE NOT NULL DEFAULT 0,
  total_costo DOUBLE NOT NULL DEFAULT 0,
  total_ganancia DOUBLE NOT NULL DEFAULT 0,
  monto_pagar_proveedor DOUBLE NOT NULL DEFAULT 0,
  mi_utilidad DOUBLE NOT NULL DEFAULT 0,
  metodo_pago VARCHAR(20) DEFAULT 'EFECTIVO',
  movimientos_id INT DEFAULT NULL,
  detalle_json TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_liq_empresa (empresa_id),
  KEY idx_liq_proveedor (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;