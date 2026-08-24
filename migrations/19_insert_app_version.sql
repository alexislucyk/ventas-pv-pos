-- Migración 19: Insertar versión por defecto de la aplicación
-- Esta migración agrega la configuración inicial de versión si no existe

INSERT INTO configuracion (clave, valor) 
VALUES ('app_version', '1.0.0')
ON DUPLICATE KEY UPDATE valor = '1.0.0';