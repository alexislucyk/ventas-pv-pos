-- ============================================================
-- MIGRACIÓN 32: Validación de transferencias
-- Fecha: 19/08/2026
-- ============================================================
-- Objetivo: Permitir marcar manualmente si una venta o pago por
-- transferencia realmente fue acreditado en el banco, registrando
-- quién y cuándo lo validó, más una referencia/comprobante opcional
-- (ej: nº de comprobante, alias CVU, etc.).
--
-- Los campos se agregan a `movimientos`, donde ya se guarda el
-- desglose monto_efectivo / monto_transferencia.

ALTER TABLE movimientos
    ADD COLUMN `transferencia_validada` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=Transferencia validada/acreditada en el banco'
        AFTER `monto_transferencia`,
    ADD COLUMN `transferencia_validada_usuario` VARCHAR(50) DEFAULT NULL
        COMMENT 'Usuario que validó la transferencia'
        AFTER `transferencia_validada`,
    ADD COLUMN `transferencia_validada_fecha` DATETIME DEFAULT NULL
        COMMENT 'Fecha/hora en que se validó la transferencia'
        AFTER `transferencia_validada_usuario`,
    ADD COLUMN `transferencia_comprobante` VARCHAR(100) DEFAULT NULL
        COMMENT 'Referencia/comprobante de la transferencia (opcional)'
        AFTER `transferencia_validada_fecha`;