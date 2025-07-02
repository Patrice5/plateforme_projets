<?php
/**
 * Fichier: assets/upload.php
 * Gestion des uploads de fichiers pour les projets
 */

session_start();

// Inclure les fichiers nécessaires
require_once '../config/database.php';
require_once '../includes/functions.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

// Configuration d'upload
$upload_config = [
    'max_size' => 10 * 1024 * 1024, // 10 MB
    'allowed_extensions' => ['pdf', 'doc', 'docx'],
    'allowed_mimes' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ]
];

/**
 * Valider un fichier uploadé
 */
function validateUploadedFile($file, $config) {
    $errors = [];
    
    // Vérifier les erreurs d'upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = "Le fichier est trop volumineux.";
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors[] = "Le fichier n'a été que partiellement uploadé.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = "Aucun fichier n'a été uploadé.";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errors[] = "Dossier temporaire manquant.";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errors[] = "Impossible d'écrire le fichier sur le disque.";
                break;
            default:
                $errors[] = "Erreur d'upload inconnue.";
        }
        return $errors;
    }
    
    // Vérifier la taille
    if ($file['size'] > $config['max_size']) {
        $errors[] = "Le fichier ne doit pas dépasser " . ($config['max_size'] / 1024 / 1024) . " MB.";
    }
    
    if ($file['size'] == 0) {
        $errors[] = "Le fichier est vide.";
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $config['allowed_extensions'])) {
        $errors[] = "Seuls les fichiers " . implode(', ', $config['allowed_extensions']) . " sont autorisés.";
    }
    
    // Vérifier le type MIME
    if (!in_array($file['type'], $config['allowed_mimes'])) {
        $errors[] = "Type de fichier non autorisé.";
    }
    
    // Validation spéciale pour PDF
    if ($extension === 'pdf' && empty($errors)) {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);
            if ($header !== '%PDF') {
                $errors[] = "Le fichier PDF semble corrompu.";
            }
        } else {
            $errors[] = "Impossible de lire le fichier PDF.";
        }
    }
    
    return $errors;
}

/**
 * Créer le dossier de destination
 */
function createUploadDirectory($base_path) {
    $year_month = date('Y/m');
    $upload_dir = $base_path . '/uploads/projets/' . $year_month . '/';
    
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            return false;
        }
        chmod($upload_dir, 0777);
    }
    
    return $upload_dir;
}

/**
 * Générer un nom de fichier sécurisé
 */
function generateSecureFilename($original_name) {
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $name_without_ext = pathinfo($original_name, PATHINFO_FILENAME);
    
    // Nettoyer le nom de fichier
    $clean_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name_without_ext);
    $clean_name = substr($clean_name, 0, 50); // Limiter la longueur
    
    // Générer un nom unique
    return $clean_name . '_' . uniqid() . '_' . time() . '.' . $extension;
}

// Traitement de l'upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => '', 'data' => null];
    
    try {
        // Vérifier qu'un fichier a été envoyé
        if (!isset($_FILES['fichier_projet']) || empty($_FILES['fichier_projet']['name'])) {
            throw new Exception("Aucun fichier sélectionné.");
        }
        
        $file = $_FILES['fichier_projet'];
        
        // Valider le fichier
        $validation_errors = validateUploadedFile($file, $upload_config);
        if (!empty($validation_errors)) {
            throw new Exception(implode(' ', $validation_errors));
        }
        
        // Créer le dossier de destination
        $base_path = dirname(__DIR__); // Remonte d'un niveau depuis assets/
        $upload_dir = createUploadDirectory($base_path);
        
        if (!$upload_dir) {
            throw new Exception("Impossible de créer le dossier de destination.");
        }
        
        // Générer le nom de fichier sécurisé
        $secure_filename = generateSecureFilename($file['name']);
        $full_path = $upload_dir . $secure_filename;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $full_path)) {
            throw new Exception("Erreur lors de la sauvegarde du fichier.");
        }
        
        // Vérifier que le fichier a bien été sauvegardé
        if (!file_exists($full_path)) {
            throw new Exception("Le fichier n'a pas pu être sauvegardé correctement.");
        }
        
        // Chemin relatif pour la base de données
        $relative_path = '/uploads/projets/' . date('Y/m') . '/' . $secure_filename;
        
        // Préparer la réponse
        $response = [
            'success' => true,
            'message' => 'Fichier uploadé avec succès',
            'data' => [
                'original_name' => $file['name'],
                'secure_filename' => $secure_filename,
                'file_path' => $relative_path,
                'file_size' => $file['size'],
                'full_path' => $full_path
            ]
        ];
        
        // Log pour debug
        error_log("Upload réussi: " . $full_path);
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Erreur upload: " . $e->getMessage());
    }
    
    // Retourner la réponse en JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Si ce n'est pas une requête POST, afficher une page d'information
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de fichiers - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md max-w-md w-full">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Service d'Upload</h1>
            <p class="text-gray-600 mb-4">Ce service permet l'upload de fichiers pour les projets.</p>
            
            <div class="bg-blue-50 border border-blue-200 rounded p-4">
                <h3 class="font-medium text-blue-800 mb-2">Fichiers acceptés :</h3>
                <ul class="text-sm text-blue-700">
                    <li>• PDF (max 10MB)</li>
                    <li>• DOC (max 10MB)</li>
                    <li>• DOCX (max 10MB)</li>
                </ul>
            </div>
            
            <div class="mt-6">
                <a href="../etudiant/dashboard_etudiant.php" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                    Retour au dashboard
                </a>
            </div>
        </div>
    </div>
</body>
</html>
