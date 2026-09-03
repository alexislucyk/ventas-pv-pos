-- ============================================================
-- Migracion 42: Indices para acelerar reportes que cruzan
-- ventas / ventas_detalle (ej. reporte_movimientos_productos).
--
-- Problema: el JOIN v.n_documento = vd.n_documento no tenia indice
-- en ventas_detalle.n_documento, por lo que MySQL escaneaba TODA la
-- tabla de detalle (filas x filas) por cada venta (nested loop sin
-- indice). Con crecimiento de datos esto degrada exponencialmente.
-- ============================================================

-- Indice para el JOIN ventas -> ventas_detalle (por empresa y documento)
CREATE INDEX idx_vd_empresa_documento
    ON ventas_detalle (empresa_id, n_documento);

-- Indice para filtros de reportes: empresa + estado + rango de fechas
CREATE INDEX idx_v_empresa_estado_fecha
    ON ventas (empresa_id, estado, fecha_venta);

-- Indice para JOIN ventas_detalle -> ventas (busqueda de la cabecera)
CREATE INDEX idx_v_empresa_documento
    ON ventas (empresa_id, n_documento);
