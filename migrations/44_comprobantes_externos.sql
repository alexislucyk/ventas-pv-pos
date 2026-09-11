-- =============================================================================
-- Migración: Comprobantes Externos
-- Fecha: 2026-09-09
-- Propósito: Permitir asociar ventas internas a facturas emitidas externamente
-- =============================================================================

-- 1. TABLA: comprobantes_externos
CREATE TABLE IF NOT EXISTS `comprobantes_externos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `punto_venta` INT NOT NULL COMMENT 'Punto de venta del comprobante externo',
  `n_comprobante` INT NOT NULL COMMENT 'Número de comprobante externo',
  `tipo_comprobante` INT NOT NULL DEFAULT 11 COMMENT '1=Factura A, 6=Factura B, 11=Factura C, etc.',
  `fecha_emision` DATE NOT NULL,
  `total_comprobante` DECIMAL(15,2) NOT NULL,
  `cuit_cliente` VARCHAR(20) NOT NULL COMMENT 'CUIT del cliente facturado',
  `razon_social_cliente` VARCHAR(255) NOT NULL,
  `cond_iva` VARCHAR(50) DEFAULT NULL COMMENT 'Responsable inscripto, monotributo, etc.',
  `observaciones` TEXT NULL,
  `fecha_carga` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Cuándo se registró en el sistema',
  `usuario_carga` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ce_empresa` (`empresa_id`),
  KEY `idx_ce_comprobante` (`empresa_id`, `punto_venta`, `n_comprobante`, `tipo_comprobante`),
  KEY `idx_ce_fecha` (`empresa_id`, `fecha_emision`),
  KEY `idx_ce_cuit` (`empresa_id`, `cuit_cliente`),
  CONSTRAINT `fk_ce_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- 2. TABLA: venta_comprobante_externo (relación N:1)
CREATE TABLE IF NOT EXISTS `venta_comprobante_externo` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `venta_id` INT NOT NULL COMMENT 'ID de la venta en tabla ventas',
  `comprobante_externo_id` INT NOT NULL COMMENT 'ID del comprobante externo',
  `monto_asignado` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto de esta venta asignado al comprobante',
  `fecha_asociacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `usuario_asociacion` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vce_venta` (`venta_id`, `empresa_id`) COMMENT 'Una venta solo puede estar asociada a un comprobante externo',
  KEY `idx_vce_comprobante` (`comprobante_externo_id`),
  KEY `idx_vce_empresa` (`empresa_id`),
  CONSTRAINT `fk_vce_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vce_comprobante` FOREIGN KEY (`comprobante_externo_id`) REFERENCES `comprobantes_externos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vce_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- 3. TRIGGER: Validar que no se exceda el total del comprobante
DELIMITER $$
CREATE TRIGGER `trg_validar_monto_comprobante`
BEFORE INSERT ON `venta_comprobante_externo`
FOR EACH ROW
BEGIN
    DECLARE total_asignado DECIMAL(15,2);
    DECLARE total_comprobante DECIMAL(15,2);
    
    -- Obtener el total del comprobante
    SELECT total_comprobante INTO total_comprobante
    FROM comprobantes_externos
    WHERE id = NEW.comprobante_externo_id AND empresa_id = NEW.empresa_id;
    
    -- Calcular la suma de montos ya asignados (excluyendo la venta actual)
    SELECT COALESCE(SUM(monto_asignado), 0) INTO total_asignado
    FROM venta_comprobante_externo
    WHERE comprobante_externo_id = NEW.comprobante_externo_id 
      AND empresa_id = NEW.empresa_id
      AND venta_id != NEW.venta_id;
    
    -- Verificar que no se exceda
    IF (total_asignado + NEW.monto_asignado) > total_comprobante THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Error: El monto asignado excede el total disponible del comprobante externo.';
    END IF;
END$$
DELIMITER ;

-- 4. TRIGGER para UPDATE (misma validación)
DELIMITER $$
CREATE TRIGGER `trg_validar_monto_comprobante_update`
BEFORE UPDATE ON `venta_comprobante_externo`
FOR EACH ROW
BEGIN
    DECLARE total_asignado DECIMAL(15,2);
    DECLARE total_comprobante DECIMAL(15,2);
    
    SELECT total_comprobante INTO total_comprobante
    FROM comprobantes_externos
    WHERE id = NEW.comprobante_externo_id AND empresa_id = NEW.empresa_id;
    
    SELECT COALESCE(SUM(monto_asignado), 0) INTO total_asignado
    FROM venta_comprobante_externo
    WHERE comprobante_externo_id = NEW.comprobante_externo_id 
      AND empresa_id = NEW.empresa_id
      AND id != NEW.id;
    
    IF (total_asignado + NEW.monto_asignado) > total_comprobante THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Error: El monto asignado excede el total disponible del comprobante externo.';
    END IF;
END$$
DELIMITER ;

-- 5. Registrar módulo en tabla modulos (si no existe)
INSERT INTO modulos (nombre, archivo, icono, seccion, tipo)
SELECT 'Comprobantes Externos', 'pages/comprobantes_externos.php', 'fas fa-file-invoice', 'Transacciones', 'pagina'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'pages/comprobantes_externos.php');

-- 6. Registrar permisos para el rol admin (usando columna 'rol' segun estructura actual)
INSERT INTO permisos_rol (rol, empresa_id, modulo_id)
SELECT 'admin', 1, id
FROM modulos 
WHERE archivo = 'pages/comprobantes_externos.php'
AND NOT EXISTS (
    SELECT 1 FROM permisos_rol pr 
    JOIN modulos m ON pr.modulo_id = m.id 
    WHERE pr.rol = 'admin' AND m.archivo = 'pages/comprobantes_externos.php'
);

-- 7. NOTA: Los triggers deben crearse ejecutando el script:
--    procesos/crear_triggers_comprobantes.php
--    (No se incluyen aqui porque requieren DELIMITER que no es compatible con PDO)
