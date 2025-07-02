<?php
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Traitement du formulaire de mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $filiere_id = $_POST['filiere_id'] ?? null;
    $nouveau_mot_de_passe = trim($_POST['nouveau_mot_de_passe'] ?? '');
    $confirmer_mot_de_passe = trim($_POST['confirmer_mot_de_passe'] ?? '');
    $mot_de_passe_actuel = trim($_POST['mot_de_passe_actuel'] ?? '');

    // Validation
    $errors = [];
    
    if (empty($nom)) $errors[] = "Le nom est requis";
    if (empty($prenom)) $errors[] = "Le prénom est requis";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Un email valide est requis";
    }
    if (empty($filiere_id)) $errors[] = "La filière est requise";
    
    // Vérifier si l'email existe déjà pour un autre utilisateur
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = "Cette adresse email est déjà utilisée par un autre compte";
        }
    }
    
    // Si changement de mot de passe
    if (!empty($nouveau_mot_de_passe)) {
        if (empty($mot_de_passe_actuel)) {
            $errors[] = "Votre mot de passe actuel est requis pour le modifier";
        } else {
            // Vérifier le mot de passe actuel
            $stmt = $pdo->prepare("SELECT mot_de_passe_hash FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch();
            
            if (!password_verify($mot_de_passe_actuel, $user_data['mot_de_passe_hash'])) {
                $errors[] = "Le mot de passe actuel est incorrect";
            }
        }
        
        if (strlen($nouveau_mot_de_passe) < 8) {
            $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères";
        }
        
        if ($nouveau_mot_de_passe !== $confirmer_mot_de_passe) {
            $errors[] = "La confirmation du mot de passe ne correspond pas";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Mettre à jour les informations de base
            if (!empty($nouveau_mot_de_passe)) {
                $mot_de_passe_hash = password_hash($nouveau_mot_de_passe, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE utilisateurs 
                    SET nom = ?, prenom = ?, email = ?, filiere_id = ?, mot_de_passe_hash = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nom, $prenom, $email, $filiere_id, $mot_de_passe_hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE utilisateurs 
                    SET nom = ?, prenom = ?, email = ?, filiere_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nom, $prenom, $email, $filiere_id, $user_id]);
            }
            
            $pdo->commit();
            $success_message = "Votre profil a été mis à jour avec succès !";
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

try {
    // Récupérer les informations de l'utilisateur
    $stmt = $pdo->prepare("
        SELECT u.*, f.nom as filiere_nom 
        FROM utilisateurs u
        LEFT JOIN filieres f ON u.filiere_id = f.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        logoutUser();
        header('Location: ../index.php');
        exit();
    }

    // Récupérer toutes les filières
    $stmt = $pdo->prepare("SELECT * FROM filieres WHERE actif = TRUE ORDER BY nom");
    $stmt->execute();
    $filieres = $stmt->fetchAll();


} catch (PDOException $e) {
    error_log("Erreur profil enseignant: " . $e->getMessage());
    $user = null;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - Enseignant - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .sidebar {
            transition: all 0.3s ease;
        }
        
        .nav-link {
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            transform: translateX(5px);
        }
        
        .form-input {
            transition: all 0.2s ease;
        }
        
        .form-input:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }
        
        .hamburger {
            transition: all 0.3s ease;
        }
        
        .hamburger.active {
            transform: rotate(90deg);
        }
        
        .mobile-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .mobile-menu.open {
            max-height: 1000px;
        }

        .avatar-container {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .avatar-container:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex flex-col md:flex-row min-h-screen">
        <!-- Mobile header -->
        <div class="md:hidden bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-chalkboard-teacher text-2xl text-emerald-600"></i>
                    <span class="font-bold text-gray-800">UNB-ESI</span>
                </div>
                <button id="mobile-menu-button" class="p-2 focus:outline-none">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu bg-white shadow-md">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fas fa-chalkboard-teacher text-emerald-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Encadrant</p>
                            <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_enseignant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                        <a href="../logout.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Déconnexion</span>
                        </a>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- Desktop sidebar -->
        <aside class="sidebar hidden md:flex md:flex-col w-64 bg-white shadow-lg">
            <div class="flex items-center justify-center p-6">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-chalkboard-teacher text-3xl text-emerald-600"></i>
                    <span class="text-xl font-bold text-gray-800">UNB-ESI</span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto">
                <div class="p-4">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fas fa-chalkboard-teacher text-emerald-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Encadrant</p>
                            <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_enseignant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                    </nav>
                </div>
            </div>
            
            <div class="p-4 border-t">
                <a href="../logout.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>
        
        <!-- Main content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Mon Profil</h1>
                        <p class="opacity-90">Gérez vos informations personnelles et vos paramètres de compte</p>
                    </div>
                    <div class="avatar-container">
                        <div class="w-16 h-16 rounded-full bg-white bg-opacity-20 flex items-center justify-center">
                            <i class="fas fa-user text-3xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6" id="success-message">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo $success_message; ?></span>
                        <button onclick="this.parentElement.parentElement.style.display='none'" class="ml-auto">
                            <i class="fas fa-times text-green-500 hover:text-green-700"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6" id="error-message">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $error_message; ?></span>
                        <button onclick="this.parentElement.parentElement.style.display='none'" class="ml-auto">
                            <i class="fas fa-times text-red-500 hover:text-red-700"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <div >
                <!-- Formulaire de modification -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-md">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-edit text-green-600 mr-2"></i>
                                Modifier mes Informations
                            </h3>
                        </div>
                        
                        <form method="POST" class="p-6 space-y-6">
                            <!-- Informations personnelles -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="nom" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-user mr-1"></i>
                                        Nom *
                                    </label>
                                    <input 
                                        type="text" 
                                        id="nom" 
                                        name="nom" 
                                        value="<?php echo htmlspecialchars($user['nom']); ?>"
                                        required
                                        class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    >
                                </div>
                                
                                <div>
                                    <label for="prenom" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-user mr-1"></i>
                                        Prénom *
                                    </label>
                                    <input 
                                        type="text" 
                                        id="prenom" 
                                        name="prenom" 
                                        value="<?php echo htmlspecialchars($user['prenom']); ?>"
                                        required
                                        class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    >
                                </div>
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-envelope mr-1"></i>
                                    Adresse Email *
                                </label>
                                <input 
                                    type="email" 
                                    id="email" 
                                    name="email" 
                                    value="<?php echo htmlspecialchars($user['email']); ?>"
                                    required
                                    class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                >
                            </div>
                            
                            <!-- Informations académiques -->
                            <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
                                <div>
                                    <label for="filiere_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-graduation-cap mr-1"></i>
                                        Filière d'enseignement *
                                    </label>
                                    <select 
                                        id="filiere_id" 
                                        name="filiere_id" 
                                        required
                                        class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    >
                                        <option value="">Choisir une filière</option>
                                        <?php foreach ($filieres as $filiere): ?>
                                            <option value="<?php echo $filiere['id']; ?>" 
                                                    <?php echo ($filiere['id'] == $user['filiere_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($filiere['nom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                
                            
                            <!-- Section changement de mot de passe -->
                            <div class="border-t pt-6">
                                <h4 class="text-md font-medium text-gray-800 mb-4">
                                    <i class="fas fa-lock text-yellow-600 mr-2"></i>
                                    Changer le Mot de Passe (Optionnel)
                                </h4>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label for="mot_de_passe_actuel" class="block text-sm font-medium text-gray-700 mb-2">
                                            Mot de passe actuel
                                        </label>
                                        <input 
                                            type="password" 
                                            id="mot_de_passe_actuel" 
                                            name="mot_de_passe_actuel" 
                                            class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="Entrez votre mot de passe actuel"
                                        >
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="nouveau_mot_de_passe" class="block text-sm font-medium text-gray-700 mb-2">
                                                Nouveau mot de passe
                                            </label>
                                            <input 
                                                type="password" 
                                                id="nouveau_mot_de_passe" 
                                                name="nouveau_mot_de_passe" 
                                                class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                                placeholder="Minimum 8 caractères"
                                            >
                                        </div>
                                        
                                        <div>
<div>
                            <label for="confirmer_mot_de_passe" class="block text-sm font-medium text-gray-700 mb-2">
                                Confirmer le nouveau mot de passe
                            </label>
                            <input 
                                type="password" 
                                id="confirmer_mot_de_passe" 
                                name="confirmer_mot_de_passe" 
                                class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                placeholder="Confirmez le nouveau mot de passe"
                            >
                        </div>
                    </div>
                    
                    
                </div>
            </div>
            
            <!-- Boutons d'action -->
            <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t">
                <button 
                    type="submit" 
                    class="flex-1 bg-emerald-600 hover:bg-teal-700 text-white font-medium py-3 px-6 rounded-lg transition duration-200 flex items-center justify-center"
                >
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer les Modifications
                </button>
                
                <button 
                    type="reset" 
                    class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-medium py-3 px-6 rounded-lg transition duration-200 flex items-center justify-center"
                    onclick="resetForm()"
                >
                    <i class="fas fa-undo mr-2"></i>
                    Annuler
                </button>
            </div>
        </form>
    </div>
</div>
</div>
</main>
</div>

<script>
// Menu mobile toggle
document.getElementById('mobile-menu-button').addEventListener('click', function() {
    const mobileMenu = document.getElementById('mobile-menu');
    const hamburger = this;
    
    mobileMenu.classList.toggle('open');
    hamburger.classList.toggle('active');
});

// Auto-hide messages après 5 secondes
setTimeout(function() {
    const successMessage = document.getElementById('success-message');
    const errorMessage = document.getElementById('error-message');
    
    if (successMessage) {
        successMessage.style.opacity = '0';
        setTimeout(() => successMessage.style.display = 'none', 300);
    }
    
    if (errorMessage) {
        errorMessage.style.opacity = '0';
        setTimeout(() => errorMessage.style.display = 'none', 300);
    }
}, 5000);

// Fonction pour réinitialiser le formulaire
function resetForm() {
    // Réinitialiser les champs de mot de passe
    document.getElementById('mot_de_passe_actuel').value = '';
    document.getElementById('nouveau_mot_de_passe').value = '';
    document.getElementById('confirmer_mot_de_passe').value = '';
    
    // Optionnel: recharger la page pour restaurer les valeurs originales
    if (confirm('Êtes-vous sûr de vouloir annuler toutes les modifications ?')) {
        location.reload();
    }
}

// Validation côté client
document.querySelector('form').addEventListener('submit', function(e) {
    const nouveauMotDePasse = document.getElementById('nouveau_mot_de_passe').value;
    const confirmerMotDePasse = document.getElementById('confirmer_mot_de_passe').value;
    const motDePasseActuel = document.getElementById('mot_de_passe_actuel').value;
    
    // Si un nouveau mot de passe est saisi
    if (nouveauMotDePasse) {
        if (!motDePasseActuel) {
            e.preventDefault();
            alert('Veuillez entrer votre mot de passe actuel pour le modifier.');
            document.getElementById('mot_de_passe_actuel').focus();
            return;
        }
        
        if (nouveauMotDePasse.length < 8) {
            e.preventDefault();
            alert('Le nouveau mot de passe doit contenir au moins 8 caractères.');
            document.getElementById('nouveau_mot_de_passe').focus();
            return;
        }
        
        if (nouveauMotDePasse !== confirmerMotDePasse) {
            e.preventDefault();
            alert('La confirmation du mot de passe ne correspond pas.');
            document.getElementById('confirmer_mot_de_passe').focus();
            return;
        }
    }
    
    // Confirmation avant soumission
    if (!confirm('Êtes-vous sûr de vouloir enregistrer ces modifications ?')) {
        e.preventDefault();
    }
});

// Animation d'entrée progressive
document.addEventListener('DOMContentLoaded', function() {
    const elements = document.querySelectorAll('.animate-fade-in');
    elements.forEach((el, index) => {
        el.style.animationDelay = `${index * 0.1}s`;
    });
});

// Indicateur de force du mot de passe
document.getElementById('nouveau_mot_de_passe').addEventListener('input', function() {
    const password = this.value;
    const strengthIndicator = document.getElementById('password-strength');
    
    if (!strengthIndicator) {
        // Créer l'indicateur s'il n'existe pas
        const indicator = document.createElement('div');
        indicator.id = 'password-strength';
        indicator.className = 'mt-2 text-sm';
        this.parentNode.appendChild(indicator);
    }
    
    if (password.length === 0) {
        document.getElementById('password-strength').innerHTML = '';
        return;
    }
    
    let strength = 0;
    let feedback = [];
    
    if (password.length >= 8) strength++;
    else feedback.push('au moins 8 caractères');
    
    if (/[a-z]/.test(password)) strength++;
    else feedback.push('une minuscule');
    
    if (/[A-Z]/.test(password)) strength++;
    else feedback.push('une majuscule');
    
    if (/[0-9]/.test(password)) strength++;
    else feedback.push('un chiffre');
    
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    else feedback.push('un caractère spécial');
    
    const strengthColors = ['text-red-600', 'text-orange-500', 'text-yellow-500', 'text-blue-500', 'text-green-600'];
    const strengthTexts = ['Très faible', 'Faible', 'Moyen', 'Bon', 'Très fort'];
    
    const strengthText = strengthTexts[Math.min(strength, 4)];
    const strengthColor = strengthColors[Math.min(strength, 4)];
    
    document.getElementById('password-strength').innerHTML = `
        <span class="${strengthColor}">Force: ${strengthText}</span>
        ${feedback.length > 0 ? `<br><span class="text-gray-500">Manque: ${feedback.join(', ')}</span>` : ''}
    `;
});
</script>

</body>
</html>
