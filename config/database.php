<?php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_projets');
define('DB_USER', 'patrice'); 
define('DB_PASS', 'fichier');

// Classe simple pour la connexion à la base PostgreSQL
class Database {
    private $pdo;

    public function __construct() {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";dbname=" . DB_NAME;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Log au lieu de die() pour éviter les redirections infinies
            error_log("Erreur connexion BDD : " . $e->getMessage());
            // Crée une réponse propre sans interrompre brutalement l'exécution
            $this->pdo = null;
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}

// Fonction d’accès à la base de données
function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new Database();
    }
    return $db->getConnection();
}

// Évite l'utilisation de $pdo global si non connecté
$pdo = getDB();

