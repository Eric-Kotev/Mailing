<?php
require_once 'config.php';
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

// Utilisation des constantes de config.php
$supabaseUrl = SUPABASE_URL;
$supabaseKey = SUPABASE_KEY;

// Vérifier si la colonne logo_url existe
$hasLogoColumn = isset($compte['logo_url']);

// Traitement de l'upload du logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo'])) {
    $file = $_FILES['logo'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors de l'upload du fichier";
    } elseif (!in_array($file['type'], $allowedTypes)) {
        $error = "Format de fichier non supporté. Utilisez JPG, PNG, GIF ou WEBP";
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $error = "Le fichier est trop volumineux (max 2MB)";
    } else {
        $fileContent = file_get_contents($file['tmp_name']);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'logo_' . $idCompte . '_' . time() . '.' . $extension;
        
        $uploadUrl = rtrim($supabaseUrl, '/') . "/storage/v1/object/logo/$fileName";
        
        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $supabaseKey",
            "Content-Type: {$file['type']}"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $logoUrl = rtrim($supabaseUrl, '/') . "/storage/v1/object/public/logo/$fileName";
            $result = $db->update('compte', ['logo_url' => $logoUrl], ['id_compte' => $idCompte]);
            
            if ($result !== false) {
                $success = "Logo mis à jour avec succès !";
                $compte = $db->select('compte', ['id_compte' => $idCompte])[0];
            } else {
                $error = "Erreur lors de la mise à jour";
            }
        } else {
            $error = "Erreur lors de l'upload du logo";
        }
    }
}

// Traitement de la suppression du logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_logo'])) {
    $oldLogoUrl = $compte['logo_url'];
    
    if (!empty($oldLogoUrl)) {
        $oldFileName = basename($oldLogoUrl);
        
        // Supprimer de la base de données
        $result = $db->update('compte', ['logo_url' => null], ['id_compte' => $idCompte]);
        
        if ($result !== false) {
            // Supprimer le fichier physique
            $deleteUrl = rtrim($supabaseUrl, '/') . "/storage/v1/object/logo/$oldFileName";
            
            $ch = curl_init($deleteUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $supabaseKey"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            curl_exec($ch);
            curl_close($ch);
            
            $success = "Logo supprimé avec succès !";
            $compte = $db->select('compte', ['id_compte' => $idCompte])[0];
        } else {
            $error = "Erreur lors de la suppression";
        }
    } else {
        $error = "Aucun logo trouvé";
    }
}

// Traitement du formulaire de profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['logo']) && !isset($_POST['delete_logo'])) {
    $prenom = trim($_POST['prenom']);
    $nom = trim($_POST['nom']);
    $entreprise = trim($_POST['entreprise']);
    $user = trim($_POST['user']);
    
    if (empty($prenom) || empty($nom) || empty($entreprise) || empty($user)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        $data = [
            'prenom' => $prenom,
            'nom' => $nom,
            'entreprise' => $entreprise,
            'user' => $user
        ];
        
        if (!empty($_POST['new_password'])) {
            if (password_verify($_POST['current_password'], $compte['password'])) {
                $data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            } else {
                $error = "Mot de passe actuel incorrect";
            }
        }
        
        if (empty($error)) {
            try {
                $result = $db->update('compte', $data, ['id_compte' => $idCompte]);
                
                if ($result !== false) {
                    $_SESSION['user_name'] = $prenom . ' ' . $nom;
                    $_SESSION['user_entreprise'] = $entreprise;
                    $success = "Profil mis à jour avec succès !";
                    $compte = $db->select('compte', ['id_compte' => $idCompte])[0];
                } else {
                    $error = "Erreur lors de la mise à jour";
                }
            } catch (Exception $e) {
                $error = "Erreur : " . $e->getMessage();
            }
        }
    }
}
?>

<div class="w-full">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Mon compte</h1>
        <p class="text-gray-500">Gérez vos informations personnelles et votre logo</p>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <!-- Section Logo -->
        <div class="mb-6 pb-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Logo de l'entreprise</h3>
            <div class="flex flex-col sm:flex-row items-center sm:items-start space-y-4 sm:space-y-0 sm:space-x-6">
                <div class="logo-wrapper" id="logoWrapper">
                    <?php if (!empty($compte['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($compte['logo_url']) . '?t=' . time() ?>" 
                             alt="Logo <?= htmlspecialchars($compte['entreprise']) ?>"
                             id="logoImage"
                             class="logo-image">
                    <?php else: ?>
                        <div id="logoPlaceholder" class="logo-placeholder">
                            <i class="fas fa-building"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex-1 w-full">
                    <form method="POST" enctype="multipart/form-data" class="space-y-3" id="logoForm">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Changer le logo</label>
                            <input type="file" name="logo" id="logoInput" accept="image/*" 
                                   class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                            <p class="text-xs text-gray-500 mt-1">Formats: JPG, PNG, GIF, WEBP. Max 2MB</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" name="upload_logo" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm transition duration-200">
                                <i class="fas fa-upload mr-1"></i>Modifier
                            </button>
                            
                            <?php if (!empty($compte['logo_url'])): ?>
                                <button type="button" id="deleteLogoBtn" 
                                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm transition duration-200">
                                    <i class="fas fa-trash mr-1"></i>Supprimer
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Formulaire Profil -->
        <form method="POST" id="profileForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise <span class="text-red-500">*</span></label>
                    <input type="text" name="entreprise" required value="<?= htmlspecialchars($compte['entreprise']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom <span class="text-red-500">*</span></label>
                    <input type="text" name="prenom" required value="<?= htmlspecialchars($compte['prenom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input type="text" name="nom" required value="<?= htmlspecialchars($compte['nom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Identifiant de connexion <span class="text-red-500">*</span></label>
                    <input type="text" name="user" required value="<?= htmlspecialchars($compte['user']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
                    <p class="text-xs text-gray-500 mt-1">Utilisé pour vous connecter (email ou pseudo)</p>
                </div>
            </div>
            
            <div class="mt-6 pt-4 border-t border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Changer le mot de passe</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition duration-200">
                    <i class="fas fa-save mr-2"></i>Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de confirmation -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <i class="fas fa-trash-alt text-red-500 text-4xl mb-3"></i>
        <h3>Confirmer la suppression</h3>
        <p>Êtes-vous sûr de vouloir supprimer votre logo ? Cette action est irréversible.</p>
        <div class="modal-buttons">
            <button class="btn-cancel" id="cancelDelete">Annuler</button>
            <button class="btn-confirm" id="confirmDelete">Supprimer</button>
        </div>
    </div>
</div>

<script>
// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer') || (() => {
        const newContainer = document.createElement('div');
        newContainer.id = 'toastContainer';
        newContainer.className = 'toast-container';
        document.body.appendChild(newContainer);
        return newContainer;
    })();
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    
    let icon = '';
    switch(type) {
        case 'success': icon = '<i class="fas fa-check-circle"></i>'; break;
        case 'error': icon = '<i class="fas fa-exclamation-circle"></i>'; break;
        case 'warning': icon = '<i class="fas fa-exclamation-triangle"></i>'; break;
        case 'info': icon = '<i class="fas fa-info-circle"></i>'; break;
        default: icon = '<i class="fas fa-bell"></i>';
    }
    
    toast.innerHTML = `
        <div class="toast-content">
            <div class="toast-icon">${icon}</div>
            <div class="toast-message">${escapeHtml(message)}</div>
            <div class="toast-close"><i class="fas fa-times"></i></div>
        </div>
    `;
    
    container.appendChild(toast);
    
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 300);
    });
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// MESSAGES PHP EN TOAST
// ============================================
<?php if ($success): ?>
showToast('<?= addslashes($success) ?>', 'success');
<?php endif; ?>

<?php if ($error): ?>
showToast('<?= addslashes($error) ?>', 'error');
<?php endif; ?>

// ============================================
// AFFICHER/MASQUER LE MOT DE PASSE
// ============================================
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

// ============================================
// APERÇU DU LOGO AVANT UPLOAD
// ============================================
const logoInput = document.getElementById('logoInput');
if (logoInput) {
    logoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                const wrapper = document.getElementById('logoWrapper');
                const existingImage = document.getElementById('logoImage');
                const existingPlaceholder = document.getElementById('logoPlaceholder');
                
                if (existingImage) {
                    existingImage.src = event.target.result;
                } else if (existingPlaceholder) {
                    wrapper.innerHTML = `<img src="${event.target.result}" alt="Aperçu" id="logoImage" class="logo-image">`;
                } else {
                    wrapper.innerHTML = `<img src="${event.target.result}" alt="Aperçu" id="logoImage" class="logo-image">`;
                }
            };
            reader.readAsDataURL(file);
        }
    });
}

// ============================================
// MODAL DE CONFIRMATION
// ============================================
const deleteBtn = document.getElementById('deleteLogoBtn');
const modal = document.getElementById('confirmModal');
const confirmDelete = document.getElementById('confirmDelete');
const cancelDelete = document.getElementById('cancelDelete');

if (deleteBtn) {
    deleteBtn.addEventListener('click', function() {
        modal.classList.add('show');
    });
}

if (confirmDelete) {
    confirmDelete.addEventListener('click', function() {
        modal.classList.remove('show');
        const form = document.createElement('form');
        form.method = 'POST';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_logo';
        input.value = '1';
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    });
}

if (cancelDelete) {
    cancelDelete.addEventListener('click', function() {
        modal.classList.remove('show');
    });
}

window.addEventListener('click', function(e) {
    if (e.target === modal) {
        modal.classList.remove('show');
    }
});
</script>

<style>
/* ============================================
   TOAST STYLES
   ============================================ */
.toast-container {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.toast-notification {
    min-width: 300px;
    max-width: 450px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    animation: slideInRight 0.3s ease-out;
    overflow: hidden;
}

@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.toast-notification .toast-content {
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.toast-notification.success .toast-content { background: #10b981; color: white; }
.toast-notification.error .toast-content { background: #ef4444; color: white; }
.toast-notification.warning .toast-content { background: #f59e0b; color: white; }
.toast-notification.info .toast-content { background: #3b82f6; color: white; }

.toast-notification .toast-icon {
    font-size: 1.25rem;
}

.toast-notification .toast-message {
    flex: 1;
    font-size: 0.875rem;
    font-weight: 500;
}

.toast-notification .toast-close {
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-notification .toast-close:hover {
    opacity: 1;
}

.toast-notification.fade-out {
    animation: fadeOut 0.3s ease forwards;
}

@keyframes fadeOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

/* ============================================
   PASSWORD STYLES
   ============================================ */
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
    z-index: 10;
    background: transparent;
    border: none;
    font-size: 1.1rem;
}
.toggle-password:hover {
    color: #3b82f6;
}

/* ============================================
   LOGO STYLES
   ============================================ */
.logo-wrapper {
    width: 96px;
    height: 96px;
    position: relative;
    flex-shrink: 0;
}
.logo-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    padding: 8px;
}
.logo-placeholder {
    width: 100%;
    height: 100%;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.logo-placeholder i {
    font-size: 2.5rem;
    color: #9ca3af;
}

/* ============================================
   MODAL STYLES
   ============================================ */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1001;
    justify-content: center;
    align-items: center;
}
.modal.show {
    display: flex;
}
.modal-content {
    background: white;
    border-radius: 12px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    animation: modalIn 0.2s ease;
}
@keyframes modalIn {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}
.modal-content h3 {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 12px;
}
.modal-content p {
    color: #6b7280;
    margin-bottom: 24px;
}
.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
}
.modal-buttons button {
    padding: 8px 20px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s;
}
.btn-confirm {
    background: #ef4444;
    color: white;
}
.btn-confirm:hover {
    background: #dc2626;
}
.btn-cancel {
    background: #e5e7eb;
    color: #374151;
}
.btn-cancel:hover {
    background: #d1d5db;
}
</style>