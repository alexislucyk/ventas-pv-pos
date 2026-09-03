-- ============================================================
-- Migración 43: Control del QR en el ticket de venta
--
-- Agrega a `empresas` el flag `mostrar_qr_ticket` que permite
-- activar/desactivar el código QR al final del ticket de venta
-- (80mm). El QR apunta al "Sitio Web" de la sucursal principal
-- configurado en Perfil del Negocio (abm_empresa) o, si no se
-- cargó sitio web, al contenido por defecto de
-- funciones/ticket_generator.php (TICKET_QR_CONTENIDO).
--
-- Por defecto queda ACTIVADO (1) para no cambiar el comportamiento
-- actual: las empresas existentes seguirán imprimiendo el QR hasta
-- que se destilde el cheque en Perfil del Negocio.
-- ============================================================

SET @columnExists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'empresas'
      AND COLUMN_NAME = 'mostrar_qr_ticket'
);
SET @sql = IF(@columnExists = 0,
    'ALTER TABLE empresas ADD COLUMN mostrar_qr_ticket TINYINT(1) NOT NULL DEFAULT 1 AFTER logo_path',
    'SELECT ''Columna mostrar_qr_ticket ya existe'' as mensaje'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Registrar migración aplicada
INSERT INTO configuracion (clave, valor) VALUES ('ultima_migracion_aplicada', '43')
    ON DUPLICATE KEY UPDATE valor = '43';
