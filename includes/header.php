<?php
require_once __DIR__ . '/../config/database.php';

require_once  __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Projets ESI</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="clearfix">
        <div class="container">
            <h1>ESI - Gestion des Projets</h1>
            
            <?php if (isLoggedIn()): ?>
            <nav class="nav-links">
                <a href="pages/dashboard.php">Tableau de bord</a>
                
                <?php if (hasRole('etudiant')): ?>
                    <a href="pages/projets.php">Mes Projets</a>
                <?php endif; ?>
                
                <?php if (hasRole('enseignant')): ?>
                    <a href="pages/projets.php">Projets à évaluer</a>
                <?php endif; ?>
                
                <?php if (hasRole('administrateur')): ?>
                    <a href="pages/admin.php">Administration</a>
                <?php endif; ?>
                
                <a href="pages/profile.php">Profil</a>
                <a href="logout.php">Déconnexion</a>
            </nav>
            <?php endif; ?>
        </div>
    </header>
    
    <div class="container"><?php if (isLoggedIn()): ?>
        <p>Bienvenue, <?php echo htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']); ?> 
        (<?php echo ucfirst($_SESSION['user_role']); ?>)</p>
    <?php endif; ?>
