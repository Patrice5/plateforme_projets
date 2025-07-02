<?php
// delete_project.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si la requête est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit();
}

// Vérifier si l'utilisateur est connecté et est un étudiant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Récupérer les données JSON de la requête
$data = json_decode(file_get_contents('php://input'), true);
$project_id = isset($data['project_id']) ? intval($data['project_id']) : 0;

if ($project_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'ID de projet invalide']);
    exit();
}

try {
    // Commencer une transaction
    $pdo->beginTransaction();

    // 1. Vérifier que le projet appartient bien à l'étudiant et qu'il peut être supprimé
    $stmt = $pdo->prepare("
        SELECT id, statut, fichier_chemin 
        FROM projets 
        WHERE id = ? AND etudiant_id = ? AND statut IN ('soumis', 'en_revision')
    ");
    $stmt->execute([$project_id, $user_id]);
    $projet = $stmt->fetch();

    if (!$projet) {
        $pdo->rollBack();
        header('HTTP/1.1 404 Not Found');
        echo json_encode([
            'success' => false, 
            'message' => 'Projet non trouvé ou ne peut pas être supprimé',
            'details' => 'Seuls les projets avec statut "soumis" ou "en_revision" peuvent être supprimés'
        ]);
        exit();
    }

    // 2. Supprimer les commentaires associés au projet
    $stmt = $pdo->prepare("DELETE FROM commentaires WHERE projet_id = ?");
    $stmt->execute([$project_id]);

    // 3. Supprimer le fichier associé s'il existe (avec vérification de sécurité)
    if (!empty($projet['fichier_chemin'])) {
        $upload_dir = realpath(__DIR__ . '/../uploads/');
        $file_path = realpath($upload_dir . '/' . basename($projet['fichier_chemin']));
        
        // Vérifier que le fichier est bien dans le répertoire uploads
        if ($file_path && strpos($file_path, $upload_dir) === 0 && file_exists($file_path)) {
            if (!unlink($file_path)) {
                error_log("Échec de suppression du fichier: " . $file_path);
                // On continue quand même la suppression en base
            }
        }
    }

    // 4. Supprimer le projet lui-même
    $stmt = $pdo->prepare("DELETE FROM projets WHERE id = ?");
    $stmt->execute([$project_id]);

    // Valider la transaction
    $pdo->commit();

    // Réponse JSON de succès
    echo json_encode([
        'success' => true,
        'message' => 'Projet supprimé avec succès'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Erreur suppression projet (PDO): " . $e->getMessage() . " - Project ID: " . $project_id);
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la suppression du projet',
        'error' => $e->getMessage(),
        'error_type' => 'database'
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erreur suppression projet (General): " . $e->getMessage() . " - Project ID: " . $project_id);
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la suppression du projet',
        'error' => $e->getMessage(),
        'error_type' => 'general'
    ]);
}
