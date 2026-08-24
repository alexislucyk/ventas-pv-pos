-- Migration: tabla global de proveedores autorizados
-- Fecha: 2026
-- Descripción: Crea una tabla centralizada de proveedores autorizados SIN vínculo a usuario.
--              Cualquier visitante de la consulta remota verá únicamente estos proveedores.

CREATE TABLE IF NOT EXISTS proveedores_autorizados (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proveedor_nombre VARCHAR(255) NOT NULL,
    empresa_id INT NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_proveedor (proveedor_nombre, empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrar los proveedores existentes desde la tabla vieja (deduplicando por usuario)
INSERT IGNORE INTO proveedores_autorizados (proveedor_nombre, empresa_id)
SELECT DISTINCT TRIM(proveedor_nombre), empresa_id
FROM proveedores_autorizados_usuario
WHERE proveedor_nombre IS NOT NULL AND TRIM(proveedor_nombre) != '';
