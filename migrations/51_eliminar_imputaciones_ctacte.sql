-- =============================================================================
-- Migración: 51 - El pago a cuenta corriente se aplica sobre el SALDO de la cuenta
-- Fecha:     2026-09-29
-- Version:   2.15.42
--
-- Propósito:
--   Desde la migración 50 el interés por mora se calcula sobre el saldo deudor de
--   la cuenta, leyendo únicamente debe/haber de ctacte. La imputación
--   pago -> factura (migraciones 48 y 49) dejó de tener sentido: nunca movió
--   debe/haber, sólo guardaba metadatos de "qué factura quedó cubierta".
--
-- Impacto:
--   Se eliminan las dos tablas de metadatos. Ninguna otra tabla tiene FK hacia
--   ellas y el módulo de actualizaciones respalda la BD antes de aplicarlas.
--   El histórico de pagos sigue intacto en ctacte (movimiento 'Pago Cta.Cte.').
--
-- Nota: las migraciones 48 y 49 se conservan como archivo histórico; en una base
--   nueva se aplican y esta 51 las vuelve a eliminar, por eso el DROP es IF EXISTS.
-- =============================================================================

DROP TABLE IF EXISTS ctacte_creditos_a_favor_aplicaciones;
DROP TABLE IF EXISTS ctacte_pagos_imputaciones;
