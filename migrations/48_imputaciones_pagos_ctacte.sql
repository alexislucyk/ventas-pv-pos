-- Migración 48: relación persistente entre pagos y facturas de cuenta corriente.
-- No modifica debe/haber ni saldos contables; solo guarda metadatos de imputación.
CREATE TABLE IF NOT EXISTS ctacte_pagos_imputaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pago_movimiento_id BIGINT UNSIGNED NOT NULL,
    movimiento_deudor_id BIGINT UNSIGNED NOT NULL,
    empresa_id INT NOT NULL,
    id_cliente INT NOT NULL,
    importe_aplicado DECIMAL(14,2) NOT NULL,
    fecha_imputacion DATE NOT NULL,
    origen VARCHAR(40) NOT NULL DEFAULT 'historico',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_imputacion_pago_movimiento (pago_movimiento_id, movimiento_deudor_id),
    KEY idx_imputacion_factura (movimiento_deudor_id, empresa_id, id_cliente),
    KEY idx_imputacion_pago (pago_movimiento_id),
    KEY idx_imputacion_fecha (empresa_id, id_cliente, fecha_imputacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La migración de datos se ejecuta con:
-- php core/migrar_imputaciones_ctacte.php
