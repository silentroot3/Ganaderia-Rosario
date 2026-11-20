/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.8-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: Ganaderia_Rosario
-- ------------------------------------------------------
-- Server version	11.8.8-MariaDB-0+deb13u1 from Debian

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--asmgimdsogmgsmd 
-- Table structure for table `alertas`
--

DROP TABLE IF EXISTS `alertas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `alertas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('stock_bajo','animal_enfermo','animal_sin_pesar','corral_lleno','vacuna_proxima','retiro_activo','otro') NOT NULL,
  `mensaje` varchar(255) NOT NULL,
  `bovino_id` int(11) DEFAULT NULL,
  `corral_id` int(11) DEFAULT NULL,
  `ingrediente_id` int(11) DEFAULT NULL,
  `rol_destino` varchar(50) DEFAULT NULL,
  `leida` tinyint(1) DEFAULT 0,
  `leida_por` int(11) DEFAULT NULL,
  `fecha_lectura` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leida` (`leida`),
  KEY `idx_fecha` (`created_at`),
  KEY `alert_bov_fk` (`bovino_id`),
  KEY `alert_corral_fk` (`corral_id`),
  KEY `alert_ing_fk` (`ingrediente_id`),
  KEY `alert_usr_fk` (`leida_por`),
  CONSTRAINT `alert_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alert_corral_fk` FOREIGN KEY (`corral_id`) REFERENCES `corrales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alert_ing_fk` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alert_usr_fk` FOREIGN KEY (`leida_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alimentaciones`
--

DROP TABLE IF EXISTS `alimentaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `alimentaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `corral_id` int(11) NOT NULL,
  `dieta_id` int(11) NOT NULL,
  `cantidad_kg` decimal(10,2) NOT NULL,
  `horario` enum('mañana','tarde','noche') NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_corral` (`corral_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `alim_dieta_fk` (`dieta_id`),
  KEY `alim_usr_fk` (`usuario_id`),
  CONSTRAINT `alim_corral_fk` FOREIGN KEY (`corral_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `alim_dieta_fk` FOREIGN KEY (`dieta_id`) REFERENCES `dietas` (`id`),
  CONSTRAINT `alim_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bajas_recepcion`
--

DROP TABLE IF EXISTS `bajas_recepcion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bajas_recepcion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recepcion_id` int(11) NOT NULL,
  `tipo` enum('muerte','devolucion','rechazo') NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `motivo` varchar(255) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recepcion` (`recepcion_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `bajas_usuario_fk` (`usuario_id`),
  CONSTRAINT `bajas_recepcion_fk` FOREIGN KEY (`recepcion_id`) REFERENCES `recepciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bajas_usuario_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bovinos`
--

DROP TABLE IF EXISTS `bovinos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bovinos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recepcion_id` int(11) NOT NULL,
  `id_siniiga` varchar(15) NOT NULL,
  `sexo` enum('M','H') NOT NULL,
  `raza` varchar(50) DEFAULT NULL,
  `peso_entrada` decimal(6,2) DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `edad_meses` int(11) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `estado` enum('recibido','revision','apto','observacion','rechazado','clasificado','en_corral','en_cuarentena','vendido','muerto','devuelto') DEFAULT 'recibido',
  `corral_id` int(11) DEFAULT NULL,
  `corral_original_id` int(11) DEFAULT NULL,
  `en_hospital` tinyint(1) DEFAULT 0,
  `motivo_hospital` varchar(255) DEFAULT NULL,
  `fecha_entrada_hospital` datetime DEFAULT NULL,
  `fecha_salida_hospital` datetime DEFAULT NULL,
  `fecha_salida` datetime DEFAULT NULL,
  `motivo_baja` varchar(255) DEFAULT NULL,
  `observaciones_baja` text DEFAULT NULL,
  `fecha_baja` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_siniiga` (`id_siniiga`),
  KEY `recepcion_id` (`recepcion_id`),
  KEY `idx_corral` (`corral_id`),
  KEY `idx_en_hospital` (`en_hospital`),
  KEY `idx_estado_bovino` (`estado`),
  CONSTRAINT `bovinos_corral_fk` FOREIGN KEY (`corral_id`) REFERENCES `corrales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bovinos_ibfk_1` FOREIGN KEY (`recepcion_id`) REFERENCES `recepciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categorias_peso`
--

DROP TABLE IF EXISTS `categorias_peso`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias_peso` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `peso_min` decimal(6,2) NOT NULL,
  `peso_max` decimal(6,2) NOT NULL,
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clasificaciones`
--

DROP TABLE IF EXISTS `clasificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clasificaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `sexo` enum('M','H') NOT NULL,
  `peso` decimal(6,2) NOT NULL,
  `edad_meses` int(11) DEFAULT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `clas_cat_fk` (`categoria_id`),
  KEY `clas_usr_fk` (`usuario_id`),
  CONSTRAINT `clas_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clas_cat_fk` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_peso` (`id`),
  CONSTRAINT `clas_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compradores`
--

DROP TABLE IF EXISTS `compradores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `compradores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `rfc` varchar(13) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rfc` (`rfc`),
  KEY `idx_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) NOT NULL,
  `valor` text DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `tipo` enum('texto','numero','booleano','json','imagen') DEFAULT 'texto',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `corrales`
--

DROP TABLE IF EXISTS `corrales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corrales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `capacidad_maxima` int(11) NOT NULL,
  `tipo` enum('Hembras','Machos','Castrados','Engorda','Cuarentena','Mixto') NOT NULL,
  `sexo_permitido` enum('M','H','Mixto') DEFAULT 'Mixto',
  `peso_min` decimal(6,2) DEFAULT NULL,
  `peso_max` decimal(6,2) DEFAULT NULL,
  `dimensiones_m2` decimal(8,2) DEFAULT NULL,
  `estado` enum('Disponible','Ocupado','Mantenimiento') DEFAULT 'Disponible',
  `es_hospital` tinyint(1) DEFAULT 0,
  `es_venta` tinyint(1) DEFAULT 0,
  `ocupacion_actual` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`),
  KEY `idx_estado` (`estado`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `costos`
--

DROP TABLE IF EXISTS `costos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `costos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) DEFAULT NULL,
  `tipo` enum('compra','alimentacion','veterinario','operativo','otro') NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha` date NOT NULL,
  `comprobante` varchar(500) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_tipo` (`tipo`),
  KEY `cost_usr_fk` (`usuario_id`),
  CONSTRAINT `cost_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cost_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cuarentenas`
--

DROP TABLE IF EXISTS `cuarentenas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuarentenas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `corral_id` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin_estimada` date DEFAULT NULL,
  `fecha_fin_real` date DEFAULT NULL,
  `autorizado_por` int(11) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `cuar_corral_fk` (`corral_id`),
  KEY `cuar_usr_fk` (`autorizado_por`),
  CONSTRAINT `cuar_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cuar_corral_fk` FOREIGN KEY (`corral_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `cuar_usr_fk` FOREIGN KEY (`autorizado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `detalle_ventas`
--

DROP TABLE IF EXISTS `detalle_ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `detalle_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `bovino_id` int(11) NOT NULL,
  `peso_final` decimal(6,2) NOT NULL,
  `precio_kg` decimal(8,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `det_bov_fk` (`bovino_id`),
  CONSTRAINT `det_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`),
  CONSTRAINT `det_vent_fk` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dieta_ingredientes`
--

DROP TABLE IF EXISTS `dieta_ingredientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dieta_ingredientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dieta_id` int(11) NOT NULL,
  `ingrediente_id` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dieta_ing` (`dieta_id`,`ingrediente_id`),
  KEY `di_ing_fk` (`ingrediente_id`),
  CONSTRAINT `di_dieta_fk` FOREIGN KEY (`dieta_id`) REFERENCES `dietas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `di_ing_fk` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dietas`
--

DROP TABLE IF EXISTS `dietas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dietas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `etapa_id` int(11) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `costo_por_kg` decimal(8,2) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_etapa` (`etapa_id`),
  CONSTRAINT `dietas_etapa_fk` FOREIGN KEY (`etapa_id`) REFERENCES `etapas_alimentacion` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documentos_recepcion`
--

DROP TABLE IF EXISTS `documentos_recepcion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos_recepcion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recepcion_id` int(11) NOT NULL,
  `tipo` enum('factura','guia_traslado','permiso_senasica','otro') NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `tamanio` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recepcion` (`recepcion_id`),
  CONSTRAINT `doc_rec_fk` FOREIGN KEY (`recepcion_id`) REFERENCES `recepciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `etapas_alimentacion`
--

DROP TABLE IF EXISTS `etapas_alimentacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `etapas_alimentacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `duracion_meses` int(11) NOT NULL,
  `porcentaje_peso_corporal` decimal(5,2) DEFAULT NULL,
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fotos_heridas`
--

DROP TABLE IF EXISTS `fotos_heridas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fotos_heridas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `herida_id` int(11) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_herida` (`herida_id`),
  CONSTRAINT `foto_her_fk` FOREIGN KEY (`herida_id`) REFERENCES `heridas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fotos_recepcion`
--

DROP TABLE IF EXISTS `fotos_recepcion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fotos_recepcion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recepcion_id` int(11) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recepcion` (`recepcion_id`),
  CONSTRAINT `foto_rec_fk` FOREIGN KEY (`recepcion_id`) REFERENCES `recepciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `heridas`
--

DROP TABLE IF EXISTS `heridas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `heridas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `revision_id` int(11) NOT NULL,
  `ubicacion` varchar(100) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `severidad` enum('leve','moderada','grave') DEFAULT 'leve',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_revision` (`revision_id`),
  CONSTRAINT `her_rev_fk` FOREIGN KEY (`revision_id`) REFERENCES `revisiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ingredientes`
--

DROP TABLE IF EXISTS `ingredientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingredientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `unidad` varchar(20) DEFAULT 'kg',
  `precio_unitario` decimal(8,2) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventario_alimento`
--

DROP TABLE IF EXISTS `inventario_alimento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventario_alimento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ingrediente_id` int(11) NOT NULL,
  `stock_actual` decimal(10,2) DEFAULT 0.00,
  `unidad` varchar(20) DEFAULT 'kg',
  `stock_minimo` decimal(10,2) DEFAULT 0.00,
  `proveedor` varchar(150) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ingrediente` (`ingrediente_id`),
  CONSTRAINT `inv_ing_fk` FOREIGN KEY (`ingrediente_id`) REFERENCES `ingredientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventario_medicamentos`
--

DROP TABLE IF EXISTS `inventario_medicamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventario_medicamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medicamento_id` int(11) NOT NULL,
  `stock_actual` decimal(10,2) DEFAULT 0.00,
  `unidad` varchar(20) DEFAULT 'ml',
  `fecha_caducidad` date DEFAULT NULL,
  `lote` varchar(50) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medicamento` (`medicamento_id`),
  CONSTRAINT `inv_med_fk` FOREIGN KEY (`medicamento_id`) REFERENCES `medicamentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_actividad`
--

DROP TABLE IF EXISTS `logs_actividad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_actividad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `medicamentos`
--

DROP TABLE IF EXISTS `medicamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medicamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `tipo` enum('vacuna','antibiotico','vitamina','desparasitante','otro') NOT NULL,
  `descripcion` text DEFAULT NULL,
  `dosis_recomendada` varchar(100) DEFAULT NULL,
  `via_administracion` varchar(50) DEFAULT NULL,
  `dias_retiro` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mortalidad`
--

DROP TABLE IF EXISTS `mortalidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mortalidad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `causa_probable` varchar(255) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `mort_usr_fk` (`usuario_id`),
  CONSTRAINT `mort_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`),
  CONSTRAINT `mort_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `movimientos_bovino`
--

DROP TABLE IF EXISTS `movimientos_bovino`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimientos_bovino` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `corral_origen_id` int(11) DEFAULT NULL,
  `corral_destino_id` int(11) DEFAULT NULL,
  `tipo_movimiento` enum('recepcion','clasificacion','hospital','alta','venta','regreso','muerte') NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_tipo` (`tipo_movimiento`),
  KEY `movbov_corral_origen_fk` (`corral_origen_id`),
  KEY `movbov_corral_destino_fk` (`corral_destino_id`),
  KEY `movbov_usr_fk` (`usuario_id`),
  CONSTRAINT `movbov_bovino_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movbov_corral_destino_fk` FOREIGN KEY (`corral_destino_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `movbov_corral_origen_fk` FOREIGN KEY (`corral_origen_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `movbov_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `movimientos_corral`
--

DROP TABLE IF EXISTS `movimientos_corral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimientos_corral` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `corral_origen_id` int(11) DEFAULT NULL,
  `corral_destino_id` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `mov_corral_origen_fk` (`corral_origen_id`),
  KEY `mov_corral_destino_fk` (`corral_destino_id`),
  KEY `mov_corral_usuario_fk` (`usuario_id`),
  CONSTRAINT `mov_corral_bovino_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mov_corral_destino_fk` FOREIGN KEY (`corral_destino_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `mov_corral_origen_fk` FOREIGN KEY (`corral_origen_id`) REFERENCES `corrales` (`id`),
  CONSTRAINT `mov_corral_usuario_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pesajes`
--

DROP TABLE IF EXISTS `pesajes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pesajes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `peso` decimal(6,2) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `gdp` decimal(5,3) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `pes_usr_fk` (`usuario_id`),
  CONSTRAINT `pes_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pes_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `precios_becear`
--

DROP TABLE IF EXISTS `precios_becear`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `precios_becear` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` enum('MACHOS_ENTEROS','HEMBRAS') NOT NULL,
  `peso_min` decimal(6,2) NOT NULL,
  `peso_max` decimal(6,2) NOT NULL,
  `precio_kg` decimal(8,2) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_peso` (`peso_min`,`peso_max`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `precios_referencia`
--

DROP TABLE IF EXISTS `precios_referencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `precios_referencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` varchar(50) NOT NULL,
  `precio_min_kg` decimal(8,2) DEFAULT NULL,
  `precio_max_kg` decimal(8,2) DEFAULT NULL,
  `fecha_vigencia` date NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recepciones`
--

DROP TABLE IF EXISTS `recepciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recepciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) NOT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_recepcion` datetime DEFAULT current_timestamp(),
  `cantidad_animales` int(11) NOT NULL DEFAULT 0,
  `cantidad_llegada` int(11) DEFAULT 0,
  `cantidad_recibida` int(11) DEFAULT 0,
  `cantidad_bajas` int(11) DEFAULT 0,
  `peso_total_recibido` decimal(10,2) DEFAULT 0.00,
  `transporte` varchar(100) DEFAULT NULL,
  `placas` varchar(20) DEFAULT NULL,
  `chofer` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `estado` enum('borrador','completado','cancelado') DEFAULT 'borrador',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `recepciones_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recepciones_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `revisiones`
--

DROP TABLE IF EXISTS `revisiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `veterinario_id` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `temperatura` decimal(4,2) DEFAULT NULL,
  `condicion_corporal` tinyint(4) DEFAULT NULL,
  `peso` decimal(6,2) DEFAULT NULL,
  `tiene_golpes` tinyint(1) DEFAULT 0,
  `tiene_heridas` tinyint(1) DEFAULT 0,
  `sintomas` text DEFAULT NULL,
  `diagnostico_preliminar` text DEFAULT NULL,
  `estado` enum('APTO','OBSERVACION','RECHAZADO','MUERTO') NOT NULL,
  `motivo_rechazo` text DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `rev_vet_fk` (`veterinario_id`),
  CONSTRAINT `rev_bovino_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rev_vet_fk` FOREIGN KEY (`veterinario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sesiones`
--

DROP TABLE IF EXISTS `sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `fecha_inicio` datetime DEFAULT current_timestamp(),
  `fecha_ultima_actividad` datetime DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `sesiones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tratamientos`
--

DROP TABLE IF EXISTS `tratamientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tratamientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `medicamento_id` int(11) NOT NULL,
  `dosis` decimal(8,2) DEFAULT NULL,
  `via_administracion` varchar(50) DEFAULT NULL,
  `diagnostico` text DEFAULT NULL,
  `dias_retiro` int(11) DEFAULT 0,
  `fecha_aplicacion` datetime DEFAULT current_timestamp(),
  `fecha_fin_retiro` date DEFAULT NULL,
  `costo` decimal(8,2) DEFAULT 0.00,
  `veterinario_id` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `idx_fecha` (`fecha_aplicacion`),
  KEY `trat_med_fk` (`medicamento_id`),
  KEY `trat_vet_fk` (`veterinario_id`),
  CONSTRAINT `trat_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trat_med_fk` FOREIGN KEY (`medicamento_id`) REFERENCES `medicamentos` (`id`),
  CONSTRAINT `trat_vet_fk` FOREIGN KEY (`veterinario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('admin','recepcionista','veterinario','clasificador','alimentador') DEFAULT 'recepcionista',
  `telefono` varchar(20) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `ip_registro` varchar(45) DEFAULT NULL,
  `intentos_fallidos` int(11) DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `token_recuperacion` varchar(255) DEFAULT NULL,
  `token_expiracion` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_token` (`token_recuperacion`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`silentroot`@`localhost`*/ /*!50003 TRIGGER tr_bitacora_usuario
AFTER INSERT ON usuarios
FOR EACH ROW
BEGIN
    INSERT INTO logs_actividad (usuario_id, accion, descripcion)
    VALUES (NEW.id, 'crear_usuario', CONCAT('Usuario creado: ', NEW.email, ' con rol ', NEW.rol));
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `vacunaciones`
--

DROP TABLE IF EXISTS `vacunaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vacunaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bovino_id` int(11) NOT NULL,
  `medicamento_id` int(11) NOT NULL,
  `fecha_aplicacion` date NOT NULL,
  `proxima_dosis` date DEFAULT NULL,
  `veterinario_id` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bovino` (`bovino_id`),
  KEY `vac_med_fk` (`medicamento_id`),
  KEY `vac_vet_fk` (`veterinario_id`),
  CONSTRAINT `vac_bov_fk` FOREIGN KEY (`bovino_id`) REFERENCES `bovinos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vac_med_fk` FOREIGN KEY (`medicamento_id`) REFERENCES `medicamentos` (`id`),
  CONSTRAINT `vac_vet_fk` FOREIGN KEY (`veterinario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ventas`
--

DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) NOT NULL,
  `comprador_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `iva` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `factura` varchar(50) DEFAULT NULL,
  `guia_senasica` varchar(50) DEFAULT NULL,
  `estado` enum('activa','cancelada') DEFAULT 'activa',
  `motivo_cancelacion` text DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_estado` (`estado`),
  KEY `vent_compr_fk` (`comprador_id`),
  KEY `vent_usr_fk` (`usuario_id`),
  CONSTRAINT `vent_compr_fk` FOREIGN KEY (`comprador_id`) REFERENCES `compradores` (`id`),
  CONSTRAINT `vent_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `vista_bovinos_completo`
--

DROP TABLE IF EXISTS `vista_bovinos_completo`;
/*!50001 DROP VIEW IF EXISTS `vista_bovinos_completo`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vista_bovinos_completo` AS SELECT
 NULL AS `id`,
 NULL AS `id_siniiga`,
 NULL AS `sexo`,
 NULL AS `raza`,
 NULL AS `peso_entrada`,
 NULL AS `categoria`,
 NULL AS `edad_meses`,
 NULL AS `color`,
 NULL AS `estado`,
 NULL AS `fecha_ingreso`,
 NULL AS `fecha_salida`,
 NULL AS `corral`,
 NULL AS `folio_recepcion`,
 NULL AS `proveedor` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vista_ocupacion_corrales`
--

DROP TABLE IF EXISTS `vista_ocupacion_corrales`;
/*!50001 DROP VIEW IF EXISTS `vista_ocupacion_corrales`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vista_ocupacion_corrales` AS SELECT
 NULL AS `id`,
 NULL AS `nombre`,
 NULL AS `tipo`,
 NULL AS `capacidad_maxima`,
 NULL AS `ocupacion_actual`,
 NULL AS `porcentaje_ocupacion`,
 NULL AS `estado` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vista_stock_bajo`
--

DROP TABLE IF EXISTS `vista_stock_bajo`;
/*!50001 DROP VIEW IF EXISTS `vista_stock_bajo`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vista_stock_bajo` AS SELECT
 NULL AS `id`,
 NULL AS `nombre`,
 NULL AS `stock_actual`,
 NULL AS `stock_minimo`,
 NULL AS `unidad` */;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `vista_bovinos_completo`
--

/*!50001 DROP VIEW IF EXISTS `vista_bovinos_completo`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`silentroot`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vista_bovinos_completo` AS select `b`.`id` AS `id`,`b`.`id_siniiga` AS `id_siniiga`,`b`.`sexo` AS `sexo`,`b`.`raza` AS `raza`,`b`.`peso_entrada` AS `peso_entrada`,`b`.`categoria` AS `categoria`,`b`.`edad_meses` AS `edad_meses`,`b`.`color` AS `color`,`b`.`estado` AS `estado`,`b`.`created_at` AS `fecha_ingreso`,`b`.`fecha_salida` AS `fecha_salida`,`c`.`nombre` AS `corral`,`r`.`folio` AS `folio_recepcion`,`p`.`nombre` AS `proveedor` from (((`bovinos` `b` left join `corrales` `c` on(`b`.`corral_id` = `c`.`id`)) left join `recepciones` `r` on(`b`.`recepcion_id` = `r`.`id`)) left join `proveedores` `p` on(`r`.`proveedor_id` = `p`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vista_ocupacion_corrales`
--

/*!50001 DROP VIEW IF EXISTS `vista_ocupacion_corrales`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`silentroot`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vista_ocupacion_corrales` AS select `c`.`id` AS `id`,`c`.`nombre` AS `nombre`,`c`.`tipo` AS `tipo`,`c`.`capacidad_maxima` AS `capacidad_maxima`,count(`b`.`id`) AS `ocupacion_actual`,round(count(`b`.`id`) / `c`.`capacidad_maxima` * 100,2) AS `porcentaje_ocupacion`,`c`.`estado` AS `estado` from (`corrales` `c` left join `bovinos` `b` on(`b`.`corral_id` = `c`.`id` and `b`.`estado` = 'en_corral')) group by `c`.`id`,`c`.`nombre`,`c`.`tipo`,`c`.`capacidad_maxima`,`c`.`estado` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vista_stock_bajo`
--

/*!50001 DROP VIEW IF EXISTS `vista_stock_bajo`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`silentroot`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vista_stock_bajo` AS select `i`.`id` AS `id`,`i`.`nombre` AS `nombre`,`inv`.`stock_actual` AS `stock_actual`,`inv`.`stock_minimo` AS `stock_minimo`,`inv`.`unidad` AS `unidad` from (`inventario_alimento` `inv` join `ingredientes` `i` on(`inv`.`ingrediente_id` = `i`.`id`)) where `inv`.`stock_actual` <= `inv`.`stock_minimo` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-10-22 21:12:25
