<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et a un rôle autorisé
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['enseignant', 'etudiant'])) {
    http_response_code(403);
    die('Accès non autorisé');
}

// Vérifier que l'ID du projet est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die('ID de projet invalide');
}

$projet_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

try {
    // Construire la requête selon le rôle de l'utilisateur
    if ($user_role === 'enseignant') {
        // L'enseignant peut télécharger les projets qu'il encadre
        $stmt = $pdo->prepare("
            SELECT p.*, u.nom as etudiant_nom, u.prenom as etudiant_prenom
            FROM projets p 
            LEFT JOIN utilisateurs u ON p.etudiant_id = u.id
            WHERE p.id = ? AND p.encadrant_id = ?
        ");
        $stmt->execute([$projet_id, $user_id]);
    } else {
        // L'étudiant peut télécharger ses propres projets
        $stmt = $pdo->prepare("
            SELECT p.*, u.nom as etudiant_nom, u.prenom as etudiant_prenom
            FROM projets p 
            LEFT JOIN utilisateurs u ON p.etudiant_id = u.id
            WHERE p.id = ? AND p.etudiant_id = ?
        ");
        $stmt->execute([$projet_id, $user_id]);
    }

    $projet = $stmt->fetch();

    if (!$projet) {
        http_response_code(404);
        die('Projet non trouvé ou accès non autorisé');
    }

    // Vérifier qu'un fichier est associé au projet
    if (empty($projet['fichier_chemin'])) {
        http_response_code(404);
        die('Aucun fichier associé à ce projet');
    }

    // Construire le chemin complet du fichier
    $file_path = $projet['fichier_chemin'];

    // Vérifier que le fichier existe physiquement
    if (!file_exists($file_path) || !is_readable($file_path)) {
        error_log("Fichier introuvable ou illisible: $file_path");
        http_response_code(404);
        die('Fichier introuvable sur le serveur');
    }

    // Récupérer les informations du fichier
    $file_size = filesize($file_path);
    $file_info = pathinfo($file_path);
    
    // Utiliser le nom original du fichier s'il est disponible, sinon utiliser le nom du fichier sur le serveur
    $original_filename = $projet['fichier_nom_original'] ?? $file_info['basename'];
    
    // Si pas de nom original, créer un nom basé sur le titre du projet
    if (empty($original_filename)) {
        $extension = $file_info['extension'] ?? 'pdf';
        $safe_title = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $projet['titre']);
        $original_filename = $safe_title . '.' . $extension;
    }

    // Déterminer le type MIME
    $mime_type = getMimeType($file_path);

    // Nettoyer le buffer de sortie
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Envoyer les headers pour le téléchargement
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $original_filename . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    // Pour les gros fichiers, lire et envoyer par chunks
    if ($file_size > 1024 * 1024) { // Si le fichier fait plus de 1MB
        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            die('Erreur lors de la lecture du fichier');
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024); // Lire par chunks de 1MB
            echo $chunk;
            flush();
        }
        fclose($handle);
    } else {
        // Pour les petits fichiers, lire d'un coup
        readfile($file_path);
    }

    // Logger le téléchargement avec des détails adaptés au rôle
    try {
        $action_type = ($user_role === 'enseignant') ? 'download_file_teacher' : 'download_file_student';
        
        // Utiliser la table appropriée selon le rôle
        if ($user_role === 'enseignant') {
            $stmt = $pdo->prepare("
                INSERT INTO actions_administrateur (administrateur_id, action, projet_cible_id, details, adresse_ip, date_creation)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
        } else {
            // Pour les étudiants, on peut créer une table similaire ou utiliser la même avec un champ role
            $stmt = $pdo->prepare("
                INSERT INTO actions_administrateur (administrateur_id, action, projet_cible_id, details, adresse_ip, date_creation)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
        }
        
        $details = json_encode([
            'fichier' => $original_filename,
            'projet_titre' => $projet['titre'],
            'etudiant' => $projet['etudiant_prenom'] . ' ' . $projet['etudiant_nom'],
            'role_utilisateur' => $user_role,
            'user_id' => $user_id
        ]);
        
        $stmt->execute([
            $user_id,
            $action_type,
            $projet_id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Ne pas interrompre le téléchargement si le logging échoue
        error_log("Erreur logging téléchargement: " . $e->getMessage());
    }

    exit();

} catch (PDOException $e) {
    error_log("Erreur base de données dans download.php: " . $e->getMessage());
    http_response_code(500);
    die('Erreur serveur');
} catch (Exception $e) {
    error_log("Erreur dans download.php: " . $e->getMessage());
    http_response_code(500);
    die('Erreur lors du téléchargement');
}

/**
 * Détermine le type MIME d'un fichier
 */
function getMimeType($file_path) {
    // Utiliser finfo si disponible
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        
        if ($mime_type !== false) {
            return $mime_type;
        }
    }

    // Fallback basé sur l'extension
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
    ];

    return $mime_types[$extension] ?? 'application/octet-stream';
}
?>
