-- Migración: Sistema de Intereses por Mora en Cuentas Corrientes
-- Fecha: 08/03/2026
-- Versión: 1.0

-- 1. Agregar campo fecha_vencimiento a tabla ctacte
ALTER TABLE `ctacte`
ADD COLUMN `fecha_vencimiento` date DEFAULT NULL 
COMMENT 'Fecha de vencimiento del movimiento (para cálculo de intereses)'
AFTER `fecha`;

-- 2. Agregar índice para búsquedas por fecha de vencimiento
ALTER TABLE `ctacte`
ADD INDEX `idx_fecha_vencimiento` (`fecha_vencimiento`);

-- 3. Actualizar registros existentes (fecha + 30 días por defecto)
-- Esto asigna una fecha de vencimiento a facturas que no la tienen
UPDATE `ctacte`
SET `fecha_vencimiento` = DATE_ADD(`fecha`, INTERVAL 30 DAY)
WHERE `fecha_vencimiento` IS NULL;

-- 4. Crear tabla de configuración de intereses
CREATE TABLE IF NOT EXISTS `configuracion_intereses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `tasa_mensual` decimal(5,2) DEFAULT '3.00' COMMENT 'Tasa de interés mensual (ej: 3.00 = 3%)',
  `dias_gracia` int DEFAULT '0' COMMENT 'Días de gracia antes de aplicar intereses',
  `aplicar_automatico` tinyint(1) DEFAULT '0' COMMENT '1 = Aplicar automáticamente, 0 = Manual',
  `frecuencia` enum('DIARIA','SEMANAL','MENSUAL') DEFAULT 'DIARIA' COMMENT 'Frecuencia de cálculo',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Insertar configuración por defecto para cada empresa existente
INSERT INTO `configuracion_intereses` (empresa_id, tasa_mensual, dias_gracia, aplicar_automatico, frecuencia, activo)
SELECT id, 3.00, 0, 0, 'DIARIA', 1
FROM `empresas`
WHERE id NOT IN (SELECT empresa_id FROM configuracion_intereses WHERE empresa_id IS NOT NULL);

-- 6. Crear tabla de registro de intereses generados (auditoría)
CREATE TABLE IF NOT EXISTS `intereses_generados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `monto_interes` decimal(15,2) NOT NULL,
  `saldo_utilizado` decimal(15,2) NOT NULL COMMENT 'Saldo sobre el que se calculó',
  `dias_mora` int NOT NULL,
  `tasa_aplicada` decimal(5,2) NOT NULL,
  `fecha_calculo` date NOT NULL,
  `fecha_aplicacion` date DEFAULT NULL COMMENT 'Fecha en que se registró en ctacte',
  `usuario_id` int DEFAULT NULL,
  `observaciones` text,
  PRIMARY KEY (`id`),
  KEY `idx_intereses_cliente` (`id_cliente`),
  KEY `idx_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_intereses_generados_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Verificar cambios
SELECT 'Migración completada: Sistema de intereses por mora' as mensaje;
SELECT COUNT(*) as 'Registros ctacte actualizados' FROM ctacte WHERE fecha_vencimiento IS NOT NULL;
SELECT COUNT(*) as 'Empresas con configuración' FROM configuracion_intereses;