# Informe: Adaptacion para Multi-Empresa con Aislamiento Completo

## Estado actual del schema.sql

El esquema actual tiene **24 tablas** sin ningun concepto de multi-tenancy.

| Aspecto | Estado |
|---------|--------|
| Empresa/Sucursal | sucursales existe pero sin FK; datos_empresa es unico registro global |
| Usuarios | Sin relacion a sucursal/empresa |
| Productos | Stock unico sin aislamiento por sucursal |
| Ventas/compras | Sin sucursal_id ni empresa_id |

## Estrategia: Multi-tenancy con empresa_id

Agregar empresa_id a TODAS las tablas con FK a nueva tabla empresas.

## Modificaciones requeridas

### 1. Nueva tabla empresas

```sql
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
);
```

### 2. Tablas maestras
- usuarios: agregar empresa_id INT NOT NULL, FK a empresas
- clientes: agregar empresa_id INT NOT NULL
- proveedores: agregar empresa_id INT NOT NULL
- productos: agregar empresa_id INT NOT NULL
- sucursales: agregar empresa_id INT NOT NULL

### 3. Tablas transaccionales
- ventas*, ventas_detalle, ventas_afip, ventas_financiacion: empresa_id, sucursal_id
- compras*, compras_detalle: empresa_id, sucursal_id
- ctacte, ctacte_proveedores: empresa_id
- cierres_caja, movimientos: empresa_id, sucursal_id
- presupuestos*, presupuestos_detalle: empresa_id
- devoluciones*, devoluciones_detalle: empresa_id

### 4. Tablas de permisos
- permisos_rol, permisos_usuario: agregar empresa_id INT NOT NULL

### 5. Stock por sucursal (nueva tabla)

```sql
CREATE TABLE stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    sucursal_id INT NOT NULL,
    cod_prod VARCHAR(50) NOT NULL,
    stock_actual DECIMAL(10,2) DEFAULT 0,
    UNIQUE(empresa_id, sucursal_id, cod_prod)
);
```

### 6. Login adaptado

```php
$_SESSION['empresa_id'] = $user['empresa_id'];
```

### 7. Migracion de datos existentes

```sql
INSERT INTO empresas (nombre_fantasia) VALUES ('Empresa Principal');
UPDATE usuarios SET empresa_id = 1;
UPDATE clientes SET empresa_id = 1;
-- repetir para otras tablas
``` 
