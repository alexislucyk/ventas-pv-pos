# TODO - Consulta de Consignaciones Remota (Globalización de Proveedores Autorizados)

## Plan de Implementación

### Objetivo
Unificar los proveedores autorizados en una lista global (sin `usuario_id`), de modo que cualquier visitante de la página de consulta remota vea únicamente los proveedores autorizados.

## Pasos

- [x] 1. Crear migración SQL: tabla `proveedores_autorizados` (id, proveedor_nombre, empresa_id) y migrar datos existentes (deduplicados).
- [x] 2. Modificar `api/proveedores.php`: devolver todos los proveedores de la tabla global.
- [x] 3. Modificar `api/consignaciones.php`: validar que el proveedor esté autorizado globalmente.
- [x] 4. Modificar `pages/consulta_consignaciones_remota.php`: eliminar campo "Código de Usuario"; dropdown con proveedores autorizados globales.
- [x] 5. Modificar `pages/abm_proveedores_autorizados.php`: rediseñar para administrar la lista global.
- [x] 6. Ejecutar la migración en la BD. (Tabla `proveedores_autorizados` creada con 1 registro: TOTTIS)
- [x] 7. Probar los endpoints y la página.

## Nota
- La tabla vieja `proveedores_autorizados_usuario` se mantiene intacta por seguridad.
