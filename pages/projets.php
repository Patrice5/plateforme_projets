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

// Paramètres de pagination et filtrage
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 6;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

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

    // Construire la requête avec filtres
    $whereClause = "WHERE p.etudiant_id = ?";
    $params = [$user_id];

    if (!empty($search)) {
        $whereClause .= " AND (p.titre LIKE ? OR p.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($filter_status)) {
        $whereClause .= " AND p.statut = ?";
        $params[] = $filter_status;
    }

    // Compter le total pour la pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM projets p $whereClause");
    $countStmt->execute($params);
    $totalProjects = $countStmt->fetchColumn();
    $totalPages = ceil($totalProjects / $limit);

    // Récupérer les projets avec pagination
   $stmt = $pdo->prepare("
    SELECT 
        p.*,
        e.nom as encadrant_nom,
        e.prenom as encadrant_prenom,
        e.email as encadrant_email,
        p.fichier_chemin as fichier_path,  
        p.fichier_nom_original as fichier_nom  
    FROM projets p
    LEFT JOIN utilisateurs e ON p.encadrant_id = e.id
    $whereClause
    ORDER BY p.date_creation DESC
    LIMIT $limit OFFSET $offset
");
    $stmt->execute($params);
    $projets = $stmt->fetchAll();

    // Récupérer les statistiques
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as valides,
            SUM(CASE WHEN statut = 'en_cours_evaluation' THEN 1 ELSE 0 END) as en_cours,
            SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as refuses,
            SUM(CASE WHEN statut = 'en_revision' THEN 1 ELSE 0 END) as en_revision
        FROM projets 
        WHERE etudiant_id = ?
    ");
    $statsStmt->execute([$user_id]);
    $stats = $statsStmt->fetch();

} catch (PDOException $e) {
    error_log("Erreur projets étudiant: " . $e->getMessage());
    $projets = [];
    $stats = ['total' => 0, 'valides' => 0, 'en_cours' => 0, 'refuses' => 0, 'en_revision' => 0];
    $totalPages = 0;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Projets - Étudiant - UNB-ESI</title>
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
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .project-card {
            transition: all 0.3s ease;
        }
        
        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge {
            transition: all 0.2s ease;
        }
        
        .search-box {
            transition: all 0.3s ease;
        }
        
        .search-box:focus {
            transform: scale(1.02);
        }

        .modal-backdrop {
            backdrop-filter: blur(4px);
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
                    <span class="font-bold text-gray-800">UNB-ESI</span>
                </div>
                <button id="mobile-menu-button" class="p-2 focus:outline-none">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden bg-white shadow-md">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-user text-indigo-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Étudiant</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_etudiant.php" class="flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="projets.php" class="flex items-center space-x-3 p-2 rounded-lg bg-indigo-50 text-indigo-700">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Mes Projets</span>
                        </a>
                        <a href="soumettre_projet.php" class="flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-plus-circle w-5 text-center"></i>
                            <span>Nouveau Projet</span>
                        </a>
                        <a href="profile.php" class="flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                        <a href="../logout.php" class="flex items-center space-x-3 p-2 rounded-lg text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Déconnexion</span>
                        </a>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- Desktop sidebar -->
        <aside class="hidden md:flex md:flex-col w-64 bg-white shadow-lg">
            <div class="flex items-center justify-center p-6">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-graduation-cap text-3xl text-indigo-600"></i>
                    <span class="text-xl font-bold text-gray-800">UNB-ESI</span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto">
                <div class="p-4">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-user text-indigo-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Étudiant</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_etudiant.php" class="flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="projets.php" class="flex items-center space-x-3 p-3 rounded-lg bg-indigo-50 text-indigo-700">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Mes Projets</span>
                        </a>
                        <a href="soumettre_projet.php" class="flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-plus-circle w-5 text-center"></i>
                            <span>Nouveau Projet</span>
                        </a>
                        <a href="profile.php" class="flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                    </nav>
                </div>
            </div>
            
            <div class="p-4 border-t">
                <a href="../logout.php" class="flex items-center space-x-3 p-3 rounded-lg text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>
        
        <!-- Main content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-md p-6 mb-6 text-white">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Mes Projets</h1>
                        <p class="opacity-90">Gérez et suivez l'évolution de vos projets académiques</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="soumettre_projet.php" class="bg-white text-indigo-600 px-4 py-2 rounded-lg font-medium hover:bg-gray-100 transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Nouveau Projet
                        </a>
                    </div>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <div class="text-2xl font-bold text-gray-800"><?php echo $stats['total']; ?></div>
                    <div class="text-sm text-gray-500">Total</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $stats['valides']; ?></div>
                    <div class="text-sm text-gray-500">Validés</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['en_cours']; ?></div>
                    <div class="text-sm text-gray-500">En cours d'évaluation</div>
                </div>
               
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?php echo $stats['refuses']; ?></div>
                    <div class="text-sm text-gray-500">Refusés</div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                <form method="GET" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Rechercher par titre ou description..." 
                               class="search-box w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div class="md:w-48">
                        <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                            <option value="">Tous les statuts</option>
                            <option value="soumis" <?php echo $filter_status === 'soumis' ? 'selected' : ''; ?>>Soumis</option>
                            <option value="en_cours_evaluation" <?php echo $filter_status === 'en_cours_evaluation' ? 'selected' : ''; ?>>En évaluation</option>
                            <option value="valide" <?php echo $filter_status === 'valide' ? 'selected' : ''; ?>>Validés</option>
                            <option value="refuse" <?php echo $filter_status === 'refuse' ? 'selected' : ''; ?>>Refusés</option>
                        </select>
                    </div>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="projets.php" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-center">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </form>
            </div>

            <!-- Projects Grid -->
            <?php if (!empty($projets)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                    <?php foreach ($projets as $projet): ?>
                        <div class="project-card bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <h3 class="text-lg font-semibold text-gray-800 line-clamp-2">
                                        <?php echo htmlspecialchars($projet['titre']); ?>
                                    </h3>
                                    <div class="ml-2 flex items-center space-x-2">
                                        <?php
                                        $statusConfig = getStatusConfig($projet['statut']);
                                        ?>
                                        <span class="status-badge <?php echo $statusConfig['class']; ?> text-xs font-medium px-2 py-1 rounded-full whitespace-nowrap">
                                            <i class="<?php echo $statusConfig['icon']; ?> mr-1"></i>
                                            <?php echo $statusConfig['label']; ?>
                                        </span>
                                        
                                        <!-- Bouton hamburger -->
                                        <div class="relative">
                                            <button onclick="toggleDropdown(<?php echo $projet['id']; ?>)" 
                                                    class="p-1 rounded-full hover:bg-gray-100 focus:outline-none">
                                                <i class="fas fa-ellipsis-v text-gray-400"></i>
                                            </button>
                                            
                                            <!-- Menu dropdown -->
                                            <div id="dropdown-<?php echo $projet['id']; ?>" 
                                                 class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-10">
                                                <div class="py-1">
                                                    
                                                    
                                                    <button onclick="viewComments(<?php echo $projet['id']; ?>)" 
                                                            class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                                                        <i class="fas fa-comments mr-2"></i>
                                                        Voir les commentaires
                                                    </button>
                                                    
                                                    <?php if ($projet['statut'] === 'en_revision'): ?>
                                                        <a href="modifier_projet.php?id=<?php echo $projet['id']; ?>" 
                                                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                            <i class="fas fa-edit mr-2"></i>
                                                            Modifier
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (in_array($projet['statut'], ['soumis', 'en_revision'])): ?>
                                                        <button onclick="deleteProject(<?php echo $projet['id']; ?>)" 
                                                                class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center">
                                                            <i class="fas fa-trash mr-2"></i>
                                                            Supprimer
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <p class="text-gray-600 text-sm mb-4 line-clamp-3">
                                    <?php echo htmlspecialchars(substr($projet['description'], 0, 150)); ?>
                                    <?php if (strlen($projet['description']) > 150): ?>...<?php endif; ?>
                                </p>
                                
                                <div class="space-y-2 text-sm text-gray-500 mb-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-alt w-4 text-center mr-2"></i>
                                        <span><?php echo formatDateFrench($projet['date_creation']); ?></span>
                                    </div>
                                    
                                    <?php if ($projet['encadrant_nom']): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tie w-4 text-center mr-2"></i>
                                            <span><?php echo htmlspecialchars($projet['encadrant_prenom'] . ' ' . $projet['encadrant_nom']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($projet['note_finale'] && $projet['statut'] === 'valide'): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-star w-4 text-center mr-2 text-yellow-500"></i>
                                            <span class="font-medium text-yellow-600"><?php echo $projet['note_finale']; ?>/20</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-center space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>" 
                               class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>" 
                               class="px-3 py-2 <?php echo $i === $page ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-lg">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>" 
                               class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Empty State -->
                <div class="bg-white rounded-xl shadow-md p-12 text-center">
                    <i class="fas fa-project-diagram text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">
                        <?php if (!empty($search) || !empty($filter_status)): ?>
                            Aucun projet trouvé
                        <?php else: ?>
                            Vous n'avez pas encore de projets
                        <?php endif; ?>
                    </h3>
                    <p class="text-gray-500 mb-6">
                        <?php if (!empty($search) || !empty($filter_status)): ?>
                            Essayez de modifier vos critères de recherche ou de filtrage.
                        <?php else: ?>
                            Commencez par soumettre votre premier projet académique !
                        <?php endif; ?>
                    </p>
                    
                    <?php if (!empty($search) || !empty($filter_status)): ?>
                        <a href="projets.php" class="inline-block bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 transition-colors mr-4">
                            <i class="fas fa-times mr-2"></i>Effacer les filtres
                        </a>
                    <?php endif; ?>
                    
                    <a href="soumettre_projet.php" class="inline-block bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i>Nouveau Projet
                    </a>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal pour les commentaires -->
    <div id="commentsModal" class="fixed inset-0 bg-black bg-opacity-50 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-800">Commentaires du projet</h3>
                <button onclick="closeCommentsModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="commentsContent" class="p-6">
                <!-- Contenu chargé dynamiquement -->
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle mobile menu
        document.getElementById('mobile-menu-button')?.addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobile-menu');
            mobileMenu.classList.toggle('hidden');
        });

        // Toggle dropdown menus
        function toggleDropdown(projectId) {
            const dropdown = document.getElementById('dropdown-' + projectId);
            const allDropdowns = document.querySelectorAll('[id^="dropdown-"]');
            
            // Close all other dropdowns
            allDropdowns.forEach(d => {
                if (d !== dropdown) {
                    d.classList.add('hidden');
                }
            });
            
            dropdown.classList.toggle('hidden');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('[onclick^="toggleDropdown"]') && !e.target.closest('[id^="dropdown-"]')) {
                document.querySelectorAll('[id^="dropdown-"]').forEach(d => {
                    d.classList.add('hidden');
                });
            }
        });

        // View project details (continuation)
        async function viewProject(projectId) {
            const modal = document.getElementById('projectModal');
            const modalContent = document.getElementById('modalContent');
            
            modal.classList.remove('hidden');
            modalContent.innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-indigo-600"></i>
                    <span class="ml-2">Chargement...</span>
                </div>
            `;

            try {
                const response = await fetch(`get_project_details.php?id=${projectId}`);
                const data = await response.text();
                modalContent.innerHTML = data;
            } catch (error) {
                modalContent.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                        <p>Erreur lors du chargement des détails</p>
                    </div>
                `;
            }
        }

        // View project comments
        async function viewComments(projectId) {
            const modal = document.getElementById('commentsModal');
            const modalContent = document.getElementById('commentsContent');
            
            modal.classList.remove('hidden');
            modalContent.innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-indigo-600"></i>
                    <span class="ml-2">Chargement des commentaires...</span>
                </div>
            `;

            try {
                const response = await fetch(`get_project_comments.php?id=${projectId}`);
                const data = await response.text();
                modalContent.innerHTML = data;
            } catch (error) {
                modalContent.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                        <p>Erreur lors du chargement des commentaires</p>
                    </div>
                `;
            }
        }

        // Delete project
        async function deleteProject(projectId) {
            if (!confirm('Êtes-vous sûr de vouloir supprimer ce projet ? Cette action est irréversible.')) {
                return;
            }

            try {
                const response = await fetch('delete_project.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ project_id: projectId })
                });

                const result = await response.json();

                if (result.success) {
                    // Afficher un message de succès
                    showNotification('Projet supprimé avec succès', 'success');
                    // Recharger la page après un délai
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(result.message || 'Erreur lors de la suppression', 'error');
                }
            } catch (error) {
                showNotification('Erreur lors de la suppression du projet', 'error');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('projectModal').classList.add('hidden');
        }

        // Close comments modal
        function closeCommentsModal() {
            document.getElementById('commentsModal').classList.add('hidden');
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transform transition-all duration-300 translate-x-full`;
            
            const bgColor = {
                'success': 'bg-green-500',
                'error': 'bg-red-500',
                'info': 'bg-blue-500',
                'warning': 'bg-yellow-500'
            }[type] || 'bg-blue-500';
            
            notification.className += ` ${bgColor} text-white`;
            
            const icon = {
                'success': 'fas fa-check-circle',
                'error': 'fas fa-exclamation-circle',
                'info': 'fas fa-info-circle',
                'warning': 'fas fa-exclamation-triangle'
            }[type] || 'fas fa-info-circle';
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="${icon} mr-2"></i>
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }

        // Close modals when clicking outside
        document.getElementById('projectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('commentsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCommentsModal();
            }
        });

        // Handle escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeCommentsModal();
            }
        });

        // Add animation to project cards on page load
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.project-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>

