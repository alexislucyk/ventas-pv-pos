# Manual de Errores ARCA (ex AFIP) - Facturación Electrónica

Este documento sirve como referencia para interpretar los mensajes de error devueltos por el Web Service de ARCA (WSFEv1) durante el proceso de facturación en el sistema POS.

## 1. Errores de Validación (Datos del Comprobante)

| Código | Significado | Qué hacer / Acción sugerida |
| :--- | :--- | :--- |
| **10001** | El CUIT informado no es válido. | Verificar que el CUIT del emisor (tu empresa) esté bien cargado en `datos_empresa`. |
| **10002** | El tipo de comprobante es inexistente. | El código enviado (ej. 1, 6, 11) no existe. Revisar el mapeo en `procesar_factura_arca.php`. |
| **10003** | El punto de venta es inexistente. | Verificar en el portal de ARCA que el Punto de Venta esté dado de alta como "Factura Electrónica - Web Services". |
| **10013** | El número de comprobante informado ya existe. | Ocurre si intentas pedir CAE para un número que ya se usó. Sincronizar con `GetLastVoucher`. |
| **10015** | La fecha del comprobante es inválida. | ARCA permite hasta 5 días atrás o 10 adelante (servicios). Ajustar la fecha del servidor/venta. |
| **10016** | El punto de venta no está habilitado. | El punto de venta existe pero está inactivo o no vinculado al sistema de Web Services. |
| **10048** | CUIT/DNI del receptor inválido. | El documento del cliente es erróneo. Validar en `abm_clientes.php` o usar Consumidor Final (99). |
| **10051** | El tipo de documento es inválido. | Revisar si estás enviando 80 (CUIT), 96 (DNI) o 99 (Sin documento) según corresponda. |

## 2. Errores de Negocio (Condiciones Impositivas)

| Código | Significado | Qué hacer / Acción sugerida |
| :--- | :--- | :--- |
| **1500** | Comprobante no autorizado para esta condición de IVA. | Un Monotributista solo puede emitir Factura C. Un RI no puede emitir C. Revisar `datos_empresa`. |
| **1510** | El emisor no tiene el servicio habilitado. | Debes entrar a la web de ARCA y habilitar el servicio "Facturación Electrónica" para este CUIT. |
| **1530** | El receptor no es Responsable Inscripto. | Estás intentando hacer una Factura A a un cliente que es Monotributista o Consumidor Final. |
| **1540** | El importe total no coincide con la suma de los parciales. | Revisar cálculos de Subtotal + IVA + Tributos. En Factura C, ImpTotal debe ser igual a ImpNeto. |

## 3. Errores Técnicos y de Servidor
| Código | Significado | Qué hacer / Acción sugerida |
| :--- | :--- | :--- |
| **500 / 501** | Error interno de ARCA. | El servidor de ARCA está caído o saturado. Reintentar en 5 o 10 minutos. |
| **600** | Error en la base de datos de ARCA. | Problema temporal del fisco. No hay acción posible más que esperar y reintentar. |
| **1000** | Certificado vencido o no válido. | Tu archivo `.crt` ha expirado. Debes renovar el certificado en el portal de ARCA y subirlo a `afip_res/`. |
| **-1** | Error de conexión (Timeout). | Problema de Internet local o del servidor de Laragon. Verificar salida al exterior. |

## 4. Flujo de Resolución General

1. **Verificar Conexión:** ¿Internet funciona? ¿El servidor de ARCA responde (puedes chequearlo en afipstat.com.ar)?
2. **Validar Certificados:** ¿Están los archivos `.key` y `.crt` en la carpeta `afip_res/`? ¿La fecha del certificado es vigente?
3. **Depurar JSON:** Si el error es de "Importes", revisa los valores que el sistema está enviando. Recuerda que ARCA no acepta comas, solo puntos decimales, y valores numéricos estrictos.
4. **Modo Homologación:** Si estás probando, asegúrate de que `production` esté en `false` en el SDK. Los datos de prueba no funcionan en el servidor real y viceversa.

---
*Nota: Este archivo debe actualizarse a medida que se encuentren nuevos códigos durante la etapa de pruebas.*