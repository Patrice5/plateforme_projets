<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un étudiant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

try {
    // Récupérer les informations de l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        logoutUser();
        header('Location: ../index.php');
        exit();
    }

    // Récupérer la liste des enseignants pour assignation
    $stmt = $pdo->prepare("SELECT id, nom, prenom, email FROM utilisateurs WHERE role = 'enseignant' AND actif = TRUE ORDER BY nom, prenom");
    $stmt->execute();
    $enseignants = $stmt->fetchAll();

    // Traitement du formulaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $titre = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type_projet = $_POST['type_projet'] ?? '';
        $encadrant_id = $_POST['encadrant_id'] ?? null;
        $mode_assignation = $_POST['mode_assignation'] ?? 'manuel';
        
        // Validation des champs obligatoires
        if (empty($titre)) {
            $error = "Le titre du projet est obligatoire.";
        } elseif (empty($description)) {
            $error = "La description du projet est obligatoire.";
        } elseif (empty($type_projet)) {
            $error = "Le type de projet est obligatoire.";
        } elseif ($mode_assignation === 'manuel' && empty($encadrant_id)) {
            $error = "Veuillez sélectionner un encadrant ou choisir l'assignation automatique.";
        } else {
           // Gestion de l'upload de fichier
$fichier_nom_original = null;
$fichier_chemin = null;
$fichier_taille = 0;

if (isset($_FILES['fichier_projet']) && $_FILES['fichier_projet']['error'] === UPLOAD_ERR_OK) {
    $fichier = $_FILES['fichier_projet'];
    $fichier_nom_original = $fichier['name'];
    $fichier_taille = $fichier['size'];
    
    // Vérifications de sécurité
    $extension = strtolower(pathinfo($fichier_nom_original, PATHINFO_EXTENSION));
    $extensions_autorisees = ['pdf', 'doc', 'docx'];
    
    if (!in_array($extension, $extensions_autorisees)) {
        $error = "Seuls les fichiers PDF, DOC et DOCX sont autorisés.";
    } elseif ($fichier_taille > 10 * 1024 * 1024) { // 10 MB
        $error = "Le fichier ne doit pas dépasser 10 MB.";
    } else {
        $dossier_upload = '../assets/uploads/' . date('Y/m/') . '/';
        
        // Créer le dossier de destination s'il n'existe pas
        if (!file_exists($dossier_upload)) {
            if (!mkdir($dossier_upload, 0755, true)) {
                error_log("Impossible de créer le dossier: " . $dossier_upload);
                $error = "Erreur lors de la création du dossier de destination.";
            }
        }
        
        // Vérifier que le dossier est accessible en écriture
        if (empty($error) && !is_writable($dossier_upload)) {
            error_log("Dossier non accessible en écriture: " . $dossier_upload);
            $error = "Permissions insuffisantes pour l'upload.";
        }
        
        if (empty($error)) {
            // Générer un nom de fichier unique
            $nom_fichier_unique = uniqid() . '_' . time() . '.' . $extension;
            $fichier_chemin = $dossier_upload . $nom_fichier_unique;
            
            if (!move_uploaded_file($fichier['tmp_name'], $fichier_chemin)) {
                error_log("Échec upload - Source: " . $fichier['tmp_name'] . " - Destination: " . $fichier_chemin);
                $error = "Erreur lors de l'upload du fichier.";
                $fichier_chemin = null; // Reset si échec
            }
        }
    }
}
            // Si pas d'erreur, insérer le projet en base
            if (empty($error)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO projets (titre, description, type_projet, fichier_nom_original, 
                                           fichier_chemin, fichier_taille, etudiant_id, encadrant_id, 
                                           mode_assignation, statut) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'soumis')
                    ");
                    
                    $stmt->execute([
                        $titre,
                        $description,
                        $type_projet,
                        $fichier_nom_original,
                        $fichier_chemin,
                        $fichier_taille,
                        $user_id,
                        $mode_assignation === 'automatique' ? null : $encadrant_id,
                        $mode_assignation
                    ]);
                    
                    $message = "Votre projet a été soumis avec succès !";
                    
                    // Redirection après succès
                    header("Location: projets.php?message=" . urlencode($message));
                    exit();
                    
                } catch (PDOException $e) {
                    error_log("Erreur insertion projet: " . $e->getMessage());
                    $error = "Erreur lors de la soumission du projet. Veuillez réessayer.";
                }
            }
        }
    }

} catch (PDOException $e) {
    error_log("Erreur soumission projet: " . $e->getMessage());
    $error = "Erreur de connexion à la base de données.";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soumettre un Projet - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudfare.com/ajax/libs/font-awesone/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="min-h-screen py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <a href="dashboard_etudiant.php" class="text-indigo-600 hover:text-indigo-800">
                            <i class="fas fa-arrow-left text-xl"></i>
                        </a>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Soumettre un Nouveau Projet</h1>
                            <p class="text-gray-600">Remplissez le formulaire pour soumettre votre projet</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Connecté en tant que:</p>
                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($error)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex">
                        <i class="fas fa-exclamation-circle text-red-400 mr-3 mt-1"></i>
                        <div class="text-red-700"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex">
                        <i class="fas fa-check-circle text-green-400 mr-3 mt-1"></i>
                        <div class="text-green-700"><?php echo htmlspecialchars($message); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <div class="bg-white rounded-xl shadow-md">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Informations du Projet</h2>
                    <p class="text-sm text-gray-600 mt-1">Tous les champs marqués d'un astérisque (*) sont obligatoires</p>
                </div>

                <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                    <!-- Titre du projet -->
                    <div>
                        <label for="titre" class="block text-sm font-medium text-gray-700 mb-2">
                            Titre du projet *
                        </label>
                        <input 
                            type="text" 
                            id="titre" 
                            name="titre" 
                            value="<?php echo htmlspecialchars($_POST['titre'] ?? ''); ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Entrez le titre de votre projet"
                            required
                        >
                    </div>

                    <!-- Type de projet -->
                    <div>
                        <label for="type_projet" class="block text-sm font-medium text-gray-700 mb-2">
                            Type de projet *
                        </label>
                        <select 
                            id="type_projet" 
                            name="type_projet" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            required
                        >
                            <option value="">Sélectionnez le type de projet</option>
                            <option value="projet_tutore" <?php echo (($_POST['type_projet'] ?? '') === 'projet_tutore') ? 'selected' : ''; ?>>
                                Projet Tutoré
                            </option>
                            <option value="memoire" <?php echo (($_POST['type_projet'] ?? '') === 'memoire') ? 'selected' : ''; ?>>
                                Mémoire
                            </option>
                            <option value="stage" <?php echo (($_POST['type_projet'] ?? '') === 'stage') ? 'selected' : ''; ?>>
                                Stage
                            </option>
                            <option value="autre" <?php echo (($_POST['type_projet'] ?? '') === 'autre') ? 'selected' : ''; ?>>
                                Autre
                            </option>
                        </select>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description du projet *
                        </label>
                        <textarea 
                            id="description" 
                            name="description" 
                            rows="5"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Décrivez votre projet en détail (objectifs, méthodologie, résultats attendus...)"
                            required
                        ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <!-- Mode d'assignation -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mode d'assignation de l'encadrant *
                        </label>
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input 
                                    type="radio" 
                                    name="mode_assignation" 
                                    value="manuel" 
                                    <?php echo (($_POST['mode_assignation'] ?? 'manuel') === 'manuel') ? 'checked' : ''; ?>
                                    class="mr-2 text-indigo-600 focus:ring-indigo-500"
                                    onchange="toggleEncadrantSelect()"
                                >
                                <span class="text-sm text-gray-700">Je choisis mon encadrant</span>
                            </label>
                            <label class="flex items-center">
                                <input 
                                    type="radio" 
                                    name="mode_assignation" 
                                    value="automatique" 
                                    <?php echo (($_POST['mode_assignation'] ?? '') === 'automatique') ? 'checked' : ''; ?>
                                    class="mr-2 text-indigo-600 focus:ring-indigo-500"
                                    onchange="toggleEncadrantSelect()"
                                >
                                <span class="text-sm text-gray-700">Assignation automatique</span>
                            </label>
                        </div>
                    </div>

                    <!-- Sélection encadrant -->
                    <div id="encadrant_section" class="<?php echo (($_POST['mode_assignation'] ?? 'manuel') === 'automatique') ? 'hidden' : ''; ?>">
                        <label for="encadrant_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Encadrant souhaité
                        </label>
                        <select 
                            id="encadrant_id" 
                            name="encadrant_id" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">Sélectionnez un encadrant</option>
                            <?php foreach ($enseignants as $enseignant): ?>
                                <option value="<?php echo $enseignant['id']; ?>" 
                                        <?php echo (($_POST['encadrant_id'] ?? '') == $enseignant['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($enseignant['prenom'] . ' ' . $enseignant['nom']); ?>
                                    (<?php echo htmlspecialchars($enseignant['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Upload fichier -->
                    <div>
                        <label for="fichier_projet" class="block text-sm font-medium text-gray-700 mb-2">
                            Document du projet
                        </label>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-indigo-400 transition-colors">
                            <div class="text-center">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                <input 
                                    type="file" 
                                    id="fichier_projet" 
                                    name="fichier_projet" 
                                    accept=".pdf,.doc,.docx"
                                    class="hidden"
                                    onchange="updateFileName(this)"
                                >
                                <label for="fichier_projet" class="cursor-pointer">
                                    <span class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors">
                                        Choisir un fichier
                                    </span>
                                </label>
                                <p class="text-sm text-gray-500 mt-2">
                                    Formats acceptés: PDF, DOC, DOCX (Max: 10 MB)
                                </p>
                                <p id="file_name" class="text-sm text-gray-700 mt-2 hidden"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons -->
                    <div class="flex justify-between items-center pt-6 border-t border-gray-200">
                        <a href="dashboard_etudiant.php" class="text-gray-600 hover:text-gray-800 font-medium">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Annuler
                        </a>
                        <button 
                            type="submit" 
                            class="bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 transition-colors font-medium"
                        >
                            <i class="fas fa-paper-plane mr-2"></i>
                            Soumettre le Projet
                        </button>
                    </div>
                </form>
            </div>

            <!-- Aide -->
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex">
                    <i class="fas fa-info-circle text-blue-400 mr-3 mt-1"></i>
                    <div class="text-blue-800">
                        <h3 class="font-medium mb-2">Conseils pour votre soumission :</h3>
                        <ul class="text-sm space-y-1">
                            <li>• Rédigez un titre clair et précis</li>
                            <li>• Décrivez votre projet de manière détaillée</li>
                            <li>• Si vous choisissez l'assignation manuelle, contactez d'abord l'encadrant</li>
                            <li>• Assurez-vous que votre document est au format PDF pour une meilleure compatibilité</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleEncadrantSelect() {
            const modeAssignation = document.querySelector('input[name="mode_assignation"]:checked').value;
            const encadrantSection = document.getElementById('encadrant_section');
            const encadrantSelect = document.getElementById('encadrant_id');
            
            if (modeAssignation === 'automatique') {
                encadrantSection.classList.add('hidden');
                encadrantSelect.value = '';
                encadrantSelect.removeAttribute('required');
            } else {
                encadrantSection.classList.remove('hidden');
                encadrantSelect.setAttribute('required', 'required');
            }
        }

        function updateFileName(input) {
            const fileName = document.getElementById('file_name');
            if (input.files && input.files[0]) {
                fileName.textContent = 'Fichier sélectionné: ' + input.files[0].name;
                fileName.classList.remove('hidden');
            } else {
                fileName.classList.add('hidden');
            }
        }

        // Validation du formulaire
        document.querySelector('form').addEventListener('submit', function(e) {
            const modeAssignation = document.querySelector('input[name="mode_assignation"]:checked').value;
            const encadrantId = document.getElementById('encadrant_id').value;
            
            if (modeAssignation === 'manuel' && !encadrantId) {
                e.preventDefault();
                alert('Veuillez sélectionner un encadrant ou choisir l\'assignation automatique.');
                return false;
            }
        });
    </script>
</body>
</html>
