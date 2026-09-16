-- Migracion: Limpieza de tablas obsoletas (paridad de esquema pos_dev / pos_prod)
-- Fecha: 16/09/2026
--
-- Motivo:
--   pos_dev y pos_prod tenian tablas vestigiales de funcionalidades abandonadas
--   o ya reemplazadas. Todas estan VACIAS (0 filas), no son referenciadas por el
--   codigo de la aplicacion ni por triggers/vistas, y generaban ruido al comparar
--   los esquemas de ambos entornos; ese ruido fue justamente lo que oculto que
--   produccion no tenia aplicada la migracion 33 (transferencias no realizadas).
--
--   - empresas_autorizadas / empresas_autorizadas_proveedores / log_accesos_remotos
--     Existian SOLO en pos_dev (0 filas). Pertenecen a una funcionalidad de
--     "empresas autorizadas / accesos remotos" que quedo abandonada: ninguna
--     pagina, ajax, proceso ni migracion las usa (solo figuran en backups).
--   - proveedores_autorizados_usuario
--     Existia SOLO en pos_prod (0 filas). Fue reemplazada por la tabla global
--     proveedores_autorizados (migracion 25), que ya copio sus datos. Como
--     consecuencia, la migracion 25 ya no puede re-ejecutarse (su origen de
--     datos deja de existir): queda como migracion historica.
--
-- Reversion:
--   El DDL exacto de las 4 tablas esta en los backups versionados del repositorio
--   (pos_dev/backups/backup__2026-09-03_08-45-59.sql y
--    pos_prod/backups/backup__2026-09-15_19-52-40.sql).
--
-- Idempotente: puede ejecutarse en cualquier entorno (IF EXISTS).
-- El orden respeta las claves foraneas internas (hijas primero).

DROP TABLE IF EXISTS empresas_autorizadas_proveedores;
DROP TABLE IF EXISTS log_accesos_remotos;
DROP TABLE IF EXISTS empresas_autorizadas;
DROP TABLE IF EXISTS proveedores_autorizados_usuario;
