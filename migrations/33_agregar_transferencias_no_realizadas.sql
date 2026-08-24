-- ============================================================
-- MIGRACIÓN 33: Transferencias "No realizadas" + resolución
-- Fecha: 19/08/2026
-- ============================================================
-- Objetivo: Permitir marcar una transferencia como NO realizada
-- (nunca llegó al banco) y registrar qué se decidió hacer con la
-- venta asociada + una observación.
--
-- transferencia_validada pasa a tener 3 estados:
--   0 = Pendiente de validación
--   1 = Validada (acreditada en el banco)
--   2 = No realizada
--
-- transferencia_no_realizada_accion guarda la resolución aplicada:
--   ANULADA   = La venta se anuló y se reintegró el stock
--   CTACTE    = La venta pasó a deuda (cuenta corriente)
--   PENDIENTE = La venta quedó como está, solo se dejó el comentario
--   REVERSADA = Se revirtió un pago de cta.cte. recibido por transferencia

ALTER TABLE movimientos
    MODIFY COLUMN `transferencia_validada` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0=Pendiente, 1=Validada/acreditada, 2=No realizada',
    ADD COLUMN `transferencia_no_realizada_accion` VARCHAR(20) DEFAULT NULL
        COMMENT 'Resolución aplicada: ANULADA, CTACTE, PENDIENTE, REVERSADA'
        AFTER `transferencia_comprobante`,
    ADD COLUMN `transferencia_observacion` TEXT DEFAULT NULL
        COMMENT 'Observación/comentario al marcar la transferencia como no realizada'
        AFTER `transferencia_no_realizada_accion`;