<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();

// Validate project ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die(json_encode(['error' => 'ID de projet invalide']));
}

$project_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['user_role'] ?? null;

try {
    // Verify user has access to this project's comments
    $stmt = $pdo->prepare("
        SELECT p.etudiant_id, p.encadrant_id 
        FROM projets p
        WHERE p.id = ?
    ");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        http_response_code(404);
        die(json_encode(['error' => 'Projet non trouvé']));
    }

    // Check access rights
    $has_access = ($project['etudiant_id'] == $user_id) || 
                 ($project['encadrant_id'] == $user_id) || 
                 ($user_role === 'administrateur');

    if (!$has_access) {
        http_response_code(403);
        die(json_encode(['error' => 'Accès non autorisé']));
    }

    // Get comments
    $stmt = $pdo->prepare("
        SELECT c.*, u.nom, u.prenom, u.role 
        FROM commentaires c
        JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.projet_id = ?
        ORDER BY c.date_creation DESC
    ");
    $stmt->execute([$project_id]);
    $comments = $stmt->fetchAll();

    if (empty($comments)) {
        echo '<div class="p-6 text-center text-gray-500">';
        echo '<i class="fas fa-comment-slash text-3xl mb-4"></i>';
        echo '<p>Aucun commentaire pour ce projet</p>';
        echo '</div>';
    } else {
        echo '<div class="space-y-4">';
        foreach ($comments as $comment) {
            $bgColor = $comment['role'] === 'enseignant' ? 'bg-blue-50' : 
                      ($comment['role'] === 'administrateur' ? 'bg-purple-50' : 'bg-gray-50');
            $borderColor = $comment['role'] === 'enseignant' ? 'border-blue-200' : 
                          ($comment['role'] === 'administrateur' ? 'border-purple-200' : 'border-gray-200');
            
            echo '<div class="'.$bgColor.' border-l-4 '.$borderColor.' p-4 rounded-r-lg">';
            echo '<div class="flex justify-between items-start mb-2">';
            echo '<div class="font-medium">'.htmlspecialchars($comment['prenom'].' '.$comment['nom']).'</div>';
            echo '<div class="text-xs text-gray-500">'.formatDateFrench($comment['date_creation']).'</div>';
            echo '</div>';
            echo '<div class="text-gray-700">'.nl2br(htmlspecialchars($comment['contenu'])).'</div>';
            echo '</div>';
        }
        echo '</div>';
    }
} catch (PDOException $e) {
    error_log("Erreur chargement commentaires: " . $e->getMessage());
    http_response_code(500);
    echo '<div class="text-center py-8 text-red-600">';
    echo '<i class="fas fa-exclamation-triangle text-2xl mb-2"></i>';
    echo '<p>Erreur lors du chargement des commentaires</p>';
    echo '</div>';
}
?>
