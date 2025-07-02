<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrateur') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$messageType = '';

$stmt = $pdo->prepare("SELECT * FROM filieres WHERE actif = true ORDER BY nom");
$stmt->execute();
$filieres = $stmt->fetchAll();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
	    // Créer un nouvel utilisateur
	    $nom = trim($_POST['nom']);
	    $prenom = trim($_POST['prenom']);
	    $email = trim($_POST['email']);
	    $role = $_POST['role'];
	    $password = $_POST['password'];
	    $filiere_id = !empty($_POST['filiere_id']) ? $_POST['filiere_id'] : null;
	    $niveau = !empty($_POST['niveau']) ? $_POST['niveau'] : null;
	    
	    // Validation
	    if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
		throw new Exception("Tous les champs sont obligatoires");
	    }
	    
	    // Validation spécifique pour les étudiants
	    if ($role === 'etudiant' && (empty($filiere_id) || empty($niveau))) {
		throw new Exception("La filière et le niveau sont obligatoires pour les étudiants");
	    }
	    
	    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		throw new Exception("Email invalide");
	    }
	    
	    // Vérifier si l'email existe déjà
	    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
	    $stmt->execute([$email]);
	    if ($stmt->fetch()) {
		throw new Exception("Cet email est déjà utilisé");
	    }
	    
	    // Hasher le mot de passe
	    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
	    
	    // Insérer l'utilisateur
	    $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role, filiere_id, niveau, cree_par, date_creation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
	    $stmt->execute([$nom, $prenom, $email, $hashedPassword, $role, $filiere_id, $niveau, $user_id]);
    
    $message = "Utilisateur créé avec succès";
    $messageType = 'success';
    break;
                case 'update':
		    // Modifier un utilisateur
		    $id = $_POST['user_id'];
		    $nom = trim($_POST['nom']);
		    $prenom = trim($_POST['prenom']);
		    $email = trim($_POST['email']);
		    $role = $_POST['role'];
		    $filiere_id = !empty($_POST['filiere_id']) ? $_POST['filiere_id'] : null;
		    $niveau = !empty($_POST['niveau']) ? $_POST['niveau'] : null;
		    
		    if (empty($nom) || empty($prenom) || empty($email)) {
			throw new Exception("Tous les champs sont obligatoires");
		    }
		    
		    // Validation spécifique pour les étudiants
		    if ($role === 'etudiant' && (empty($filiere_id) || empty($niveau))) {
			throw new Exception("La filière et le niveau sont obligatoires pour les étudiants");
		    }
		    
		    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception("Email invalide");
		    }
		    
		    // Vérifier si l'email existe déjà pour un autre utilisateur
		    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ?");
		    $stmt->execute([$email, $id]);
		    if ($stmt->fetch()) {
			throw new Exception("Cet email est déjà utilisé par un autre utilisateur");
		    }
		    
		    // Mettre à jour l'utilisateur
		    $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, role = ?, filiere_id = ?, niveau = ? WHERE id = ?");
		    $stmt->execute([$nom, $prenom, $email, $role, $filiere_id, $niveau, $id]);
		    
		    // Mettre à jour le mot de passe si fourni
		    if (!empty($_POST['password'])) {
			$hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
			$stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe_hash = ? WHERE id = ?");
			$stmt->execute([$hashedPassword, $id]);
		    }
		    
		    $message = "Utilisateur modifié avec succès";
		    $messageType = 'success';
		    break;
                    
                case 'delete':
                    // Supprimer un utilisateur
                    $id = $_POST['user_id'];
                    
                    // Vérifier qu'on ne supprime pas le dernier administrateur
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'administrateur'");
                    $stmt->execute();
                    $adminCount = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("SELECT role FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$id]);
                    $userRole = $stmt->fetchColumn();
                    
                    if ($userRole === 'administrateur' && $adminCount <= 1) {
                        throw new Exception("Impossible de supprimer le dernier administrateur");
                    }
                    
                    // Supprimer l'utilisateur
                    $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $message = "Utilisateur supprimé avec succès";
                    $messageType = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Récupération des utilisateurs avec pagination et filtre
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : '';

$whereConditions = [];
$params = [];

if (!empty($search)) {
    // CORRECTION: Préfixer les colonnes avec l'alias de table pour éviter l'ambiguïté
    $whereConditions[] = "(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($roleFilter)) {
    $whereConditions[] = "u.role = ?";
    $params[] = $roleFilter;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Compter le total des utilisateurs
$countSql = "SELECT COUNT(*) FROM utilisateurs u 
             LEFT JOIN filieres f ON u.filiere_id = f.id 
             $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalUsers = $stmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Récupérer les utilisateurs
$sql = "SELECT u.*, f.nom as filiere_nom,
        (SELECT COUNT(*) FROM projets WHERE etudiant_id = u.id OR encadrant_id = u.id) as nb_projets
        FROM utilisateurs u 
        LEFT JOIN filieres f ON u.filiere_id = f.id
        $whereClause 
        ORDER BY u.date_creation DESC 
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Récupérer les informations de l'utilisateur admin connecté
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$user_id]);
$currentUser = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - UNB-ESI</title>
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
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        
        .sidebar {
            transition: all 0.3s ease;
        }
        
        .nav-link {
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            transform: translateX(5px);
        }
        
	.modal {
    transition: all 0.3s ease;
}

.modal-backdrop {
    backdrop-filter: blur(4px);
}

/* Fix pour s'assurer que le modal est correctement affiché */
#userModal .bg-white {
    min-height: auto;
    max-height: 90vh;
}

/* Assurer que le contenu peut défiler */
#userModal .overflow-y-auto {
    max-height: calc(90vh - 140px); /* Soustraire la hauteur de l'en-tête et du pied */
}

/* Améliorer l'expérience sur mobile */
@media (max-width: 640px) {
    #userModal .max-w-md {
        max-width: 95vw;
        margin: 0.5rem;
        max-height: 95vh;
    }
    
    #userModal .p-6 {
        padding: 1rem;
    }
    
    #userModal .grid-cols-2 {
        grid-template-columns: 1fr;
    }
    
    #userModal .overflow-y-auto {
        max-height: calc(95vh - 120px);
    }
}

/* Assurer la visibilité sur très petits écrans */
@media (max-height: 600px) {
    #userModal .bg-white {
        max-height: 95vh;
        margin: 0.25rem auto;
    }
    
    #userModal .overflow-y-auto {
        max-height: calc(95vh - 100px);
    }
}

/* Personnaliser la barre de défilement pour Webkit */
.overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
        
        .table-row:hover {
            background-color: #f8fafc;
            transform: translateX(2px);
            transition: all 0.2s ease;
        }
        
        @media (max-width: 640px) {
    .table-row td {
        padding: 0.5rem;
        font-size: 0.875rem;
    }
    .table-row td:first-child {
        padding-left: 1rem;
    }
    .table-row td:last-child {
        padding-right: 1rem;
    }
}



        
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex flex-col md:flex-row min-h-screen">
        <!-- Mobile header -->
        <div class="md:hidden bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-graduation-cap text-2xl text-indigo-600"></i>
                    <span class="font-bold text-gray-800">UNB-ESI Admin</span>
                </div>
                <button id="mobile-menu-button" class="hamburger p-2 focus:outline-none">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu bg-white shadow-md" style="max-height: 0; overflow: hidden; transition: max-height 0.3s ease;">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-user-shield text-red-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></p>
                            <p class="text-xs text-gray-500">Administrateur</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_administrateur.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-red-50 text-red-700">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Utilisateurs</span>
                        </a>
                        <a href="gestion_projets.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets</span>
                        </a>
                       <!-- <a href="gestion_fichiers.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-folder w-5 text-center"></i>
                            <span>Fichiers</span>
                        </a>-->
                        
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
                    <i class="fas fa-graduation-cap text-3xl text-indigo-600"></i>
                    <span class="text-xl font-bold text-gray-800">UNB-ESI</span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto">
                <div class="p-4">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-user-shield text-red-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></p>
                            <p class="text-xs text-gray-500">Administrateur</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_administrateur.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-red-50 text-red-700">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Utilisateurs</span>
                        </a>
                        <a href="gestion_projets.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets</span>
                        </a>
                      <!--  <a href="gestion_fichiers.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-folder w-5 text-center"></i>
                            <span>Fichiers</span>
                        </a>-->
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
            <div class="bg-gradient-to-r from-red-500 to-pink-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <h1 class="text-2xl font-bold">Gestion des Utilisateurs</h1>
                <p class="opacity-90">Gérez les comptes utilisateurs, étudiants et enseignants.</p>
            </div>
            
            <!-- Messages -->
            <?php if (!empty($message)): ?>
            <div class="mb-6">
                <div class="alert-animation p-4 rounded-lg shadow-md <?php echo $messageType === 'success' ? 'bg-green-100 border-l-4 border-green-500 text-green-700' : 'bg-red-100 border-l-4 border-red-500 text-red-700'; ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-3"></i>
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Filters and Search -->
            <!-- Filters and Search - Version améliorée pour la responsivité -->
<div class="bg-white rounded-xl shadow-md p-4 mb-6 animate-fade-in">
    <div class="flex flex-col sm:flex-row gap-4">
        <!-- Champ de recherche -->
        <div class="flex-1 min-w-0">
            <form method="GET" class="flex flex-col sm:flex-row gap-2 w-full">
                <!-- Groupe recherche + filtre -->
                <div class="flex flex-1 gap-2">
                    <!-- Champ recherche -->
                    <div class="relative flex-1 min-w-[150px]">
                        <input type="text" name="search" placeholder="Rechercher..." 
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                    
                    <!-- Sélecteur de rôle -->
                    <select name="role" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent w-full sm:w-auto">
                        <option value="">Tous</option>
                        <option value="etudiant" <?php echo $roleFilter === 'etudiant' ? 'selected' : ''; ?>>Étudiants</option>
                        <option value="enseignant" <?php echo $roleFilter === 'enseignant' ? 'selected' : ''; ?>>Enseignants</option>
                        <option value="administrateur" <?php echo $roleFilter === 'administrateur' ? 'selected' : ''; ?>>Admins</option>
                    </select>
                </div>
                
                <!-- Boutons -->
                <div class="flex gap-2">
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap w-full sm:w-auto">
                        <i class="fas fa-filter mr-2"></i>Filtrer
                    </button>
                    
                    <button type="button" onclick="openCreateModal()" 
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors whitespace-nowrap w-full sm:w-auto">
                        <i class="fas fa-plus mr-2"></i>Nouveau Utilisateur
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
                
            
            <!-- Users Table -->
            <div class="bg-white rounded-xl shadow-md animate-fade-in">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Liste des Utilisateurs 
                        <span class="text-sm font-normal text-gray-500">(<?php echo $totalUsers; ?> utilisateurs)</span>
                    </h3>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rôle</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Projets</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Inscription</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                <tr class="table-row">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 w-10 h-10">
                                                <div class="w-10 h-10 rounded-full <?php echo $user['role'] === 'etudiant' ? 'bg-blue-100' : ($user['role'] === 'enseignant' ? 'bg-green-100' : 'bg-red-100'); ?> flex items-center justify-center">
                                                    <i class="fas <?php echo $user['role'] === 'etudiant' ? 'fa-user-graduate text-blue-600' : ($user['role'] === 'enseignant' ? 'fa-chalkboard-teacher text-green-600' : 'fa-user-shield text-red-600'); ?>"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $user['role'] === 'etudiant' ? 'bg-blue-100 text-blue-800' : ($user['role'] === 'enseignant' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'); ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">
                                            <?php echo $user['nb_projets']; ?> projet<?php echo $user['nb_projets'] > 1 ? 's' : ''; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo formatDateFrench($user['date_creation']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" 
                                                    class="text-indigo-600 hover:text-indigo-900 transition-colors">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user['id'] != $user_id): ?>
                                            <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>')" 
                                                    class="text-red-600 hover:text-red-900 transition-colors">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                        <p>Aucun utilisateur trouvé</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Affichage de <?php echo ($offset + 1); ?> à <?php echo min($offset + $perPage, $totalUsers); ?> sur <?php echo $totalUsers; ?> utilisateurs
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>" 
                                   class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors">
                                    Précédent
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>" 
                                   class="px-3 py-2 text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded transition-colors">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>" 
                                   class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors">
                                    Suivant
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Create/Edit User Modal -->
<div id="userModal" class="modal fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4 py-6 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full my-8 max-h-screen overflow-hidden flex flex-col">
            <!-- En-tête fixe -->
            <div class="p-6 border-b border-gray-200 flex-shrink-0">
                <div class="flex items-center justify-between">
                    <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">Nouvel Utilisateur</h3>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <form id="userForm" method="POST" class="flex flex-col flex-1 min-h-0">
                <!-- Contenu défilable -->
                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prénom</label>
                            <input type="text" name="prenom" id="prenom" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nom</label>
                            <input type="text" name="nom" id="nom" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" name="email" id="email" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                        <select name="role" id="role" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Sélectionner un rôle</option>
                            <option value="etudiant">Étudiant</option>
                            <option value="enseignant">Enseignant</option>
                            <option value="administrateur">Administrateur</option>
                        </select>
                    </div>
                    
                    <div id="studentFields" class="space-y-4 hidden">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filière</label>
                            <select name="filiere_id" id="filiere" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Sélectionner une filière</option>
                                <?php foreach ($filieres as $filiere): ?>
                                    <option value="<?php echo $filiere['id']; ?>"><?php echo htmlspecialchars($filiere['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Niveau</label>
                            <select name="niveau" id="niveau"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Sélectionner un niveau</option>
                                <option value="1">L1-TC1</option>
                                <option value="2">L2-TC2</option>
                                <option value="3">L3-IRS</option>
                                <option value="4">L3-ISI</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mot de passe <span id="passwordRequired" class="text-red-500">*</span>
                            <span id="passwordOptional" class="text-gray-500 text-xs hidden">(laisser vide pour ne pas modifier)</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="password" id="password"
                                   class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <button type="button" onclick="togglePassword()" class="absolute right-3 top-2 text-gray-400 hover:text-gray-600">
                                <i id="passwordIcon" class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Pied de page fixe -->
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-3 flex-shrink-0">
                    <button type="button" onclick="closeModal()" 
                            class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-save mr-2"></i>
                        <span id="submitText">Créer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
  
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-sm w-full">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 bg-red-100 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 text-center mb-2">Confirmer la suppression</h3>
                    <p class="text-gray-600 text-center mb-6">
                        Êtes-vous sûr de vouloir supprimer l'utilisateur <strong id="deleteUserName"></strong> ?
                        Cette action est irréversible.
                    </p>
                    
                    <form id="deleteForm" method="POST" class="flex justify-center space-x-3">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" id="deleteUserId">
                        
                        <button type="button" onclick="closeDeleteModal()" 
                                class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-trash mr-2"></i>
                            Supprimer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            const icon = this.querySelector('i');
            
            if (menu.style.maxHeight === '0px' || menu.style.maxHeight === '') {
                menu.style.maxHeight = menu.scrollHeight + 'px';
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                menu.style.maxHeight = '0px';
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
        
        // Fonction pour afficher/masquer les champs étudiant
function toggleStudentFields() {
    const role = document.getElementById('role').value;
    const studentFields = document.getElementById('studentFields');
    const filiereSelect = document.getElementById('filiere');
    const niveauSelect = document.getElementById('niveau');
    
    if (role === 'etudiant') {
        studentFields.classList.remove('hidden');
        filiereSelect.required = true;
        niveauSelect.required = true;
    } else {
        studentFields.classList.add('hidden');
        filiereSelect.required = false;
        niveauSelect.required = false;
        filiereSelect.value = '';
        niveauSelect.value = '';
    }
}

        
        // Modal functions
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Nouvel Utilisateur';
            document.getElementById('formAction').value = 'create';
            document.getElementById('submitText').textContent = 'Créer';
            document.getElementById('passwordRequired').style.display = 'inline';
            document.getElementById('passwordOptional').style.display = 'none';
            document.getElementById('password').required = true;
            document.getElementById('userForm').reset();
            document.getElementById('userModal').classList.remove('hidden');
        }
        
       function openEditModal(user) {
    document.getElementById('modalTitle').textContent = 'Modifier l\'utilisateur';
    document.getElementById('formAction').value = 'update';
    document.getElementById('submitText').textContent = 'Modifier';
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordOptional').style.display = 'inline';
    document.getElementById('password').required = false;
    
    // Remplir les champs
    document.getElementById('userId').value = user.id;
    document.getElementById('prenom').value = user.prenom;
    document.getElementById('nom').value = user.nom;
    document.getElementById('email').value = user.email;
    document.getElementById('role').value = user.role;
    document.getElementById('password').value = '';
    
    // Remplir filière et niveau si étudiant
    if (user.role === 'etudiant') {
        document.getElementById('filiere').value = user.filiere_id || '';
        document.getElementById('niveau').value = user.niveau || '';
    }
    
    // Afficher/masquer les champs étudiant
    toggleStudentFields();
    
    document.getElementById('userModal').classList.remove('hidden');
}
        
        function closeModal() {
            document.getElementById('userModal').classList.add('hidden');
        }
        
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }
        
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // Close modals when clicking outside
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-animation');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });
        
        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
    const prenom = document.getElementById('prenom').value.trim();
    const nom = document.getElementById('nom').value.trim();
    const email = document.getElementById('email').value.trim();
    const role = document.getElementById('role').value;
    const password = document.getElementById('password').value;
    const action = document.getElementById('formAction').value;
    const filiere = document.getElementById('filiere').value;
    const niveau = document.getElementById('niveau').value;
    
    if (!prenom || !nom || !email || !role) {
        e.preventDefault();
        alert('Veuillez remplir tous les champs obligatoires.');
        return;
    }
    
    // Validation spécifique pour les étudiants
    if (role === 'etudiant' && (!filiere || !niveau)) {
        e.preventDefault();
        alert('La filière et le niveau sont obligatoires pour les étudiants.');
        return;
    }
    
    if (action === 'create' && !password) {
        e.preventDefault();
        alert('Le mot de passe est obligatoire pour créer un utilisateur.');
        return;
    }
    
    // Validation email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Veuillez saisir une adresse email valide.');
        return;
    }
    
    // Validation mot de passe (si fourni)
    if (password && password.length < 6) {
        e.preventDefault();
        alert('Le mot de passe doit contenir au moins 6 caractères.');
        return;
    }
});
        
        // Search form auto-submit on role change
        document.querySelector('select[name="role"]').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('role').addEventListener('change', toggleStudentFields);
        
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close modals
            if (e.key === 'Escape') {
                if (!document.getElementById('userModal').classList.contains('hidden')) {
                    closeModal();
                }
                if (!document.getElementById('deleteModal').classList.contains('hidden')) {
                    closeDeleteModal();
                }
            }
            
            // Ctrl+N to open create modal
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openCreateModal();
            }
        });
    </script>
</body>
</html>
