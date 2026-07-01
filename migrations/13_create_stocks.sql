-- Migration: crear tabla stocks por sucursal
-- Fecha: 2026-06-30

CREATE TABLE stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    sucursal_id INT NOT NULL,
    cod_prod VARCHAR(50) NOT NULL,
    stock_actual DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
    UNIQUE(empresa_id, sucursal_id, cod_prod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrar stock existente de productos a stocks
INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual)
SELECT 1, 1, cod_prod, stock FROM productos;