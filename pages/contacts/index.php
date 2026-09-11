<?php
require_once 'includes/functions.php';
require_once 'includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ob_clean();
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;

$idCompte = $_SESSION['user_id'];
$contacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'date_inscription DESC');

// ============================================
// RÉCUPÉRATION DES TYPES DE MESSAGES
// ============================================
$typeMessages = $db->select('type_message');
$typesParId = [];
foreach ($typeMessages as $type) {
    $typesParId[$type['id_type_message']] = $type['libelle_type'];
}

// ============================================
// RÉCUPÉRATION DE LA BLACKLIST AVEC TYPES
// ============================================
$blacklistItems = $db->select('blacklist', [], '*');
$blacklistedIds = [];
$blacklistDetails = [];
$blocagesParContact = [];

foreach ($blacklistItems as $bl) {
    $blacklistedIds[] = $bl['id_contact'];
    
    if (!isset($blocagesParContact[$bl['id_contact']])) {
        $blocagesParContact[$bl['id_contact']] = [];
    }
    
    $libelle = $typesParId[$bl['id_type_message']] ?? 'Inconnu';
    $blocagesParContact[$bl['id_contact']][] = [
        'id_type_message' => $bl['id_type_message'],
        'libelle' => $libelle,
        'motif' => $bl['motif'] ?? '',
        'date_ajout' => $bl['date_ajout'] ?? ''
    ];
    
    if (!isset($blacklistDetails[$bl['id_contact']])) {
        $blacklistDetails[$bl['id_contact']] = [];
    }
    $blacklistDetails[$bl['id_contact']][] = $bl;
}

$contactsCustomValues = [];
$contactsEnfants = [];
foreach ($contacts as $contact) {
    $contactsCustomValues[$contact['id_contact']] = getContactCustomValues($contact['id_contact']);
    $contactsEnfants[$contact['id_contact']] = $db->select('enfants', ['contact_id' => $contact['id_contact']], '*', 'date_anniversaire ASC');
}

$totalContacts = count($contacts);

// ============================================
// CRÉATION D'UN CHAMP PERSONNALISÉ (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_custom_field']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $fieldName = trim($_POST['field_name'] ?? '');
    $fieldLabel = trim($_POST['field_label'] ?? '');
    $fieldType = $_POST['field_type'] ?? 'text';
    $fieldOptions = $_POST['field_options'] ?? null;
    $idContact = $_POST['id_contact'] ?? null;
    
    if (empty($fieldName)) { echo json_encode(['success' => false, 'error' => 'Le nom du champ est requis']); exit; }
    if (empty($fieldLabel)) { echo json_encode(['success' => false, 'error' => 'Le libellé du champ est requis']); exit; }
    if (empty($idContact)) { echo json_encode(['success' => false, 'error' => 'ID contact manquant']); exit; }
    
    $existingFields = $db->select('custom_fields', ['id_contact' => $idContact]);
    if (count($existingFields) >= 10) {
        echo json_encode(['success' => false, 'error' => 'Nombre maximum de champs personnalisés atteint (10 max)']);
        exit;
    }
    
    $result = createCustomFieldForContact($idCompte, $idContact, $fieldName, $fieldLabel, $fieldType, $fieldOptions);
    echo json_encode($result);
    exit;
}

// ============================================
// AJOUT DE CONTACT (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_contact'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $dateNaissance = $_POST['date_naissance'] ?? null;
        $noClient = trim($_POST['no_client'] ?? '');
        $sexe = !empty($_POST['sexe']) ? $_POST['sexe'] : null;
        $civilite = !empty($_POST['civilite']) ? $_POST['civilite'] : null;
        $telPortable = trim($_POST['tel_portable'] ?? '');
        $commentaire = trim($_POST['commentaire'] ?? '');
        $commentairePrive = trim($_POST['commentaire_prive'] ?? '');
        $enseigne = trim($_POST['enseigne'] ?? '');
        $numeroSiret = trim($_POST['numero_siret'] ?? '');
        $mari = trim($_POST['mari'] ?? '');
        $anniversaireMari = $_POST['anniversaire_mari'] ?? null;
        $femme = trim($_POST['femme'] ?? '');
        $anniversaireFemme = $_POST['anniversaire_femme'] ?? null;
        $pointsFidelite = intval($_POST['points_fidelite'] ?? 0);
        $cumulCadeau = floatval($_POST['cumul_cadeau'] ?? 0);
        $cumulAchats = floatval($_POST['cumul_achats'] ?? 0);
        $cumulAchatAvantCadeau = floatval($_POST['cumul_achat_avant_cadeau'] ?? 0);
        $quantiteArticle = intval($_POST['quantite_article'] ?? 0);
        $nombreTicket = intval($_POST['nombre_ticket'] ?? 0);
        $identifiantEcommerce = trim($_POST['identifiant_ecommerce'] ?? '');
        $valeurCoupon = trim($_POST['valeur_coupon'] ?? '');
        $dateFinValiditeCoupon = $_POST['date_fin_validite_coupon'] ?? null;
        
        $errors = [];
        if (empty($prenom)) $errors[] = 'Le prénom est requis';
        if (empty($nom)) $errors[] = 'Le nom est requis';
        if (empty($email)) $errors[] = "L'email est requis";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email n'est pas valide";
        
        if (!empty($dateNaissance) && !verifierAge($dateNaissance, 18)) {
            $errors[] = "Le contact doit avoir au moins 18 ans et la date ne peut pas être dans le futur";
        }
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact)) $errors[] = "Cet email est déjà utilisé par un autre contact";
        }
        
        if (!empty($noClient)) {
            $existingNoClient = $db->select('contact', ['id_compte' => $idCompte, 'no_client' => $noClient]);
            if (!empty($existingNoClient)) $errors[] = "Ce numéro client est déjà utilisé";
        }
        
        if (!empty($errors)) { echo json_encode(['success' => false, 'error' => implode(', ', $errors)]); exit; }
        
        $telephoneFormatted = !empty($telephone) ? (substr($telephone, 0, 3) === '261' ? $telephone : formatPhoneNumber($telephone)) : null;
        $telPortableFormatted = !empty($telPortable) ? (substr($telPortable, 0, 3) === '261' ? $telPortable : formatPhoneNumber($telPortable)) : null;
        
        $data = [
            'id_compte' => $idCompte, 'prenom' => $prenom, 'nom' => $nom, 'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France',
            'no_client' => !empty($noClient) ? $noClient : null,
            'civilite' => $civilite, 'sexe' => $sexe,
            'tel_portable' => $telPortableFormatted,
            'commentaire' => !empty($commentaire) ? $commentaire : null,
            'commentaire_prive' => !empty($commentairePrive) ? $commentairePrive : null,
            'enseigne' => !empty($enseigne) ? $enseigne : null,
            'numero_siret' => !empty($numeroSiret) ? $numeroSiret : null,
            'mari' => !empty($mari) ? $mari : null,
            'anniversaire_mari' => !empty($anniversaireMari) ? $anniversaireMari : null,
            'femme' => !empty($femme) ? $femme : null,
            'anniversaire_femme' => !empty($anniversaireFemme) ? $anniversaireFemme : null,
            'points_fidelite' => $pointsFidelite, 'cumul_cadeau' => $cumulCadeau, 'cumul_achats' => $cumulAchats,
            'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau, 'quantite_article' => $quantiteArticle,
            'nombre_ticket' => $nombreTicket,
            'identifiant_ecommerce' => !empty($identifiantEcommerce) ? $identifiantEcommerce : null,
            'valeur_coupon' => !empty($valeurCoupon) ? $valeurCoupon : null,
            'date_fin_validite_coupon' => !empty($dateFinValiditeCoupon) ? $dateFinValiditeCoupon : null
        ];
        
        $contactId = $db->insertAndGetId('contact', $data);
        
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($contactId, $_POST['custom_fields']);
        }
        
        if (isset($_POST['temp_custom_fields']) && !empty($_POST['temp_custom_fields'])) {
            $tempFields = json_decode($_POST['temp_custom_fields'], true);
            if (is_array($tempFields)) {
                foreach ($tempFields as $field) {
                    $existingFields = $db->select('custom_fields', ['id_contact' => $contactId]);
                    if (count($existingFields) >= 10) continue;
                    createCustomFieldForContact($idCompte, $contactId, $field['field_name'], $field['field_label'], $field['field_type'], $field['field_options'] ?? null);
                    if (isset($field['field_value']) && !empty($field['field_value'])) {
                        $createdField = $db->select('custom_fields', ['id_contact' => $contactId, 'field_name' => $field['field_name']]);
                        if (!empty($createdField)) {
                            $db->insert('contact_custom_values', ['id_custom_field' => $createdField[0]['id_custom_field'], 'field_value' => $field['field_value']]);
                        }
                    }
                }
            }
        }
        
        if (isset($_POST['enfants']) && is_array($_POST['enfants'])) {
            foreach ($_POST['enfants'] as $enfant) {
                if (!empty($enfant['prenom']) && !empty($enfant['nom'])) {
                    $db->insert('enfants', [
                        'contact_id' => $contactId, 'nom' => trim($enfant['nom']), 'prenom' => trim($enfant['prenom']),
                        'sexe' => $enfant['sexe'] ?? null,
                        'date_anniversaire' => !empty($enfant['date_anniversaire']) ? $enfant['date_anniversaire'] : null
                    ]);
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Contact ajouté avec succès', 'id_contact' => $contactId]);
    } catch (Exception $e) {
        error_log("ERREUR AJOUT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// MODIFICATION (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_contact'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_POST['id_contact'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'ID contact manquant']); exit; }
        
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $dateNaissance = $_POST['date_naissance'] ?? null;
        $noClient = trim($_POST['no_client'] ?? '');
        $civilite = !empty($_POST['civilite']) ? $_POST['civilite'] : null;
        $sexe = !empty($_POST['sexe']) ? $_POST['sexe'] : null;
        $telPortable = trim($_POST['tel_portable'] ?? '');
        $commentaire = trim($_POST['commentaire'] ?? '');
        $commentairePrive = trim($_POST['commentaire_prive'] ?? '');
        $enseigne = trim($_POST['enseigne'] ?? '');
        $numeroSiret = trim($_POST['numero_siret'] ?? '');
        $mari = trim($_POST['mari'] ?? '');
        $anniversaireMari = $_POST['anniversaire_mari'] ?? null;
        $femme = trim($_POST['femme'] ?? '');
        $anniversaireFemme = $_POST['anniversaire_femme'] ?? null;
        $pointsFidelite = intval($_POST['points_fidelite'] ?? 0);
        $cumulCadeau = floatval($_POST['cumul_cadeau'] ?? 0);
        $cumulAchats = floatval($_POST['cumul_achats'] ?? 0);
        $cumulAchatAvantCadeau = floatval($_POST['cumul_achat_avant_cadeau'] ?? 0);
        $quantiteArticle = intval($_POST['quantite_article'] ?? 0);
        $nombreTicket = intval($_POST['nombre_ticket'] ?? 0);
        $identifiantEcommerce = trim($_POST['identifiant_ecommerce'] ?? '');
        $valeurCoupon = trim($_POST['valeur_coupon'] ?? '');
        $dateFinValiditeCoupon = $_POST['date_fin_validite_coupon'] ?? null;
        
        $errors = [];
        if (empty($prenom)) $errors[] = 'Le prénom est requis';
        if (empty($nom)) $errors[] = 'Le nom est requis';
        if (empty($email)) $errors[] = "L'email est requis";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email n'est pas valide";
        if (!empty($dateNaissance) && !verifierAge($dateNaissance, 18)) $errors[] = "Le contact doit avoir au moins 18 ans";
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact) && $existingContact[0]['id_contact'] != $id) $errors[] = "Cet email est déjà utilisé par un autre contact";
        }
        if (!empty($noClient)) {
            $existingNoClient = $db->select('contact', ['id_compte' => $idCompte, 'no_client' => $noClient]);
            if (!empty($existingNoClient) && $existingNoClient[0]['id_contact'] != $id) $errors[] = "Ce numéro client est déjà utilisé";
        }
        
        if (!empty($errors)) { echo json_encode(['success' => false, 'error' => implode(', ', $errors)]); exit; }
        
        $telephoneFormatted = !empty($telephone) ? (substr($telephone, 0, 3) === '261' ? $telephone : formatPhoneNumber($telephone)) : null;
        $telPortableFormatted = !empty($telPortable) ? (substr($telPortable, 0, 3) === '261' ? $telPortable : formatPhoneNumber($telPortable)) : null;
        
        $data = [
            'prenom' => $prenom, 'nom' => $nom, 'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France',
            'no_client' => !empty($noClient) ? $noClient : null,
            'civilite' => $civilite, 'sexe' => $sexe,
            'tel_portable' => $telPortableFormatted,
            'commentaire' => !empty($commentaire) ? $commentaire : null,
            'commentaire_prive' => !empty($commentairePrive) ? $commentairePrive : null,
            'enseigne' => !empty($enseigne) ? $enseigne : null,
            'numero_siret' => !empty($numeroSiret) ? $numeroSiret : null,
            'mari' => !empty($mari) ? $mari : null,
            'anniversaire_mari' => !empty($anniversaireMari) ? $anniversaireMari : null,
            'femme' => !empty($femme) ? $femme : null,
            'anniversaire_femme' => !empty($anniversaireFemme) ? $anniversaireFemme : null,
            'points_fidelite' => $pointsFidelite, 'cumul_cadeau' => $cumulCadeau, 'cumul_achats' => $cumulAchats,
            'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau, 'quantite_article' => $quantiteArticle,
            'nombre_ticket' => $nombreTicket,
            'identifiant_ecommerce' => !empty($identifiantEcommerce) ? $identifiantEcommerce : null,
            'valeur_coupon' => !empty($valeurCoupon) ? $valeurCoupon : null,
            'date_fin_validite_coupon' => !empty($dateFinValiditeCoupon) ? $dateFinValiditeCoupon : null
        ];
        
        $db->update('contact', $data, ['id_contact' => $id]);
        
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($id, $_POST['custom_fields']);
        }
        
        $enfantsExistants = $db->select('enfants', ['contact_id' => $id]);
        foreach ($enfantsExistants as $enfant) $db->delete('enfants', $enfant['id'], 'id');
        
        if (isset($_POST['enfants']) && is_array($_POST['enfants'])) {
            foreach ($_POST['enfants'] as $enfant) {
                if (!empty($enfant['prenom']) && !empty($enfant['nom'])) {
                    $db->insert('enfants', [
                        'contact_id' => $id, 'nom' => trim($enfant['nom']), 'prenom' => trim($enfant['prenom']),
                        'sexe' => $enfant['sexe'] ?? null,
                        'date_anniversaire' => !empty($enfant['date_anniversaire']) ? $enfant['date_anniversaire'] : null
                    ]);
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Contact modifié avec succès']);
    } catch (Exception $e) {
        error_log("ERREUR MODIFICATION: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET_CONTACT (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        if (empty($id)) { echo json_encode(['error' => 'ID de contact manquant']); exit; }
        
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        if (empty($contact)) { echo json_encode(['error' => 'Contact non trouvé']); exit; }
        
        $contact = $contact[0];
        $contact['custom_values'] = getContactCustomValues($id);
        $contact['enfants'] = $db->select('enfants', ['contact_id' => $id], '*', 'date_anniversaire ASC');
        $contact['is_blacklisted'] = in_array($id, $blacklistedIds);
        $contact['blocages'] = $blocagesParContact[$id] ?? [];
        
        echo json_encode($contact);
    } catch (Exception $e) {
        error_log("ERREUR GET_CONTACT: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET_CONTACT_FIELDS (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact_fields' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        if (empty($contact)) { echo json_encode(['error' => 'Contact non trouvé']); exit; }
        
        $fields = $db->select('custom_fields', ['id_contact' => $id]);
        $result = [];
        
        foreach ($fields as $field) {
            $value = $db->select('contact_custom_values', ['id_custom_field' => $field['id_custom_field']]);
            $result[] = [
                'id_custom_field' => $field['id_custom_field'],
                'field_name' => $field['field_name'],
                'field_label' => $field['field_label'],
                'field_type' => $field['field_type'],
                'field_options' => $field['field_options'],
                'is_required' => $field['is_required'],
                'value' => !empty($value) ? $value[0]['field_value'] : ''
            ];
        }
        
        echo json_encode(['fields' => $result]);
    } catch (Exception $e) {
        error_log("ERREUR GET_FIELDS: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $file = $_FILES['fichier'];
        if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'upload du fichier']); exit; }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xls', 'xlsx'])) { echo json_encode(['success' => false, 'error' => 'Format non supporté']); exit; }
        
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) { echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']); exit; }
        
        $firstLine = fgets($handle);
        rewind($handle);
        $separators = [';', ',', "\t", '|'];
        $separator = ',';
        $maxCount = 0;
        foreach ($separators as $testSep) {
            $count = substr_count($firstLine, $testSep);
            if ($count > $maxCount) { $maxCount = $count; $separator = $testSep; }
        }
        
        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) { echo json_encode(['success' => false, 'error' => 'Format CSV invalide']); exit; }
        
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        
        $mapping = [
            'prenom' => array_search('prenom', $headers), 'nom' => array_search('nom', $headers), 'email' => array_search('email', $headers),
            'telephone' => array_search('telephone', $headers), 'ville' => array_search('ville', $headers), 'adresse' => array_search('adresse', $headers),
            'code_postal' => array_search('code_postal', $headers), 'pays' => array_search('pays', $headers),
            'date_naissance' => array_search('date_naissance', $headers), 'no_client' => array_search('no_client', $headers),
            'civilite' => array_search('civilite', $headers), 'sexe' => array_search('sexe', $headers),
            'tel_portable' => array_search('tel_portable', $headers), 'commentaire' => array_search('commentaire', $headers),
            'commentaire_prive' => array_search('commentaire_prive', $headers), 'enseigne' => array_search('enseigne', $headers),
            'numero_siret' => array_search('numero_siret', $headers), 'mari' => array_search('mari', $headers),
            'anniversaire_mari' => array_search('anniversaire_mari', $headers), 'femme' => array_search('femme', $headers),
            'anniversaire_femme' => array_search('anniversaire_femme', $headers), 'points_fidelite' => array_search('points_fidelite', $headers),
            'cumul_cadeau' => array_search('cumul_cadeau', $headers), 'cumul_achats' => array_search('cumul_achats', $headers),
            'cumul_achat_avant_cadeau' => array_search('cumul_achat_avant_cadeau', $headers), 'quantite_article' => array_search('quantite_article', $headers),
            'nombre_ticket' => array_search('nombre_ticket', $headers), 'identifiant_ecommerce' => array_search('identifiant_ecommerce', $headers),
            'valeur_coupon' => array_search('valeur_coupon', $headers), 'date_fin_validite_coupon' => array_search('date_fin_validite_coupon', $headers)
        ];
        
        if ($mapping['prenom'] === false || $mapping['nom'] === false || $mapping['email'] === false) {
            echo json_encode(['success' => false, 'error' => 'Colonnes requises manquantes: prenom, nom, email']); exit;
        }
        
        $importedCount = 0; $existingCount = 0; $errorCount = 0; $errors = [];
        
        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            $row = array_map('trim', $row);
            $prenom = $mapping['prenom'] !== false ? trim($row[$mapping['prenom']] ?? '') : '';
            $nom = $mapping['nom'] !== false ? trim($row[$mapping['nom']] ?? '') : '';
            $email = $mapping['email'] !== false ? trim($row[$mapping['email']] ?? '') : '';
            $telephone = $mapping['telephone'] !== false ? trim($row[$mapping['telephone']] ?? '') : '';
            $dateNaissance = $mapping['date_naissance'] !== false ? trim($row[$mapping['date_naissance']] ?? '') : '';
            $noClient = $mapping['no_client'] !== false ? trim($row[$mapping['no_client']] ?? '') : '';
            $civilite = $mapping['civilite'] !== false ? trim($row[$mapping['civilite']] ?? '') : '';
            $sexe = $mapping['sexe'] !== false ? trim($row[$mapping['sexe']] ?? '') : '';
            $telPortable = $mapping['tel_portable'] !== false ? trim($row[$mapping['tel_portable']] ?? '') : '';
            $commentaire = $mapping['commentaire'] !== false ? trim($row[$mapping['commentaire']] ?? '') : '';
            $commentairePrive = $mapping['commentaire_prive'] !== false ? trim($row[$mapping['commentaire_prive']] ?? '') : '';
            $enseigne = $mapping['enseigne'] !== false ? trim($row[$mapping['enseigne']] ?? '') : '';
            $numeroSiret = $mapping['numero_siret'] !== false ? trim($row[$mapping['numero_siret']] ?? '') : '';
            $mari = $mapping['mari'] !== false ? trim($row[$mapping['mari']] ?? '') : '';
            $anniversaireMari = $mapping['anniversaire_mari'] !== false ? trim($row[$mapping['anniversaire_mari']] ?? '') : '';
            $femme = $mapping['femme'] !== false ? trim($row[$mapping['femme']] ?? '') : '';
            $anniversaireFemme = $mapping['anniversaire_femme'] !== false ? trim($row[$mapping['anniversaire_femme']] ?? '') : '';
            $pointsFidelite = $mapping['points_fidelite'] !== false ? intval(trim($row[$mapping['points_fidelite']] ?? 0)) : 0;
            $cumulCadeau = $mapping['cumul_cadeau'] !== false ? floatval(trim($row[$mapping['cumul_cadeau']] ?? 0)) : 0;
            $cumulAchats = $mapping['cumul_achats'] !== false ? floatval(trim($row[$mapping['cumul_achats']] ?? 0)) : 0;
            $cumulAchatAvantCadeau = $mapping['cumul_achat_avant_cadeau'] !== false ? floatval(trim($row[$mapping['cumul_achat_avant_cadeau']] ?? 0)) : 0;
            $quantiteArticle = $mapping['quantite_article'] !== false ? intval(trim($row[$mapping['quantite_article']] ?? 0)) : 0;
            $nombreTicket = $mapping['nombre_ticket'] !== false ? intval(trim($row[$mapping['nombre_ticket']] ?? 0)) : 0;
            $identifiantEcommerce = $mapping['identifiant_ecommerce'] !== false ? trim($row[$mapping['identifiant_ecommerce']] ?? '') : '';
            $valeurCoupon = $mapping['valeur_coupon'] !== false ? trim($row[$mapping['valeur_coupon']] ?? '') : '';
            $dateFinValiditeCoupon = $mapping['date_fin_validite_coupon'] !== false ? trim($row[$mapping['date_fin_validite_coupon']] ?? '') : '';
            
            if (empty($prenom) || empty($nom) || empty($email)) { $errorCount++; continue; }
            
            if (!empty($dateNaissance)) {
                $dateFormats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y'];
                $dateConverted = null;
                foreach ($dateFormats as $format) {
                    $dateObj = DateTime::createFromFormat($format, $dateNaissance);
                    if ($dateObj) { $dateConverted = $dateObj->format('Y-m-d'); break; }
                }
                if ($dateConverted) { $dateNaissance = $dateConverted; }
                else {
                    $timestamp = strtotime($dateNaissance);
                    $dateNaissance = $timestamp !== false ? date('Y-m-d', $timestamp) : null;
                }
            }
            if (!empty($anniversaireMari)) { $ts = strtotime($anniversaireMari); $anniversaireMari = $ts !== false ? date('Y-m-d', $ts) : null; }
            if (!empty($anniversaireFemme)) { $ts = strtotime($anniversaireFemme); $anniversaireFemme = $ts !== false ? date('Y-m-d', $ts) : null; }
            if (!empty($dateFinValiditeCoupon)) { $ts = strtotime($dateFinValiditeCoupon); $dateFinValiditeCoupon = $ts !== false ? date('Y-m-d', $ts) : null; }
            if (!empty($dateNaissance) && !verifierAge($dateNaissance, 18)) { $errorCount++; continue; }
            
            $existing = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existing)) { $existingCount++; continue; }
            
            $telephoneFormatted = !empty($telephone) ? (substr($telephone, 0, 3) === '261' ? $telephone : formatPhoneNumber($telephone)) : null;
            $telPortableFormatted = !empty($telPortable) ? (substr($telPortable, 0, 3) === '261' ? $telPortable : formatPhoneNumber($telPortable)) : null;
            
            $data = [
                'id_compte' => $idCompte, 'prenom' => $prenom, 'nom' => $nom, 'email' => $email,
                'telephone' => $telephoneFormatted,
                'ville' => $mapping['ville'] !== false ? trim($row[$mapping['ville']] ?? '') : null,
                'adresse' => $mapping['adresse'] !== false ? trim($row[$mapping['adresse']] ?? '') : null,
                'code_postal' => $mapping['code_postal'] !== false ? trim($row[$mapping['code_postal']] ?? '') : null,
                'pays' => $mapping['pays'] !== false ? trim($row[$mapping['pays']] ?? 'France') : 'France',
                'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
                'no_client' => !empty($noClient) ? $noClient : null,
                'civilite' => !empty($civilite) ? $civilite : null,
                'sexe' => !empty($sexe) ? $sexe : null,
                'tel_portable' => $telPortableFormatted,
                'commentaire' => !empty($commentaire) ? $commentaire : null,
                'commentaire_prive' => !empty($commentairePrive) ? $commentairePrive : null,
                'enseigne' => !empty($enseigne) ? $enseigne : null,
                'numero_siret' => !empty($numeroSiret) ? $numeroSiret : null,
                'mari' => !empty($mari) ? $mari : null,
                'anniversaire_mari' => !empty($anniversaireMari) ? $anniversaireMari : null,
                'femme' => !empty($femme) ? $femme : null,
                'anniversaire_femme' => !empty($anniversaireFemme) ? $anniversaireFemme : null,
                'points_fidelite' => $pointsFidelite, 'cumul_cadeau' => $cumulCadeau, 'cumul_achats' => $cumulAchats,
                'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau, 'quantite_article' => $quantiteArticle,
                'nombre_ticket' => $nombreTicket,
                'identifiant_ecommerce' => !empty($identifiantEcommerce) ? $identifiantEcommerce : null,
                'valeur_coupon' => !empty($valeurCoupon) ? $valeurCoupon : null,
                'date_fin_validite_coupon' => !empty($dateFinValiditeCoupon) ? $dateFinValiditeCoupon : null
            ];
            
            try { $db->insert('contact', $data); $importedCount++; }
            catch (Exception $e) { $errorCount++; $errors[] = $e->getMessage(); }
        }
        fclose($handle);
        
        if ($importedCount > 0) {
            $message = "$importedCount contact(s) importé(s) avec succès.";
            if ($existingCount > 0) $message .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $message .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) $message .= " Détails: " . implode('; ', array_slice($errors, 0, 3));
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            $errorMsg = "Aucun contact importé.";
            if ($existingCount > 0) $errorMsg .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $errorMsg .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) $errorMsg .= " Erreurs: " . implode('; ', array_slice($errors, 0, 3));
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
    } catch (Exception $e) {
        error_log("ERREUR IMPORT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes contacts - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 0;
            color: #0f172a;
        }

        /* ============================================
           TOASTS
        ============================================ */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 14px 22px;
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.18);
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .toast-notification.success .toast-content { background: linear-gradient(135deg, #10b981, #059669); }
        .toast-notification.error .toast-content { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .toast-notification.warning .toast-content { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .toast-notification.info .toast-content { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        
        .contact-row.hidden-row { display: none; }

        /* ============================================
           MODALS - OVERLAY
        ============================================ */
        #addContactModal,
        #editContactModal,
        #importModal,
        #addCustomFieldModal,
        #unblacklistModal,
        #deleteModal {
            position: fixed !important;
            inset: 0 !important;
            background: rgba(15, 23, 42, 0.6) !important;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 9999 !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
            padding: 16px !important;
            margin: 0 !important;
        }

        /* ============================================
           MODAL CONTAINER COMPACT
        ============================================ */
        .modal-add-contact,
        .modal-edit-contact,
        .modal-import-csv,
        .modal-custom-field {
            position: relative !important;
            width: 95% !important;
            max-width: 1150px !important;
            height: 88vh !important;
            max-height: 88vh !important;
            margin: 0 auto !important;
            border-radius: 16px !important;
            background: #ffffff !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            z-index: 10000 !important;
            animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.35) !important;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(20px) scale(0.97); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-add-contact .p-6,
        .modal-edit-contact .p-6,
        .modal-import-csv .p-6,
        .modal-custom-field .p-6 {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
            min-height: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            height: 100% !important;
            width: 100% !important;
        }

        /* EN-TÊTE COMPACT */
        .modal-header-sticky {
            flex-shrink: 0 !important;
            background: #ffffff !important;
            padding: 14px 22px !important;
            margin: 0 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            position: relative !important;
            z-index: 20 !important;
        }

        /* CONTENU SCROLLABLE COMPACT */
        .modal-scroll-content {
            flex: 1 !important;
            overflow-y: auto !important;
            padding: 18px 22px 16px 22px !important;
            min-height: 0 !important;
            margin: 0 !important;
            background: #f8fafc !important;
            scroll-behavior: smooth;
        }

        .modal-scroll-content::-webkit-scrollbar { width: 8px; }
        .modal-scroll-content::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .modal-scroll-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .modal-scroll-content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* PIED COMPACT */
        .modal-footer-sticky {
            flex-shrink: 0 !important;
            background: #ffffff !important;
            padding: 12px 22px !important;
            margin: 0 !important;
            border-top: 1px solid #e2e8f0 !important;
            position: relative !important;
            z-index: 20 !important;
        }

        .modal-add-contact form,
        .modal-edit-contact form,
        .modal-import-csv form,
        .modal-custom-field form {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
            min-height: 0 !important;
            height: 100% !important;
        }

        /* INPUTS COMPACTS */
        .modal-scroll-content input,
        .modal-scroll-content select,
        .modal-scroll-content textarea {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
            font-size: 13px !important;
            color: #0f172a !important;
            transition: all 0.15s ease !important;
            width: 100% !important;
            font-family: inherit !important;
        }

        .modal-scroll-content input:hover,
        .modal-scroll-content select:hover,
        .modal-scroll-content textarea:hover {
            border-color: #cbd5e1 !important;
        }

        .modal-scroll-content input:focus,
        .modal-scroll-content select:focus,
        .modal-scroll-content textarea:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
            outline: none !important;
        }

        .modal-scroll-content label {
            font-weight: 600 !important;
            font-size: 11.5px !important;
            color: #475569 !important;
            margin-bottom: 4px !important;
            display: block !important;
        }

        /* SECTIONS COMPACTES */
        .section-title {
            font-size: 11px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin: 18px 0 10px 0 !important;
            padding: 0 0 8px 0 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .section-title:first-child { margin-top: 0 !important; }

        .section-title i {
            font-size: 11px !important;
            width: 22px !important;
            height: 22px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #eff6ff !important;
            border-radius: 6px !important;
            color: #2563eb !important;
        }

        /* ENFANTS COMPACTS */
        .enfant-item {
            background: #ffffff !important;
            padding: 12px !important;
            border-radius: 10px !important;
            border: 1px solid #e2e8f0 !important;
            position: relative !important;
            transition: all 0.15s ease !important;
            margin-bottom: 10px !important;
        }

        .enfant-item:hover {
            border-color: #cbd5e1 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        }

        .enfant-item .remove-enfant {
            position: absolute !important;
            top: 8px !important;
            right: 8px !important;
            background: #fee2e2 !important;
            color: #dc2626 !important;
            border: none !important;
            border-radius: 50% !important;
            width: 22px !important;
            height: 22px !important;
            cursor: pointer !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.15s ease !important;
            line-height: 1;
        }

        .enfant-item .remove-enfant:hover {
            background: #ef4444 !important;
            color: white !important;
        }

        /* BOUTONS COMPACTS */
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
            color: white !important;
            padding: 9px 20px !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2) !important;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3) !important;
        }

        .btn-secondary {
            background: #ffffff !important;
            color: #475569 !important;
            padding: 9px 20px !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            transition: all 0.2s ease !important;
            border: 1px solid #e2e8f0 !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .btn-secondary:hover {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: white !important;
            padding: 9px 20px !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2) !important;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
            transform: translateY(-1px) !important;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
            color: white !important;
            padding: 9px 20px !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
        }

        /* ============================================
           BADGES (PAGE PRINCIPALE - TAILLE ORIGINALE)
        ============================================ */
        .custom-field-badge {
            display: inline-block;
            background-color: #f3f4f6;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }
        .custom-field-badge strong { font-weight: 600; color: #4b5563; }

        .child-badge {
            display: inline-block;
            background-color: #dbeafe;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }
        .child-badge strong { font-weight: 600; color: #1e40af; }

        /* ============================================
           BLACKLIST HEADER (MODAL - COMPACT)
        ============================================ */
        .blacklist-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 700;
            margin: 3px;
            letter-spacing: 0.02em;
            border: 1.5px solid transparent;
            text-transform: uppercase;
        }
        .blacklist-header-badge i { font-size: 12px; }
        .blacklist-header-badge.sms { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
        .blacklist-header-badge.whatsapp { background: #dcfce7; color: #166534; border-color: #86efac; }
        .blacklist-header-badge.email { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .blacklist-header-badge.default { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

        .blacklist-header-container {
            margin-top: 8px;
            padding: 10px 14px;
            background: #fef2f2;
            border-radius: 10px;
            border-left: 4px solid #ef4444;
        }
        .blacklist-header-container .title {
            font-size: 11.5px;
            font-weight: 700;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .blacklist-header-container .title i { font-size: 13px; color: #dc2626; }
        .blacklist-header-container .types { display: flex; flex-wrap: wrap; gap: 4px; }

        .blacklist-status-ok {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 700;
            background: #dcfce7;
            color: #166534;
            border: 1.5px solid #86efac;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .blacklist-status-ok i { font-size: 12px; color: #16a34a; }

        .blacklist-loading {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 11.5px;
            color: #64748b;
            font-style: italic;
            margin-top: 6px;
        }
        .blacklist-loading i { font-size: 12px; color: #3b82f6; }

        /* STATS CARDS (PAGE PRINCIPALE - TAILLE ORIGINALE) */
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        .stats-number {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
        }
        .stats-label {
            font-size: 13px;
            color: #6b7280;
        }

        /* EN-TÊTE MINI DES MODALS */
        .modal-header-mini {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .modal-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        .modal-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .modal-icon-box i { font-size: 17px; }
        .modal-icon-box.blue { background: #eff6ff; color: #2563eb; }
        .modal-icon-box.yellow { background: #fef3c7; color: #d97706; }
        .modal-icon-box.green { background: #dcfce7; color: #059669; }
        .modal-title-wrap { min-width: 0; flex: 1; }
        .modal-title-wrap h3 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            line-height: 1.2;
        }
        .modal-title-wrap p {
            font-size: 11.5px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            color: #64748b;
            font-size: 14px;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .modal-close-btn:hover { background: #e2e8f0; color: #0f172a; }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            #addContactModal, #editContactModal, #importModal, #addCustomFieldModal, #unblacklistModal, #deleteModal {
                padding: 8px !important;
            }
            .modal-add-contact, .modal-edit-contact, .modal-import-csv, .modal-custom-field {
                width: 100% !important;
                height: 96vh !important;
                max-height: 96vh !important;
                border-radius: 14px !important;
            }
            .modal-header-sticky { padding: 12px 16px !important; }
            .modal-scroll-content { padding: 16px !important; }
            .modal-footer-sticky { padding: 12px 16px !important; }
        }
    </style>
</head>
<body>

<div style="padding: 20px 32px; max-width: 100%;">
    <!-- EN-TÊTE PAGE (TAILLE ORIGINALE) -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; color: #0f172a; margin: 0;">Mes contacts</h1>
            <p style="color: #64748b; margin: 4px 0 0 0; font-size: 14px;">Gérez votre base de contacts</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" onclick="openAddContactModal()" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                <i class="fas fa-plus"></i> Ajouter un contact
            </button>
            <button type="button" onclick="openImportModal()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);">
                <i class="fas fa-upload"></i> Importer CSV
            </button>
        </div>
    </div>

    <!-- STATS (TAILLE ORIGINALE) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div class="stats-card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div class="stats-number" id="totalCount"><?= $totalContacts ?></div>
                    <div class="stats-label">Total des contacts</div>
                </div>
                <div style="width: 52px; height: 52px; background: linear-gradient(135deg, #eff6ff, #dbeafe); border-radius: 14px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-users" style="font-size: 24px; color: #2563eb;"></i>
                </div>
            </div>
        </div>

        <div class="stats-card" style="grid-column: span 2;">
            <div style="position: relative;">
                <i class="fas fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px;"></i>
                <input type="text" id="searchInput" placeholder="Rechercher par nom, email, téléphone ou ville..." 
                    style="width: 100%; padding: 12px 14px 12px 42px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: all 0.2s ease; outline: none;"
                    onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 4px rgba(59,130,246,0.12)'"
                    onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button class="filter-btn active" data-filter="all" style="padding: 6px 16px; font-size: 12px; border-radius: 20px; border: none; cursor: pointer; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; font-weight: 600; transition: all 0.2s ease;">Tous</button>
                    <button class="filter-btn" data-filter="email" style="padding: 6px 16px; font-size: 12px; border-radius: 20px; border: 1px solid #e2e8f0; cursor: pointer; background: white; color: #475569; font-weight: 600; transition: all 0.2s ease;">Avec email</button>
                    <button class="filter-btn" data-filter="phone" style="padding: 6px 16px; font-size: 12px; border-radius: 20px; border: 1px solid #e2e8f0; cursor: pointer; background: white; color: #475569; font-weight: 600; transition: all 0.2s ease;">Avec téléphone</button>
                    <button class="filter-btn" data-filter="blacklisted" style="padding: 6px 16px; font-size: 12px; border-radius: 20px; border: 1px solid #fecaca; cursor: pointer; background: #fef2f2; color: #b91c1c; font-weight: 600; transition: all 0.2s ease;">
                        <i class="fas fa-ban"></i> Blacklistés
                    </button>
                </div>
                <span id="filteredCount" style="font-size: 12px; color: #64748b;"></span>
            </div>
        </div>
    </div>

    <!-- TABLEAU (TAILLE ORIGINALE) -->
    <div style="background: white; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Client</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Contact</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Infos</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Enfants</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Fidélité</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Inscription</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Statut</th>
                        <th style="padding: 14px 20px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody">
                    <?php if (empty($contacts)): ?>
                        <tr id="noContactsRow">
                            <td colspan="8" style="padding: 48px 20px; text-align: center; color: #64748b; font-size: 14px;">
                                <i class="fas fa-address-book" style="font-size: 48px; margin-bottom: 12px; display: block; color: #cbd5e1;"></i>
                                Aucun contact pour le moment.
                                <button type="button" onclick="openAddContactModal()" style="color: #2563eb; display: block; margin: 12px auto 0 auto; background: none; border: none; cursor: pointer; font-weight: 600; font-size: 14px;">Ajouter votre premier contact →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): 
                            $isBlacklisted = in_array($contact['id_contact'], $blacklistedIds);
                            $customVals = $contactsCustomValues[$contact['id_contact']] ?? [];
                            $enfants = $contactsEnfants[$contact['id_contact']] ?? [];
                        ?>
                            <tr class="contact-row" 
                                data-name="<?= strtolower(htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($contact['email'] ?? '')) ?>"
                                data-phone="<?= strtolower(htmlspecialchars($contact['telephone'] ?? '')) ?>"
                                data-city="<?= strtolower(htmlspecialchars($contact['ville'] ?? '')) ?>"
                                data-has-email="<?= !empty($contact['email']) ? 'true' : 'false' ?>"
                                data-has-phone="<?= !empty($contact['telephone']) ? 'true' : 'false' ?>"
                                data-blacklisted="<?= $isBlacklisted ? 'true' : 'false' ?>"
                                data-contact-id="<?= $contact['id_contact'] ?>"
                                style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s ease; <?= $isBlacklisted ? 'background: #fef2f2;' : '' ?>"
                                onmouseover="this.style.background='<?= $isBlacklisted ? '#fee2e2' : '#f8fafc' ?>'"
                                onmouseout="this.style.background='<?= $isBlacklisted ? '#fef2f2' : 'transparent' ?>'">
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <div style="font-weight: 600; color: #0f172a; font-size: 14px;"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></div>
                                    <?php if (!empty($contact['no_client'])): ?>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">N°: <?= htmlspecialchars($contact['no_client']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['civilite']) || !empty($contact['sexe'])): ?>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                            <?= htmlspecialchars($contact['civilite'] ?? '') ?> 
                                            <?= !empty($contact['sexe']) ? ($contact['sexe'] === 'M' ? '♂' : '♀') : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <div style="font-size: 13px; color: #334155;"><?= htmlspecialchars($contact['email'] ?? '-') ?></div>
                                    <div style="font-size: 13px; color: #334155;"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></div>
                                    <?php if (!empty($contact['tel_portable'])): ?>
                                        <div style="font-size: 12px; color: #64748b;">P: <?= htmlspecialchars($contact['tel_portable']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <?php if (!empty($contact['enseigne'])): ?>
                                        <div style="font-size: 12px;"><strong>Enseigne:</strong> <?= htmlspecialchars($contact['enseigne']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['ville'])): ?>
                                        <div style="font-size: 12px; color: #64748b;"><?= htmlspecialchars($contact['ville']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($customVals)): ?>
                                        <?php foreach (array_slice($customVals, 0, 2) as $field): ?>
                                            <span class="custom-field-badge">
                                                <strong><?= htmlspecialchars($field['label']) ?>:</strong> <?= htmlspecialchars(substr($field['value'], 0, 20)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($customVals) > 2): ?>
                                            <span style="font-size: 11px; color: #94a3b8;">+<?= count($customVals) - 2 ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['mari']) || !empty($contact['femme'])): ?>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                            <?php if (!empty($contact['mari'])): ?>👨 <?= htmlspecialchars($contact['mari']) ?><?php endif; ?>
                                            <?php if (!empty($contact['femme'])): ?>👩 <?= htmlspecialchars($contact['femme']) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <?php if (!empty($enfants)): ?>
                                        <?php foreach (array_slice($enfants, 0, 3) as $enfant): ?>
                                            <span class="child-badge">
                                                <?= htmlspecialchars($enfant['prenom']) ?> 
                                                (<?= $enfant['sexe'] === 'M' ? '♂' : '♀' ?>)
                                                <?php if (!empty($enfant['date_anniversaire'])): ?>
                                                    <?= date('d/m/Y', strtotime($enfant['date_anniversaire'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($enfants) > 3): ?>
                                            <span style="font-size: 11px; color: #94a3b8;">+<?= count($enfants) - 3 ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1; font-size: 13px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <?php if ($contact['points_fidelite'] > 0 || $contact['cumul_achats'] > 0): ?>
                                        <div style="font-size: 12px;"><span style="font-weight: 600;">Points:</span> <?= $contact['points_fidelite'] ?></div>
                                        <div style="font-size: 12px;"><span style="font-weight: 600;">Achats:</span> <?= number_format($contact['cumul_achats'], 2) ?> €</div>
                                        <?php if (!empty($contact['valeur_coupon'])): ?>
                                            <div style="font-size: 12px; color: #059669;">🎫 <?= htmlspecialchars($contact['valeur_coupon']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1; font-size: 13px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top; font-size: 13px; color: #64748b;"><?= date('d/m/Y', strtotime($contact['date_inscription'])) ?></td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <?php if ($isBlacklisted): ?>
                                        <button onclick="openUnblacklistModal('<?= $contact['id_contact'] ?>')" style="padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-ban"></i> Blacklisté
                                        </button>
                                    <?php else: ?>
                                        <span style="padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 20px; vertical-align: top;">
                                    <button type="button" onclick="openEditContactModal('<?= $contact['id_contact'] ?>')" title="Modifier" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; width: 36px; height: 36px; border-radius: 9px; cursor: pointer; margin-right: 6px; transition: all 0.2s ease;">
                                        <i class="fas fa-edit" style="font-size: 14px;"></i>
                                    </button>
                                    <button type="button" onclick="showDeleteModal('<?= $contact['id_contact'] ?>')" title="Supprimer" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; width: 36px; height: 36px; border-radius: 9px; cursor: pointer; transition: all 0.2s ease;">
                                        <i class="fas fa-trash" style="font-size: 14px;"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL AJOUT CONTACT -->
<!-- ============================================ -->
<div id="addContactModal">
    <div class="modal-add-contact">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="modal-header-mini">
                    <div class="modal-header-left">
                        <div class="modal-icon-box blue"><i class="fas fa-user-plus"></i></div>
                        <div class="modal-title-wrap">
                            <h3>Ajouter un contact</h3>
                            <p>Remplissez les informations du nouveau contact</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeAddContactModal()" class="modal-close-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <form id="addContactForm" method="POST">
                <input type="hidden" name="action_add_contact" value="1">
                <input type="hidden" id="tempCustomFields" name="temp_custom_fields" value="">
                <div class="modal-scroll-content">
                    <div class="section-title"><i class="fas fa-id-card"></i> Identité</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>Civilité</label><select name="civilite" id="add_civilite"><option value="">--</option><option value="M.">M.</option><option value="Mme">Mme</option><option value="Mlle">Mlle</option><option value="Dr">Dr</option><option value="Pr">Pr</option></select></div>
                        <div><label>Sexe</label><select name="sexe" id="add_sexe"><option value="">--</option><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
                        <div><label>Prénom *</label><input type="text" name="prenom" id="add_prenom" required></div>
                        <div><label>Nom *</label><input type="text" name="nom" id="add_nom" required></div>
                    </div>

                    <div class="section-title"><i class="fas fa-phone"></i> Coordonnées</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <div><label>Email *</label><input type="email" name="email" id="add_email" required></div>
                        <div><label>Téléphone fixe *</label><input type="tel" name="telephone" id="add_telephone" placeholder="ex: 0612345678"></div>
                        <div><label>Téléphone portable</label><input type="tel" name="tel_portable" id="add_tel_portable" placeholder="ex: 0612345678"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-map-marker-alt"></i> Adresse</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div style="grid-column: span 2;"><label>Adresse</label><input type="text" name="adresse" id="add_adresse"></div>
                        <div><label>Code postal</label><input type="text" name="code_postal" id="add_code_postal"></div>
                        <div><label>Ville</label><input type="text" name="ville" id="add_ville"></div>
                        <div><label>Pays</label><input type="text" name="pays" id="add_pays" value="France"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-briefcase"></i> Informations client</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>N° client</label><input type="text" name="no_client" id="add_no_client" placeholder="ex: CLT-2026-001"></div>
                        <div><label>Enseigne</label><input type="text" name="enseigne" id="add_enseigne"></div>
                        <div><label>N° SIRET</label><input type="text" name="numero_siret" id="add_numero_siret"></div>
                        <div><label>ID E-commerce</label><input type="text" name="identifiant_ecommerce" id="add_identifiant_ecommerce"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-birthday-cake"></i> Date de naissance</div>
                    <div>
                        <label>Date de naissance</label>
                        <input type="date" name="date_naissance" id="add_date_naissance" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                        <p style="font-size: 11px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i class="fas fa-info-circle"></i> Le contact doit avoir au moins 18 ans.</p>
                    </div>

                    <div class="section-title"><i class="fas fa-heart"></i> Conjoint(e)</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>Nom du mari</label><input type="text" name="mari" id="add_mari"></div>
                        <div><label>Anniversaire mari</label><input type="date" name="anniversaire_mari" id="add_anniversaire_mari"></div>
                        <div><label>Nom de la femme</label><input type="text" name="femme" id="add_femme"></div>
                        <div><label>Anniversaire femme</label><input type="date" name="anniversaire_femme" id="add_anniversaire_femme"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-child"></i> Enfants</div>
                    <div id="enfantsContainer">
                        <div id="noEnfantsMessage" style="text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;">
                            <i class="fas fa-info-circle" style="margin-right: 4px;"></i>
                            Aucun enfant enregistré.
                            <button type="button" onclick="addEnfantRow('add')" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter un enfant</button>
                        </div>
                    </div>
                    <button type="button" onclick="addEnfantRow('add')" style="margin-top: 8px; background: none; border: none; color: #2563eb; cursor: pointer; font-weight: 600; font-size: 12px;">
                        <i class="fas fa-plus-circle"></i> Ajouter un enfant
                    </button>

                    <div class="section-title"><i class="fas fa-star"></i> Programme de fidélité</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                        <div><label>Points de fidélité</label><input type="number" name="points_fidelite" id="add_points_fidelite" value="0"></div>
                        <div><label>Cumul cadeau (€)</label><input type="number" step="0.01" name="cumul_cadeau" id="add_cumul_cadeau" value="0"></div>
                        <div><label>Cumul achats (€)</label><input type="number" step="0.01" name="cumul_achats" id="add_cumul_achats" value="0"></div>
                        <div><label>Cumul avant cadeau (€)</label><input type="number" step="0.01" name="cumul_achat_avant_cadeau" id="add_cumul_achat_avant_cadeau" value="0"></div>
                        <div><label>Qté articles</label><input type="number" name="quantite_article" id="add_quantite_article" value="0"></div>
                        <div><label>Nb tickets</label><input type="number" name="nombre_ticket" id="add_nombre_ticket" value="0"></div>
                        <div><label>Valeur coupon</label><input type="text" name="valeur_coupon" id="add_valeur_coupon" placeholder="ex: 10,00 €"></div>
                        <div><label>Fin validité coupon</label><input type="date" name="date_fin_validite_coupon" id="add_date_fin_validite_coupon"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-comment"></i> Commentaires</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px;">
                        <div><label>Commentaire</label><textarea name="commentaire" id="add_commentaire" rows="2"></textarea></div>
                        <div><label>Commentaire privé</label><textarea name="commentaire_prive" id="add_commentaire_prive" rows="2"></textarea></div>
                    </div>

                    <div class="section-title"><i class="fas fa-cog"></i> Champs personnalisés <span style="font-size: 10px; color: #94a3b8; font-weight: 400; text-transform: none; letter-spacing: 0;">(max 10)</span></div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 11.5px; color: #64748b; font-weight: 500;" id="customFieldCountAdd">0 / 10</span>
                        <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 12px; border-radius: 7px; cursor: pointer; font-weight: 600; font-size: 11.5px;">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="addCustomFieldsContainer" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                        <div id="noCustomFieldsMessage" style="grid-column: 1 / -1; text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;">
                            <i class="fas fa-info-circle" style="margin-right: 4px;"></i>
                            Aucun champ personnalisé.
                            <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter votre premier champ</button>
                        </div>
                    </div>
                    <div id="tempFieldsList" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px;"></div>
                </div>
                <div class="modal-footer-sticky">
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" onclick="closeAddContactModal()" class="btn-secondary"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL MODIFICATION CONTACT -->
<!-- ============================================ -->
<div id="editContactModal">
    <div class="modal-edit-contact">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="modal-header-mini">
                    <div class="modal-header-left" style="align-items: flex-start;">
                        <div class="modal-icon-box yellow"><i class="fas fa-edit"></i></div>
                        <div class="modal-title-wrap">
                            <h3>Modifier le contact</h3>
                            <div id="editBlacklistStatus" style="margin-top: 4px;"></div>
                        </div>
                    </div>
                    <button type="button" onclick="closeEditContactModal()" class="modal-close-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <form id="editContactForm" method="POST">
                <input type="hidden" name="action_edit_contact" value="1">
                <input type="hidden" name="id_contact" id="edit_id_contact">
                <div class="modal-scroll-content">
                    <div class="section-title"><i class="fas fa-id-card"></i> Identité</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>Civilité</label><select name="civilite" id="edit_civilite"><option value="">--</option><option value="M.">M.</option><option value="Mme">Mme</option><option value="Mlle">Mlle</option><option value="Dr">Dr</option><option value="Pr">Pr</option></select></div>
                        <div><label>Sexe</label><select name="sexe" id="edit_sexe"><option value="">--</option><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
                        <div><label>Prénom *</label><input type="text" name="prenom" id="edit_prenom" required></div>
                        <div><label>Nom *</label><input type="text" name="nom" id="edit_nom" required></div>
                    </div>

                    <div class="section-title"><i class="fas fa-phone"></i> Coordonnées</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <div><label>Email *</label><input type="email" name="email" id="edit_email" required></div>
                        <div><label>Téléphone fixe</label><input type="tel" name="telephone" id="edit_telephone" placeholder="ex: 0612345678"></div>
                        <div><label>Téléphone portable</label><input type="tel" name="tel_portable" id="edit_tel_portable" placeholder="ex: 0612345678"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-map-marker-alt"></i> Adresse</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div style="grid-column: span 2;"><label>Adresse</label><input type="text" name="adresse" id="edit_adresse"></div>
                        <div><label>Code postal</label><input type="text" name="code_postal" id="edit_code_postal"></div>
                        <div><label>Ville</label><input type="text" name="ville" id="edit_ville"></div>
                        <div><label>Pays</label><input type="text" name="pays" id="edit_pays" value="France"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-briefcase"></i> Informations client</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>N° client</label><input type="text" name="no_client" id="edit_no_client" placeholder="ex: CLT-2026-001"></div>
                        <div><label>Enseigne</label><input type="text" name="enseigne" id="edit_enseigne"></div>
                        <div><label>N° SIRET</label><input type="text" name="numero_siret" id="edit_numero_siret" placeholder="14 chiffres"></div>
                        <div><label>ID E-commerce</label><input type="text" name="identifiant_ecommerce" id="edit_identifiant_ecommerce"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-birthday-cake"></i> Date de naissance</div>
                    <div>
                        <label>Date de naissance</label>
                        <input type="date" name="date_naissance" id="edit_date_naissance" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                        <p style="font-size: 11px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i class="fas fa-info-circle"></i> Le contact doit avoir au moins 18 ans.</p>
                    </div>

                    <div class="section-title"><i class="fas fa-heart"></i> Conjoint(e)</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div><label>Nom du mari</label><input type="text" name="mari" id="edit_mari"></div>
                        <div><label>Anniversaire mari</label><input type="date" name="anniversaire_mari" id="edit_anniversaire_mari"></div>
                        <div><label>Nom de la femme</label><input type="text" name="femme" id="edit_femme"></div>
                        <div><label>Anniversaire femme</label><input type="date" name="anniversaire_femme" id="edit_anniversaire_femme"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-child"></i> Enfants</div>
                    <div id="editEnfantsContainer">
                        <div id="editNoEnfantsMessage" style="text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;">
                            <i class="fas fa-info-circle" style="margin-right: 4px;"></i>
                            Aucun enfant enregistré.
                            <button type="button" onclick="addEnfantRow('edit')" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter un enfant</button>
                        </div>
                    </div>
                    <button type="button" onclick="addEnfantRow('edit')" style="margin-top: 8px; background: none; border: none; color: #2563eb; cursor: pointer; font-weight: 600; font-size: 12px;">
                        <i class="fas fa-plus-circle"></i> Ajouter un enfant
                    </button>

                    <div class="section-title"><i class="fas fa-star"></i> Programme de fidélité</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                        <div><label>Points de fidélité</label><input type="number" name="points_fidelite" id="edit_points_fidelite" value="0"></div>
                        <div><label>Cumul cadeau (€)</label><input type="number" step="0.01" name="cumul_cadeau" id="edit_cumul_cadeau" value="0"></div>
                        <div><label>Cumul achats (€)</label><input type="number" step="0.01" name="cumul_achats" id="edit_cumul_achats" value="0"></div>
                        <div><label>Cumul avant cadeau (€)</label><input type="number" step="0.01" name="cumul_achat_avant_cadeau" id="edit_cumul_achat_avant_cadeau" value="0"></div>
                        <div><label>Qté articles</label><input type="number" name="quantite_article" id="edit_quantite_article" value="0"></div>
                        <div><label>Nb tickets</label><input type="number" name="nombre_ticket" id="edit_nombre_ticket" value="0"></div>
                        <div><label>Valeur coupon</label><input type="text" name="valeur_coupon" id="edit_valeur_coupon" placeholder="ex: 10,00 €"></div>
                        <div><label>Fin validité coupon</label><input type="date" name="date_fin_validite_coupon" id="edit_date_fin_validite_coupon"></div>
                    </div>

                    <div class="section-title"><i class="fas fa-comment"></i> Commentaires</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px;">
                        <div><label>Commentaire public</label><textarea name="commentaire" id="edit_commentaire" rows="2"></textarea></div>
                        <div><label>Commentaire privé</label><textarea name="commentaire_prive" id="edit_commentaire_prive" rows="2"></textarea></div>
                    </div>

                    <div class="section-title"><i class="fas fa-cog"></i> Champs personnalisés <span style="font-size: 10px; color: #94a3b8; font-weight: 400; text-transform: none; letter-spacing: 0;">(max 10)</span></div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 11.5px; color: #64748b; font-weight: 500;" id="customFieldCountEdit">0 / 10</span>
                        <button type="button" onclick="openAddCustomFieldModalFromEdit()" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 12px; border-radius: 7px; cursor: pointer; font-weight: 600; font-size: 11.5px;">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="editCustomFieldsContainer" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;"></div>
                </div>
                <div class="modal-footer-sticky">
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" onclick="closeEditContactModal()" class="btn-secondary"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL CHAMP PERSONNALISÉ -->
<!-- ============================================ -->
<div id="addCustomFieldModal">
    <div class="modal-custom-field">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="modal-header-mini">
                    <div class="modal-header-left">
                        <div class="modal-icon-box blue"><i class="fas fa-plus-circle"></i></div>
                        <div class="modal-title-wrap">
                            <h3>Nouveau champ personnalisé</h3>
                            <p>Créez un champ sur mesure pour ce contact</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeAddCustomFieldModal()" class="modal-close-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <form id="addCustomFieldForm">
                <input type="hidden" id="custom_field_contact_id" value="">
                <input type="hidden" id="custom_field_mode" value="temp">
                <div class="modal-scroll-content">
                    <div style="margin-bottom: 16px;">
                        <label>Nom technique <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="new_field_name" required placeholder="ex: societe, fonction">
                        <p style="font-size: 11px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i class="fas fa-info-circle"></i> Sans accent, sans espace (utilisez _ )</p>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label>Libellé <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="new_field_label" required placeholder="ex: Société, Fonction">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label>Type de champ</label>
                        <select id="new_field_type">
                            <option value="text">Texte court</option>
                            <option value="textarea">Zone texte</option>
                            <option value="number">Nombre</option>
                            <option value="date">Date</option>
                            <option value="email">Email</option>
                            <option value="tel">Téléphone</option>
                            <option value="select">Liste déroulante</option>
                        </select>
                    </div>
                    <div id="new_field_options_div" style="display:none; margin-bottom: 16px;">
                        <label>Options <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="new_field_options" placeholder="ex: Option 1|Option 2|Option 3">
                        <p style="font-size: 11px; color: #64748b; margin-top: 4px;">Séparez les options par <strong style="color: #3b82f6;">|</strong></p>
                    </div>
                    <div id="new_field_value_div" style="margin-bottom: 16px;">
                        <label>Valeur (optionnel)</label>
                        <input type="text" id="new_field_value" placeholder="Valeur du champ">
                    </div>
                </div>
                <div class="modal-footer-sticky">
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" onclick="closeAddCustomFieldModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" id="createFieldBtn" class="btn-primary"><i class="fas fa-plus"></i> Ajouter le champ</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL IMPORT CSV -->
<!-- ============================================ -->
<div id="importModal">
    <div class="modal-import-csv">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="modal-header-mini">
                    <div class="modal-header-left">
                        <div class="modal-icon-box green"><i class="fas fa-file-import"></i></div>
                        <div class="modal-title-wrap">
                            <h3>Importer des contacts</h3>
                            <p>Ajoutez plusieurs contacts depuis un fichier</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeImportModal()" class="modal-close-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <div class="modal-scroll-content">
                    <div style="background: #eff6ff; padding: 18px 20px; border-radius: 12px; margin-bottom: 18px; border: 1px solid #bfdbfe;">
                        <h4 style="font-size: 13px; font-weight: 700; color: #1e40af; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-info-circle"></i> Format du fichier
                        </h4>
                        <ul style="font-size: 12px; color: #1e3a5f; list-style: none; padding: 0; margin: 0; line-height: 1.7;">
                            <li><i class="fas fa-check-circle" style="color: #10b981; margin-right: 8px;"></i>Colonnes requises : <strong>prenom, nom, email</strong></li>
                            <li><i class="fas fa-check-circle" style="color: #10b981; margin-right: 8px;"></i>Colonnes optionnelles : telephone, tel_portable, ville, adresse, code_postal, pays, date_naissance, no_client, civilite, sexe, enseigne, numero_siret, mari, anniversaire_mari, femme, anniversaire_femme, points_fidelite, cumul_cadeau, cumul_achats, cumul_achat_avant_cadeau, quantite_article, nombre_ticket, identifiant_ecommerce, valeur_coupon, date_fin_validite_coupon, commentaire, commentaire_prive</li>
                            <li><i class="fas fa-check-circle" style="color: #10b981; margin-right: 8px;"></i>Séparateur : point-virgule (;) ou virgule (,)</li>
                            <li><i class="fas fa-check-circle" style="color: #10b981; margin-right: 8px;"></i>Les contacts déjà existants (même email) sont ignorés</li>
                            <li><i class="fas fa-info-circle" style="color: #3b82f6; margin-right: 8px;"></i>La date de naissance doit être au format YYYY-MM-DD, 18 ans minimum</li>
                        </ul>
                    </div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">
                        <i class="fas fa-file" style="margin-right: 6px;"></i>Fichier CSV/Excel
                    </label>
                    <input type="file" name="fichier" id="importFile" accept=".csv,.xls,.xlsx" required
                           style="width: 100%; padding: 14px 16px; font-size: 13px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; cursor: pointer;"
                           onmouseover="this.style.borderColor='#3b82f6'; this.style.background='#eff6ff';"
                           onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='#f8fafc';">
                    <p style="font-size: 11px; color: #64748b; margin-top: 8px; display: flex; align-items: center; gap: 4px;">
                        <i class="fas fa-info-circle"></i>Formats acceptés : CSV, XLS, XLSX (Taille max : 10MB)
                    </p>
                </div>
                <div class="modal-footer-sticky">
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" onclick="closeImportModal()" class="btn-secondary"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" id="importSubmitBtn" class="btn-success"><i class="fas fa-upload"></i> Importer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL DÉBLOQUER -->
<!-- ============================================ -->
<div id="unblacklistModal">
    <div style="background: white; border-radius: 16px; max-width: 440px; width: 92%; margin: 0 auto; box-shadow: 0 20px 40px -12px rgba(0,0,0,0.35); animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding: 24px; text-align: center;">
            <div style="width: 56px; height: 56px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
                <i class="fas fa-unlock-alt" style="font-size: 22px; color: #059669;"></i>
            </div>
            <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;">Débloquer le contact</h3>
            <p style="color: #64748b; font-size: 13px; margin: 0 0 20px 0; line-height: 1.5;">Êtes-vous sûr de vouloir retirer ce contact de la blacklist ?</p>
            <form method="POST" action="?page=contacts/unblacklist">
                <input type="hidden" name="id_contact" id="unblacklistContactId">
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="closeUnblacklistModal()" class="btn-secondary" style="flex: 1; justify-content: center;">Annuler</button>
                    <button type="submit" class="btn-success" style="flex: 1; justify-content: center;"><i class="fas fa-check"></i> Débloquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL SUPPRESSION -->
<!-- ============================================ -->
<div id="deleteModal">
    <div style="background: white; border-radius: 16px; max-width: 440px; width: 92%; margin: 0 auto; box-shadow: 0 20px 40px -12px rgba(0,0,0,0.35); animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding: 24px; text-align: center;">
            <div style="width: 56px; height: 56px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
                <i class="fas fa-exclamation-triangle" style="font-size: 22px; color: #dc2626;"></i>
            </div>
            <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;">Confirmer la suppression</h3>
            <p style="color: #64748b; font-size: 13px; margin: 0 0 6px 0; line-height: 1.5;">Êtes-vous sûr de vouloir supprimer ce contact ?</p>
            <p style="font-size: 12px; color: #ef4444; margin: 0 0 20px 0; font-weight: 600;">Cette action est irréversible.</p>
            <div style="display: flex; gap: 8px;">
                <button type="button" onclick="closeModal()" class="btn-secondary" style="flex: 1; justify-content: center;">Annuler</button>
                <a href="#" id="confirmDeleteBtn" class="btn-danger" style="flex: 1; justify-content: center; text-decoration: none;"><i class="fas fa-trash"></i> Supprimer</a>
            </div>
        </div>
    </div>
</div>

<script>
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    toast.innerHTML = `<div class="toast-content"><i class="fas ${icons[type] || icons.success}"></i><span>${message}</span></div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

<?php if ($flashMessage): ?> showToast('<?= addslashes($flashMessage) ?>', 'success'); <?php endif; ?>
<?php if ($flashError): ?> showToast('<?= addslashes($flashError) ?>', 'error'); <?php endif; ?>

let currentContactIdForEdit = null;
let tempCustomFields = [];
let enfantCounter = 0;

// ============================================
// AFFICHAGE DU STATUT BLACKLIST
// ============================================
function renderBlacklistStatus(blocages) {
    const container = document.getElementById('editBlacklistStatus');
    if (!container) return;
    
    if (!blocages || blocages.length === 0) {
        container.innerHTML = `<span class="blacklist-status-ok"><i class="fas fa-check-circle"></i>Actif</span>`;
        return;
    }
    
    let typesHtml = '';
    blocages.forEach(function(bl) {
        const libelle = (bl.libelle || 'Inconnu');
        let badgeClass = 'default';
        const libLower = libelle.toLowerCase();
        if (libLower === 'sms') badgeClass = 'sms';
        else if (libLower === 'whatsapp') badgeClass = 'whatsapp';
        else if (libLower === 'email') badgeClass = 'email';
        
        let icon = 'fa-ban';
        if (libLower === 'whatsapp') icon = 'fa-brands fa-whatsapp';
        else if (libLower === 'sms') icon = 'fa-comment-dots';
        else if (libLower === 'email') icon = 'fa-envelope';
        
        const motifHtml = bl.motif ? ` <span style="font-size: 10px; opacity: 0.85; text-transform: none; font-weight: 500;">• ${escapeHtml(bl.motif)}</span>` : '';
        
        typesHtml += `<span class="blacklist-header-badge ${badgeClass}"><i class="fas ${icon}"></i> ${escapeHtml(libelle)}${motifHtml}</span>`;
    });
    
    container.innerHTML = `
        <div class="blacklist-header-container">
            <div class="title"><i class="fas fa-ban"></i> Blacklisté pour ${blocages.length} type(s)</div>
            <div class="types">${typesHtml}</div>
        </div>
    `;
}

// ============================================
// ENFANTS
// ============================================
function addEnfantRow(mode) {
    const containerId = mode === 'add' ? 'enfantsContainer' : 'editEnfantsContainer';
    const noMsgId = mode === 'add' ? 'noEnfantsMessage' : 'editNoEnfantsMessage';
    const container = document.getElementById(containerId);
    const noMsg = document.getElementById(noMsgId);
    if (noMsg) noMsg.remove();
    const id = ++enfantCounter;
    const div = document.createElement('div');
    div.className = 'enfant-item';
    div.dataset.enfantId = id;
    div.innerHTML = `
        <button type="button" class="remove-enfant" onclick="this.closest('.enfant-item').remove()">×</button>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
            <div><label>Nom</label><input type="text" name="enfants[${id}][nom]" placeholder="Nom"></div>
            <div><label>Prénom</label><input type="text" name="enfants[${id}][prenom]" placeholder="Prénom"></div>
            <div><label>Sexe</label><select name="enfants[${id}][sexe]"><option value="">--</option><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
            <div><label>Date anniversaire</label><input type="date" name="enfants[${id}][date_anniversaire]"></div>
        </div>
    `;
    container.appendChild(div);
}

function loadEnfants(mode, enfants) {
    const containerId = mode === 'add' ? 'enfantsContainer' : 'editEnfantsContainer';
    const container = document.getElementById(containerId);
    container.innerHTML = '';
    if (!enfants || enfants.length === 0) {
        const noMsg = document.createElement('div');
        noMsg.id = mode === 'add' ? 'noEnfantsMessage' : 'editNoEnfantsMessage';
        noMsg.style.cssText = 'text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;';
        noMsg.innerHTML = `<i class="fas fa-info-circle" style="margin-right: 4px;"></i>Aucun enfant enregistré. <button type="button" onclick="addEnfantRow('${mode}')" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter un enfant</button>`;
        container.appendChild(noMsg);
        return;
    }
    enfants.forEach((enfant) => {
        const id = ++enfantCounter;
        const div = document.createElement('div');
        div.className = 'enfant-item';
        div.dataset.enfantId = id;
        div.innerHTML = `
            <button type="button" class="remove-enfant" onclick="this.closest('.enfant-item').remove()">×</button>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
                <div><label>Nom</label><input type="text" name="enfants[${id}][nom]" value="${escapeHtml(enfant.nom || '')}"></div>
                <div><label>Prénom</label><input type="text" name="enfants[${id}][prenom]" value="${escapeHtml(enfant.prenom || '')}"></div>
                <div><label>Sexe</label><select name="enfants[${id}][sexe]"><option value="">--</option><option value="M" ${enfant.sexe === 'M' ? 'selected' : ''}>Masculin</option><option value="F" ${enfant.sexe === 'F' ? 'selected' : ''}>Féminin</option></select></div>
                <div><label>Date anniversaire</label><input type="date" name="enfants[${id}][date_anniversaire]" value="${escapeHtml(enfant.date_anniversaire || '')}"></div>
            </div>
        `;
        container.appendChild(div);
    });
}

// ============================================
// CHAMPS PERSONNALISÉS
// ============================================
function addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue) {
    if (tempCustomFields.length >= 10) { showToast('Maximum 10 champs', 'warning'); return false; }
    if (tempCustomFields.some(f => f.field_name === fieldName)) { showToast('Un champ avec ce nom existe déjà', 'warning'); return false; }
    tempCustomFields.push({ field_name: fieldName, field_label: fieldLabel, field_type: fieldType, field_options: fieldOptions || null, field_value: fieldValue || '' });
    updateTempFieldsDisplay();
    updateTempFieldsInput();
    updateCustomFieldCount('add');
    showToast(`Champ "${fieldLabel}" ajouté`, 'success');
    return true;
}

function removeTempField(index) {
    tempCustomFields.splice(index, 1);
    updateTempFieldsDisplay();
    updateTempFieldsInput();
    updateCustomFieldCount('add');
}

function updateTempFieldsDisplay() {
    const container = document.getElementById('tempFieldsList');
    if (!container) return;
    if (tempCustomFields.length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = tempCustomFields.map((field, index) => {
        const label = field.field_label || field.field_name;
        const value = field.field_value ? `: ${field.field_value}` : '';
        return `<span style="display: inline-flex; align-items: center; gap: 4px; background: #eff6ff; color: #1e40af; padding: 4px 10px; border-radius: 14px; font-size: 11px; font-weight: 600; border: 1px solid #bfdbfe;">
            <i class="fas fa-tag"></i>${escapeHtml(label)}${escapeHtml(value)}
            <span onclick="removeTempField(${index})" style="cursor: pointer; margin-left: 4px; font-weight: 700; color: #dc2626;">×</span>
        </span>`;
    }).join('');
}

function updateTempFieldsInput() {
    const input = document.getElementById('tempCustomFields');
    if (input) input.value = JSON.stringify(tempCustomFields);
}

function updateCustomFieldCount(mode) {
    const countId = mode === 'add' ? 'customFieldCountAdd' : 'customFieldCountEdit';
    const countEl = document.getElementById(countId);
    if (!countEl) return;
    let currentCount = 0;
    if (mode === 'add') {
        currentCount = tempCustomFields.length;
        const container = document.getElementById('addCustomFieldsContainer');
        if (container) currentCount += container.querySelectorAll('.custom-field-wrapper').length;
    } else {
        const container = document.getElementById('editCustomFieldsContainer');
        if (container) currentCount = container.querySelectorAll('.custom-field-wrapper').length;
    }
    countEl.textContent = `${currentCount} / 10`;
}

function buildCustomFieldHtml(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue, dataAttr) {
    const fn = escapeHtml(fieldName);
    const fl = escapeHtml(fieldLabel);
    const fv = escapeHtml(fieldValue || '');
    const attr = dataAttr ? ` data-field-name="${fn}"` : '';
    let inner = '';
    if (fieldType === 'textarea') {
        inner = `<textarea name="custom_fields[${fn}]" rows="2">${fv}</textarea>`;
    } else if (fieldType === 'select' && fieldOptions) {
        const options = fieldOptions.split('|');
        let optionsHtml = '<option value="">-- Sélectionner --</option>';
        options.forEach(opt => {
            const t = opt.trim();
            const sel = fieldValue === t ? 'selected' : '';
            optionsHtml += `<option value="${escapeHtml(t)}" ${sel}>${escapeHtml(t)}</option>`;
        });
        inner = `<select name="custom_fields[${fn}]">${optionsHtml}</select>`;
    } else if (fieldType === 'date') {
        inner = `<input type="date" name="custom_fields[${fn}]" value="${fv}">`;
    } else if (fieldType === 'number') {
        inner = `<input type="number" name="custom_fields[${fn}]" value="${fv}">`;
    } else {
        inner = `<input type="text" name="custom_fields[${fn}]" value="${fv}" placeholder="${fl}">`;
    }
    return `<div class="custom-field-wrapper"${attr} style="background: white; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;"><label>${fl}</label>${inner}</div>`;
}

function ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue, mode) {
    const containerId = mode === 'add' ? 'addCustomFieldsContainer' : 'editCustomFieldsContainer';
    const container = document.getElementById(containerId);
    if (!container) return;
    const currentFields = container.querySelectorAll('.custom-field-wrapper').length;
    if (mode === 'add') {
        if (currentFields + tempCustomFields.length >= 10) { showToast('Maximum 10 champs', 'warning'); return; }
    } else {
        if (currentFields >= 10) { showToast('Maximum 10 champs', 'warning'); return; }
    }
    const noMsg = container.querySelector('#noCustomFieldsMessage, .no-custom-msg');
    if (noMsg) noMsg.remove();
    container.insertAdjacentHTML('beforeend', buildCustomFieldHtml(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue, mode === 'edit'));
    updateCustomFieldCount(mode);
}

function openAddCustomFieldModalFromAddTemp() {
    document.getElementById('custom_field_contact_id').value = 'temp';
    document.getElementById('custom_field_mode').value = 'temp';
    document.getElementById('new_field_value_div').style.display = 'block';
    document.getElementById('addCustomFieldModal').style.display = 'flex';
}

function openAddCustomFieldModalFromEdit() {
    if (!currentContactIdForEdit) { showToast('Contact non identifié', 'error'); return; }
    document.getElementById('custom_field_contact_id').value = currentContactIdForEdit;
    document.getElementById('custom_field_mode').value = 'edit';
    document.getElementById('new_field_value_div').style.display = 'none';
    document.getElementById('addCustomFieldModal').style.display = 'flex';
}

function closeAddCustomFieldModal() {
    document.getElementById('addCustomFieldModal').style.display = 'none';
}

document.getElementById('new_field_type')?.addEventListener('change', function() {
    document.getElementById('new_field_options_div').style.display = this.value === 'select' ? 'block' : 'none';
});

document.getElementById('addCustomFieldForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fieldName = document.getElementById('new_field_name').value.trim();
    const fieldLabel = document.getElementById('new_field_label').value.trim();
    const fieldType = document.getElementById('new_field_type').value;
    const fieldOptions = document.getElementById('new_field_options').value.trim();
    const fieldValue = document.getElementById('new_field_value').value.trim();
    const mode = document.getElementById('custom_field_mode').value;
    const contactId = document.getElementById('custom_field_contact_id').value;
    if (!fieldName || !fieldLabel) { showToast('Veuillez remplir tous les champs obligatoires', 'warning'); return; }
    const btn = document.getElementById('createFieldBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ajout...';
    btn.disabled = true;
    try {
        if (mode === 'temp') {
            if (addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue)) {
                ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue, 'add');
                closeAddCustomFieldModal();
            }
        } else if (mode === 'edit') {
            if (!contactId || contactId === 'temp') { showToast('Contact non identifié', 'error'); btn.innerHTML = originalText; btn.disabled = false; return; }
            const formData = new FormData();
            formData.append('action_create_custom_field', '1');
            formData.append('field_name', fieldName);
            formData.append('field_label', fieldLabel);
            formData.append('field_type', fieldType);
            if (fieldOptions) formData.append('field_options', fieldOptions);
            formData.append('id_contact', contactId);
            const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
            const textResponse = await response.text();
            let result;
            try { result = JSON.parse(textResponse); } catch(e) { showToast('Erreur de parsing', 'error'); btn.innerHTML = originalText; btn.disabled = false; return; }
            if (result.success) {
                showToast(result.message, 'success');
                closeAddCustomFieldModal();
                ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, '', 'edit');
                currentContactIdForEdit = contactId;
            } else {
                showToast(result.error || 'Erreur inconnue', 'error');
            }
        }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); }
    finally { btn.innerHTML = originalText; btn.disabled = false; }
});

// ============================================
// OPEN / CLOSE MODALS
// ============================================
function openAddContactModal() {
    const modal = document.getElementById('addContactModal');
    document.getElementById('addContactForm').reset();
    tempCustomFields = [];
    document.getElementById('tempCustomFields').value = '';
    document.getElementById('tempFieldsList').innerHTML = '';
    document.getElementById('addCustomFieldsContainer').innerHTML = `<div id="noCustomFieldsMessage" style="grid-column: 1 / -1; text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;"><i class="fas fa-info-circle" style="margin-right: 4px;"></i>Aucun champ personnalisé. <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter votre premier champ</button></div>`;
    document.getElementById('enfantsContainer').innerHTML = `<div id="noEnfantsMessage" style="text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;"><i class="fas fa-info-circle" style="margin-right: 4px;"></i>Aucun enfant enregistré. <button type="button" onclick="addEnfantRow('add')" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter un enfant</button></div>`;
    updateCustomFieldCount('add');
    modal.style.display = 'flex';
}

function closeAddContactModal() { document.getElementById('addContactModal').style.display = 'none'; }

async function openEditContactModal(contactId) {
    currentContactIdForEdit = contactId;
    const modal = document.getElementById('editContactModal');
    
    const statusContainer = document.getElementById('editBlacklistStatus');
    if (statusContainer) {
        statusContainer.innerHTML = '<div class="blacklist-loading"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>';
    }
    
    try {
        const response = await fetch(`index.php?page=contacts/index&action=get_contact&id=${contactId}`);
        if (!response.ok) { showToast('Erreur serveur: ' + response.status, 'error'); return; }
        const textResponse = await response.text();
        let contact;
        try { contact = JSON.parse(textResponse); } catch(e) { showToast('Erreur de parsing', 'error'); return; }
        if (contact.error) { showToast(contact.error, 'error'); return; }
        
        renderBlacklistStatus(contact.blocages || []);
        
        document.getElementById('edit_id_contact').value = contact.id_contact;
        document.getElementById('edit_civilite').value = contact.civilite || '';
        document.getElementById('edit_sexe').value = contact.sexe || '';
        document.getElementById('edit_prenom').value = contact.prenom || '';
        document.getElementById('edit_nom').value = contact.nom || '';
        document.getElementById('edit_email').value = contact.email || '';
        document.getElementById('edit_telephone').value = contact.telephone || '';
        document.getElementById('edit_tel_portable').value = contact.tel_portable || '';
        document.getElementById('edit_adresse').value = contact.adresse || '';
        document.getElementById('edit_code_postal').value = contact.code_postal || '';
        document.getElementById('edit_ville').value = contact.ville || '';
        document.getElementById('edit_pays').value = contact.pays || 'France';
        document.getElementById('edit_date_naissance').value = contact.date_naissance || '';
        document.getElementById('edit_no_client').value = contact.no_client || '';
        document.getElementById('edit_enseigne').value = contact.enseigne || '';
        document.getElementById('edit_numero_siret').value = contact.numero_siret || '';
        document.getElementById('edit_identifiant_ecommerce').value = contact.identifiant_ecommerce || '';
        document.getElementById('edit_mari').value = contact.mari || '';
        document.getElementById('edit_anniversaire_mari').value = contact.anniversaire_mari || '';
        document.getElementById('edit_femme').value = contact.femme || '';
        document.getElementById('edit_anniversaire_femme').value = contact.anniversaire_femme || '';
        document.getElementById('edit_points_fidelite').value = contact.points_fidelite || 0;
        document.getElementById('edit_cumul_cadeau').value = contact.cumul_cadeau || 0;
        document.getElementById('edit_cumul_achats').value = contact.cumul_achats || 0;
        document.getElementById('edit_cumul_achat_avant_cadeau').value = contact.cumul_achat_avant_cadeau || 0;
        document.getElementById('edit_quantite_article').value = contact.quantite_article || 0;
        document.getElementById('edit_nombre_ticket').value = contact.nombre_ticket || 0;
        document.getElementById('edit_valeur_coupon').value = contact.valeur_coupon || '';
        document.getElementById('edit_date_fin_validite_coupon').value = contact.date_fin_validite_coupon || '';
        document.getElementById('edit_commentaire').value = contact.commentaire || '';
        document.getElementById('edit_commentaire_prive').value = contact.commentaire_prive || '';
        
        const container = document.getElementById('editCustomFieldsContainer');
        container.innerHTML = '';
        const fieldsResponse = await fetch(`index.php?page=contacts/index&action=get_contact_fields&id=${contactId}`);
        const fieldsData = await fieldsResponse.json();
        if (fieldsData.fields && fieldsData.fields.length > 0) {
            for (const field of fieldsData.fields) {
                const currentValue = field.value || '';
                const required = field.is_required ? '<span style="color: #ef4444;">*</span>' : '';
                const fn = escapeHtml(field.field_name);
                const fl = escapeHtml(field.field_label);
                let fieldHtml = `<div class="custom-field-wrapper" data-field-name="${fn}" style="background: white; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;"><label>${fl} ${required}</label>`;
                if (field.field_type === 'textarea') {
                    fieldHtml += `<textarea name="custom_fields[${fn}]" rows="2">${escapeHtml(currentValue)}</textarea>`;
                } else if (field.field_type === 'select' && field.field_options) {
                    const options = field.field_options.split('|');
                    fieldHtml += `<select name="custom_fields[${fn}]"><option value="">-- Sélectionner --</option>`;
                    for (const opt of options) {
                        const t = opt.trim();
                        const sel = currentValue === t ? 'selected' : '';
                        fieldHtml += `<option value="${escapeHtml(t)}" ${sel}>${escapeHtml(t)}</option>`;
                    }
                    fieldHtml += `</select>`;
                } else if (field.field_type === 'date') {
                    fieldHtml += `<input type="date" name="custom_fields[${fn}]" value="${escapeHtml(currentValue)}">`;
                } else if (field.field_type === 'number') {
                    fieldHtml += `<input type="number" name="custom_fields[${fn}]" value="${escapeHtml(currentValue)}">`;
                } else {
                    fieldHtml += `<input type="text" name="custom_fields[${fn}]" value="${escapeHtml(currentValue)}">`;
                }
                fieldHtml += `</div>`;
                container.innerHTML += fieldHtml;
            }
        } else {
            container.innerHTML = `<div class="no-custom-msg" style="grid-column: 1 / -1; text-align: center; padding: 14px; color: #94a3b8; font-size: 12px; background: white; border-radius: 10px; border: 1.5px dashed #e2e8f0;"><i class="fas fa-info-circle" style="margin-right: 4px;"></i>Aucun champ personnalisé pour ce contact. <button type="button" onclick="openAddCustomFieldModalFromEdit()" style="color: #2563eb; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 600; font-size: 12px;">Ajouter un champ</button></div>`;
        }
        updateCustomFieldCount('edit');
        loadEnfants('edit', contact.enfants || []);
        modal.style.display = 'flex';
    } catch(error) { showToast('Erreur lors du chargement du contact', 'error'); }
}

function closeEditContactModal() { document.getElementById('editContactModal').style.display = 'none'; }
function openImportModal() { document.getElementById('importModal').style.display = 'flex'; }
function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }
function openUnblacklistModal(contactId) { document.getElementById('unblacklistContactId').value = contactId; document.getElementById('unblacklistModal').style.display = 'flex'; }
function closeUnblacklistModal() { document.getElementById('unblacklistModal').style.display = 'none'; }
function showDeleteModal(contactId) { document.getElementById('confirmDeleteBtn').href = 'index.php?page=contacts/supprimer&id=' + contactId; document.getElementById('deleteModal').style.display = 'flex'; }
function closeModal() { document.getElementById('deleteModal').style.display = 'none'; }

// ============================================
// SOUMISSION
// ============================================
document.getElementById('editContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: formData });
        const textResponse = await response.text();
        let result;
        try { result = JSON.parse(textResponse); } catch(e) { showToast('Erreur de parsing', 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; return; }
        if (result.success) { showToast(result.message, 'success'); closeEditContactModal(); setTimeout(() => window.location.reload(), 1500); }
        else { showToast(result.error || 'Erreur inconnue', 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
});

document.getElementById('addContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: formData });
        const textResponse = await response.text();
        let result;
        try { result = JSON.parse(textResponse); } catch(e) { showToast('Erreur de parsing', 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; return; }
        if (result.success) { showToast(result.message, 'success'); setTimeout(() => window.location.reload(), 2000); }
        else { showToast(result.error || 'Erreur inconnue', 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
});

document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('importFile');
    if (!fileInput.files.length) { showToast('Veuillez sélectionner un fichier', 'warning'); return; }
    const formData = new FormData(this);
    const submitBtn = document.getElementById('importSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Import...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); closeImportModal(); setTimeout(() => window.location.reload(), 1500); }
        else { showToast(result.error, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
});

// ============================================
// FILTRES
// ============================================
const searchInput = document.getElementById('searchInput');
const filterBtns = document.querySelectorAll('.filter-btn');
const contactsRows = document.querySelectorAll('.contact-row');
const filteredCountSpan = document.getElementById('filteredCount');
let currentFilter = 'all';

function filterContacts() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    let visibleCount = 0;
    contactsRows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const phone = row.getAttribute('data-phone') || '';
        const city = row.getAttribute('data-city') || '';
        const hasEmail = row.getAttribute('data-has-email') === 'true';
        const hasPhone = row.getAttribute('data-has-phone') === 'true';
        const isBlacklisted = row.getAttribute('data-blacklisted') === 'true';
        let filterMatch = true;
        if (currentFilter === 'email') filterMatch = hasEmail;
        else if (currentFilter === 'phone') filterMatch = hasPhone;
        else if (currentFilter === 'blacklisted') filterMatch = isBlacklisted;
        let searchMatch = true;
        if (searchTerm !== '') searchMatch = name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm) || city.includes(searchTerm);
        if (filterMatch && searchMatch) { row.classList.remove('hidden-row'); visibleCount++; }
        else { row.classList.add('hidden-row'); }
    });
    if (filteredCountSpan) filteredCountSpan.textContent = `${visibleCount} contact(s) affiché(s)`;
}

if (searchInput) searchInput.addEventListener('input', filterContacts);

filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        filterBtns.forEach(b => {
            b.style.background = 'white';
            b.style.color = '#475569';
            b.style.borderColor = '#e2e8f0';
        });
        this.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
        this.style.color = 'white';
        this.style.borderColor = 'transparent';
        currentFilter = this.getAttribute('data-filter');
        filterContacts();
    });
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('addContactModal')?.addEventListener('click', function(e) { if (e.target === this) closeAddContactModal(); });
document.getElementById('editContactModal')?.addEventListener('click', function(e) { if (e.target === this) closeEditContactModal(); });
document.getElementById('importModal')?.addEventListener('click', function(e) { if (e.target === this) closeImportModal(); });
document.getElementById('unblacklistModal')?.addEventListener('click', function(e) { if (e.target === this) closeUnblacklistModal(); });
document.getElementById('deleteModal')?.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.getElementById('addCustomFieldModal')?.addEventListener('click', function(e) { if (e.target === this) closeAddCustomFieldModal(); });

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddContactModal();
        closeEditContactModal();
        closeImportModal();
        closeUnblacklistModal();
        closeModal();
        closeAddCustomFieldModal();
    }
});
</script>

</body>
</html>