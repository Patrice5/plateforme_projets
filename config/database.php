<?php
class Database {
    private $pdo;

    public function __construct() {
        $url = getenv("DATABASE_URL");

        if (!$url) {
            error_log("DATABASE_URL non défini.");
            $this->pdo = null;
            return;
        }

        try {
            // Format attendu : postgresql://user:password@host:port/dbname
            $this->pdo = new PDO($url);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur connexion BDD : " . $e->getMessage());
            $this->pdo = null;
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new Database();
    }
    return $db->getConnection();
}

$pdo = getDB();
