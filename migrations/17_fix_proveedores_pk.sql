-- Migration: Fix proveedores PRIMARY KEY to be composite (cod_prov, empresa_id)
-- Fecha: 2026-07-03
-- 
-- Problema: La PK era solo cod_prov, lo que impedía tener el mismo código
-- de proveedor en diferentes empresas. Al cambiar de empresa, el código
-- sugerido (1) chocaba con el existente de otra empresa.

-- 1. Eliminar FK existente en ctacte_proveedores que referencia proveedores(cod_prov)
ALTER TABLE ctacte_proveedores DROP FOREIGN KEY ctacte_proveedores_ibfk_1;

-- 2. Eliminar la PK actual (solo cod_prov)
ALTER TABLE proveedores DROP PRIMARY KEY;

-- 3. Agregar la nueva PK compuesta (cod_prov, empresa_id)
ALTER TABLE proveedores ADD PRIMARY KEY (cod_prov, empresa_id);

-- 4. Re-agregar la FK en ctacte_proveedores apuntando a la PK compuesta
ALTER TABLE ctacte_proveedores 
ADD CONSTRAINT ctacte_proveedores_ibfk_1 
FOREIGN KEY (id_proveedor, empresa_id) 
REFERENCES proveedores(cod_prov, empresa_id) 
ON UPDATE CASCADE;