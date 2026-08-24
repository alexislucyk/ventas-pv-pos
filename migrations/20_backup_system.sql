-- Migración 20: Sistema de Backup
-- Agrega configuraciones para backup automático

INSERT INTO configuracion (clave, valor) VALUES 
('backup_habilitado', '0'),
('backup_frecuencia', 'diario'),
('backup_ruta', ''),
('backup_cantidad', '7')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- Insertar módulo de backup si no existe
INSERT INTO modulos (nombre, archivo, icono, seccion) 
SELECT 'Backup', 'pages/backup.php', 'fa-database', 'Sistema'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'pages/backup.php');