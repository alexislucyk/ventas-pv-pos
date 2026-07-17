# TODO - Fix: insertar producto (stock NOT NULL)

- [x] Crear migración SQL para setear `productos.stock` con DEFAULT 0 (o convertirlo a NULL) para evitar `1364 Field 'stock' doesn't have a default value`.
- [ ] Ejecutar migración en BD y probar los endpoints:
  - [ ] `ajax/agregar_producto_rapido.php`
  - [ ] `ajax/cargar_multiples_productos.php`
- [ ] (Opcional) Actualizar código si hubiera INSERTs en otros lugares que sí dependan de `productos.stock`.



