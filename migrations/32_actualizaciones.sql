-- Migración 32: Módulo de Actualizaciones (GitHub)
-- Registra el módulo que permite consultar y aplicar actualizaciones desde GitHub.

INSERT INTO modulos (nombre, archivo, icono, seccion, tipo)
SELECT 'Actualizar Sistema', 'pages/actualizaciones.php', 'fa-sync-alt', 'Sistema', 'pagina'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'pages/actualizaciones.php');

-- Configuración del repositorio de origen (URL del repo, branch objetivo)
INSERT INTO configuracion (clave, valor) VALUES
('actualizar_repo_url', 'https://github.com/alexislucyk/ventas-pv-pos.git'),
('actualizar_repo_owner', 'alexislucyk'),
('actualizar_repo_name', 'ventas-pv-pos'),
('actualizar_branch', 'main')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);