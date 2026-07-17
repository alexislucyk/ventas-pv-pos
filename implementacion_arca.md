# Implementación de Facturación Electrónica ARCA (AFIP) en POS_DEV

Este documento detalla paso a paso cómo pasar del **modo simulación (MOCK)** actual
a la **conexión real con los servidores de ARCA/AFIP** usando el SDK ya instalado
(`afipsdk/afip.php` en `vendor/`).

---

## 0. Estado actual del sistema (diagnóstico)

| Componente | Estado | Detalle |
|---|---|---|
| SDK `afipsdk/afip.php` | ✅ Instalado | Está en `vendor/afipsdk/`, autoload presente |
| Tabla `ventas_afip` | ✅ Existe | Usada en `procesar_factura_arca.php`, `facturacion_arca.php`, `generar_pdf_ticket.php`, `resumen_ventas.php` |
| Lógica real de AFIP | ❌ Comentada | Líneas 50-88 de `pages/procesar_factura_arca.php` |
| MOCK de CAE | ⚠️ Activo | Líneas 90-93: CAE fijo `74123456789012`, nro `125` |
| Certificados `.crt` / `.key` | ❌ Faltan | Carpeta `afip_res/` vacía |
| CUIT del emisor | ❌ Hardcodeado | Línea 23: `"20123456789"` (debe venir de BD) |
| Punto de venta (PtoVta) | ❌ Hardcodeado | Fijo en `1` |
| Tipo comprobante | ❌ Hardcodeado | Fijo en `11` (Factura C) |
| Producción / Homologación | ❌ No configurable | Está en `false` dentro del bloque comentado |
| Cálculo de nro comprobante | ❌ Fijo en `1` | Debe usar `GetLastVoucher` para evitar error 10013 |

---

## PASO 1 — Obtener certificados digitales en ARCA

El Web Service de ARCA (WSFEv1) exige autenticación con certificado X.509 y llave
privada firmados por ARCA.

1. **Generar la llave privada y la CSR** (desde una terminal con OpenSSL):
   ```bash
   cd c:\laragon\www\pos_dev\afip_res
   openssl genrsa -out tu_llave.key 2048
   openssl req -new -key tu_llave.key -subj "/C=AR/O=CUIT 20XXXXXXXX/CN=20XXXXXXXX" -out tu_csr.csr
   ```
   > Reemplazar `20XXXXXXXX` por el CUIT real de la empresa (sin guiones).

2. **Solicitar el certificado en el portal de ARCA:**
   - Ingresar a [https://arca.gob.ar](https://arca.gob.ar) (ex AFIP) con CUIT y Clave Fiscal.
   - Ir a *"Certificados y Autorización de Acceso"* → *"Generar Certificado"*.
   - Pegar el contenido del `tu_csr.csr`.
   - Descargar el certificado resultante como `tu_certificado.crt`.

3. **Colocar los archivos** en `c:\laragon\www\pos_dev\afip_res\`:
   ```
   afip_res/
   ├── tu_certificado.crt
   └── tu_llave.key
   ```
   > ⚠️ Nunca subir la llave `.key` a un repositorio público. Agregar `afip_res/*.key`
   > y `afip_res/*.crt` al `.gitignore`.

4. **Verificar vigencia** del certificado:
   ```bash
   openssl x509 -in tu_certificado.crt -noout -enddate
   ```

---

## PASO 2 — Habilitar el servicio de Facturación Electrónica

En el portal de ARCA, con la Clave Fiscal:
- Autorizar al Certificado para el **Web Service WSFEv1** (Facturación Electrónica).
- Verificar que el **Punto de Venta** esté dado de alta como *"Factura Electrónica - Web Services"*
  (no "Manual"). Si no, darlo de alta en *"Administración de Puntos de Venta"*.
- Confirmar la **condición frente al IVA** del emisor (RI → puede A/B/C; Monotributo → solo C).

---

## PASO 3 — Crear archivo de configuración `config/afip_config.php`

Centralizar todos los datos que hoy están hardcodeados. Ejemplo de contenido:

```php
<?php
// config/afip_config.php
// Configuración de conexión a ARCA/AFIP

define('AFIP_RES_FOLDER', dirname(__DIR__) . '/afip_res/');

// === MODO ===
// false = Homologación (pruebas, servidor de test de ARCA)
// true  = Producción (servidor real de ARCA)
define('AFIP_PRODUCTION', false);

// === Credenciales (leer desde BD en runtime, ver PASO 4) ===
// Estas constantes son fallback; lo ideal es cargarlas por empresa/sucursal.
define('AFIP_CUIT_EMISOR', '20XXXXXXXX');          // CUIT real sin guiones
define('AFIP_CERT_FILE', AFIP_RES_FOLDER . 'tu_certificado.crt');
define('AFIP_KEY_FILE',  AFIP_RES_FOLDER . 'tu_llave.key');

// === Punto de venta por defecto ===
// En producción debería venir de la tabla sucursales o una tabla de config.
define('AFIP_PTO_VTA_DEFAULT', 1);
```

> 💡 Recomendación: guardar `punto_venta` y `production` en una tabla de
> configuración por sucursal/empresa para soportar multi-sucursal.

---

## PASO 4 — Modificar `pages/procesar_factura_arca.php`

### 4.1 Reemplazar el CUIT hardcodeado por el de la BD

Actual (línea 23):
```php
$cuit_vendedor = "20123456789";
```

Nuevo:
```php
$stmtEmp = $pdo->prepare("SELECT REPLACE(cuit,'-','') AS cuit_limpio FROM datos_empresa WHERE id = 1 LIMIT 1");
$stmtEmp->execute();
$emp = $stmtEmp->fetch();
$cuit_vendedor = $emp['cuit_limpio'] ?? null;
if (!$cuit_vendedor || strlen($cuit_vendedor) !== 11) {
    throw new Exception("CUIT del emisor no configurado correctamente en datos_empresa.");
}
```

### 4.2 Determinar tipo de comprobante y punto de venta

```php
// Mapeo según condición IVA del cliente y de la empresa
$condicion_cliente = $venta['id_tipo_iva']; // 1=RI, 2=Monotributo, etc. (ajustar a tu tabla)
$tipo_comprobante = 11; // Factura C por defecto (consumidor final / monotributo)
if ($condicion_cliente == 1 /* Responsable Inscripto */) {
    $tipo_comprobante = 1;  // Factura A
} elseif ($condicion_cliente == 2 /* Monotributo con datos */) {
    $tipo_comprobante = 6;  // Factura B
}
$punto_venta = AFIP_PTO_VTA_DEFAULT; // o el de la sucursal
```

### 4.3 Instanciar el SDK y calcular el último comprobante

Reemplazar el bloque MOCK (líneas 90-93) por la lógica real (ya esbozada en
las líneas 50-88 comentadas), con estas correcciones:

```php
if (!class_exists('Afip')) {
    throw new Exception("El SDK de AFIP no está instalado. Ejecute 'composer require afipsdk/afip.php'");
}

$afip = new Afip([
    'CUIT'       => $cuit_vendedor,
    'cert'       => AFIP_CERT_FILE,
    'key'        => AFIP_KEY_FILE,
    'res_folder' => AFIP_RES_FOLDER,
    'production' => AFIP_PRODUCTION,
]);

// Calcular próximo número con GetLastVoucher (EVITA error 10013)
$ultimo = $afip->ElectronicBilling->GetLastVoucher($punto_venta, $tipo_comprobante);
$nro_comprobante = $ultimo + 1;

$data = [
    'CantReg'      => 1,
    'PtoVta'       => $punto_venta,
    'CbteTipo'     => $tipo_comprobante,
    'Concepto'     => 1,
    'DocTipo'      => ($venta['cuit'] != '') ? 80 : (($venta['dni'] != '') ? 96 : 99),
    'DocNro'       => ($venta['cuit'] != '') ? (int)str_replace('-','',$venta['cuit']) : 0,
    'CbteDesde'    => $nro_comprobante,
    'CbteHasta'    => $nro_comprobante,
    'CbteFch'      => date('Ymd'),
    'ImpTotal'     => (float)$venta['total_venta'],
    'ImpTotalConc' => 0,
    'ImpNeto'      => (float)$venta['total_venta'],
    'ImpOpEx'      => 0,
    'ImpIVA'       => 0,
    'ImpTrib'      => 0,
    'MonId'        => 'PES',
    'MonCotiz'     => 1,
];

$res = $afip->ElectronicBilling->CreateVoucher($data);
$cae_simulado = $res['CAE'];
$vto_simulado = $res['CAEVto'];
$nro_comprobante = $res['CbteDesde'];
```

> ⚠️ **Importante:** ARCA no acepta comas en los decimales. Los importes deben
> enviarse como `float` con punto decimal (el `setlocale` ya está en `'C'` en
> `db_config.php`, lo cual ayuda).

### 4.4 El INSERT a `ventas_afip` ya queda igual

El `INSERT` actual (líneas 95-105) ya usa las variables `$cae_simulado`,
`$vto_simulado`, `$nro_comprobante`, por lo que solo cambia el origen de los
datos (ya no es MOCK, sino respuesta real del WS).

---

## PASO 5 — Probar en HOMOLOGACIÓN

1. Dejar `AFIP_PRODUCTION = false`.
2. Usar un **CUIT de prueba** y certificados de homologación (se obtienen con el
   mismo trámite del Paso 1 pero en el entorno de testing de ARCA).
3. Ejecutar una venta de prueba desde `resumen_ventas.php` → "Facturar ARCA".
4. Verificar en `pages/facturacion_arca.php` que aparezca el CAE real y el nro
   de comprobante correlativo.
5. Consultar `arca.md` si aparecen errores (ej. 10013, 10048, 1500, 1000).

---

## PASO 6 — Validaciones y manejo de errores

- **Reintentos:** ante error `-1` (timeout) o `500/600`, reintentar con backoff.
- **Idempotencia:** si el WS devuelve CAE pero falla el INSERT local, guardar el
  CAE para no volver a pedirlo (evita duplicados / error 10013).
- **Log:** registrar en un archivo (`afip_res/log_afip.txt`) cada request/response
  para depurar.
- **Fecha:** ARCA acepta hasta 5 días atrás / 10 adelante. Usar
  `date('Ymd')` del servidor (timezone ya está en `America/Argentina/Buenos_Aires`).

---

## PASO 7 — Pasar a PRODUCCIÓN

Solo cuando homologación funcione 100%:
1. Generar certificados **reales** (Paso 1 con CUIT real, no de prueba).
2. Colocarlos en `afip_res/`.
3. Cambiar `AFIP_PRODUCTION = true` en `config/afip_config.php`.
4. Verificar que `AFIP_CUIT_EMISOR` sea el CUIT real.
5. Hacer una factura de prueba real de bajo importe y confirmar recepción en
   el portal de ARCA (libro de IVA).

---

## Checklist de implementación

- [ ] Generar CSR y obtener certificados `.crt` / `.key`
- [ ] Subir certificados a `afip_res/` y agregarlos al `.gitignore`
- [ ] Habilitar WSFEv1 y punto de venta en portal ARCA
- [ ] Crear `config/afip_config.php` con modo, rutas y constantes
- [ ] En `procesar_factura_arca.php`: leer CUIT real desde `datos_empresa`
- [ ] En `procesar_factura_arca.php`: mapear tipo comprobante (A/B/C) dinámicamente
- [ ] Reemplazar MOCK por llamada real al SDK (`CreateVoucher`)
- [ ] Calcular `CbteDesde` con `GetLastVoucher`
- [ ] Probar en homologación y validar contra `arca.md`
- [ ] Agregar log de requests/responses
- [ ] Cambiar a producción con certificados reales

---

*Nota: El MOCK actual (CAE `74123456789012`, nro `125`) NO debe usarse en
producción bajo ningún concepto, ya que no tiene validez fiscal.*