-- ============================================================
-- Migración 47: `movimientos.monto` con dos decimales
--
-- PROBLEMA:
--   `movimientos.monto` era DECIMAL(10,0), por lo que las ventas con centavos
--   se guardaban redondeadas (13.599,82 -> 13.600 | 233,45 -> 233 | 1.191,04 -> 1.191).
--   Los cierres de caja suman `monto`, mientras el conteo físico del cajón tiene
--   centavos: eso generaba diferencias de centavos TODOS los días.
--   Las columnas `monto_efectivo` y `monto_transferencia` (agregadas para las
--   ventas mixtas) ya eran DECIMAL(10,2).
--
-- SOLUCIÓN:
--   Pasar `monto` a DECIMAL(10,2). Los valores existentes (enteros) se mantienen
--   sin cambios; a partir de ahora se guardan los centavos reales.
--
-- Idempotente: si la columna ya está en DECIMAL(10,2), volver a ejecutarla no
-- altera los datos.
-- ============================================================

ALTER TABLE movimientos
    MODIFY COLUMN monto DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- Registrar migración aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '47')
    ON DUPLICATE KEY UPDATE valor = '47';
