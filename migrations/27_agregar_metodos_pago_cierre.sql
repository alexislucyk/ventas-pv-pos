-- Migración: Agregar campos para métodos de pago adicionales en cierres_caja
-- Fecha: 08/08/2026
-- Descripción: Agrega campos para cheques, tarjetas y otros métodos de pago

-- Agregar columnas para diferentes métodos de pago
ALTER TABLE cierres_caja 
ADD COLUMN ingresos_cheques DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_transf,
ADD COLUMN ingresos_tarjetas DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_cheques,
ADD COLUMN ingresos_otros DECIMAL(10,2) DEFAULT 0.00 AFTER ingresos_tarjetas;

-- Actualizar registros existentes: mover el total de transferencias a ingresos_transf
-- (los registros antiguos ya tienen este valor correcto, solo inicializamos los nuevos campos)
UPDATE cierres_caja 
SET ingresos_cheques = 0,
    ingresos_tarjetas = 0,
    ingresos_otros = 0
WHERE ingresos_cheques IS NULL;