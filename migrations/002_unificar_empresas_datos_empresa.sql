-- ============================================================
-- MIGRACIÓN 002: Unificar tablas empresas y datos_empresa
-- ============================================================
-- Fecha: 2026-07-17
-- Descripción: Agrega columnas faltantes a la tabla empresas,
-- migra los datos desde datos_empresa y elimina la tabla duplicada.
-- NOTA: Si ya se ejecutó antes (vía script PHP), algunas columnas
-- pueden existir. En ese caso, el ALTER TABLE fallará por columna
-- duplicada y se debe continuar con el UPDATE y DROP.
-- ============================================================

-- 1. Verificar si datos_empresa existe antes de migrar
--    (Si la tabla ya fue eliminada, la migración ya se aplicó)

-- 2. Agregar columnas faltantes a empresas (una por una para mejor control de errores)
ALTER TABLE empresas ADD COLUMN ingresos_brutos VARCHAR(50) DEFAULT NULL AFTER condicion_iva;
ALTER TABLE empresas ADD COLUMN inicio_actividades DATE DEFAULT NULL AFTER ingresos_brutos;
ALTER TABLE empresas ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER inicio_actividades;

-- 3. Migrar datos desde datos_empresa a empresas (solo si la tabla origen existe)
INSERT INTO empresas (
    id, nombre_fantasia, razon_social, cuit, condicion_iva,
    ingresos_brutos, inicio_actividades, logo_path,
    direccion, localidad, telefono, activa
)
SELECT
    1,
    COALESCE(de.nombre_fantasia, e.nombre_fantasia),
    COALESCE(de.razon_social, e.razon_social),
    COALESCE(de.cuit, e.cuit),
    COALESCE(de.condicion_iva, e.condicion_iva),
    de.ingresos_brutos,
    de.inicio_actividades,
    de.logo_path,
    COALESCE(de.direccion, e.direccion),
    COALESCE(de.localidad, e.localidad),
    COALESCE(de.telefono, e.telefono),
    1
FROM datos_empresa de
LEFT JOIN empresas e ON e.id = 1
WHERE de.id = 1
ON DUPLICATE KEY UPDATE
    nombre_fantasia    = VALUES(nombre_fantasia),
    razon_social       = VALUES(razon_social),
    cuit               = VALUES(cuit),
    condicion_iva      = VALUES(condicion_iva),
    ingresos_brutos    = VALUES(ingresos_brutos),
    inicio_actividades = VALUES(inicio_actividades),
    logo_path          = VALUES(logo_path),
    direccion          = VALUES(direccion),
    localidad          = VALUES(localidad),
    telefono           = VALUES(telefono);

-- 4. Eliminar tabla datos_empresa (ya no necesaria)
DROP TABLE IF EXISTS datos_empresa;
