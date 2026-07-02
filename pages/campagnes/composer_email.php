<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagne = $campagneConfig[0];

// Vérifier que le type de message est Email
$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'email') {
    $_SESSION['flash_error'] = "Type de message non valide pour cette page";
    header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $campagneConfigId);
    exit;
}

// ============================================
// RÉCUPÉRATION DE L'ID DU TYPE MESSAGE EMAIL
// ============================================
$emailTypeId = null;
$typeMessageEmail = $db->select('type_message', ['libelle_type' => 'Email']);
if (empty($typeMessageEmail)) {
    $typeMessageEmail = $db->select('type_message', ['libelle_type' => 'email']);
}
if (!empty($typeMessageEmail)) {
    $emailTypeId = $typeMessageEmail[0]['id_type_message'];
}

// ============================================
// RÉCUPÉRATION DE LA BLACKLIST POUR EMAIL
// ============================================
$blacklistIds = [];
if ($emailTypeId) {
    $blacklist = $db->select('blacklist', ['id_type_message' => $emailTypeId]);
    foreach ($blacklist as $b) {
        if (!empty($b['id_contact'])) {
            $blacklistIds[] = $b['id_contact'];
        }
    }
}

// Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// Filtrer les contacts non blacklistés ET qui ont un email
$contacts = [];
$contactsSansEmail = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        if (!empty($contact['email'])) {
            $contacts[] = $contact;
        } else {
            $contactsSansEmail[] = $contact;
        }
    }
}

// Récupérer les listes avec le nombre de contacts (excluant blacklist Email)
$listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);
$listes = [];

foreach ($listesBrutes as $liste) {
    $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
    $nbContacts = 0;
    $nbSansEmail = 0;
    foreach ($listeContacts as $lc) {
        if (!in_array($lc['id_contact'], $blacklistIds)) {
            $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
            if (!empty($contact) && !empty($contact[0]['email'])) {
                $nbContacts++;
            } else {
                $nbSansEmail++;
            }
        }
    }
    
    $listes[] = [
        'id_liste' => $liste['id_liste'],
        'nom_liste' => $liste['nom_liste'],
        'nombre_contacts' => $nbContacts,
        'nombre_sans_email' => $nbSansEmail
    ];
}

$error = '';
$success = '';

// ============================================
// TRAITEMENT DU FORMULAIRE - ENREGISTREMENT SEULEMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enregistrer'])) {
    $objet = trim($_POST['objet'] ?? '');
    $corps = trim($_POST['corps'] ?? '');
    $type_envoi = $_POST['type_envoi'] ?? 'simple';
    $contact_id = $_POST['contact_unique'] ?? null;
    $liste_id = $_POST['liste_id'] ?? null;
    
    // Gestion des fichiers
    $hasFile = isset($_FILES['piece_jointe']) && $_FILES['piece_jointe']['error'] === UPLOAD_ERR_OK;
    
    // Validation
    if (empty($objet)) {
        $error = "Veuillez saisir un objet";
    } elseif (empty($corps)) {
        $error = "Veuillez saisir le corps du message";
    } elseif ($type_envoi === 'simple' && empty($contact_id)) {
        $error = "Veuillez sélectionner un destinataire";
    } elseif ($type_envoi === 'multiple' && empty($liste_id)) {
        $error = "Veuillez sélectionner une liste";
    } else {
        // Préparer les données à enregistrer
        $destinataires = [];
        $destinatairesNoms = [];
        $fichierInfo = null;
        $contactsSansEmailDansListe = 0;
        
        // Traiter le fichier si présent
        if ($hasFile) {
            $file = $_FILES['piece_jointe'];
            $fichierInfo = [
                'nom' => $file['name'],
                'taille' => round($file['size'] / 1024 / 1024, 2),
                'type' => $file['type'],
                'extension' => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))
            ];
        }
        
        if ($type_envoi === 'simple') {
            // Récupérer les infos du contact
            $contact = $db->select('contact', ['id_contact' => $contact_id, 'id_compte' => $idCompte]);
            if (!empty($contact) && !empty($contact[0]['email'])) {
                $destinataires[] = $contact[0]['email'];
                $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $contact[0]['email'] . ')';
            } else {
                $error = "Ce contact n'a pas d'adresse email valide";
            }
        } else {
            // Récupérer les contacts de la liste (excluant blacklist)
            $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
            foreach ($listeContacts as $lc) {
                if (!in_array($lc['id_contact'], $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $idCompte]);
                    if (!empty($contact) && !empty($contact[0]['email'])) {
                        $destinataires[] = $contact[0]['email'];
                        $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $contact[0]['email'] . ')';
                    } else {
                        $contactsSansEmailDansListe++;
                    }
                }
            }
        }
        
        if (empty($destinataires) && empty($error)) {
            if ($contactsSansEmailDansListe > 0) {
                $error = "Aucun destinataire valide. $contactsSansEmailDansListe contact(s) n'ont pas d'email.";
            } else {
                $error = "Aucun destinataire valide dans cette liste";
            }
        }
        
        if (empty($error)) {
            // Enregistrer dans la table campagne (historique)
            $campagneData = [
                'id_compte' => $idCompte,
                'id_campagne_config' => $campagneConfigId,
                'type_campagne' => 'email',
                'titre' => "Email: " . (strlen($objet) > 40 ? substr($objet, 0, 40) . '...' : $objet),
                'message' => $corps,
                'objet' => $objet,
                'destinataires' => json_encode($destinatairesNoms),
                'nb_destinataires' => count($destinataires),
                'nb_envoyes' => 0,
                'nb_succes' => 0,
                'nb_erreurs' => 0,
                'statut' => 'brouillon',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Ajouter les infos du fichier si présent
            if ($fichierInfo) {
                $campagneData['piece_jointe'] = json_encode($fichierInfo);
            }
            
            try {
                $db->insert('campagne', $campagneData);
                
                // Mettre à jour le statut de la campagne config
                $updateData = [
                    'statut' => 'pret_a_envoyer',
                    'message_content' => $corps,
                    'objet' => $objet
                ];
                
                if ($fichierInfo) {
                    $updateData['piece_jointe'] = json_encode($fichierInfo);
                }
                
                $db->update('campagne_config', $updateData, ['id_campagne_config' => $campagneConfigId]);
                
                $successMsg = "Email enregistré avec succès !";
                if ($contactsSansEmailDansListe > 0) {
                    $successMsg .= "<br><small>⚠️ $contactsSansEmailDansListe contact(s) n'ont pas d'email et ont été exclus.</small>";
                }
                $success = $successMsg;
                
                // Stocker en session pour l'étape suivante
                $_SESSION['message_content'] = $corps;
                $_SESSION['objet'] = $objet;
                $_SESSION['type_envoi'] = $type_envoi;
                if ($fichierInfo) {
                    $_SESSION['piece_jointe'] = $fichierInfo;
                }
                
            } catch (Exception $e) {
                $error = "Erreur lors de l'enregistrement : " . $e->getMessage();
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
    <title>Composer l'email - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Summernote - Éditeur HTML gratuit sans clé API -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <style>
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .type-envoi-option {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .type-envoi-option:hover {
            transform: translateY(-2px);
        }
        
        .select2-container--default .select2-selection--single {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
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
            background-color: #d97706 !important;
        }
        
        .campagne-info {
            background: #f3e8ff;
            border: 1px solid #d8b4fe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .campagne-info-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b21a5;
            margin-bottom: 8px;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 32px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #9ca3af;
        }
        .step .number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        .step.active .number {
            background: #d97706;
            color: white;
        }
        .step.done .number {
            background: #10b981;
            color: white;
        }
        .step.active {
            color: #1f2937;
            font-weight: 500;
        }
        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
        }
        .step-line.done {
            background: #10b981;
        }
        
        .btn-primary {
            background: #d97706;
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: #b45309;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
        }
        .btn-outline {
            background: transparent;
            color: #6b7280;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        .email-badge {
            background: #d97706;
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }
        
        #fileUploadArea {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        #fileUploadArea.drag-over {
            border-color: #d97706;
            background-color: #fffbeb;
        }
        
        .blacklist-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .info-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-left: 6px;
        }
        .info-badge.success { background: #dcfce7; color: #166534; }
        .info-badge.warning { background: #fef3c7; color: #92400e; }
        .info-badge.danger { background: #fee2e2; color: #991b1b; }
        
        .note-editor {
            border-radius: 8px !important;
            border-color: #d1d5db !important;
        }
        .note-editor .note-toolbar {
            background: #f9fafb !important;
            border-radius: 8px 8px 0 0 !important;
        }
        .note-editor .note-editable {
            min-height: 300px !important;
        }
    </style>
</head>
<body>

<div class="max-w-4xl mx-auto py-8 px-4">
    <!-- Indicateur d'étape -->
    <div class="step-indicator">
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Type de message</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
            <span class="number">2</span>
            <span>Composition</span>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <span class="number">3</span>
            <span>Envoi</span>
        </div>
    </div>

    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-gray-500 hover:text-gray-700 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-yellow-100 p-3 rounded-full mr-4">
            <i class="fas fa-envelope text-yellow-600 text-xl"></i>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Composer l'email</h1>
            <p class="text-gray-500">Rédigez votre email et choisissez les destinataires</p>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="campagne-info-title">
                <i class="fas fa-bullhorn mr-2"></i>
                Campagne : <?= htmlspecialchars($campagne['nom_campagne']) ?>
                <span class="email-badge ml-2"><i class="fas fa-envelope mr-1"></i>Email</span>
            </div>
            <div class="text-sm text-purple-700 mt-1">
                <i class="fas fa-users mr-1"></i> <?= count($contacts) ?> contact(s) avec email disponibles
                <?php if (count($contactsSansEmail) > 0): ?>
                    <span class="info-badge warning ml-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <?= count($contactsSansEmail) ?> contact(s) sans email
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
                <i class="fas fa-check-circle mr-2"></i> <?= $success ?>
                <div class="mt-2">
                    <a href="index.php?page=campagnes/choix_email&campagne_id=<?= $campagneConfigId ?>" class="text-green-700 underline font-semibold">
                        Cliquez ici pour continuer vers l'envoi →
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>
        
        <!-- Avertissement blacklist -->
        <?php if (count($tousContacts) - count($contacts) - count($contactsSansEmail) > 0): ?>
            <div class="blacklist-warning">
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                <span class="text-sm text-red-700">
                    <?= (count($tousContacts) - count($contacts) - count($contactsSansEmail)) ?> contact(s) blacklistés pour les emails ne sont pas affichés.
                </span>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="composerForm">
            <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
            <input type="hidden" name="action_enregistrer" value="1">
            
            <!-- Type d'envoi -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-envelope mr-1"></i> Type d'envoi *
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <div id="typeSimple" 
                         class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (!isset($_POST['type_envoi']) || $_POST['type_envoi'] == 'simple') ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200 hover:border-yellow-300' ?>">
                        <i class="fas fa-user text-yellow-600 text-xl mb-1"></i>
                        <p class="font-medium text-gray-800">Envoi unique</p>
                        <p class="text-xs text-gray-500">À un seul destinataire</p>
                    </div>
                    <div id="typeMultiple" 
                         class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200 hover:border-yellow-300' ?>">
                        <i class="fas fa-list text-yellow-600 text-xl mb-1"></i>
                        <p class="font-medium text-gray-800">Envoi par liste</p>
                        <p class="text-xs text-gray-500">À tous les contacts d'une liste</p>
                    </div>
                </div>
                <input type="hidden" name="type_envoi" id="type_envoi" value="<?= isset($_POST['type_envoi']) ? $_POST['type_envoi'] : 'simple' ?>">
            </div>
            
            <!-- Envoi unique -->
            <div id="simpleZone" class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-user mr-1"></i> Destinataire *
                </label>
                <select name="contact_unique" id="contact_unique" class="w-full" style="width: 100%;">
                    <option value="">Sélectionnez un contact...</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= $contact['id_contact'] ?>" <?= (isset($_POST['contact_unique']) && $_POST['contact_unique'] == $contact['id_contact']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> - <?= htmlspecialchars($contact['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (count($contactsSansEmail) > 0): ?>
                    <p class="text-xs text-yellow-600 mt-1">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <?= count($contactsSansEmail) ?> contact(s) n'ont pas d'email et ne sont pas affichés.
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Envoi par liste -->
            <div id="multipleZone" class="mb-4" style="display: none;">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-list mr-1"></i> Sélectionner une liste *
                </label>
                <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;">
                    <option value="">-- Sélectionnez une liste --</option>
                    <?php foreach ($listes as $liste): ?>
                        <option value="<?= $liste['id_liste'] ?>" <?= (isset($_POST['liste_id']) && $_POST['liste_id'] == $liste['id_liste']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($liste['nom_liste']) ?>
                            (<?= $liste['nombre_contacts'] ?> avec email
                            <?php if ($liste['nombre_sans_email'] > 0): ?>
                                , <span class="text-yellow-600"><?= $liste['nombre_sans_email'] ?> sans email</span>
                            <?php endif; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle mr-1"></i>
                    Seuls les contacts avec une adresse email valide seront inclus.
                </p>
            </div>
            
            <!-- Objet -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-tag mr-1"></i> Objet *
                </label>
                <input type="text" name="objet" id="objet" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                       placeholder="Objet de l'email..."
                       value="<?= isset($_POST['objet']) ? htmlspecialchars($_POST['objet']) : '' ?>">
            </div>
            
            <!-- Corps du message avec Summernote -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-comment mr-1"></i> Corps du message *
                </label>
                <textarea name="corps" id="corps" rows="10"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                          placeholder="Contenu de l'email..."><?= isset($_POST['corps']) ? htmlspecialchars($_POST['corps']) : '' ?></textarea>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-code mr-1"></i> Le contenu supporte le HTML (mise en forme, images, liens...)
                </p>
            </div>
            
            <!-- Pièce jointe -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-paperclip mr-1"></i> Pièce jointe (optionnel)
                </label>
                
                <div id="fileUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                    <input type="file" name="piece_jointe" id="piece_jointe" class="hidden">
                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                    <p class="text-gray-500">Cliquez ou glissez un fichier ici</p>
                    <p class="text-xs text-gray-400 mt-1">PDF, images, documents (Max 10 Mo)</p>
                    <div id="fileInfo" class="mt-3 text-sm hidden">
                        <i class="fas fa-file mr-1"></i> <span id="fileName"></span>
                        <button type="button" id="removeFileBtn" class="text-red-500 ml-2 hover:text-red-700">Supprimer</button>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="action-buttons">
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                    <i class="fas fa-times mr-2"></i>Annuler
                </a>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save mr-2"></i>Enregistrer l'email
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
$(document).ready(function() {
    $('#contact_unique').select2({
        placeholder: "Sélectionnez un contact...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
    
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
    
    // Initialisation de Summernote
    $('#corps').summernote({
        height: 300,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['fontname', ['fontname']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        placeholder: 'Rédigez le contenu de votre email...',
        lang: 'fr-FR'
    });
});

// Gestion du type d'envoi
const typeSimple = document.getElementById('typeSimple');
const typeMultiple = document.getElementById('typeMultiple');
const simpleZone = document.getElementById('simpleZone');
const multipleZone = document.getElementById('multipleZone');
const typeEnvoiInput = document.getElementById('type_envoi');

function setTypeEnvoi(type) {
    if (type === 'simple') {
        typeSimple.classList.add('border-yellow-500', 'bg-yellow-50');
        typeSimple.classList.remove('border-gray-200');
        typeMultiple.classList.remove('border-yellow-500', 'bg-yellow-50');
        typeMultiple.classList.add('border-gray-200');
        simpleZone.style.display = 'block';
        multipleZone.style.display = 'none';
        typeEnvoiInput.value = 'simple';
        
        $('#liste_id').prop('disabled', true);
        $('#liste_id').next().css('opacity', '0.5');
        $('#contact_unique').prop('disabled', false);
        $('#contact_unique').next().css('opacity', '1');
    } else {
        typeMultiple.classList.add('border-yellow-500', 'bg-yellow-50');
        typeMultiple.classList.remove('border-gray-200');
        typeSimple.classList.remove('border-yellow-500', 'bg-yellow-50');
        typeSimple.classList.add('border-gray-200');
        simpleZone.style.display = 'none';
        multipleZone.style.display = 'block';
        typeEnvoiInput.value = 'multiple';
        
        $('#contact_unique').prop('disabled', true);
        $('#contact_unique').next().css('opacity', '0.5');
        $('#liste_id').prop('disabled', false);
        $('#liste_id').next().css('opacity', '1');
    }
}

typeSimple.addEventListener('click', () => setTypeEnvoi('simple'));
typeMultiple.addEventListener('click', () => setTypeEnvoi('multiple'));

setTypeEnvoi(typeEnvoiInput.value);

// ============================================
// GESTION DU FICHIER
// ============================================
const fileUploadArea = document.getElementById('fileUploadArea');
const pieceJointeInput = document.getElementById('piece_jointe');
const fileInfo = document.getElementById('fileInfo');
const fileNameSpan = document.getElementById('fileName');
const removeFileBtn = document.getElementById('removeFileBtn');

function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }
    
    fileNameSpan.textContent = `${file.name} (${sizeMB} Mo)`;
    fileInfo.classList.remove('hidden');
    
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    pieceJointeInput.files = dataTransfer.files;
}

fileUploadArea.addEventListener('click', (e) => {
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target)) {
        pieceJointeInput.click();
    }
});

pieceJointeInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

fileUploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    fileUploadArea.classList.add('drag-over');
});

fileUploadArea.addEventListener('dragleave', (e) => {
    e.preventDefault();
    fileUploadArea.classList.remove('drag-over');
});

fileUploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    fileUploadArea.classList.remove('drag-over');
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
});

removeFileBtn.addEventListener('click', () => {
    resetFileUpload();
});

function resetFileUpload() {
    pieceJointeInput.value = '';
    fileInfo.classList.add('hidden');
}

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Validation du formulaire
document.getElementById('composerForm').addEventListener('submit', function(e) {
    const type_envoi = document.getElementById('type_envoi').value;
    const objet = document.getElementById('objet').value.trim();
    const corps = $('#corps').summernote('code');
    let hasRecipients = false;
    
    if (type_envoi === 'simple') {
        const contact = $('#contact_unique').val();
        hasRecipients = contact && contact !== '';
        if (!hasRecipients) {
            e.preventDefault();
            showToast('Veuillez sélectionner un destinataire', 'error');
            return false;
        }
    } else {
        const liste = $('#liste_id').val();
        hasRecipients = liste && liste !== '';
        if (!hasRecipients) {
            e.preventDefault();
            showToast('Veuillez sélectionner une liste', 'error');
            return false;
        }
    }
    
    if (!objet) {
        e.preventDefault();
        showToast('Veuillez saisir un objet', 'error');
        return false;
    }
    
    if (!corps || corps === '<p><br></p>' || corps === '<p>\u200b</p>') {
        e.preventDefault();
        showToast('Veuillez saisir le corps du message', 'error');
        return false;
    }
    
    // Mettre à jour le textarea avec le contenu Summernote
    $('#corps').val(corps);
});
</script>

</body>
</html>