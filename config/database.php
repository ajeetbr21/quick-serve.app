<?php
/**
 * QuickServe - Database Configuration
 * Service Marketplace Platform
 */

class Database {
    // Default to local XAMPP credentials if env vars are not set
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function getConnection() {
        $this->conn = null;

        // Detect environment: Cloud or Local
        $this->host = getenv('DB_HOST') ?: "localhost";
        $this->db_name = getenv('DB_NAME') ?: "nearbyme_db";
        $this->username = getenv('DB_USER') ?: "root";
        $this->password = getenv('DB_PASS') ?: "";

        try {
            // Check if using Cloud SQL Socket (common in GCP)
            if (getenv('DB_SOCKET')) {
                $this->conn = new mysqli(null, $this->username, $this->password, $this->db_name, null, getenv('DB_SOCKET'));
            } else {
                $this->conn = new mysqli($this->host, $this->username, $this->password, $this->db_name);
            }
            
            if ($this->conn->connect_error) {
                die("Connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8mb4");
            
        } catch(Exception $e) {
            echo "Connection error: " . $e->getMessage();
        }

        return $this->conn;
    }

    public function closeConnection() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
?>
