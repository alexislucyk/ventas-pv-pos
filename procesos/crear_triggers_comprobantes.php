<?php
// procesos/crear_triggers_comprobantes.php
// Script para crear los triggers de validación de comprobantes externos

require_once __DIR__ . '/../config/db_config.php';

try {
    // Eliminar triggers existentes
    $pdo->exec("DROP TRIGGER IF EXISTS trg_validar_monto_comprobante");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_validar_monto_comprobante_update");
    echo "Triggers existentes eliminados.\n";
    
    // Crear trigger para INSERT - version simplificada para debug
    $sql_insert = "CREATE TRIGGER trg_validar_monto_comprobante
    BEFORE INSERT ON venta_comprobante_externo
    FOR EACH ROW
    BEGIN
        DECLARE v_total_asignado DECIMAL(15,2) DEFAULT 0.00;
        DECLARE v_total_comprobante DECIMAL(15,2) DEFAULT 0.00;
        DECLARE v_suma DECIMAL(15,2) DEFAULT 0.00;
        
        SELECT total_comprobante INTO v_total_comprobante
        FROM comprobantes_externos
        WHERE id = NEW.comprobante_externo_id AND empresa_id = NEW.empresa_id;
        
        SELECT COALESCE(SUM(monto_asignado), 0.00) INTO v_total_asignado
        FROM venta_comprobante_externo
        WHERE comprobante_externo_id = NEW.comprobante_externo_id 
          AND empresa_id = NEW.empresa_id
          AND venta_id != NEW.venta_id;
        
        SET v_suma = v_total_asignado + NEW.monto_asignado;
        
        IF v_suma > v_total_comprobante THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Error: Monto excede disponible';
        END IF;
    END";
    
    $pdo->exec($sql_insert);
    echo "Trigger INSERT creado exitosamente.\n";
    
    // Crear trigger para UPDATE
    $sql_update = "CREATE TRIGGER trg_validar_monto_comprobante_update
    BEFORE UPDATE ON venta_comprobante_externo
    FOR EACH ROW
    BEGIN
        DECLARE v_total_asignado DECIMAL(15,2) DEFAULT 0.00;
        DECLARE v_total_comprobante DECIMAL(15,2) DEFAULT 0.00;
        DECLARE v_suma DECIMAL(15,2) DEFAULT 0.00;
        
        SELECT total_comprobante INTO v_total_comprobante
        FROM comprobantes_externos
        WHERE id = NEW.comprobante_externo_id AND empresa_id = NEW.empresa_id;
        
        SELECT COALESCE(SUM(monto_asignado), 0.00) INTO v_total_asignado
        FROM venta_comprobante_externo
        WHERE comprobante_externo_id = NEW.comprobante_externo_id 
          AND empresa_id = NEW.empresa_id
          AND id != NEW.id;
        
        SET v_suma = v_total_asignado + NEW.monto_asignado;
        
        IF v_suma > v_total_comprobante THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Error: El monto asignado excede el total disponible del comprobante externo.';
        END IF;
    END";
    
    $pdo->exec($sql_update);
    echo "Trigger UPDATE creado exitosamente.\n";
    
    echo "\nMigracion completada.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
