<?php
require_once 'includes/db.php';
require_once 'config.php';

// Vérifier si une session n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['message']) && $_GET['message'] == 'deconnected') {
    $success = "Vous avez été déconnecté avec succès.";
}

// Si déjà connecté, rediriger vers le dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        $compte = null;
        $isClient = false;
        
        // 1. Vérifier d'abord dans la table compte (admins, managers, users)
        $compte = getCompteByUser($login);
        
        // 2. Si non trouvé dans compte, chercher dans la table clients (clients)
        if (!$compte) {
            $clientData = $db->select('clients', ['email' => $login]);
            if (!empty($clientData)) {
                $client = $clientData[0];
                $isClient = true;
                
                // Vérifier le mot de passe du client
                if (password_verify($password, $client['mot_de_passe'])) {
                    if ($client['statut'] === 'inactif') {
                        $error = "Votre compte client est désactivé. Contactez l'administrateur.";
                    } else {
                        // Stocker les informations du client en session
                        $_SESSION['user_id'] = $client['id_client'];
                        $_SESSION['user_name'] = $client['prenom'] . ' ' . $client['nom'];
                        $_SESSION['user_prenom'] = $client['prenom'];
                        $_SESSION['user_nom'] = $client['nom'];
                        $_SESSION['user_entreprise'] = $client['societe'];
                        $_SESSION['user_email'] = $client['email'];
                        $_SESSION['user_credits'] = floatval($client['credit'] ?? 0);
                        $_SESSION['user_role'] = 'client';
                        $_SESSION['is_client'] = true;
                        $_SESSION['user_type'] = 'client';
                        
                        // Mettre à jour la date de dernière connexion
                        $db->update('clients', ['derniere_connexion' => date('Y-m-d H:i:s')], ['id_client' => $client['id_client']]);
                        
                        header('Location: index.php');
                        exit;
                    }
                } else {
                    $error = "Mot de passe incorrect";
                }
            } else {
                $error = "Identifiant inconnu";
            }
        } 
        // 3. Si trouvé dans compte, vérifier le mot de passe
        else {
            if (password_verify($password, $compte['password'])) {
                if (!$compte['actif']) {
                    $error = "Votre compte est suspendu. Contactez l'administrateur.";
                } else {
                    // Stocker les informations du compte en session
                    $_SESSION['user_id'] = $compte['id_compte'];
                    $_SESSION['user_name'] = $compte['prenom'] . ' ' . $compte['nom'];
                    $_SESSION['user_prenom'] = $compte['prenom'];
                    $_SESSION['user_nom'] = $compte['nom'];
                    $_SESSION['user_entreprise'] = $compte['entreprise'];
                    $_SESSION['user_email'] = $compte['user'];
                    $_SESSION['user_credits'] = floatval($compte['credits_total']);
                    $_SESSION['user_role'] = $compte['role'] ?? 'user';
                    $_SESSION['is_client'] = false;
                    $_SESSION['user_type'] = 'compte';
                    
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = "Mot de passe incorrect";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(
                135deg,
                #020617 0%,
                #0f172a 30%,
                #1e293b 70%,
                #334155 100%
            );
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.3;
            pointer-events: none;
        }
        
        .login-card {
            backdrop-filter: blur(10px);
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .input-focus:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }
        
        .btn-gradient {
            background: #2563eb;
            transition: all .3s ease;
        }

        .btn-gradient:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(37, 99, 235, .35);
        }
        
        .wave-bg {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 100px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" fill-opacity="1" d="M0,256L48,240C96,224,192,192,288,192C384,192,480,224,576,229.3C672,235,768,213,864,202.7C960,192,1056,192,1152,208C1248,224,1344,256,1392,272L1440,288L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') repeat-x;
            background-size: cover;
            pointer-events: none;
        }
        
        /* Style pour le conteneur du mot de passe */
        .password-container {
            position: relative;
        }
        
        .password-container input {
            padding-right: 45px;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            transition: color 0.2s ease;
            z-index: 10;
            background: transparent;
            border: none;
            font-size: 1.1rem;
        }
        
        .toggle-password:hover {
            color: #3b82f6;
        }
        
        .toggle-password:focus {
            outline: none;
        }
        
        @keyframes animate-shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .animate-shake {
            animation: animate-shake 0.5s ease-in-out;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .animate-bounce {
            animation: bounce 2s ease-in-out infinite;
        }
        
        .login-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative">
    <div class="absolute inset-0 overflow-hidden">
        <div class="absolute top-0 left-0 w-96 h-96 bg-blue-600/20 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 bg-slate-500/20 rounded-full blur-3xl"></div>
    </div>
    <div class="wave-bg"></div>
    
    <div class="max-w-md w-full relative z-10">
        <!-- Logo / Titre -->
        <div class="text-center mb-8 animate-bounce">
            <div class="bg-blue-600 inline-flex items-center justify-center w-24 h-24 rounded-3xl mb-5 shadow-2xl">
                <i class="fas fa-envelope-open-text text-white text-5xl"></i>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2 tracking-tight"><?= APP_NAME ?></h1>
            <p class="text-slate-300 text-sm">
                Plateforme d'envoi multi-canal
            </p>
        </div>
        
        <!-- Formulaire de connexion -->
        <div class="login-card bg-white rounded-3xl border border-slate-200 shadow-[0_25px_50px_-12px_rgba(0,0,0,0.45)] p-8">
            <h2 class="text-2xl font-bold text-slate-800 mb-2 text-center">Bienvenue</h2>
            <p class="text-slate-500 text-center text-sm mb-6">Connectez-vous à votre compte</p>
            
            <!-- Message d'erreur -->
            <?php if ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-sm animate-shake">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
                        <span class="text-sm"><?= $error ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Message de succès -->
            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg shadow-sm">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3 text-green-500"></i>
                        <span class="text-sm"><?= $success ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Formulaire -->
            <form method="POST" action="" id="loginForm">
                <div class="mb-5">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">
                        <i class="fas fa-envelope mr-2 text-blue-500"></i>
                        Email ou nom d'utilisateur
                    </label>
                    <input type="text" name="login" id="login" required 
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-slate-200 rounded-xl bg-slate-50 focus:outline-none focus:border-blue-600 input-focus transition"
                           placeholder="Entrez votre email ou nom d'utilisateur">
                    <p class="login-hint">
                        <i class="fas fa-info-circle mr-1"></i>
                        Utilisez votre email (clients) ou nom d'utilisateur (admin/manager)
                    </p>
                </div>
                
                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">
                        <i class="fas fa-lock mr-2 text-blue-500"></i>
                        Mot de passe
                    </label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" required 
                               class="w-full px-4 py-3 border border-slate-200 rounded-xl bg-slate-50 focus:outline-none focus:border-blue-600 input-focus transition"  
                               placeholder="Votre mot de passe">
                        <button type="button" id="togglePassword" class="toggle-password">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" 
                        class="btn-gradient w-full text-white font-bold py-3 px-4 rounded-xl transition duration-200 shadow-md">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Se connecter
                </button>
            </form>
            
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-8 text-slate-400 text-xs">
            &copy; <?= date('Y') ?> <?= APP_NAME ?> - Tous droits réservés
        </div>
    </div>
    
    <script>
        // Fonction pour afficher/masquer le mot de passe
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                // Basculer le type d'input
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Changer l'icône
                const icon = this.querySelector('i');
                if (icon) {
                    if (type === 'password') {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                }
            });
        }
    </script>
</body>
</html>