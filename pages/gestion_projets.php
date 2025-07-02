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
try {
    $stmt = $pdo->prepare("SELECT nom, prenom, role FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentUser) {
        header('Location: ../index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Erreur récupération utilisateur: " . $e->getMessage());
    header('Location: ../index.php');
    exit();
}


// Fonction pour déboguer l'assignation d'encadrant
function debugAssignSupervisor($pdo, $project_id, $supervisor_id) {
    error_log("DEBUG: Tentative d'assignation - Projet ID: $project_id, Encadrant ID: $supervisor_id");
    
    // Vérifier que le projet existe
    $stmt = $pdo->prepare("SELECT id, titre FROM projets WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        error_log("DEBUG: Projet ID $project_id non trouvé");
        return false;
    }
    
    // Vérifier que l'encadrant existe
    $stmt = $pdo->prepare("SELECT id, nom, prenom, role, actif FROM utilisateurs WHERE id = ?");
    $stmt->execute([$supervisor_id]);
    $supervisor = $stmt->fetch();
    
    if (!$supervisor) {
        error_log("DEBUG: Encadrant ID $supervisor_id non trouvé");
        return false;
    }
    
    error_log("DEBUG: Encadrant trouvé - " . $supervisor['prenom'] . " " . $supervisor['nom'] . " (Role: " . $supervisor['role'] . ", Actif: " . $supervisor['actif'] . ")");
    
    return true;
}
$message = '';
$error = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'assign_supervisor':
    $project_id = (int)$_POST['project_id'];
    $supervisor_id = (int)$_POST['supervisor_id'];
    
    error_log("DEBUG: Début assignation - Projet: $project_id, Encadrant: $supervisor_id");
    
    // Debug des données reçues
    error_log("DEBUG: POST data: " . print_r($_POST, true));
    
    // Vérifier que les IDs sont valides
    if ($project_id <= 0 || $supervisor_id <= 0) {
        error_log("DEBUG: IDs invalides - Projet: $project_id, Encadrant: $supervisor_id");
        throw new Exception("IDs de projet ou d'encadrant invalides");
    }
    
    // Vérifier que le projet existe
    $stmt = $pdo->prepare("SELECT id, titre, etudiant_id FROM projets WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        error_log("DEBUG: Projet ID $project_id non trouvé");
        throw new Exception("Projet non trouvé");
    }
    
    error_log("DEBUG: Projet trouvé: " . $project['titre']);
    
    // Vérifier que l'encadrant existe et a le bon rôle
    $stmt = $pdo->prepare("SELECT id, nom, prenom, role, actif FROM utilisateurs WHERE id = ?");
    $stmt->execute([$supervisor_id]);
    $supervisor = $stmt->fetch();
    
    if (!$supervisor) {
        error_log("DEBUG: Encadrant ID $supervisor_id non trouvé");
        throw new Exception("Encadrant non trouvé");
    }
    
    error_log("DEBUG: Encadrant trouvé: " . $supervisor['prenom'] . " " . $supervisor['nom'] . 
              " (Role: " . $supervisor['role'] . ", Actif: " . $supervisor['actif'] . ")");
    
    // Vérifier le rôle et le statut
    if ($supervisor['role'] !== 'enseignant') {
        error_log("DEBUG: Rôle incorrect - Attendu: enseignant, Reçu: " . $supervisor['role']);
        throw new Exception("L'utilisateur sélectionné n'est pas un enseignant");
    }
    
    if (!$supervisor['actif']) {
        error_log("DEBUG: Encadrant inactif");
        throw new Exception("Encadrant inactif");
    }
    
    // Mettre à jour le projet avec le nouvel encadrant
    $stmt = $pdo->prepare("UPDATE projets SET encadrant_id = ?, date_modification = NOW() WHERE id = ?");
    $result = $stmt->execute([$supervisor_id, $project_id]);
    
    if (!$result) {
        error_log("DEBUG: Échec de la mise à jour");
        throw new Exception("Échec de la mise à jour du projet");
    }
    
    $rowsAffected = $stmt->rowCount();
    error_log("DEBUG: Lignes affectées: $rowsAffected");
    
    if ($rowsAffected === 0) {
        error_log("DEBUG: Aucune ligne modifiée");
        throw new Exception("Aucune modification effectuée");
    }
    
    // Log de l'activité
    logActivity($pdo, $user_id, 'supervisor_assigned', 
               "Encadrant {$supervisor['prenom']} {$supervisor['nom']} assigné au projet ID $project_id");
    
    error_log("DEBUG: Assignation réussie");
    $message = "Encadrant {$supervisor['prenom']} {$supervisor['nom']} assigné avec succès au projet.";
    break;
                
            case 'update_status':
                $project_id = (int)$_POST['project_id'];
                $new_status = $_POST['new_status'];
                $admin_comment = $_POST['admin_comment'] ?? '';
                
                // Récupérer l'ancien statut
                $stmt = $pdo->prepare("SELECT statut FROM projets WHERE id = ?");
                $stmt->execute([$project_id]);
                $old_status = $stmt->fetchColumn();
                
                // Mettre à jour le statut
                $stmt = $pdo->prepare("UPDATE projets SET statut = ?, date_modification = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $project_id]);
                
                // Enregistrer dans l'historique
                $stmt = $pdo->prepare("INSERT INTO historique_statut_projets (projet_id, ancien_statut, nouveau_statut, motif, modifie_par) 
                                      VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$project_id, $old_status, $new_status, $admin_comment, $user_id]);
                
                // Enregistrer le commentaire si fourni
                if (!empty($admin_comment)) {
                    $stmt = $pdo->prepare("INSERT INTO commentaires (projet_id, utilisateur_id, contenu, type_commentaire) 
                                          VALUES (?, ?, ?, 'validation')");
                    $stmt->execute([$project_id, $user_id, $admin_comment]);
                }
                
                // Log de l'activité
                logActivity($pdo, $user_id, 'project_status_updated', "Statut du projet ID $project_id mis à jour de $old_status vers $new_status");
                
                $message = "Statut du projet mis à jour avec succès.";
                break;
                
            case 'delete_project':
                $project_id = (int)$_POST['project_id'];
                
                // Récupérer les fichiers associés
                $stmt = $pdo->prepare("SELECT fichier_chemin FROM projets WHERE id = ? AND fichier_chemin IS NOT NULL");
                $stmt->execute([$project_id]);
                $project = $stmt->fetch();
                
                // Supprimer le fichier physique s'il existe
                if ($project && !empty($project['fichier_chemin']) && file_exists($project['fichier_chemin'])) {
                    unlink($project['fichier_chemin']);
                }
                
                // Supprimer l'enregistrement
                $stmt = $pdo->prepare("DELETE FROM projets WHERE id = ?");
                $stmt->execute([$project_id]);
                
                logActivity($pdo, $user_id, 'project_deleted', "Projet ID $project_id supprimé");
                $message = "Projet supprimé avec succès.";
                break;
                
            case 'bulk_action':
                $project_ids = $_POST['project_ids'] ?? [];
                $bulk_action = $_POST['bulk_action'];
                
                if (!empty($project_ids)) {
                    $placeholders = str_repeat('?,', count($project_ids) - 1) . '?';
                    
                    switch ($bulk_action) {
                        case 'validate':
                            $stmt = $pdo->prepare("UPDATE projets SET statut = 'valide', date_validation = NOW() WHERE id IN ($placeholders)");
                            $stmt->execute($project_ids);
                            $message = count($project_ids) . " projet(s) validé(s) avec succès.";
                            break;
                            
                        case 'reject':
                            $stmt = $pdo->prepare("UPDATE projets SET statut = 'refuse', date_validation = NOW() WHERE id IN ($placeholders)");
                            $stmt->execute($project_ids);
                            $message = count($project_ids) . " projet(s) refusé(s) avec succès.";
                            break;
                    }
                }
                break;
                
            default:
                $error = "Action non reconnue.";
                break;
        }
    } catch (PDOException $e) {
        error_log("Erreur gestion projets: " . $e->getMessage());
        $error = "Une erreur s'est produite lors de l'opération.";
    } catch (Exception $e) {
        error_log("Erreur: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Paramètres de filtrage et pagination
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$supervisor_filter = $_GET['supervisor'] ?? '';
$sort = $_GET['sort'] ?? 'date_creation';
$order = $_GET['order'] ?? 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    // Construction de la requête de filtrage
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(p.titre LIKE ? OR p.description LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "p.statut = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($type_filter)) {
        $where_conditions[] = "p.type_projet = ?";
        $params[] = $type_filter;
    }
    
    if (!empty($supervisor_filter)) {
        if ($supervisor_filter === 'unassigned') {
            $where_conditions[] = "p.encadrant_id IS NULL";
        } else {
            $where_conditions[] = "p.encadrant_id = ?";
            $params[] = $supervisor_filter;
        }
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Requête principale avec pagination
    $stmt = $pdo->prepare("
    SELECT 
        p.*,
        e.nom as etudiant_nom,
        e.prenom as etudiant_prenom,
        e.email as etudiant_email,
        enc.nom as encadrant_nom,
        enc.prenom as encadrant_prenom,
        enc.email as encadrant_email
    FROM projets p
    LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    $where_clause
    ORDER BY $sort $order
    LIMIT $per_page OFFSET $offset
");
    $stmt->execute($params);
    $projects = $stmt->fetchAll();
    


    
    // Compter le total pour la pagination
    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id)
        FROM projets p
        LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total_projects = $count_stmt->fetchColumn();
    $total_pages = ceil($total_projects / $per_page);
    
    // Récupérer les enseignants pour l'assignation
    $stmt = $pdo->prepare("SELECT id, nom, prenom FROM utilisateurs WHERE role = 'enseignant' ORDER BY nom, prenom");
    $stmt->execute();
    $supervisors = $stmt->fetchAll();
    
   
	    // Statistiques des projets
	$stats_stmt = $pdo->prepare("
	    SELECT 
		(SELECT COUNT(*) FROM projets) as total_projets,
		(SELECT COUNT(*) FROM projets WHERE statut = 'soumis') as projets_soumis,
		(SELECT COUNT(*) FROM projets WHERE statut = 'en_cours_evaluation') as projets_en_cours,
		(SELECT COUNT(*) FROM projets WHERE statut = 'valide') as projets_valides,
		(SELECT COUNT(*) FROM projets WHERE statut = 'refuse') as projets_refuses
	");
	$stats_stmt->execute();
	$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    
} catch (PDOException $e) {
    error_log("Erreur récupération projets: " . $e->getMessage());
    $projects = [];
    $supervisors = [];
    $stats = ['total_projets' => 0, 'projets_soumis' => 0, 'projets_en_cours' => 0, 'projets_valides' => 0, 'projets_refuses' => 0];
    $total_projects = 0;
    $total_pages = 0;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Projets - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
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
		
	       /* Menu mobile */
	.md\\:hidden {
	    display: none;
	}

	.mobile-menu {
	    max-height: 0;
	    overflow: hidden;
	    transition: max-height 0.3s ease;
	}

	.nav-link {
	    transition: all 0.2s ease;
	}

	.nav-link:hover {
	    transform: translateX(5px);
	}

	/* Masquer le menu desktop sur mobile */
	@media (max-width: 767px) {
	    .sidebar {
		display: none;
	    }
	}
	       
	       @media (max-width: 767px) {
    table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filters-form {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 767px) {
    .filters-form {
        grid-template-columns: 1fr;
    }
}
	       
        .table-row:hover {
            background-color: #f8fafc;
        }
        
        .status-badge {
            transition: all 0.2s ease;
        }
        
        .status-badge:hover {
            transform: scale(1.05);
        }
        
        .modal {
            backdrop-filter: blur(8px);
        }
        
        .dropdown {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .dropdown.open {
            max-height: 200px;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex flex-col md:flex-row min-h-screen">
        <!-- Sidebar -->
        <aside class="sidebar w-64 bg-white shadow-lg">
            <div class="flex items-center justify-center p-6">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-graduation-cap text-3xl text-indigo-600"></i>
                    <span class="text-xl font-bold text-gray-800">UNB-ESI</span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto">
                <div class="p-4">
                    <nav class="space-y-1">
                        <a href="dashboard_administrateur.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Utilisateurs</span>
                        </a>
                        <a href="gestion_projets.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-red-50 text-red-700">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets</span>
                        </a>
                       <!--<a href="gestion_fichiers.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
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
        
        <!-- Mobile header -->
<div class="md:hidden bg-white shadow-sm">
    <div class="flex items-center justify-between p-4">
        <div class="flex items-center space-x-2">
            <i class="fas fa-graduation-cap text-2xl text-indigo-600"></i>
            <span class="font-bold text-gray-800">UNB-ESI Admin</span>
        </div>
        <!-- Bouton hamburger -->
        <button id="mobile-menu-button" class="hamburger p-2 focus:outline-none">
            <i class="fas fa-bars text-gray-600 text-xl"></i>
        </button>
    </div>
    
    <!-- Menu mobile (caché par défaut) -->
    <div id="mobile-menu" class="mobile-menu bg-white shadow-md" style="max-height: 0; overflow: hidden; transition: max-height 0.3s ease;">
        <div class="p-4 border-t">
            <!-- Contenu du menu -->
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
                <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-users w-5 text-center"></i>
                    <span>Utilisateurs</span>
                </a>
                <a href="gestion_projets.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-red-50 text-red-700">
                    <i class="fas fa-project-diagram w-5 text-center"></i>
                    <span>Projets</span>
                </a>
                <a href="../logout.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </div>
    </div>
</div>
        
        <!-- Main content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <div class="bg-gradient-to-r from-red-500 to-pink-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <h1 class="text-2xl font-bold">Gestion des Projets</h1>
                <p class="opacity-90">Supervisez et gérez tous les projets académiques</p>
            </div>
            
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-gray-100">
                            <i class="fas fa-project-diagram text-gray-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Total</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['total_projets']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-blue-100">
                            <i class="fas fa-paper-plane text-blue-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Soumis</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['projets_soumis']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-yellow-100">
                            <i class="fas fa-hourglass-half text-yellow-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">En cours</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['projets_en_cours']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-green-100">
                            <i class="fas fa-check-circle text-green-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Validés</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['projets_valides']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-red-100">
                            <i class="fas fa-times-circle text-red-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Refusés</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['projets_refuses']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filtres et recherche -->
            <div class="bg-white rounded-lg shadow mb-6 p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Titre, description, étudiant..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">Tous les statuts</option>
                            <option value="soumis" <?php echo $status_filter === 'soumis' ? 'selected' : ''; ?>>Soumis</option>
                            <option value="en_cours_evaluation" <?php echo $status_filter === 'en_cours_evaluation' ? 'selected' : ''; ?>>En cours</option>
                            <option value="valide" <?php echo $status_filter === 'valide' ? 'selected' : ''; ?>>Validé</option>
                            <option value="refuse" <?php echo $status_filter === 'refuse' ? 'selected' : ''; ?>>Refusé</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Encadrant</label>
                        <select name="supervisor" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">Tous les encadrants</option>
                            <option value="unassigned" <?php echo $supervisor_filter === 'unassigned' ? 'selected' : ''; ?>>Non assigné</option>
                            <?php foreach ($supervisors as $supervisor): ?>
                                <option value="<?php echo $supervisor['id']; ?>" <?php echo $supervisor_filter == $supervisor['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supervisor['prenom'] . ' ' . $supervisor['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="flex-1 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors">
                            <i class="fas fa-search mr-2"></i>Filtrer
                        </button>
                        <a href="gestion_projets.php" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400 transition-colors">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
            
            
            <!-- Tableau des projets -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left">
                                    <input type="checkbox" id="selectAll" class="rounded">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'titre', 'order' => $sort === 'titre' && $order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="flex items-center space-x-1 hover:text-gray-700">
                                        <span>Projet</span>
                                        <?php if ($sort === 'titre'): ?>
                                            <i class="fas fa-sort-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Encadrant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'statut', 'order' => $sort === 'statut' && $order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="flex items-center space-x-1 hover:text-gray-700">
                                        <span>Statut</span>
                                        <?php if ($sort === 'statut'): ?>
                                            <i class="fas fa-sort-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'date_creation', 'order' => $sort === 'date_creation' && $order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="flex items-center space-x-1 hover:text-gray-700">
                                        <span>Date</span>
                                        <?php if ($sort === 'date_creation'): ?>
                                            <i class="fas fa-sort-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fichiers</th> 
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($projects)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-project-diagram text-4xl mb-4"></i>
                                        <p>Aucun projet trouvé</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($projects as $project): ?>
                                    <tr class="table-row">
                                        <td class="px-6 py-4">
                                            <input type="checkbox" name="project_ids[]" value="<?php echo $project['id']; ?>" class="project-checkbox rounded">
                                        </td>
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($project['titre']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 truncate max-w-xs">
                                                    <?php echo htmlspecialchars(substr($project['description'], 0, 100)) . (strlen($project['description']) > 100 ? '...' : ''); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($project['etudiant_prenom'] . ' ' . $project['etudiant_nom']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($project['etudiant_email']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($project['encadrant_nom']): ?>
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($project['encadrant_prenom'] . ' ' . $project['encadrant_nom']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($project['encadrant_email']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                                    Non assigné
                                                </span>
                                            <?php endif; ?>
<td class="px-6 py-4">
                                            <?php
                                            $status_classes = [
                                                'soumis' => 'bg-blue-100 text-blue-800',
                                                'en_cours_evaluation' => 'bg-yellow-100 text-yellow-800',
                                                'valide' => 'bg-green-100 text-green-800',
                                                'refuse' => 'bg-red-100 text-red-800'
                                            ];
                                            $status_labels = [
                                                'soumis' => 'Soumis',
                                                'en_cours_evaluation' => 'En cours',
                                                'valide' => 'Validé',
                                                'refuse' => 'Refusé' 
                                            ];
                                            $status_class = $status_classes[$project['statut']] ?? 'bg-gray-100 text-gray-800';
                                            $status_label = $status_labels[$project['statut']] ?? ucfirst($project['statut']);
                                            ?>
                                            <span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                                <?php echo $status_label; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo date('d/m/Y H:i', strtotime($project['date_creation'])); ?>
                                        </td>
                                     </td>
                                       <td class="px-6 py-4">
					    <?php if (!empty($project['fichier_chemin'])): ?>
						<a href="<?php echo htmlspecialchars($project['fichier_chemin']); ?>" 
						   class="text-blue-600 hover:underline flex items-center space-x-1" 
						   target="_blank"
						   title="Télécharger le fichier">
						    <i class="fas fa-download text-sm"></i>
						    <span><?php echo htmlspecialchars($project['fichier_nom_original'] ?? 'Fichier'); ?></span>
						</a>
						<div class="text-xs text-gray-400 mt-1">
						    <?php if (!empty($project['fichier_taille'])): ?>
							Taille: <?php echo formatFileSize($project['fichier_taille']); ?>
						    <?php endif; ?>
						</div>
					    <?php else: ?>
						    <i class="fas fa-file-slash mr-1"></i>
						    Aucun fichier
						</span>
					    <?php endif; ?>
					</td>

                                        <td class="px-6 py-4">
                                            <div class="flex items-center space-x-2">
                                     
                                                <button onclick="assignSupervisor(<?php echo $project['id']; ?>)" 
                                                        class="text-blue-600 hover:text-blue-900 transition-colors" 
                                                        title="Assigner un encadrant">
                                                    <i class="fas fa-user-plus"></i>
                                                </button>
                                                <button onclick="updateStatus(<?php echo $project['id']; ?>)" 
                                                        class="text-green-600 hover:text-green-900 transition-colors" 
                                                        title="Changer le statut">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteProject(<?php echo $project['id']; ?>)" 
                                                        class="text-red-600 hover:text-red-900 transition-colors" 
                                                        title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Précédent
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Suivant
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Affichage de 
                                    <span class="font-medium"><?php echo $offset + 1; ?></span>
                                    à 
                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_projects); ?></span>
                                    sur 
                                    <span class="font-medium"><?php echo $total_projects; ?></span>
                                    résultats
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $page + 2);
                                    
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 <?php echo $i === $page ? 'bg-indigo-50 border-indigo-500 text-indigo-600' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
   
    
    <!-- Modal d'assignation d'encadrant -->
    <div id="assignModal" class="modal fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <form method="POST">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Assigner un encadrant</h3>
                            <button type="button" onclick="closeModal('assignModal')" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <input type="hidden" name="action" value="assign_supervisor">
                        <input type="hidden" name="project_id" id="assignProjectId">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Encadrant</label>
                            <select name="supervisor_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Sélectionner un encadrant</option>
                                <?php foreach ($supervisors as $supervisor): ?>
                                    <option value="<?php echo $supervisor['id']; ?>">
                                        <?php echo htmlspecialchars($supervisor['prenom'] . ' ' . $supervisor['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('assignModal')" 
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Annuler
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">
                                Assigner
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de mise à jour du statut -->
    <div id="statusModal" class="modal fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <form method="POST">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Changer le statut</h3>
                            <button type="button" onclick="closeModal('statusModal')" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="project_id" id="statusProjectId">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau statut</label>
                            <select name="new_status" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="en_cours_evaluation">En cours d'évaluation</option>
                                <option value="valide">Validé</option>
                                <option value="refuse">Refusé</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire administrateur</label>
                            <textarea name="admin_comment" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Commentaire optionnel..."></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('statusModal')" 
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Annuler
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">
                                Mettre à jour
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    
    // Mobile menu toggle
document.getElementById('mobile-menu-button').addEventListener('click', function() {
    const menu = document.getElementById('mobile-menu');
    const icon = this.querySelector('i');
    
    if (menu.style.maxHeight === '0px' || menu.style.maxHeight === '') {
        // Ouvrir le menu
        menu.style.maxHeight = menu.scrollHeight + 'px';
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-times');
    } else {
        // Fermer le menu
        menu.style.maxHeight = '0px';
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    }
});
    
        // Gestion des cases à cocher
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.project-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        document.querySelectorAll('.project-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });
        
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.project-checkbox:checked');
            const count = checkboxes.length;
            const bulkSubmit = document.getElementById('bulkSubmit');
            const selectedCount = document.getElementById('selectedCount');
            
            selectedCount.textContent = count + ' sélectionné(s)';
            bulkSubmit.disabled = count === 0;
            
            // Mettre à jour le formulaire avec les IDs sélectionnés
            const bulkForm = document.getElementById('bulkForm');
            const existingInputs = bulkForm.querySelectorAll('input[name="project_ids[]"]');
            existingInputs.forEach(input => input.remove());
            
            checkboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'project_ids[]';
                input.value = checkbox.value;
                bulkForm.appendChild(input);
            });
        }
        
   function viewProject(projectId) {
    // Version simplifiée sans AJAX
    const projectRow = document.querySelector(`input[value="${projectId}"]`).closest('tr');
    const projectTitle = projectRow.querySelector('.text-sm.font-medium').textContent;
    const projectDescription = projectRow.querySelector('.text-sm.text-gray-500').textContent;
    
    const modalContent = `
        <div class="space-y-4">
            <div>
                <h4 class="font-medium text-gray-900">Titre</h4>
                <p class="text-gray-700">${projectTitle}</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-900">Description</h4>
                <p class="text-gray-700">${projectDescription}</p>
            </div>
            <div class="text-sm text-gray-500">
                <p>Pour plus de détails, consultez la base de données directement.</p>
            </div>
        </div>
    `;
    
    document.getElementById('projectDetails').innerHTML = modalContent;
    document.getElementById('projectModal').classList.remove('hidden');
}
        
        function assignSupervisor(projectId) {
            document.getElementById('assignProjectId').value = projectId;
            document.getElementById('assignModal').classList.remove('hidden');
        }
        
        function updateStatus(projectId) {
            document.getElementById('statusProjectId').value = projectId;
            document.getElementById('statusModal').classList.remove('hidden');
        }
        
        function deleteProject(projectId) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce projet ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="project_id" value="${projectId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }
        
        // Fermer les modals en cliquant à l'extérieur
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                }
            });
        });
        
        // Confirmation pour les actions en lot
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const action = document.querySelector('select[name="bulk_action"]').value;
            const count = document.querySelectorAll('.project-checkbox:checked').length;
            
            if (!action) {
                e.preventDefault();
                alert('Veuillez sélectionner une action');
                return;
            }
            
            if (count === 0) {
                e.preventDefault();
                alert('Veuillez sélectionner au moins un projet');
                return;
            }
            
            const actionNames = {
                'validate': 'valider',
                'reject': 'refuser'
            };
            
            const actionName = actionNames[action] || action;
            
            if (!confirm(`Êtes-vous sûr de vouloir ${actionName} ${count} projet(s) ?`)) {
                e.preventDefault();
            }
        });
        
        // Animation d'apparition des éléments
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.animate-fade-in');
            elements.forEach((element, index) => {
                setTimeout(() => {
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
