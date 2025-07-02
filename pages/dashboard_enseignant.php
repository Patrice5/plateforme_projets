<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

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

    // Récupérer les informations de la filière
    $filiere = null;
    if ($user['filiere_id']) {
        $stmt = $pdo->prepare("SELECT * FROM filieres WHERE id = ?");
        $stmt->execute([$user['filiere_id']]);
        $filiere = $stmt->fetch();
    }

    // Calculer les statistiques des projets encadrés
    $stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_projets,
        SUM(CASE WHEN statut = 'soumis' THEN 1 ELSE 0 END) as projets_soumis,
        SUM(CASE WHEN statut = 'en_cours_evaluation' THEN 1 ELSE 0 END) as projets_en_evaluation,
        SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as projets_valides,
        SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as projets_refuses,
        SUM(CASE WHEN statut = 'en_revision' THEN 1 ELSE 0 END) as projets_en_revision
    FROM projets 
    WHERE encadrant_id = ?
");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();

    // Récupérer les projets récents à évaluer
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.nom as etudiant_nom,
            e.prenom as etudiant_prenom,
            e.email as etudiant_email,
            f.nom as filiere_nom
        FROM projets p
        LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN filieres f ON e.filiere_id = f.id
        WHERE p.encadrant_id = ? 
        AND p.statut IN ('soumis', 'en_cours_evaluation')
        ORDER BY p.date_creation DESC 
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $projetsEnAttente = $stmt->fetchAll();

    // Récupérer les activités récentes
    $stmt = $pdo->prepare("
    SELECT 
        p.*,
        e.nom as etudiant_nom,
        e.prenom as etudiant_prenom,
        h.ancien_statut,
        h.nouveau_statut,
        h.date_modification
    FROM projets p
    LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN historique_statut_projets h ON p.id = h.projet_id
    WHERE p.encadrant_id = ? 
    ORDER BY COALESCE(h.date_modification, p.date_creation) DESC 
    LIMIT 8
");
    $stmt->execute([$user_id]);
    $activitesRecentes = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Erreur dashboard encadrant: " . $e->getMessage());
    $stats = ['total_projets' => 0, 'projets_soumis' => 0, 'projets_en_evaluation' => 0, 'projets_valides' => 0, 'projets_refuses' => 0];
    $projetsEnAttente = [];
    $activitesRecentes = [];
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Encadrant - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #059669;
            --primary-light: #10b981;
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
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .project-card {
            transition: all 0.3s ease;
        }
        
        .project-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .badge-urgent {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .mobile-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .mobile-menu.open {
            max-height: 1000px;
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
                        <a href="dashboard_encadrant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
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
                        <a href="dashboard_encadrant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
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
            <!-- Welcome banner -->
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <h1 class="text-2xl font-bold">Bienvenue, <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>!</h1>
                <p class="opacity-90">Gérez et évaluez les projets de vos étudiants.</p>
                <div class="mt-2 text-sm opacity-75">
                    Dernière connexion: <?php echo date('d/m/Y à H:i'); ?>
                </div>
            </div>
            
            <!-- Dashboard header -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Projets & Évaluations</h2>
                <div class="flex items-center space-x-2">
                    <span class="bg-emerald-100 text-emerald-800 text-xs font-medium px-3 py-1 rounded-full">
                        Encadrant
                    </span>
                    <?php if ($stats['projets_soumis'] > 0): ?>
                        <span class="badge-urgent bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded-full">
                            <?php echo $stats['projets_soumis']; ?> en attente
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Stats cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
                <!-- Total Projets -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fas fa-project-diagram text-emerald-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Total Projets</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_projets'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-emerald-500 to-emerald-300 rounded-full"></div>
                </div>
                
                <!-- Projets Soumis -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">À Évaluer</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_soumis'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-yellow-500 to-yellow-300 rounded-full"></div>
                </div>
                
                <!-- En Évaluation -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-blue-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">En Évaluation</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_en_evaluation'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-blue-500 to-blue-300 rounded-full"></div>
                </div>
                
                <!-- Projets Validés -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-300">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Validés</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_valides'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-green-500 to-green-300 rounded-full"></div>
                </div>
                
                <!-- Projets Refusés -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-300">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Refusés</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_refuses'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-red-500 to-red-300 rounded-full"></div>
                </div>
            </div>

           
            <!-- Activités récentes -->
            <div class="bg-white rounded-xl shadow-md mb-6 animate-fade-in">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Activités Récentes</h3>
                        <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2 py-1 rounded-full">
                            Dernières 24h
                        </span>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php if (!empty($activitesRecentes)): ?>
                        <div class="space-y-4">
                            <?php foreach ($activitesRecentes as $activite): ?>
                                <div class="flex items-start space-x-4 p-4 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                                            <i class="<?php echo getActivityIcon($activite['statut']); ?>"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm text-gray-800">
                                            <strong><?php echo htmlspecialchars($activite['titre']); ?></strong>
                                            <?php if ($activite['ancien_statut'] && $activite['nouveau_statut']): ?>
                                                - Statut changé de "<?php echo ucfirst($activite['ancien_statut']); ?>" à "<?php echo ucfirst($activite['nouveau_statut']); ?>"
                                            <?php else: ?>
                                                - <?php echo ucfirst($activite['statut']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            Étudiant: <?php echo htmlspecialchars($activite['etudiant_prenom'] . ' ' . $activite['etudiant_nom']); ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?php echo formatDateFrench($activite['date_modification'] ?? $activite['date_creation']); ?>
                                        </p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <a href="evaluer_projet.php?id=<?php echo $activite['id']; ?>" 
                                           class="text-emerald-600 hover:text-emerald-800 text-sm">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                      
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-history text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-500">Aucune activité récente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow-md animate-fade-in">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Actions Rapides</h3>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4">
                        <a href="evaluer_projet.php" class="flex items-center space-x-3 p-4 rounded-lg border border-gray-200 hover:border-emerald-300 hover:bg-emerald-50 transition-all">
                            <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                                <i class="fas fa-project-diagram text-emerald-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Projets à évaluer</p>
                                <p class="text-xs text-gray-500">Gérer tous les projets</p>
                            </div>
                        </a>
                       
                        
                        <a href="profile_enseignant.php" class="flex items-center space-x-3 p-4 rounded-lg border border-gray-200 hover:border-gray-300 hover:bg-gray-50 transition-all">
                            <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                                <i class="fas fa-user text-gray-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Mon Profil</p>
                                <p class="text-xs text-gray-500">Paramètres du compte</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobile-menu');
            const button = this.querySelector('i');
            
            mobileMenu.classList.toggle('open');
            
            if (mobileMenu.classList.contains('open')) {
                button.classList.remove('fa-bars');
                button.classList.add('fa-times');
            } else {
                button.classList.remove('fa-times');
                button.classList.add('fa-bars');
            }
        });

        // Auto-refresh stats every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Animate numbers on load
        window.addEventListener('load', function() {
            const statNumbers = document.querySelectorAll('.stat-card .text-3xl');
            statNumbers.forEach(function(element) {
                const finalValue = parseInt(element.textContent);
                let currentValue = 0;
                const increment = Math.ceil(finalValue / 20);
                
                const counter = setInterval(function() {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        currentValue = finalValue;
                        clearInterval(counter);
                    }
                    element.textContent = currentValue;
                }, 50);
            });
        });

        // Notification for urgent projects
        <?php if ($stats['projets_soumis'] > 5): ?>
        setTimeout(function() {
            if (Notification.permission === "granted") {
                new Notification("UNB-ESI - Projets en attente", {
                    body: "Vous avez <?php echo $stats['projets_soumis']; ?> projets en attente d'évaluation",
                    icon: "/favicon.ico"
                });
            }
        }, 3000);
        <?php endif; ?>
    </script>

    <?php
    

    ?>
</body>
</html>
