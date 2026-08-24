-- Migración: Separar permisos de páginas y funciones
-- Fecha: 2026-05-08

-- 1. Agregar columna 'tipo' a la tabla modulos
ALTER TABLE `modulos`
ADD COLUMN `tipo` ENUM('pagina', 'funcion') DEFAULT 'pagina' 
AFTER `seccion`;

-- 2. Actualizar datos existentes basándose en la sección
-- Las secciones de "Seguridad" y "Configuración" suelen contener funciones
UPDATE `modulos` 
SET `tipo` = 'funcion' 
WHERE `seccion` IN ('Seguridad', 'Configuración', 'Procesos')
   OR `archivo` LIKE 'ajax/%'
   OR `archivo` LIKE 'procesos/%'
   OR `archivo` LIKE 'funciones/%';

-- 3. Crear índice para mejorar consultas por tipo
ALTER TABLE `modulos`
ADD INDEX `idx_tipo` (`tipo`);

-- 4. Actualizar permisos_rol para soportar ambos tipos (ya está listo, solo documentación)
-- La tabla permisos_rol ya tiene modulo_id que referencia a modulos.id

-- 5. Actualizar permisos_usuario para soportar ambos tipos (ya está listo, solo documentación)
-- La tabla permisos_usuario ya tiene modulo_id que referencia a modulos.id