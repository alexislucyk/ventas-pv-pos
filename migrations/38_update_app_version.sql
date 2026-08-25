-- Migration 38: Sincronizar versión de la aplicación con los tags de Git
-- La constante APP_VERSION se lee de .env (2.8.0 = tag v2.8.0).
-- Esta migración actualiza el valor en BD para mantenerlos coherentes.
-- Fecha: 2026-08-25

INSERT INTO configuracion (clave, valor)
VALUES ('app_version', '2.8.0')
ON DUPLICATE KEY UPDATE valor = '2.8.0';

-- Registrar esta migración como aplicada
INSERT INTO configuracion (clave, valor)
VALUES ('ultima_migracion_aplicada', '38')
ON DUPLICATE KEY UPDATE valor = '38';
