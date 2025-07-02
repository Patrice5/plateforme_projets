<?php
require_once 'config/database.php';

if ($pdo) {
    echo "✅ Connexion réussie à la base de données PostgreSQL sur Render.";
} else {
    echo "❌ Connexion échouée.<br>";

    // Tester manuellement pour afficher l’erreur
    $dsn = getenv("DATABASE_URL");
    try {
        $conn = new PDO($dsn);
        echo "✅ Connexion manuelle réussie.";
    } catch (PDOException $e) {
        echo "❌ Erreur PDO : " . $e->getMessage();
    }
}
