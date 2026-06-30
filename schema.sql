-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 29, 2026 at 07:21 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pos_dev`
--
CREATE DATABASE IF NOT EXISTS `pos_dev` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `pos_dev`;

-- --------------------------------------------------------

--
-- Table structure for table `cierres_caja`
--

CREATE TABLE `cierres_caja` (
  `id` int NOT NULL,
  `fecha_cierre` datetime DEFAULT CURRENT_TIMESTAMP,
  `saldo_inicial` decimal(10,2) DEFAULT NULL,
  `ingresos_efectivo` decimal(10,2) DEFAULT NULL,
  `ingresos_transf` decimal(10,2) DEFAULT NULL,
  `egresos` decimal(10,2) DEFAULT NULL,
  `saldo_esperado_efectivo` decimal(10,2) DEFAULT NULL,
  `saldo_real_efectivo` decimal(10,2) DEFAULT NULL,
  `diferencia` decimal(10,2) DEFAULT NULL,
  `observaciones` text,
  `usuario` varchar(50) DEFAULT NULL,
  `fondo_reservado_vuelto` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `clientes`
--

CREATE TABLE `clientes` (
  `id` int NOT NULL,
  `apellido` text COLLATE utf8mb4_general_ci NOT NULL,
  `dni` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_tipo_iva` int DEFAULT '99',
  `nombre` text COLLATE utf8mb4_general_ci NOT NULL,
  `direccion` text COLLATE utf8mb4_general_ci NOT NULL,
  `cuit` text COLLATE utf8mb4_general_ci NOT NULL,
  `telefono` text COLLATE utf8mb4_general_ci NOT NULL,
  `estado` text COLLATE utf8mb4_general_ci NOT NULL,
  `habilita_cta` text COLLATE utf8mb4_general_ci NOT NULL,
  `relacion` text COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `compras`
--

CREATE TABLE `compras` (
  `id` int NOT NULL,
  `cod_proveedor` int NOT NULL,
  `cond_pago` text NOT NULL,
  `documento` text NOT NULL,
  `n_documento` varchar(100) NOT NULL,
  `total_compra` double NOT NULL,
  `observaciones` text,
  `fecha_compra` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `fecha_operacion` datetime NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `es_sin_detalle` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `compras_detalle`
--

CREATE TABLE `compras_detalle` (
  `id` int NOT NULL,
  `cod_prod` text NOT NULL,
  `descripcion` text NOT NULL,
  `cant` double NOT NULL,
  `p_unit` double NOT NULL,
  `total` double NOT NULL,
  `n_documento` varchar(100) NOT NULL,
  `fecha` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configuracion`
--

CREATE TABLE `configuracion` (
  `id` int NOT NULL,
  `clave` varchar(50) NOT NULL,
  `valor` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `ctacte`
--

CREATE TABLE `ctacte` (
  `id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `movimiento` text NOT NULL,
  `n_documento` int NOT NULL,
  `debe` double NOT NULL,
  `haber` double NOT NULL,
  `fecha` date NOT NULL,
  `usuario` varchar(100) DEFAULT 'Sistema'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `ctacte_proveedores`
--

CREATE TABLE `ctacte_proveedores` (
  `id` int NOT NULL,
  `id_proveedor` int NOT NULL,
  `movimiento` varchar(100) NOT NULL COMMENT 'FACTURA COMPRA, PAGO, NOTA CREDITO',
  `haber` decimal(10,2) NOT NULL DEFAULT '0.00',
  `debe` decimal(10,2) NOT NULL DEFAULT '0.00',
  `n_documento` varchar(50) NOT NULL COMMENT 'Referencia a la factura o recibo',
  `fecha` datetime NOT NULL COMMENT 'Fecha y hora de registro del movimiento',
  `usuario_id` int DEFAULT NULL,
  `compra_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `cuotas_pagos`
--

CREATE TABLE `cuotas_pagos` (
  `id` int NOT NULL,
  `id_cuota` int NOT NULL,
  `monto` decimal(15,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `metodo_pago` varchar(50) COLLATE utf8mb4_spanish2_ci DEFAULT NULL,
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  `usuario` varchar(100) COLLATE utf8mb4_spanish2_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cuotas_seguimiento`
--

CREATE TABLE `cuotas_seguimiento` (
  `id` int NOT NULL,
  `id_venta` int NOT NULL,
  `nro_cuota` int NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `monto_original` decimal(15,2) NOT NULL,
  `monto_pagado` decimal(15,2) DEFAULT '0.00',
  `estado` varchar(20) NOT NULL DEFAULT 'Pendiente',
  `ultima_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `datos_empresa`
--

CREATE TABLE `datos_empresa` (
  `id` int NOT NULL,
  `nombre_fantasia` varchar(100) DEFAULT NULL,
  `razon_social` varchar(100) DEFAULT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `ingresos_brutos` varchar(50) DEFAULT NULL,
  `inicio_actividades` date DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `direccion` varchar(255) NOT NULL,
  `localidad` varchar(255) NOT NULL,
  `telefono` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `devoluciones`
--

CREATE TABLE `devoluciones` (
  `id` int NOT NULL,
  `op_n` int NOT NULL,
  `n_documento_venta` int NOT NULL,
  `id_cliente` int DEFAULT '0',
  `total_reintegrado` decimal(15,2) NOT NULL,
  `motivo` text COLLATE utf8mb4_general_ci,
  `fecha` datetime NOT NULL,
  `usuario` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cond_pago` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devoluciones_detalle`
--

CREATE TABLE `devoluciones_detalle` (
  `id` int NOT NULL,
  `id_devolucion` int NOT NULL,
  `cod_prod` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cantidad` decimal(15,2) DEFAULT NULL,
  `p_unit` decimal(15,2) DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modulos`
--

CREATE TABLE `modulos` (
  `id` int NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `archivo` varchar(100) NOT NULL,
  `icono` varchar(50) DEFAULT NULL,
  `seccion` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `movimientos`
--

CREATE TABLE `movimientos` (
  `id` int NOT NULL,
  `tipo` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `detalle` text NOT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `fecha` date NOT NULL,
  `metodo_pago` text NOT NULL,
  `cerrado` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_rol`
--

CREATE TABLE `permisos_rol` (
  `id` int NOT NULL,
  `rol` varchar(50) NOT NULL,
  `modulo_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_usuario`
--

CREATE TABLE `permisos_usuario` (
  `id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `modulo_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `presupuestos`
--

CREATE TABLE `presupuestos` (
  `id` int NOT NULL,
  `id_cliente` int DEFAULT NULL,
  `fecha_presupuesto` datetime DEFAULT CURRENT_TIMESTAMP,
  `total_presupuesto` decimal(10,2) DEFAULT NULL,
  `estado` enum('Pendiente','Convertido','Vencido') DEFAULT 'Pendiente',
  `observaciones` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `presupuestos_detalle`
--

CREATE TABLE `presupuestos_detalle` (
  `id` int NOT NULL,
  `id_presupuesto` int DEFAULT NULL,
  `cod_prod` varchar(50) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `precio_unitario` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `productos`
--

CREATE TABLE `productos` (
  `id` int NOT NULL,
  `cod_prod` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `p_compra` double NOT NULL,
  `p_venta` double NOT NULL,
  `stock` double NOT NULL,
  `fecha_ult_compra` date NOT NULL,
  `rubro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `proveedor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `moneda` enum('pesos','dolar') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pesos'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `proveedores`
--

CREATE TABLE `proveedores` (
  `cod_prov` int NOT NULL,
  `razon` text NOT NULL,
  `cuit` text NOT NULL,
  `telefono` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `proveedores_catalogos`
--

CREATE TABLE `proveedores_catalogos` (
  `id` int NOT NULL,
  `cod_prov` varchar(50) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `codigo` varchar(100) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `precio` decimal(15,2) NOT NULL DEFAULT '0.00',
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rubros`
--

CREATE TABLE `rubros` (
  `id` int NOT NULL,
  `nombre` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sucursales`
--

CREATE TABLE `sucursales` (
  `id` int NOT NULL,
  `nombre_sucursal` varchar(100) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `web` varchar(100) DEFAULT NULL,
  `es_principal` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `ventas`
--

CREATE TABLE `ventas` (
  `id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `cond_pago` enum('CONTADO','CUENTA CORRIENTE','FINANCIADO') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `n_documento` int NOT NULL,
  `total_venta` double NOT NULL,
  `descuento_global` decimal(15,2) DEFAULT '0.00',
  `tipo_descuento_global` enum('fijo','porcentaje') COLLATE utf8mb4_general_ci DEFAULT 'fijo',
  `pago_efectivo` double NOT NULL,
  `pago_transf` double NOT NULL,
  `fecha_venta` datetime NOT NULL,
  `estado` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente',
  `usuario` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_afip`
--

CREATE TABLE `ventas_afip` (
  `id` int NOT NULL,
  `id_venta` int NOT NULL,
  `cae` varchar(20) NOT NULL,
  `cae_vto` date NOT NULL,
  `punto_venta` int NOT NULL,
  `n_comprobante` int NOT NULL,
  `tipo_comprobante` int NOT NULL,
  `fecha_proceso` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_detalle`
--

CREATE TABLE `ventas_detalle` (
  `id` int NOT NULL,
  `cod_prod` text COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_general_ci NOT NULL,
  `cant` double NOT NULL,
  `p_unit` double NOT NULL,
  `descuento_unitario` decimal(15,2) DEFAULT '0.00',
  `p_costo_venta` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` double NOT NULL,
  `n_documento` int NOT NULL,
  `fecha` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_financiacion`
--

CREATE TABLE `ventas_financiacion` (
  `id` int NOT NULL,
  `id_venta` int NOT NULL,
  `cant_cuotas` int NOT NULL,
  `intervalo_dias` int NOT NULL,
  `interes_porcentaje` decimal(5,2) DEFAULT '0.00',
  `monto_interes` decimal(15,2) DEFAULT '0.00',
  `entrega_inicial` decimal(15,2) DEFAULT '0.00',
  `monto_cuota_sugerida` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cierres_caja`
--
ALTER TABLE `cierres_caja`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `compras`
--
ALTER TABLE `compras`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_compras_usuario` (`usuario_id`);

--
-- Indexes for table `compras_detalle`
--
ALTER TABLE `compras_detalle`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `clave` (`clave`);

--
-- Indexes for table `ctacte`
--
ALTER TABLE `ctacte`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ctacte_proveedores`
--
ALTER TABLE `ctacte_proveedores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proveedor` (`id_proveedor`),
  ADD KEY `fk_ctacte_compra` (`compra_id`);

--
-- Indexes for table `cuotas_pagos`
--
ALTER TABLE `cuotas_pagos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_cuota` (`id_cuota`);

--
-- Indexes for table `cuotas_seguimiento`
--
ALTER TABLE `cuotas_seguimiento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_venta` (`id_venta`);

--
-- Indexes for table `datos_empresa`
--
ALTER TABLE `datos_empresa`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `devoluciones`
--
ALTER TABLE `devoluciones`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `devoluciones_detalle`
--
ALTER TABLE `devoluciones_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_devolucion` (`id_devolucion`);

--
-- Indexes for table `modulos`
--
ALTER TABLE `modulos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `movimientos`
--
ALTER TABLE `movimientos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permisos_rol`
--
ALTER TABLE `permisos_rol`
  ADD PRIMARY KEY (`id`),
  ADD KEY `modulo_id` (`modulo_id`);

--
-- Indexes for table `permisos_usuario`
--
ALTER TABLE `permisos_usuario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `modulo_id` (`modulo_id`);

--
-- Indexes for table `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Indexes for table `presupuestos_detalle`
--
ALTER TABLE `presupuestos_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_presupuesto` (`id_presupuesto`);

--
-- Indexes for table `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD COLUMN `moneda` enum('pesos','dolar') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pesos' AFTER `proveedor`;

--
-- Indexes for table `proveedores`
--
ALTER TABLE `proveedores`
  ADD PRIMARY KEY (`cod_prov`),
  ADD KEY `cod_prov` (`cod_prov`);

--
-- Indexes for table `proveedores_catalogos`
--
ALTER TABLE `proveedores_catalogos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cod_prov` (`cod_prov`),
  ADD KEY `idx_codigo` (`codigo`);

--
-- Indexes for table `rubros`
--
ALTER TABLE `rubros`
  ADD UNIQUE KEY `id_3` (`id`),
  ADD KEY `id` (`id`),
  ADD KEY `id_2` (`id`);

--
-- Indexes for table `sucursales`
--
ALTER TABLE `sucursales`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ventas_afip`
--
ALTER TABLE `ventas_afip`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_venta` (`id_venta`);

--
-- Indexes for table `ventas_detalle`
--
ALTER TABLE `ventas_detalle`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ventas_financiacion`
--
ALTER TABLE `ventas_financiacion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_venta` (`id_venta`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cierres_caja`
--
ALTER TABLE `cierres_caja`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `compras`
--
ALTER TABLE `compras`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `compras_detalle`
--
ALTER TABLE `compras_detalle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `configuracion`
--
ALTER TABLE `configuracion`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ctacte`
--
ALTER TABLE `ctacte`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ctacte_proveedores`
--
ALTER TABLE `ctacte_proveedores`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cuotas_pagos`
--
ALTER TABLE `cuotas_pagos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cuotas_seguimiento`
--
ALTER TABLE `cuotas_seguimiento`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `datos_empresa`
--
ALTER TABLE `datos_empresa`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devoluciones`
--
ALTER TABLE `devoluciones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devoluciones_detalle`
--
ALTER TABLE `devoluciones_detalle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modulos`
--
ALTER TABLE `modulos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `movimientos`
--
ALTER TABLE `movimientos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos_rol`
--
ALTER TABLE `permisos_rol`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos_usuario`
--
ALTER TABLE `permisos_usuario`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `presupuestos`
--
ALTER TABLE `presupuestos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `presupuestos_detalle`
--
ALTER TABLE `presupuestos_detalle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `productos`
--
ALTER TABLE `productos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `proveedores_catalogos`
--
ALTER TABLE `proveedores_catalogos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sucursales`
--
ALTER TABLE `sucursales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ventas_afip`
--
ALTER TABLE `ventas_afip`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ventas_detalle`
--
ALTER TABLE `ventas_detalle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ventas_financiacion`
--
ALTER TABLE `ventas_financiacion`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `compras`
--
ALTER TABLE `compras`
  ADD CONSTRAINT `fk_compras_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ctacte_proveedores`
--
ALTER TABLE `ctacte_proveedores`
  ADD CONSTRAINT `ctacte_proveedores_ibfk_1` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedores` (`cod_prov`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ctacte_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `cuotas_pagos`
--
ALTER TABLE `cuotas_pagos`
  ADD CONSTRAINT `cuotas_pagos_ibfk_1` FOREIGN KEY (`id_cuota`) REFERENCES `cuotas_seguimiento` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cuotas_seguimiento`
--
ALTER TABLE `cuotas_seguimiento`
  ADD CONSTRAINT `cuotas_seguimiento_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permisos_rol`
--
ALTER TABLE `permisos_rol`
  ADD CONSTRAINT `permisos_rol_ibfk_1` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permisos_usuario`
--
ALTER TABLE `permisos_usuario`
  ADD CONSTRAINT `permisos_usuario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_usuario_ibfk_2` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD CONSTRAINT `presupuestos_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id`);

--
-- Constraints for table `presupuestos_detalle`
--
ALTER TABLE `presupuestos_detalle`
  ADD CONSTRAINT `presupuestos_detalle_ibfk_1` FOREIGN KEY (`id_presupuesto`) REFERENCES `presupuestos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ventas_afip`
--
ALTER TABLE `ventas_afip`
  ADD CONSTRAINT `ventas_afip_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id`);

--
-- Constraints for table `ventas_financiacion`
--
ALTER TABLE `ventas_financiacion`
  ADD CONSTRAINT `ventas_financiacion_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
