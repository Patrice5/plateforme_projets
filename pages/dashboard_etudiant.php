<?php
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et est un étudiant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
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

    // Calculer les statistiques
   $stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as projets_soumis,
        SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as projets_valides,
        SUM(CASE WHEN statut = 'en_cours_evaluation' THEN 1 ELSE 0 END) as projets_en_cours,
        SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as projets_refuses,
        SUM(CASE WHEN statut = 'en_revision' THEN 1 ELSE 0 END) as projets_en_revision
    FROM projets 
    WHERE etudiant_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();


    // Récupérer les activités récentes
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.nom as encadrant_nom,
            e.prenom as encadrant_prenom
        FROM projets p
        LEFT JOIN utilisateurs e ON p.encadrant_id = e.id
        WHERE p.etudiant_id = ? 
        ORDER BY p.date_creation DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recentActivities = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Erreur dashboard étudiant: " . $e->getMessage());
    $stats = ['projets_soumis' => 0, 'projets_valides' => 0, 'projets_en_cours' => 0, 'projets_refuses' => 0];
    $recentActivities = [];
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Étudiant - UNB-ESI</title>
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

        .pulse-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
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
                <button id="mobile-menu-button" class="hamburger p-2 focus:outline-none">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu bg-white shadow-md">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-user text-indigo-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Étudiant</p>
                            <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_etudiant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-indigo-50 text-indigo-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="projets.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Mes Projets</span>
                        </a>
                        <a href="soumettre_projet.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-plus-circle w-5 text-center"></i>
                            <span>Nouveau Projet</span>
                        </a>
                        <a href="profile.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
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
                            <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_etudiant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-indigo-50 text-indigo-700">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="projets.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Mes Projets</span>
                        </a>
                        <a href="soumettre_projet.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-plus-circle w-5 text-center"></i>
                            <span>Nouveau Projet</span>
                        </a>
                        <a href="profile.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
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
            <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-md p-6 mb-6 text-white animate-fade-in">
                <h1 class="text-2xl font-bold">Bienvenue, <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>!</h1>
                <p class="opacity-90">Gérez vos projets académiques et suivez vos évaluations.</p>
                <div class="mt-2 text-sm opacity-75">
                    Dernière connexion: <?php echo date('d/m/Y à H:i'); ?>
                </div>
            </div>
            
            <!-- Dashboard header -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Mes Projets & Évaluations</h2>
                <span class="bg-indigo-100 text-indigo-800 text-xs font-medium px-3 py-1 rounded-full">
                    Étudiant
                </span>
            </div>
            
            <!-- Stats cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Projets Soumis -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-project-diagram text-indigo-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Projets Soumis</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_soumis'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-indigo-500 to-indigo-300 rounded-full"></div>
                </div>
                
                <!-- Projets Validés -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Projets Validés</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_valides'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-green-500 to-green-300 rounded-full"></div>
                </div>
                
                <!-- En Évaluation -->
                <div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-yellow-600 text-xl"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">En Évaluation</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_en_cours'] ?? 0; ?></p>
                        </div>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-yellow-500 to-yellow-300 rounded-full"></div>
                </div>
                
                
                <!-- Projets Refusés -->
<div class="stat-card bg-white rounded-xl shadow-md p-6 transition-all duration-300">
    <div class="flex items-center justify-between mb-4">
        <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
            <i class="fas fa-times-circle text-red-600 text-xl"></i>
        </div>
        <div class="text-right">
            <p class="text-sm text-gray-500">Projets Refusés</p>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['projets_refuses'] ?? 0; ?></p>
        </div>
    </div>
    <div class="h-1 bg-gradient-to-r from-red-500 to-red-300 rounded-full"></div>
</div>
          </div> 
            <!-- Recent Activities -->
            <div class="bg-white rounded-xl shadow-md mb-6 animate-fade-in">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Mes Activités Récentes</h3>
                        <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2 py-1 rounded-full">
                            Dernières 24h
                        </span>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php if (!empty($recentActivities)): ?>
                        <div class="space-y-4">
                            <?php foreach ($recentActivities as $activity): ?>
                                <div class="flex items-start space-x-4 p-4 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                                            <i class="<?php echo getActivityIcon($activity['statut']); ?>"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm text-gray-800">
                                            <strong><?php echo htmlspecialchars($activity['titre']); ?></strong>
                                            - <?php echo ucfirst($activity['statut']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php echo formatDateFrench($activity['date_creation']); ?>
                                        </p>
                                        <?php if ($activity['encadrant_nom']): ?>
                                            <p class="text-xs text-gray-400 mt-1">
                                                Encadrant: <?php echo htmlspecialchars($activity['encadrant_prenom'] . ' ' . $activity['encadrant_nom']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <?php
                                        $badgeClass = '';
                                        $badgeText = '';
                                        switch($activity['statut']) {
                                            case 'valide':
                                                $badgeClass = 'bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full';
                                                $badgeText = 'Validé';
                                                break;
                                            case 'refuse':
                                                $badgeClass = 'bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded-full';
                                                $badgeText = 'Refusé';
                                                break;
                                            case 'en_cours':
                                                $badgeClass = 'bg-yellow-100 text-yellow-800 text-xs font-medium px-2 py-1 rounded-full';
                                                $badgeText = 'En cours';
                                                break;
                                            default:
                                                $badgeClass = 'bg-gray-100 text-gray-800 text-xs font-medium px-2 py-1 rounded-full';
                                                $badgeText = 'Soumis';
                                        }
                                        ?>
                                        <span class="<?php echo $badgeClass; ?>">
                                            <?php echo $badgeText; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-6 text-center">
                            <a href="projets.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                Voir tous mes projets
                                <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-history text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-500">Aucune activité récente</p>
                            <p class="text-xs text-gray-400 mt-2">Commencez par soumettre votre premier projet !</p>
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <a href="soumettre_projet.php" class="flex items-center space-x-3 p-4 rounded-lg border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50 transition-all">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                <i class="fas fa-plus text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Nouveau Projet</p>
                                <p class="text-xs text-gray-500">Soumettre un projet</p>
                            </div>
                        </a>
                        
                        <a href="projets.php" class="flex items-center space-x-3 p-4 rounded-lg border border-gray-200 hover:border-green-300 hover:bg-green-50 transition-all">
                            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                                <i class="fas fa-list text-green-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Mes Projets</p>
                                <p class="text-xs text-gray-500">Voir mes soumissions</p>
                            </div>
                        </a>
                        
                        <a href="profile.php" class="flex items-center space-x-3 p-4 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all">
                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-user text-blue-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Mon Profil</p>
                                <p class="text-xs text-gray-500">Modifier mes infos</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- JavaScript -->
    <script>
        // Toggle mobile menu
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobile-menu');
            const hamburger = this;
            
            mobileMenu.classList.toggle('open');
            hamburger.classList.toggle('active');
        });
        
        // Animation observer
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });
        
        // Real-time clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('fr-FR');
            const dateString = now.toLocaleDateString('fr-FR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Update if clock element exists
            const clockElement = document.getElementById('current-time');
            if (clockElement) {
                clockElement.textContent = `${dateString} - ${timeString}`;
            }
        }
        
        // Update clock every second
        setInterval(updateClock, 1000);
        updateClock(); // Initial call
        
        // Add loading states to buttons
        document.querySelectorAll('a[href]').forEach(link => {
            link.addEventListener('click', function() {
                if (!this.href.includes('#')) {
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                    
                    // Reset after 3 seconds if page doesn't load
                    setTimeout(() => {
                        this.style.opacity = '1';
                        this.style.pointerEvents = 'auto';
                    }, 3000);
                }
            });
        });
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
            
            // Set notification style based on type
            const styles = {
                success: 'bg-green-500 text-white',
                error: 'bg-red-500 text-white', 
                warning: 'bg-yellow-500 text-white',
                info: 'bg-blue-500 text-white'
            };
            
            notification.className += ` ${styles[type] || styles.info}`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                                     type === 'error' ? 'fa-exclamation-circle' : 
                                     type === 'warning' ? 'fa-exclamation-triangle' : 
                                     'fa-info-circle'}"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium">${message}</p>
                    </div>
                    <button onclick="closeNotification(this)" class="ml-4 text-white hover:text-gray-200">
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
                closeNotification(notification);
            }, 5000);
        }
        
        function closeNotification(element) {
            const notification = element.closest ? element.closest('.fixed') : element;
            notification.classList.add('translate-x-full');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }
        
        // Progress bar animation
        function animateProgressBars() {
            const progressBars = document.querySelectorAll('[style*="width:"]');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.transition = 'width 1s ease-in-out';
                    bar.style.width = width;
                }, 500);
            });
        }
        
        // Initialize progress bar animation
        window.addEventListener('load', animateProgressBars);
        
        // Smooth scroll for anchor links
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
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N pour nouveau projet
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'soumettre_projet.php';
            }
            
            // Ctrl + P pour voir les projets
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'projets.php';
            }
            
            // Escape pour fermer le menu mobile
            if (e.key === 'Escape') {
                const mobileMenu = document.getElementById('mobile-menu');
                const hamburger = document.getElementById('mobile-menu-button');
                if (mobileMenu.classList.contains('open')) {
                    mobileMenu.classList.remove('open');
                    hamburger.classList.remove('active');
                }
            }
        });
        
        // Auto-refresh for real-time updates (every 5 minutes)
        setInterval(() => {
            fetch(window.location.href, { 
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.text())
            .then(html => {
                // Update only the stats if they've changed
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                
                // Update stats cards
                const currentStats = document.querySelectorAll('.stat-card .text-3xl');
                const newStats = newDoc.querySelectorAll('.stat-card .text-3xl');
                
                currentStats.forEach((stat, index) => {
                    if (newStats[index] && stat.textContent !== newStats[index].textContent) {
                        stat.textContent = newStats[index].textContent;
                        stat.parentElement.parentElement.classList.add('animate-pulse');
                        setTimeout(() => {
                            stat.parentElement.parentElement.classList.remove('animate-pulse');
                        }, 1000);
                    }
                });
            })
            .catch(error => {
                console.log('Auto-refresh failed:', error);
            });
        }, 300000); // 5 minutes
        
        // Tooltip functionality
        function initTooltips() {
            const tooltipElements = document.querySelectorAll('[data-tooltip]');
            
            tooltipElements.forEach(element => {
                element.addEventListener('mouseenter', function() {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'absolute z-50 px-2 py-1 text-xs text-white bg-gray-800 rounded shadow-lg whitespace-nowrap';
                    tooltip.textContent = this.getAttribute('data-tooltip');
                    tooltip.id = 'tooltip';
                    
                    document.body.appendChild(tooltip);
                    
                    const rect = this.getBoundingClientRect();
                    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
                });
                
                element.addEventListener('mouseleave', function() {
                    const tooltip = document.getElementById('tooltip');
                    if (tooltip) {
                        tooltip.remove();
                    }
                });
            });
        }
        
        // Initialize tooltips
        initTooltips();
        
        // Dark mode toggle (if needed)
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
        }
        
        // Load saved dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
        
        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            if (loadTime > 3000) {
                console.warn('Page load time is slow:', loadTime + 'ms');
            }
        });
        
        // Service worker registration for offline functionality (optional)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch(function(err) {
                        console.log('ServiceWorker registration failed');
                    });
            });
        }
    </script>
</body>
</html>
