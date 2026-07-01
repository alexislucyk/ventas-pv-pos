-- Migration: crear tabla empresas
-- Fecha: 2026-06-30

CREATE TABLE empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_fantasia VARCHAR(100) NOT NULL,
    razon_social VARCHAR(100),
    cuit VARCHAR(20) UNIQUE,
    condicion_iva VARCHAR(50),
    direccion VARCHAR(255) NOT NULL,
    localidad VARCHAR(255) NOT NULL,
    telefono VARCHAR(50) NOT NULL,
    activa TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;