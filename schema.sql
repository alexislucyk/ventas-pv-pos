-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 07, 2026 at 06:10 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.30
-- 
-- Esquema actualizado con soporte multi-empresa (Migraciones 01-16)

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
-- Table structure for table `empresas`
--

CREATE TABLE `empresas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre_fantasia` varchar(100) NOT NULL,
  `razon_social` varchar(100) DEFAULT NULL,
  `cuit` varchar(20) UNIQUE DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `ingresos_brutos` varchar(50) DEFAULT NULL,
  `inicio_actividades` date DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `direccion` varchar(255) NOT NULL,
  `localidad` varchar(255) NOT NULL,
  `telefono` varchar(50) NOT NULL,
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_usuarios_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `clientes`
--

CREATE TABLE `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `apellido` text COLLATE utf8mb4_general_ci NOT NULL,
  `dni` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_tipo_iva` int DEFAULT '99',
  `nombre` text COLLATE utf8mb4_general_ci NOT NULL,
  `direccion` text COLLATE utf8mb4_general_ci NOT NULL,
  `cuit` text COLLATE utf8mb4_general_ci NOT NULL,
  `telefono` text COLLATE utf8mb4_general_ci NOT NULL,
  `estado` text COLLATE utf8mb4_general_ci NOT NULL,
  `habilita_cta` text COLLATE utf8mb4_general_ci NOT NULL,
  `relacion` text COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_clientes_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `proveedores`
--

CREATE TABLE `proveedores` (
  `cod_prov` int NOT NULL,
  `empresa_id` int NOT NULL,
  `razon` text NOT NULL,
  `cuit` text NOT NULL,
  `telefono` text NOT NULL,
  PRIMARY KEY (`cod_prov`, `empresa_id`),
  CONSTRAINT `fk_proveedores_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productos`
--

CREATE TABLE `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `cod_prod` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `p_compra` double NOT NULL,
  `p_venta` double NOT NULL,
  `stock` double NOT NULL,
  `fecha_ult_compra` date NOT NULL,
  `rubro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `proveedor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `moneda` enum('pesos','dolar') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pesos',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_productos_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sucursales`
--

CREATE TABLE `sucursales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `nombre_sucursal` varchar(100) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `web` varchar(100) DEFAULT NULL,
  `es_principal` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sucursal_empresa` (`nombre_sucursal`, `empresa_id`),
  CONSTRAINT `fk_sucursales_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `stocks`
--

CREATE TABLE `stocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
  `cod_prod` varchar(50) NOT NULL,
  `stock_actual` decimal(10,2) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_stock` (`empresa_id`, `sucursal_id`, `cod_prod`),
  CONSTRAINT `fk_stocks_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stocks_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `ventas`
--

CREATE TABLE `ventas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
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
  `usuario` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ventas_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ventas_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_detalle`
--

CREATE TABLE `ventas_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
  `cod_prod` text COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_general_ci NOT NULL,
  `cant` double NOT NULL,
  `p_unit` double NOT NULL,
  `descuento_unitario` decimal(15,2) DEFAULT '0.00',
  `p_costo_venta` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` double NOT NULL,
  `n_documento` int NOT NULL,
  `fecha` date NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ventas_detalle_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_afip`
--

CREATE TABLE `ventas_afip` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_venta` int NOT NULL,
  `cae` varchar(20) NOT NULL,
  `cae_vto` date NOT NULL,
  `punto_venta` int NOT NULL,
  `n_comprobante` int NOT NULL,
  `tipo_comprobante` int NOT NULL,
  `fecha_proceso` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_venta` (`id_venta`),
  CONSTRAINT `fk_ventas_afip_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ventas_financiacion`
--

CREATE TABLE `ventas_financiacion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_venta` int NOT NULL,
  `cant_cuotas` int NOT NULL,
  `intervalo_dias` int NOT NULL,
  `interes_porcentaje` decimal(5,2) DEFAULT '0.00',
  `monto_interes` decimal(15,2) DEFAULT '0.00',
  `entrega_inicial` decimal(15,2) DEFAULT '0.00',
  `monto_cuota_sugerida` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ventas_financiacion_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ventas_financiacion_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `compras`
--

CREATE TABLE `compras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
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
  `es_sin_detalle` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_compras_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compras_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compras_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `compras_detalle`
--

CREATE TABLE `compras_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `cod_prod` text NOT NULL,
  `descripcion` text NOT NULL,
  `cant` double NOT NULL,
  `p_unit` double NOT NULL,
  `total` double NOT NULL,
  `n_documento` varchar(100) NOT NULL,
  `fecha` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ctacte`
--

CREATE TABLE `ctacte` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `movimiento` text NOT NULL,
  `n_documento` int NOT NULL,
  `debe` double NOT NULL,
  `haber` double NOT NULL,
  `fecha` date NOT NULL,
  `usuario` varchar(100) DEFAULT 'Sistema',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ctacte_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `ctacte_proveedores`
--

CREATE TABLE `ctacte_proveedores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_proveedor` int NOT NULL,
  `movimiento` varchar(100) NOT NULL COMMENT 'FACTURA COMPRA, PAGO, NOTA CREDITO',
  `haber` decimal(10,2) NOT NULL DEFAULT '0.00',
  `debe` decimal(10,2) NOT NULL DEFAULT '0.00',
  `n_documento` varchar(50) NOT NULL COMMENT 'Referencia a la factura o recibo',
  `fecha` datetime NOT NULL COMMENT 'Fecha y hora de registro del movimiento',
  `usuario_id` int DEFAULT NULL,
  `compra_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_proveedor` (`id_proveedor`),
  KEY `fk_ctacte_compra` (`compra_id`),
  CONSTRAINT `ctacte_proveedores_ibfk_1` FOREIGN KEY (`id_proveedor`, `empresa_id`) REFERENCES `proveedores` (`cod_prov`, `empresa_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ctacte_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ctacte_proveedores_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `cuotas_pagos`
--

CREATE TABLE `cuotas_pagos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_cuota` int NOT NULL,
  `monto` decimal(15,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `metodo_pago` varchar(50) COLLATE utf8mb4_spanish2_ci DEFAULT NULL,
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  `usuario` varchar(100) COLLATE utf8mb4_spanish2_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_cuota` (`id_cuota`),
  CONSTRAINT `cuotas_pagos_ibfk_1` FOREIGN KEY (`id_cuota`) REFERENCES `cuotas_seguimiento` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cuotas_seguimiento`
--

CREATE TABLE `cuotas_seguimiento` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_venta` int NOT NULL,
  `nro_cuota` int NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `monto_original` decimal(15,2) NOT NULL,
  `monto_pagado` decimal(15,2) DEFAULT '0.00',
  `estado` varchar(20) NOT NULL DEFAULT 'Pendiente',
  `ultima_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_venta` (`id_venta`),
  CONSTRAINT `cuotas_seguimiento_ibfk_1` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cuotas_seguimiento_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `datos_empresa`
--

CREATE TABLE `datos_empresa` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre_fantasia` varchar(100) DEFAULT NULL,
  `razon_social` varchar(100) DEFAULT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `ingresos_brutos` varchar(50) DEFAULT NULL,
  `inicio_actividades` date DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `direccion` varchar(255) NOT NULL,
  `localidad` varchar(255) NOT NULL,
  `telefono` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `devoluciones`
--

CREATE TABLE `devoluciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `op_n` int NOT NULL,
  `n_documento_venta` int NOT NULL,
  `id_cliente` int DEFAULT '0',
  `total_reintegrado` decimal(15,2) NOT NULL,
  `motivo` text COLLATE utf8mb4_general_ci,
  `fecha` datetime NOT NULL,
  `usuario` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cond_pago` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_devoluciones_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devoluciones_detalle`
--

CREATE TABLE `devoluciones_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_devolucion` int NOT NULL,
  `cod_prod` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cantidad` decimal(15,2) DEFAULT NULL,
  `p_unit` decimal(15,2) DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_devolucion` (`id_devolucion`),
  CONSTRAINT `devoluciones_detalle_ibfk_1` FOREIGN KEY (`id_devolucion`) REFERENCES `devoluciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modulos`
--

CREATE TABLE `modulos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `archivo` varchar(100) NOT NULL,
  `icono` varchar(50) DEFAULT NULL,
  `seccion` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `movimientos`
--

CREATE TABLE `movimientos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
  `tipo` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `detalle` text NOT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `fecha` date NOT NULL,
  `metodo_pago` text NOT NULL,
  `cerrado` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_movimientos_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_movimientos_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_rol`
--

CREATE TABLE `permisos_rol` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `rol` varchar(50) NOT NULL,
  `modulo_id` int NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_permisos_rol_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permisos_rol_ibfk_1` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_usuario`
--

CREATE TABLE `permisos_usuario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `modulo_id` int NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_permisos_usuario_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permisos_usuario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permisos_usuario_ibfk_2` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `presupuestos`
--

CREATE TABLE `presupuestos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_cliente` int DEFAULT NULL,
  `fecha_presupuesto` datetime DEFAULT CURRENT_TIMESTAMP,
  `total_presupuesto` decimal(10,2) DEFAULT NULL,
  `estado` enum('Pendiente','Convertido','Vencido') DEFAULT 'Pendiente',
  `observaciones` text,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_presupuestos_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presupuestos_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `presupuestos_detalle`
--

CREATE TABLE `presupuestos_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_presupuesto` int DEFAULT NULL,
  `cod_prod` varchar(50) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `precio_unitario` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `presupuestos_detalle_ibfk_1` FOREIGN KEY (`id_presupuesto`) REFERENCES `presupuestos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `proveedores_catalogos`
--

CREATE TABLE `proveedores_catalogos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cod_prov` varchar(50) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `codigo` varchar(100) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_spanish2_ci NOT NULL,
  `precio` decimal(15,2) NOT NULL DEFAULT '0.00',
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cod_prov` (`cod_prov`),
  KEY `idx_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rubros`
--

CREATE TABLE `rubros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `configuracion`
--

CREATE TABLE `configuracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clave` varchar(50) NOT NULL,
  `valor` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `cierres_caja`
--

CREATE TABLE `cierres_caja` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
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
  `fondo_reservado_vuelto` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_cierres_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cierres_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;