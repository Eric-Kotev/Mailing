<?php
global $db;

$idCompte = $_SESSION['user_id'];
$compte = $db->select('compte', ['id_compte' => $idCompte]);

if (!$compte) {
    header('Location: index.php');
    exit;
}
$compte = $compte[0];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prenom = trim($_POST['prenom']);
    $nom = trim($_POST['nom']);
    $entreprise = trim($_POST['entreprise']);
    $user = trim($_POST['user']);  // L'identifiant de connexion
    
    if (empty($prenom) || empty($nom) || empty($entreprise) || empty($user)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        $data = [
            'prenom' => $prenom,
            'nom' => $nom,
            'entreprise' => $entreprise,
            'user' => $user
        ];
        
        // Changer le mot de passe si fourni
        if (!empty($_POST['new_password'])) {
            if (password_verify($_POST['current_password'], $compte['password'])) {
                $data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            } else {
                $error = "Mot de passe actuel incorrect";
            }
        }
        
        if (empty($error)) {
            try {
                $db->update('compte', $data, ['id_compte' => $idCompte]);
                
                // Mettre à jour la session
                $_SESSION['user_name'] = $prenom . ' ' . $nom;
                $_SESSION['user_entreprise'] = $entreprise;
                
                $success = "Profil mis à jour avec succès !";
            } catch (Exception $e) {
                $error = "Erreur : " . $e->getMessage();
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
    <title>Mon compte - <?= APP_NAME ?></title>
    <style>
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
    </style>
</head>
<body>

<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Mon compte</h1>
    
    <div class="bg-white rounded-lg shadow p-6">
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST" id="profileForm">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise *</label>
                    <input type="text" name="entreprise" required value="<?= htmlspecialchars($compte['entreprise']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                    <input type="text" name="prenom" required value="<?= htmlspecialchars($compte['prenom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input type="text" name="nom" required value="<?= htmlspecialchars($compte['nom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Identifiant de connexion *</label>
                    <input type="text" name="user" required value="<?= htmlspecialchars($compte['user']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                    <p class="text-xs text-gray-500 mt-1">Utilisé pour vous connecter (peut être un email ou un pseudo)</p>
                </div>
            </div>
            
            <div class="mt-6 pt-4 border-t">
                <h3 class="text-lg font-bold mb-4">Changer le mot de passe</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe actuel</label>
                        <div class="password-container">
                            <input type="password" name="current_password" id="current_password"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                                   placeholder="Votre mot de passe actuel">
                            <button type="button" class="toggle-password" onclick="togglePassword('current_password', this)">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                        <div class="password-container">
                            <input type="password" name="new_password" id="new_password"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                                   placeholder="Nouveau mot de passe">
                            <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle"></i> Laissez vide si vous ne souhaitez pas changer votre mot de passe
                </p>
            </div>
            
            <div class="mt-6 flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-save mr-2"></i>Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js"></script>
<script>
// Fonction pour afficher/masquer le mot de passe
function togglePassword(inputId, buttonElement) {
    const passwordInput = document.getElementById(inputId);
    const icon = buttonElement.querySelector('i');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>

</body>
</html>