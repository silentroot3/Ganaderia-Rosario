<?php
class Database {
    private static $instance = null;
    private $conn;
    private $host;
	private $port;
    private $dbname;
    private $user;
    private $pass;

    private function __construct() {
        $this->host = DB_HOST;
        $this->port = DB_PORT;
        $this->dbname = DB_NAME;
        $this->user = DB_USER;
        $this->pass = DB_PASS;

        try {
				$this->conn = new PDO(
                	"mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4",
                	$this->user,
                	$this->pass,
                	[
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
        } catch(PDOException $e) {
            error_log("Error de conexión a BD: " . $e->getMessage());
            die("Error de conexión a la base de datos. Por favor, contacte al administrador.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // Prevenir clonación
    private function __clone() {}

    // Prevenir deserialización
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    // Método para ejecutar consultas preparadas
    public function execute($query, $params = []) {
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            error_log("Error en consulta: " . $e->getMessage() . " - Query: " . $query);
            throw $e;
        }
    }

    // Método para obtener un registro
    public function fetchOne($query, $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->fetch();
    }

    // Método para obtener todos los registros
    public function fetchAll($query, $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll();
    }

    // Método para insertar y obtener el último ID
    public function insert($query, $params = []) {
        $this->execute($query, $params);
        return $this->conn->lastInsertId();
    }

    // Método para contar registros
    public function count($query, $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->rowCount();
    }
}
?>
