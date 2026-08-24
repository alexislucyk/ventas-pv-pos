-- ============================================
-- MIGRACIÓN: Sistema de Estados de Caja
-- Versión: 2.1.0
-- Fecha: 08/03/2026
-- ============================================

-- 1. Agregar campo para estado de caja en tabla movimientos
ALTER TABLE `movimientos` 
ADD COLUMN `es_fondo_inicial` TINYINT(1) DEFAULT 0 COMMENT '1=Es fondo inicial de caja' 
AFTER `cerrado`;

-- 2. Agregar campo para tipo de cierre en tabla cierres_caja
ALTER TABLE `cierres_caja` 
ADD COLUMN `tipo_cierre` ENUM('DIARIO','PARCIAL') DEFAULT 'DIARIO' 
COMMENT 'Tipo de cierre realizado' 
AFTER `fondo_reservado_vuelto`;

-- 3. Agregar campo para número de cierre (para identificación única)
ALTER TABLE `cierres_caja` 
ADD COLUMN `numero_cierre` INT DEFAULT NULL 
COMMENT 'Número secuencial de cierre por sucursal' 
AFTER `tipo_cierre`;

-- 4. Crear tabla de estado de caja
CREATE TABLE `estado_caja` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `sucursal_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `estado` ENUM('ABIERTA','CERRADA') DEFAULT 'CERRADA',
  `saldo_inicial` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Fondo con el que se abrió la caja',
  `usuario_apertura` VARCHAR(50) DEFAULT NULL,
  `fecha_apertura` DATETIME DEFAULT NULL,
  `usuario_cierre` VARCHAR(50) DEFAULT NULL,
  `fecha_cierre` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_caja_dia` (`empresa_id`, `sucursal_id`, `fecha`),
  FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- 5. Crear tabla de log de auditoría de cierres
CREATE TABLE `cierres_caja_audit` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `cierre_id` INT NOT NULL,
  `accion` ENUM('CREADO','MODIFICADO','ANULADO') NOT NULL,
  `usuario` VARCHAR(50) NOT NULL,
  `fecha_accion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `datos_anteriores` JSON DEFAULT NULL,
  `datos_nuevos` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cierre_id`) REFERENCES `cierres_caja`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- 6. Agregar índices compuestos para mejorar rendimiento
ALTER TABLE `movimientos` 
ADD INDEX `idx_movimientos_empresa_sucursal_cerrado` (`empresa_id`, `sucursal_id`, `cerrado`);

ALTER TABLE `cierres_caja` 
ADD INDEX `idx_cierres_empresa_sucursal_fecha` (`empresa_id`, `sucursal_id`, `fecha_cierre`);

-- 7. Poblar tabla estado_caja con registros históricos (si existen cierres)
INSERT INTO `estado_caja` (`empresa_id`, `sucursal_id`, `fecha`, `estado`, `fecha_cierre`, `usuario_cierre`)
SELECT 
  DISTINCT c.empresa_id, 
  c.sucursal_id, 
  DATE(c.fecha_cierre) as fecha,
  'CERRADA' as estado,
  c.fecha_cierre,
  c.usuario
FROM `cierres_caja` c
WHERE NOT EXISTS (
  SELECT 1 FROM `estado_caja` ec 
  WHERE ec.empresa_id = c.empresa_id 
    AND ec.sucursal_id = c.sucursal_id 
    AND ec.fecha = DATE(c.fecha_cierre)
);

-- 8. Actualizar movimientos de fondo inicial
UPDATE `movimientos` 
SET `es_fondo_inicial` = 1 
WHERE `detalle` LIKE 'FONDO INICIAL%';

-- 9. Agregar campo saldo_inicial a cierres existentes (si hay datos)
UPDATE `cierres_caja` c
SET `saldo_inicial` = (
  SELECT COALESCE(SUM(m.monto), 0)
  FROM `movimientos` m
  WHERE m.empresa_id = c.empresa_id
    AND m.sucursal_id = c.sucursal_id
    AND m.es_fondo_inicial = 1
    AND DATE(m.fecha) = DATE(c.fecha_cierre)
    AND m.cerrado = 1
)
WHERE c.saldo_inicial IS NULL;

-- 10. Crear función para obtener número de cierre
DELIMITER //
CREATE FUNCTION `obtener_numero_cierre`(
  p_empresa_id INT,
  p_sucursal_id INT
) RETURNS INT
DETERMINISTIC
BEGIN
  DECLARE v_numero INT;
  SELECT COALESCE(MAX(numero_cierre), 0) + 1 
  INTO v_numero
  FROM `cierres_caja`
  WHERE empresa_id = p_empresa_id 
    AND sucursal_id = p_sucursal_id;
  RETURN v_numero;
END //
DELIMITER ;