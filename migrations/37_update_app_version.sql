-- Migration 37: Sincronizar versión de la aplicación con los tags de Git
-- La constante APP_VERSION ahora se lee de .env (2.7.1 = tag v2.7.1).
-- Esta migración actualiza el valor en BD para mantenerlos coherentes.
-- Fecha: 2026-08-24

INSERT INTO configuracion (clave, valor)
VALUES ('app_version', '2.7.1')
ON DUPLICATE KEY UPDATE valor = '2.7.1';

-- Registrar esta migración como aplicada
INSERT INTO configuracion (clave, valor)
VALUES ('ultima_migracion_aplicada', '37')
ON DUPLICATE KEY UPDATE valor = '37';