He creado un documento completo de diseño para implementar intereses por mora en las cuentas corrientes de clientes.

## 📄 Documento creado: `docs/diseño_intereses_mora.md`

### Resumen de la propuesta:

**Sistema recomendado: Interés Simple sobre Saldo Total**
- Aplica intereses cuando el saldo deudor tiene más de 30 días de mora
- Fórmula: `Interés = Saldo × (Tasa Mensual / 30) × Días de Mora`
- Tasa configurable por empresa (default: 3% mensual)
- Soporta días de gracia configurables

### Cambios principales:

1. **Base de Datos:**
   - Agregar campo `fecha_vencimiento` en tabla `ctacte`
   - Crear tabla `configuracion_intereses` para parámetros por empresa
   - Crear tabla `intereses_generados` para auditoría

2. **Funcionalidad:**
   - Cálculo automático de intereses por cliente
   - Aplicación manual o automática de intereses
   - Registro detallado de cada cálculo
   - Visualización en interfaz de cuentas corrientes

3. **Implementación:**
   - 4 archivos nuevos (funciones, AJAX, migración, configuración)
   - Modificaciones en 4 archivos existentes
   - Interfaz intuitiva con botón "Intereses" por cliente

### Características clave:
✅ Intereses se agregan como movimiento en ctacte  
✅ Se pueden pagar junto con el saldo normal  
✅ Auditoría completa de cálculos  
✅ Configuración independiente por empresa  
✅ Sin duplicar intereses (control de generados)  
✅ Ejemplos prácticos de cálculo incluidos

Agregado por developer:
En el momento de hacer una factura a ctacte se registra la fecha de emision
en la tabla ctacte, desde ahi se deben contar 30 dias (configurables) y luego si
el cliente no paga sumar intereses(deben poder configurar dias de gracia, posibilidad de NO cobrar interes, etc)

El documento incluye código PHP completo, SQL de migración, ejemplos de cálculo y plan de implementación paso a paso.

