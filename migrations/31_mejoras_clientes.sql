-- =============================================================
-- 31_mejoras_clientes.sql
-- Mejoras del módulo de Clientes:
--   1. Nuevas columnas email y localidad (habilitadas en form/CRUD)
--   2. Índice en clientes para acelerar búsqueda y listado
--   3. Índice en ctacte (id_cliente, empresa_id) para el saldo en listado
-- =============================================================

ALTER TABLE clientes
    ADD COLUMN email VARCHAR(191) NULL DEFAULT NULL AFTER relacion,
    ADD COLUMN localidad VARCHAR(191) NULL DEFAULT NULL AFTER email;

-- Búsqueda por apellido + filtrado por empresa (LIKE 'A%')
ALTER TABLE clientes
    ADD INDEX idx_clientes_empresa_apellido (empresa_id, apellido(191));

-- Saldo de cuenta corriente: WHERE id_cliente = ? AND empresa_id = ?
ALTER TABLE ctacte
    ADD INDEX idx_ctacte_cliente_empresa (id_cliente, empresa_id);