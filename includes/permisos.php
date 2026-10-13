<?php
/**
 * Sistema de Permisos basado en Roles (RBAC)
 * Proyecto: Ganadería El Rosario
 * 
 * Define qué puede ver y hacer cada rol en cada módulo.
 */

class Permisos {
    
    /**
     * Definición de permisos por rol
     * 
     * Formato: 'rol' => ['modulo' => ['ver', 'crear', 'editar', 'eliminar']]
     */
    private static $permisos = [
        
        // ============================================
        // ADMINISTRADOR: Acceso total
        // ============================================
        'admin' => [
            'dashboard'      => ['ver', 'crear', 'editar', 'eliminar'],
            'recepcion'      => ['ver', 'crear', 'editar', 'eliminar'],
            'bovinos'        => ['ver', 'crear', 'editar', 'eliminar'],
            'sanidad'        => ['ver', 'crear', 'editar', 'eliminar'],
            'clasificacion'  => ['ver', 'crear', 'editar', 'eliminar'],
            'corrales'       => ['ver', 'crear', 'editar', 'eliminar'],
            'alimentacion'   => ['ver', 'crear', 'editar', 'eliminar'],
            'tratamientos'   => ['ver', 'crear', 'editar', 'eliminar'],
            'seguimiento'    => ['ver', 'crear', 'editar', 'eliminar'],
            'costos'         => ['ver', 'crear', 'editar', 'eliminar'],
            'ventas'         => ['ver', 'crear', 'editar', 'eliminar'],
            'reportes'       => ['ver', 'crear', 'editar', 'eliminar'],
            'trazabilidad'   => ['ver', 'crear', 'editar', 'eliminar'],
            'alertas'        => ['ver', 'crear', 'editar', 'eliminar'],
            'usuarios'       => ['ver', 'crear', 'editar', 'eliminar'],
            'configuracion'  => ['ver', 'crear', 'editar', 'eliminar'],
        ],
        
        // ============================================
        // RECEPCIONISTA: Registra llegada de ganado
        // ============================================
        'recepcionista' => [
            'dashboard'      => ['ver'],
            'recepcion'      => ['ver', 'crear', 'editar'],
            'bovinos'        => ['ver', 'crear', 'editar'],
            'trazabilidad'   => ['ver'],
            'alertas'        => ['ver'],
        ],
        
        // ============================================
        // VETERINARIO: Sanidad y tratamientos
        // ============================================
        'veterinario' => [
            'dashboard'      => ['ver'],
            'recepcion'      => ['ver'],
            'bovinos'        => ['ver'],
            'sanidad'        => ['ver', 'crear', 'editar'],
            'tratamientos'   => ['ver', 'crear', 'editar'],
            'seguimiento'    => ['ver'],
            'trazabilidad'   => ['ver'],
            'alertas'        => ['ver'],
        ],
        
        // ============================================
        // CLASIFICADOR: Peso, corrales, seguimiento
        // ============================================
        'clasificador' => [
            'dashboard'      => ['ver'],
            'bovinos'        => ['ver', 'editar'],
            'clasificacion'  => ['ver', 'crear', 'editar'],
            'corrales'       => ['ver', 'crear', 'editar'],
            'seguimiento'    => ['ver', 'crear', 'editar'],
            'trazabilidad'   => ['ver'],
            'alertas'        => ['ver'],
        ],
        
        // ============================================
        // ALIMENTADOR: Solo alimentación
        // ============================================
        'alimentador' => [
            'dashboard'      => ['ver'],
            'corrales'       => ['ver'],
            'alimentacion'   => ['ver', 'crear', 'editar'],
            'alertas'        => ['ver'],
        ],
    ];
    
    /**
     * Definición de los módulos disponibles
     * (nombre => [icono, título, url, rol mínimo])
     */
    private static $modulos = [
        'dashboard'      => ['icono' => '📊', 'titulo' => 'Dashboard',      'url' => '/dashboard'],
        'recepcion'      => ['icono' => '🚚', 'titulo' => 'Recepción',       'url' => '/recepcion'],
        'bovinos'        => ['icono' => '🐄', 'titulo' => 'Bovinos',         'url' => '/bovinos'],
        'sanidad'        => ['icono' => '🩺', 'titulo' => 'Sanidad',         'url' => '/sanidad'],
        'clasificacion'  => ['icono' => '⚖️', 'titulo' => 'Clasificación',   'url' => '/clasificacion'],
        'corrales'       => ['icono' => '🏠', 'titulo' => 'Corrales',        'url' => '/corrales'],
        'alimentacion'   => ['icono' => '🍽️', 'titulo' => 'Alimentación',    'url' => '/alimentacion'],
        'tratamientos'   => ['icono' => '💊', 'titulo' => 'Tratamientos',    'url' => '/tratamientos'],
        'seguimiento'    => ['icono' => '📈', 'titulo' => 'Seguimiento',     'url' => '/seguimiento'],
        'costos'         => ['icono' => '💰', 'titulo' => 'Costos',          'url' => '/costos'],
        'ventas'         => ['icono' => '🚛', 'titulo' => 'Ventas',          'url' => '/ventas'],
        'reportes'       => ['icono' => '📋', 'titulo' => 'Reportes',        'url' => '/reportes'],
        'trazabilidad'   => ['icono' => '🔍', 'titulo' => 'Trazabilidad',    'url' => '/trazabilidad'],
        'alertas'        => ['icono' => '🔔', 'titulo' => 'Alertas',         'url' => '/alertas'],
        'usuarios'       => ['icono' => '👥', 'titulo' => 'Usuarios',        'url' => '/usuarios'],
        'configuracion'  => ['icono' => '⚙️', 'titulo' => 'Configuración',   'url' => '/configuracion'],
    ];
    
    /**
     * Verifica si el usuario actual puede hacer una acción en un módulo
     * 
     * @param string $modulo   Nombre del módulo (ej: 'recepcion')
     * @param string $accion   Acción a verificar ('ver', 'crear', 'editar', 'eliminar')
     * @return bool
     */
    public static function puede($modulo, $accion = 'ver') {
        // Verificar que haya sesión
        if (!isset($_SESSION['rol'])) {
            return false;
        }
        
        $rol = $_SESSION['rol'];
        
        // Admin tiene acceso total
        if ($rol === 'admin') {
            return true;
        }
        
        // Verificar si el rol existe en la matriz
        if (!isset(self::$permisos[$rol])) {
            return false;
        }
        
        // Verificar si el módulo está permitido para el rol
        if (!isset(self::$permisos[$rol][$modulo])) {
            return false;
        }
        
        // Verificar si la acción está permitida
        return in_array($accion, self::$permisos[$rol][$modulo]);
    }
    
    /**
     * Verifica si el usuario puede VER un módulo (atajo)
     */
    public static function puedeVer($modulo) {
        return self::puede($modulo, 'ver');
    }
    
    /**
     * Verifica si el usuario puede CREAR en un módulo
     */
    public static function puedeCrear($modulo) {
        return self::puede($modulo, 'crear');
    }
    
    /**
     * Verifica si el usuario puede EDITAR en un módulo
     */
    public static function puedeEditar($modulo) {
        return self::puede($modulo, 'editar');
    }
    
    /**
     * Verifica si el usuario puede ELIMINAR en un módulo
     */
    public static function puedeEliminar($modulo) {
        return self::puede($modulo, 'eliminar');
    }
    
    /**
     * Obtiene los módulos visibles para el usuario actual
     */
    public static function modulosVisibles() {
        $visibles = [];
        
        foreach (self::$modulos as $clave => $info) {
            if (self::puedeVer($clave)) {
                $visibles[$clave] = $info;
            }
        }
        
        return $visibles;
    }
    
    /**
     * Obtiene la información de un módulo específico
     */
    public static function getModulo($modulo) {
        return self::$modulos[$modulo] ?? null;
    }
    
    /**
     * Middleware: Protege una página completa
     * Si el usuario no tiene permiso, redirige a 403
     * 
     * Uso: Permisos::protegerPagina('recepcion', 'ver');
     */
    public static function protegerPagina($modulo, $accion = 'ver') {
        // Verificar que esté logueado
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        
        // Verificar permiso
        if (!self::puede($modulo, $accion)) {
            // Log del intento
            self::logAccesoDenegado($modulo, $accion);
            
            // Redirigir a 403
            header('Location: ' . BASE_URL . '/403');
            exit;
        }
    }
    
    /**
     * Registra en bitácora un intento de acceso denegado
     */
    private static function logAccesoDenegado($modulo, $accion) {
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    INSERT INTO logs_actividad (usuario_id, accion, descripcion, ip, user_agent)
                    VALUES (?, 'acceso_denegado', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'] ?? null,
                    "Intento de acceso a {$modulo}/{$accion}",
                    $_SERVER['REMOTE_ADDR'] ?? 'desconocida',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'
                ]);
            }
        } catch (Exception $e) {
            error_log("Error al registrar acceso denegado: " . $e->getMessage());
        }
    }
    
    /**
     * Verifica el rol del usuario actual
     */
    public static function getRol() {
        return $_SESSION['rol'] ?? null;
    }
    
    /**
     * Verifica si el usuario es admin
     */
    public static function esAdmin() {
        return self::getRol() === 'admin';
    }
    
    /**
     * Verifica si el usuario es un rol específico
     */
    public static function esRol($rol) {
        return self::getRol() === $rol;
    }
    
    /**
     * Devuelve todos los roles disponibles
     */
    public static function getRoles() {
        return array_keys(self::$permisos);
    }
    
    /**
     * Devuelve todos los módulos del sistema
     */
    public static function getTodosModulos() {
        return self::$modulos;
    }
}
