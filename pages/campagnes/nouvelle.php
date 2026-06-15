<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer le type de campagne depuis l'URL
$type = $_GET['type'] ?? 'whatsapp';

// Seul WhatsApp est géré pour l'instant
if ($type != 'whatsapp') {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

$typeLabel = 'WhatsApp';
$typeIcon = 'fa-whatsapp';
$typeColor = 'green';
$showObjet = false;
$showFichier = true;
$placeholder = "Votre message WhatsApp...";

// Récupérer l'ID du type message WhatsApp (id_type_message = 3)
$typeMessage = $db->select('type_message', ['libelle_type' => 'WhatsApp']);
$idTypeMessage = $typeMessage ? $typeMessage[0]['id_type_message'] : 3;

// Récupérer les listes
$listes = $db->select('liste', ['id_compte' => $idCompte]);

// Récupérer tous les contacts (pour envoi individuel)
$contacts = $db->select('contact', ['id_compte' => $idCompte]);

// Récupérer les canaux WhatsApp uniquement
$canaux = $db->select('canal', [
    'id_compte' => $idCompte, 
    'id_type_message' => $idTypeMessage,
    'est_actif' => true
]);

$error = '';

// Si aucun canal WhatsApp n'est configuré, rediriger vers la configuration
if (empty($canaux)) {
    $_SESSION['flash_error'] = "Veuillez d'abord configurer un canal WhatsApp.";
    header('Location: index.php?page=campagnes/config_whatsapp');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_campagne = trim($_POST['nom_campagne']);
    $type_destinataire = $_POST['type_destinataire'];
    $id_liste = ($type_destinataire == 'liste') ? $_POST['id_liste'] : null;
    $id_contact = ($type_destinataire == 'contact') ? $_POST['id_contact'] : null;
    $id_canal = $_POST['id_canal'];
    $message = trim($_POST['message']);
    $date_planification = !empty($_POST['date_planification']) ? $_POST['date_planification'] : null;
    
    // Gestion des fichiers (images, audio, etc.)
    $fichier_joint = null;
    if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/campagnes/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $extension = pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION);
        $nomFichier = uniqid() . '.' . $extension;
        $cheminFichier = $uploadDir . $nomFichier;
        if (move_uploaded_file($_FILES['fichier']['tmp_name'], $cheminFichier)) {
            $fichier_joint = $cheminFichier;
        }
    }
    
    if (empty($nom_campagne) || empty($id_canal) || empty($message)) {
        $error = "Veuillez remplir tous les champs obligatoires";
    } elseif ($type_destinataire == 'liste' && empty($id_liste)) {
        $error = "Veuillez sélectionner une liste";
    } elseif ($type_destinataire == 'contact' && empty($id_contact)) {
        $error = "Veuillez sélectionner un contact";
    } else {
        $destinataires = [
            'type' => $type_destinataire,
            'id' => $type_destinataire == 'liste' ? $id_liste : $id_contact
        ];
        
        $data = [
            'id_compte' => $idCompte,
            'id_liste' => $id_liste,
            'id_type_message' => $idTypeMessage,
            'id_canal' => $id_canal,
            'nom_campagne' => $nom_campagne,
            'message' => $message,
            'fichier_joint' => $fichier_joint,
            'destinataires' => json_encode($destinataires),
            'date_planification' => $date_planification,
            'statut' => $date_planification ? 'PROGRAMMEE' : 'EN_COURS'
        ];
        
        try {
            $db->insert('campagne', $data);
            $_SESSION['flash_message'] = "Campagne WhatsApp créée avec succès !";
            header('Location: index.php?page=campagnes/index');
            exit;
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle campagne WhatsApp - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-dropdown {
            border-radius: 0.5rem;
            border-color: #d1d5db;
        }
        .select2-search__field {
            border-radius: 0.5rem !important;
            border: 1px solid #d1d5db !important;
            padding: 6px !important;
        }
        .select2-results__option--highlighted {
            background-color: #22c55e !important;
        }
    </style>
</head>
<body>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-green-100 p-3 rounded-full mr-4">
            <i class="fab fa-whatsapp text-green-600 text-xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Nouvelle campagne WhatsApp</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <?= $error ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la campagne *</label>
                    <input type="text" name="nom_campagne" required 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                           placeholder="Ex: Newsletter WhatsApp Janvier 2025">
                </div>
                
                <!-- Type de destinataire -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Envoyer à *</label>
                    <div class="flex space-x-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="type_destinataire" value="liste" checked class="mr-2" onchange="toggleDestinataire()">
                            <span>Une liste de contacts</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="type_destinataire" value="contact" class="mr-2" onchange="toggleDestinataire()">
                            <span>Un contact unique</span>
                        </label>
                    </div>
                </div>
                
                <!-- Sélection liste -->
                <div id="liste_container">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Liste de contacts *</label>
                    <select name="id_liste" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="">Sélectionner une liste</option>
                        <?php foreach ($listes as $liste): ?>
                            <option value="<?= $liste['id_liste'] ?>"><?= htmlspecialchars($liste['nom_liste']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Sélection contact unique avec recherche -->
                <div id="contact_container" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-search mr-1 text-gray-400"></i> Rechercher un contact *
                    </label>
                    <select name="id_contact" id="contact_search" class="w-full" style="width: 100%;">
                        <option value="">Tapez le nom, prénom ou numéro...</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= $contact['id_contact'] ?>">
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                (<?= htmlspecialchars($contact['telephone'] ?? $contact['email'] ?? '-') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i> Tapez pour rechercher par nom, prénom ou numéro
                    </p>
                </div>
                
                <!-- Canal WhatsApp -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Canal WhatsApp *</label>
                    <select name="id_canal" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="">Sélectionner un canal</option>
                        <?php foreach ($canaux as $canal): ?>
                            <option value="<?= $canal['id_canal'] ?>"><?= htmlspecialchars($canal['nom_canal']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Message -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message *</label>
                    <textarea name="message" id="message" required rows="6" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                              placeholder="<?= $placeholder ?>"></textarea>
                    <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                </div>
                
                <!-- Fichier joint -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fichier joint (optionnel)</label>
                    <input type="file" name="fichier" id="fichier" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-image"></i> Formats acceptés : images, audio, PDF (Max 10 Mo)
                    </p>
                </div>
                
                <!-- Planification -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Planification</label>
                    <input type="datetime-local" name="date_planification" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1">Laissez vide pour un envoi immédiat</p>
                </div>
                
                <div class="flex justify-end space-x-2 pt-4">
                    <a href="index.php?page=campagnes/choix" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Annuler
                    </a>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fab fa-whatsapp mr-2"></i>Créer la campagne WhatsApp
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
// Initialisation de Select2 pour la recherche de contact
$(document).ready(function() {
    $('#contact_search').select2({
        placeholder: "Tapez le nom, prénom ou numéro...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

function toggleDestinataire() {
    const type = document.querySelector('input[name="type_destinataire"]:checked').value;
    const listeContainer = document.getElementById('liste_container');
    const contactContainer = document.getElementById('contact_container');
    
    if (type === 'liste') {
        listeContainer.style.display = 'block';
        contactContainer.style.display = 'none';
    } else {
        listeContainer.style.display = 'none';
        contactContainer.style.display = 'block';
        setTimeout(() => {
            $('#contact_search').select2('open').select2('close');
        }, 100);
    }
}

// Compteur de caractères
document.getElementById('message').addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('charCount').textContent = count + ' caractères';
});

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    toggleDestinataire();
});
</script>

</body>
</html>