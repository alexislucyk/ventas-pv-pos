-- Migración 49: aplicación automática de pagos no imputados a facturas posteriores.
-- El movimiento contable sigue en ctacte; esta tabla sólo registra qué crédito
-- se utilizó para cubrir cada factura y evita cobrarla nuevamente.
CREATE TABLE IF NOT EXISTS ctacte_creditos_a_favor_aplicaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credito_pago_movimiento_id BIGINT UNSIGNED NOT NULL,
    movimiento_deudor_id BIGINT UNSIGNED NOT NULL,
    empresa_id INT NOT NULL,
    id_cliente INT NOT NULL,
    importe_aplicado DECIMAL(14,2) NOT NULL,
    fecha_aplicacion DATE NOT NULL,
    origen VARCHAR(40) NOT NULL DEFAULT 'reconciliacion_automatica',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credito_factura (credito_pago_movimiento_id, movimiento_deudor_id),
    KEY idx_credito_factura (movimiento_deudor_id, empresa_id, id_cliente),
    KEY idx_credito_pago (credito_pago_movimiento_id),
    KEY idx_credito_fecha (empresa_id, id_cliente, fecha_aplicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
