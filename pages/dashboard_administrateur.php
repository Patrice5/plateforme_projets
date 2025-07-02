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
    // Récupérer les informations de l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        logoutUser();
        header('Location: ../index.php');
        exit();
    }

    // Statistiques générales du système
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM utilisateurs WHERE role = 'etudiant') as total_etudiants,
            (SELECT COUNT(*) FROM utilisateurs WHERE role = 'enseignant') as total_enseignants,
            (SELECT COUNT(*) FROM projets) as total_projets,
            (SELECT COUNT(*) FROM projets WHERE statut = 'soumis') as projets_soumis,
            (SELECT COUNT(*) FROM projets WHERE statut = 'en_cours_evaluation') as projets_en_cours,
            (SELECT COUNT(*) FROM projets WHERE statut = 'valide') as projets_valides,
            (SELECT COUNT(*) FROM projets WHERE statut = 'refuse') as projets_refuses
    ");
    $stmt->execute();
    $stats = $stmt->fetch();

    // Utilisateurs récemment inscrits
    $stmt = $pdo->prepare("
        SELECT u.*
        FROM utilisateurs u
        ORDER BY u.date_creation DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentUsers = $stmt->fetchAll();

    // Projets récents avec informations complètes
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.nom as etudiant_nom,
            e.prenom as etudiant_prenom,
            enc.nom as encadrant_nom,
            enc.prenom as encadrant_prenom
        FROM projets p
        LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
        ORDER BY p.date_creation DESC 
        LIMIT 6
    ");
    $stmt->execute();
    $recentProjects = $stmt->fetchAll();

    // Alertes système
    $alerts = [];

} catch (PDOException $e) {
    error_log("Erreur dashboard administrateur: " . $e->getMessage());
    $stats = [
        'total_etudiants' => 0, 'total_enseignants' => 0, 'total_projets' => 0,
        'projets_soumis' => 0, 'projets_en_cours' => 0, 'projets_valides' => 0,
        'projets_refuses' => 0
    ];
    $recentUsers = [];
    $recentProjects = [];
    $alerts = [];
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - UNB-ESI</title>
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
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
		
		/* Menu mobile */
	.md\\:hidden {
	    display: none;
	}

	.mobile-menu {
	    max-height: 0;
	    overflow: hidden;
	    opacity: 0;
	    transition: all 0.3s ease-out;
	}

	.mobile-menu.open {
	    max-height: 500px; /* Ajustez selon votre contenu */
	    opacity: 1;
	}

	.hamburger.active i {
	    transform: rotate(90deg);
	    transition: transform 0.3s ease;
	}

	/* Masquer le menu desktop sur mobile */
	@media (max-width: 767px) {
	    .sidebar {
		display: none;
	    }
	}

        .pulse-dot {
            animation: pulse 2s infinite;
        }

	.actions-rapides {
    	    padding: 2rem; /* Augmentez le padding */
            min-height: 200px; /* Ajustez la hauteur minimale */
  	}


        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .alert-animation {
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
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
                <button id="mobile-menu-button" class="p-2 focus:outline-none">
    		    <i class="fas fa-bars text-gray-600 text-xl transition-transform"></i>
		</button>

            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu bg-white shadow-md">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-user-shield text-red-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Administrateur</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_administrateur.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-red-50 text-red-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
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
                        </a> -->
                        
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
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Administrateur</p>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_administrateur.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-red-50 text-red-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="gestion_utilisateurs.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
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
                        </a> -->
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
            <div class="bg-gradient-to-r from-red-500 to-pink-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <h1 class="text-2xl font-bold">Panneau d'Administration</h1>
                <p class="opacity-90">Gérez les utilisateurs, projets et paramètres du système.</p>
                <div class="mt-2 text-sm opacity-75">
                    Connexion: <?php echo date('d/m/Y à H:i'); ?>
                </div>
            </div>
            
            <!-- Alertes système -->
            <?php if (!empty($alerts)): ?>
            <div class="mb-6">
                <?php foreach ($alerts as $alert): ?>
                <div class="alert-animation bg-white border-l-4 <?php echo $alert['type'] === 'warning' ? 'border-yellow-400' : 'border-blue-400'; ?> p-4 mb-4 rounded-r-lg shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas <?php echo $alert['type'] === 'warning' ? 'fa-exclamation-triangle text-yellow-500' : 'fa-info-circle text-blue-500'; ?> mr-3"></i>
                            <p class="text-gray-800"><?php echo htmlspecialchars($alert['message']); ?></p>
                        </div>
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">
                            <?php echo htmlspecialchars($alert['action']); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Stats cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                <!-- Total Utilisateurs -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Utilisateurs</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo ($stats['total_etudiants'] + $stats['total_enseignants']); ?></p>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php echo $stats['total_etudiants']; ?> étudiants, <?php echo $stats['total_enseignants']; ?> enseignants
                    </div>
                    <div class="h-1 bg-gradient-to-r from-blue-500 to-blue-300 rounded-full"></div>
                </div>
                
                <!-- Total Projets -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-project-diagram text-green-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Total Projets</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_projets']; ?></p>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php echo $stats['projets_valides']; ?> validés
                    </div>
                    <div class="h-1 bg-gradient-to-r from-green-500 to-green-300 rounded-full"></div>
                </div>
                
                <!-- Projets en attente -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-yellow-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">En attente</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo ($stats['projets_soumis'] + $stats['projets_en_cours']); ?></p>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php echo $stats['projets_soumis']; ?> soumis, <?php echo $stats['projets_en_cours']; ?> en cours
                    </div>
                    <div class="h-1 bg-gradient-to-r from-yellow-500 to-yellow-300 rounded-full"></div>
                </div>
            </div>
            
            <!-- Vue d'ensemble -->
            <div class="grid grid-cols-1 gap-6 mb-6">
         

                <!-- Actions rapides -->
		        <div class="bg-white rounded-xl shadow-md animate-fade-in actions-rapides">
	    <div class="p-6 border-b border-gray-200">
		<h3 class="text-lg font-semibold text-gray-800">Actions Rapides</h3>
	    </div>
	    
	    <div class="p-6">
		<div class="grid grid-cols-2 gap-4">
		    <a href="gestion_utilisateurs.php?action=create" class="flex flex-col items-center p-4 rounded-lg border-2 border-dashed border-gray-300 hover:border-indigo-400 hover:bg-indigo-50 transition-colors group">
		        <i class="fas fa-user-plus text-2xl text-gray-400 group-hover:text-indigo-600 mb-2"></i>
		        <span class="text-sm text-gray-600 group-hover:text-indigo-800 text-center">Nouvel Utilisateur</span>
		    </a>
		    
		    <a href="gestion_projets.php" class="flex flex-col items-center p-4 rounded-lg border-2 border-dashed border-gray-300 hover:border-green-400 hover:bg-green-50 transition-colors group">
		        <i class="fas fa-project-diagram text-2xl text-gray-400 group-hover:text-green-600 mb-2"></i>
		        <span class="text-sm text-gray-600 group-hover:text-green-800 text-center">Gérer Projets</span>
		    </a>
		</div>
	    </div>
	</div>
            
            <!-- Activité système récente -->
            <div class="bg-white rounded-xl shadow-md mb-6 animate-fade-in">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Activité Récente du Système</h3>
                        <div class="flex items-center space-x-2">
                            <div class="pulse-dot w-2 h-2 bg-green-500 rounded-full"></div>
                            <span class="text-xs text-gray-500">En temps réel</span>
                        </div>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="space-y-4 max-h-64 overflow-y-auto">
                        <?php
                        // Activités récentes simulées - dans un vrai système, ceci viendrait d'une table de logs
                        $activities = [];
                        
                        // Ajouter les utilisateurs récents comme activités
                        foreach (array_slice($recentUsers, 0, 3) as $user) {
                            $activities[] = [
                                'type' => 'user_created',
                                'description' => "Nouvel utilisateur inscrit: {$user['prenom']} {$user['nom']} ({$user['role']})",
                                'date' => $user['date_creation'],
                                'icon' => 'fa-user-plus',
                                'color' => 'text-blue-600'
                            ];
                        }
                        
                        // Ajouter les projets récents comme activités
                        foreach (array_slice($recentProjects, 0, 3) as $project) {
                            $activities[] = [
                                'type' => 'project_submitted',
                                'description' => "Nouveau projet soumis: {$project['titre']}",
                                'date' => $project['date_creation'],
                                'icon' => 'fa-file-alt',
                                'color' => 'text-green-600'
                            ];
                        }
                        
                        // Trier par date décroissante
                        usort($activities, function($a, $b) {
                            return strtotime($b['date']) - strtotime($a['date']);
                        });
                        
                        $activities = array_slice($activities, 0, 8); // Limiter à 8 activités
                        ?>
                        
                        <?php if (!empty($activities)): ?>
                            <?php foreach ($activities as $activity): ?>
                                <div class="flex items-start space-x-4 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center">
                                            <i class="fas <?php echo $activity['icon']; ?> text-sm <?php echo $activity['color']; ?>"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm text-gray-800"><?php echo htmlspecialchars($activity['description']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <i class="far fa-clock mr-1"></i>
                                            <?php echo formatTimeAgo($activity['date']); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-history text-gray-300 text-4xl mb-4"></i>
                                <p class="text-gray-500">Aucune activité récente</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
		// Mobile menu toggle
		document.addEventListener('DOMContentLoaded', function() {
	    const mobileMenuButton = document.getElementById('mobile-menu-button');
	    const mobileMenu = document.getElementById('mobile-menu');
	    
	    if (mobileMenuButton && mobileMenu) {
		mobileMenuButton.addEventListener('click', function() {
		    // Basculer la classe 'open' sur le menu
		    mobileMenu.classList.toggle('open');
		    
		    // Basculer la classe 'active' sur le bouton
		    this.classList.toggle('active');
		    
		    // Changer l'icône
		    const icon = this.querySelector('i');
		    if (mobileMenu.classList.contains('open')) {
		        icon.classList.remove('fa-bars');
		        icon.classList.add('fa-times');
		    } else {
		        icon.classList.remove('fa-times');
		        icon.classList.add('fa-bars');
		    }
		});
	    }
	});
		
		// Ajoutez temporairement ceci pour vérifier
	console.log("Bouton:", mobileMenuButton);
	console.log("Menu:", mobileMenu);
	mobileMenuButton.addEventListener('click', function() {
	    console.log("Clic détecté");
	    console.log("Menu avant:", mobileMenu.classList);
	    // ... reste du code
	});
	
        // Auto-refresh stats every 30 seconds
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(function() {
                // Refresh only the stats section
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        
                        // Update stats cards
                        const currentStats = document.querySelectorAll('.stat-card');
                        const newStats = doc.querySelectorAll('.stat-card');
                        
                        currentStats.forEach((stat, index) => {
                            if (newStats[index]) {
                                stat.innerHTML = newStats[index].innerHTML;
                            }
                        });
                        
                        // Update pulse dot to show activity
                        const pulseDot = document.querySelector('.pulse-dot');
                        if (pulseDot) {
                            pulseDot.style.backgroundColor = '#10b981';
                            setTimeout(() => {
                                pulseDot.style.backgroundColor = '#6b7280';
                            }, 1000);
                        }
                    })
                    .catch(error => {
                        console.log('Erreur lors du rafraîchissement:', error);
                    });
            }, 30000);
        }
        
        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
            
            // Stop refresh when page is hidden (user switches tab)
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    clearInterval(refreshInterval);
                } else {
                    startAutoRefresh();
                }
            });
        });
        
        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Enhanced hover effects for stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Notification system for alerts
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg text-white transform translate-x-full transition-transform duration-300 ${
                type === 'success' ? 'bg-green-500' : 
                type === 'warning' ? 'bg-yellow-500' : 
                type === 'error' ? 'bg-red-500' : 'bg-blue-500'
            }`;
            notification.innerHTML = `
                <div class="flex items-center space-x-2">
                    <i class="fas ${
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-triangle' : 
                        type === 'error' ? 'fa-times-circle' : 'fa-info-circle'
                    }"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Hide notification after 5 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(full)';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 5000);
        }
        
        
    </script>
</body>
</html>
