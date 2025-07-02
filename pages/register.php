<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/functions.php';
require_once '../config/database.php';

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

// Récupérer les filières pour le formulaire
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, nom FROM filieres WHERE actif = TRUE ORDER BY nom");
    $filieres = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Erreur lors de la récupération des filières.';
    $filieres = [];
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nom = cleanInput($_POST['nom']);
    $prenom = cleanInput($_POST['prenom']);
    $email = cleanInput($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $filiere_id = (int)$_POST['filiere_id'];
    $niveau = cleanInput($_POST['niveau']);
   
    // Validation
    if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif ($password !== $confirm_password) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif (strlen($password) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères.';
    } else {
        try {
            // Vérifier si l'email existe déjà
            $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $error = 'Cette adresse email est déjà utilisée.';
            } else {
                // Créer le compte
                $password_hash = hashPassword($password);
                
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role, filiere_id, niveau) 
                    VALUES (?, ?, ?, ?, 'etudiant', ?, ?)
                ");
                
                $stmt->execute([$nom, $prenom, $email, $password_hash, $filiere_id, $niveau]);
                
                $success = 'Votre compte a été créé avec succès ! Vous pouvez maintenant vous connecter.';
            }
        } catch (Exception $e) {
            $error = 'Erreur lors de la création du compte : ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - Gestion Projets ESI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --border-radius: 8px;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: var(--dark-color);
            line-height: 1.6;
        }

        .register-container {
            background: white;
            width: 100%;
            max-width: 550px;
            padding: 40px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .register-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header h2 {
            color: var(--secondary-color);
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .register-header p {
            color: var(--primary-color);
            font-size: 16px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background-color: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #7f8c8d;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #95a5a6;
            font-size: 18px;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(90deg, var(--success-color), #27ae60);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn:hover {
            background: linear-gradient(90deg, #27ae60, var(--success-color));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background: linear-gradient(90deg, #95a5a6, #7f8c8d);
        }

        .btn-secondary:hover {
            background: linear-gradient(90deg, #7f8c8d, #95a5a6);
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background-color: #fdecea;
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .alert-success {
            background-color: #e8f8f0;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .register-footer {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: #7f8c8d;
        }

        .register-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .register-footer a:hover {
            text-decoration: underline;
        }

        .university-logo {
            width: 80px;
            margin-bottom: 15px;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        @media (max-width: 600px) {
            .register-container {
                padding: 30px 20px;
            }
            
            .register-header h2 {
                font-size: 24px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <img src="../logo_esi.png" alt="Université Nazi BONI" class="university-logo">
            <h2>Inscription Étudiant</h2>
            <p>École Supérieure d'Informatique</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="../index.php" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </a>
            </div>
        <?php else: ?>
        
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="nom">Nom <span style="color: var(--error-color);">*</span></label>
                    <input type="text" id="nom" name="nom" required 
                           value="<?php echo isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : ''; ?>"
                           placeholder="Votre nom">
                </div>
                
                <div class="form-group">
                    <label for="prenom">Prénom <span style="color: var(--error-color);">*</span></label>
                    <input type="text" id="prenom" name="prenom" required 
                           value="<?php echo isset($_POST['prenom']) ? htmlspecialchars($_POST['prenom']) : ''; ?>"
                           placeholder="Votre prénom">
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email <span style="color: var(--error-color);">*</span></label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       placeholder="exemple@univ.edu">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="filiere_id">Filière <span style="color: var(--error-color);">*</span></label>
                    <select id="filiere_id" name="filiere_id" required>
                        <option value="">Choisir une filière</option>
                        <?php foreach ($filieres as $filiere): ?>
                            <option value="<?php echo $filiere['id']; ?>" 
                                    <?php echo (isset($_POST['filiere_id']) && $_POST['filiere_id'] == $filiere['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filiere['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="niveau">Niveau</label>
                    <select id="niveau" name="niveau">
                        <option value="">Choisir un niveau</option>
                        <option value="L1-TC1" <?php echo (isset($_POST['niveau']) && $_POST['niveau'] == 'L1-TC1') ? 'selected' : ''; ?>>L1-TC1</option>
                        <option value="L2-TC2" <?php echo (isset($_POST['niveau']) && $_POST['niveau'] == 'L2-TC2') ? 'selected' : ''; ?>>L2-TC2</option>
                        <option value="L3-IRS" <?php echo (isset($_POST['niveau']) && $_POST['niveau'] == 'L3-IRS') ? 'selected' : ''; ?>>L3-IRS</option>
                        <option value="M1-ISI" <?php echo (isset($_POST['niveau']) && $_POST['niveau'] == 'M1-ISI') ? 'selected' : ''; ?>>M1-ISI</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Mot de passe <span style="color: var(--error-color);">*</span></label>
                <div class="password-container">
                    <input type="password" id="password" name="password" required
                           placeholder="••••••••" minlength="6">
                    <button type="button" class="password-toggle" onclick="togglePassword('password')">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
                <small>Minimum 6 caractères</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirmation <span style="color: var(--error-color);">*</span></label>
                <div class="password-container">
                    <input type="password" id="confirm_password" name="confirm_password" required
                           placeholder="••••••••">
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn">
                    <i class="fas fa-user-plus"></i> Créer mon compte
                </button>
            </div>
        </form>
        
        <?php endif; ?>
        
        <div class="register-footer">
            <p><a href="../index.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a></p>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = passwordInput.nextElementSibling.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
