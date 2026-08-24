-- ============================================
-- MIGRACIÓN 28: Modelo de caja POR SESIÓN
-- Fecha: 11/08/2026
-- ============================================
-- Objetivo:
--  - Una caja permanece ABIERTA hasta que el usuario la cierra
--    (puede abarcar varios días).
--  - Puede haber varias aperturas/cierres de caja en un mismo día.
--  - La apertura de la caja del día siguiente ya NO es automática:
--    la próxima caja se apertura manualmente y el fondo reservado del
--    último cierre se sugiere como saldo inicial.
--
-- IMPORTANTE: el índice único uk_caja_dia está asociado a las FOREIGN KEYS
-- de la tabla, por lo que primero se eliminan las FKs y se vuelven a crear
-- después de quitar la unicidad.

-- 1. Quitar las FOREIGN KEY que dependen del índice único
ALTER TABLE `estado_caja` DROP FOREIGN KEY `estado_caja_ibfk_1`; -- empresa_id
ALTER TABLE `estado_caja` DROP FOREIGN KEY `estado_caja_ibfk_2`; -- sucursal_id

-- 2. Quitar la unicidad por día (empresa + sucursal + fecha).
--    Antes solo permitía UNA fila por día; con sesiones se necesita
--    poder abrir y cerrar varias veces en el mismo día.
ALTER TABLE `estado_caja` DROP INDEX `uk_caja_dia`;

-- 3. Índice para localizar rápidamente la sesión ABIERTA actual.
ALTER TABLE `estado_caja` ADD INDEX `idx_estado_abierta` (`empresa_id`, `sucursal_id`, `estado`);

-- 4. Recrear las FOREIGN KEY (usan idx_estado_abierta y el índice sucursal_id)
ALTER TABLE `estado_caja`
  ADD CONSTRAINT `estado_caja_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE;
ALTER TABLE `estado_caja`
  ADD CONSTRAINT `estado_caja_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE CASCADE;