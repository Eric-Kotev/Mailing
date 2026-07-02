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

$blacklistItems = $db->select('blacklist', [], '*');
$blacklistedIds = [];
$blacklistDetails = [];
foreach ($blacklistItems as $bl) {
    $blacklistedIds[] = $bl['id_contact'];
    $blacklistDetails[$bl['id_contact']] = $bl;
}

$contactsCustomValues = [];
$contactsEnfants = [];
foreach ($contacts as $contact) {
    $contactsCustomValues[$contact['id_contact']] = getContactCustomValues($contact['id_contact']);
    $contactsEnfants[$contact['id_contact']] = $db->select('enfants', ['contact_id' => $contact['id_contact']], '*', 'date_anniversaire ASC');
}

$totalContacts = count($contacts);

// ============================================
// CRÉATION D'UN CHAMP PERSONNALISÉ POUR UN CONTACT SPÉCIFIQUE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_custom_field']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $fieldName = trim($_POST['field_name'] ?? '');
    $fieldLabel = trim($_POST['field_label'] ?? '');
    $fieldType = $_POST['field_type'] ?? 'text';
    $fieldOptions = $_POST['field_options'] ?? null;
    $idContact = $_POST['id_contact'] ?? null;
    
    if (empty($fieldName)) {
        echo json_encode(['success' => false, 'error' => 'Le nom du champ est requis']);
        exit;
    }
    if (empty($fieldLabel)) {
        echo json_encode(['success' => false, 'error' => 'Le libellé du champ est requis']);
        exit;
    }
    if (empty($idContact)) {
        echo json_encode(['success' => false, 'error' => 'ID contact manquant']);
        exit;
    }
    
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
// TRAITEMENT DE L'AJOUT DE CONTACT (AJAX)
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
        if (empty($prenom)) {
            $errors[] = 'Le prénom est requis';
        }
        if (empty($nom)) {
            $errors[] = 'Le nom est requis';
        }
        if (empty($email)) {
            $errors[] = "L'email est requis";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "L'email n'est pas valide";
        }
        
        if (!empty($dateNaissance)) {
            if (!verifierAge($dateNaissance, 18)) {
                $errors[] = "Le contact doit avoir au moins 18 ans et la date ne peut pas être dans le futur";
            }
        }
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact)) {
                $errors[] = "Cet email est déjà utilisé par un autre contact";
            }
        }
        
        if (!empty($noClient)) {
            $existingNoClient = $db->select('contact', ['id_compte' => $idCompte, 'no_client' => $noClient]);
            if (!empty($existingNoClient)) {
                $errors[] = "Ce numéro client est déjà utilisé";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $telephoneFormatted = null;
        if (!empty($telephone)) {
            if (substr($telephone, 0, 3) === '261') {
                $telephoneFormatted = $telephone;
            } else {
                $telephoneFormatted = formatPhoneNumber($telephone);
            }
        }
        
        $telPortableFormatted = null;
        if (!empty($telPortable)) {
            if (substr($telPortable, 0, 3) === '261') {
                $telPortableFormatted = $telPortable;
            } else {
                $telPortableFormatted = formatPhoneNumber($telPortable);
            }
        }
        
        $data = [
            'id_compte' => $idCompte,
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France',
            'no_client' => !empty($noClient) ? $noClient : null,
            'civilite' => $civilite,
            'sexe' => $sexe,
            'tel_portable' => $telPortableFormatted,
            'commentaire' => !empty($commentaire) ? $commentaire : null,
            'commentaire_prive' => !empty($commentairePrive) ? $commentairePrive : null,
            'enseigne' => !empty($enseigne) ? $enseigne : null,
            'numero_siret' => !empty($numeroSiret) ? $numeroSiret : null,
            'mari' => !empty($mari) ? $mari : null,
            'anniversaire_mari' => !empty($anniversaireMari) ? $anniversaireMari : null,
            'femme' => !empty($femme) ? $femme : null,
            'anniversaire_femme' => !empty($anniversaireFemme) ? $anniversaireFemme : null,
            'points_fidelite' => $pointsFidelite,
            'cumul_cadeau' => $cumulCadeau,
            'cumul_achats' => $cumulAchats,
            'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau,
            'quantite_article' => $quantiteArticle,
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
                    if (count($existingFields) >= 10) {
                        continue;
                    }
                    createCustomFieldForContact(
                        $idCompte,
                        $contactId,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        $field['field_options'] ?? null
                    );
                    
                    if (isset($field['field_value']) && !empty($field['field_value'])) {
                        $createdField = $db->select('custom_fields', [
                            'id_contact' => $contactId,
                            'field_name' => $field['field_name']
                        ]);
                        if (!empty($createdField)) {
                            $db->insert('contact_custom_values', [
                                'id_custom_field' => $createdField[0]['id_custom_field'],
                                'field_value' => $field['field_value']
                            ]);
                        }
                    }
                }
            }
        }
        
        if (isset($_POST['enfants']) && is_array($_POST['enfants'])) {
            foreach ($_POST['enfants'] as $enfant) {
                if (!empty($enfant['prenom']) && !empty($enfant['nom'])) {
                    $db->insert('enfants', [
                        'contact_id' => $contactId,
                        'nom' => trim($enfant['nom']),
                        'prenom' => trim($enfant['prenom']),
                        'sexe' => $enfant['sexe'] ?? null,
                        'date_anniversaire' => !empty($enfant['date_anniversaire']) ? $enfant['date_anniversaire'] : null
                    ]);
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Contact ajouté avec succès',
            'id_contact' => $contactId
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR AJOUT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE LA MODIFICATION (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_contact'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_POST['id_contact'] ?? null;
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID contact manquant']);
            exit;
        }
        
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
        if (empty($prenom)) {
            $errors[] = 'Le prénom est requis';
        }
        if (empty($nom)) {
            $errors[] = 'Le nom est requis';
        }
        if (empty($email)) {
            $errors[] = "L'email est requis";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "L'email n'est pas valide";
        }
        
        if (!empty($dateNaissance)) {
            if (!verifierAge($dateNaissance, 18)) {
                $errors[] = "Le contact doit avoir au moins 18 ans et la date ne peut pas être dans le futur";
            }
        }
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact) && $existingContact[0]['id_contact'] != $id) {
                $errors[] = "Cet email est déjà utilisé par un autre contact";
            }
        }
        
        if (!empty($noClient)) {
            $existingNoClient = $db->select('contact', ['id_compte' => $idCompte, 'no_client' => $noClient]);
            if (!empty($existingNoClient) && $existingNoClient[0]['id_contact'] != $id) {
                $errors[] = "Ce numéro client est déjà utilisé";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $telephoneFormatted = null;
        if (!empty($telephone)) {
            if (substr($telephone, 0, 3) === '261') {
                $telephoneFormatted = $telephone;
            } else {
                $telephoneFormatted = formatPhoneNumber($telephone);
            }
        }
        
        $telPortableFormatted = null;
        if (!empty($telPortable)) {
            if (substr($telPortable, 0, 3) === '261') {
                $telPortableFormatted = $telPortable;
            } else {
                $telPortableFormatted = formatPhoneNumber($telPortable);
            }
        }
        
        $data = [
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France',
            'no_client' => !empty($noClient) ? $noClient : null,
            'civilite' => $civilite,
            'sexe' => $sexe,
            'tel_portable' => $telPortableFormatted,
            'commentaire' => !empty($commentaire) ? $commentaire : null,
            'commentaire_prive' => !empty($commentairePrive) ? $commentairePrive : null,
            'enseigne' => !empty($enseigne) ? $enseigne : null,
            'numero_siret' => !empty($numeroSiret) ? $numeroSiret : null,
            'mari' => !empty($mari) ? $mari : null,
            'anniversaire_mari' => !empty($anniversaireMari) ? $anniversaireMari : null,
            'femme' => !empty($femme) ? $femme : null,
            'anniversaire_femme' => !empty($anniversaireFemme) ? $anniversaireFemme : null,
            'points_fidelite' => $pointsFidelite,
            'cumul_cadeau' => $cumulCadeau,
            'cumul_achats' => $cumulAchats,
            'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau,
            'quantite_article' => $quantiteArticle,
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
        foreach ($enfantsExistants as $enfant) {
            $db->delete('enfants', $enfant['id'], 'id');
        }
        
        if (isset($_POST['enfants']) && is_array($_POST['enfants'])) {
            foreach ($_POST['enfants'] as $enfant) {
                if (!empty($enfant['prenom']) && !empty($enfant['nom'])) {
                    $db->insert('enfants', [
                        'contact_id' => $id,
                        'nom' => trim($enfant['nom']),
                        'prenom' => trim($enfant['prenom']),
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
// RÉCUPÉRATION D'UN CONTACT POUR L'ÉDITION (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        
        if (empty($id)) {
            echo json_encode(['error' => 'ID de contact manquant']);
            exit;
        }
        
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        
        if (empty($contact)) {
            echo json_encode(['error' => 'Contact non trouvé']);
            exit;
        }
        
        $contact = $contact[0];
        $contact['custom_values'] = getContactCustomValues($id);
        $contact['enfants'] = $db->select('enfants', ['contact_id' => $id], '*', 'date_anniversaire ASC');
        
        echo json_encode($contact);
        
    } catch (Exception $e) {
        error_log("ERREUR GET_CONTACT: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DES CHAMPS PERSONNALISÉS D'UN CONTACT (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact_fields' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        if (empty($contact)) {
            echo json_encode(['error' => 'Contact non trouvé']);
            exit;
        }
        
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
// TRAITEMENT DE L'IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $file = $_FILES['fichier'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'upload du fichier']);
            exit;
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xls', 'xlsx'])) {
            echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez CSV, XLS ou XLSX']);
            exit;
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']);
            exit;
        }
        
        $firstLine = fgets($handle);
        rewind($handle);
        
        $separators = [';', ',', "\t", '|'];
        $separator = ',';
        $maxCount = 0;
        
        foreach ($separators as $testSep) {
            $count = substr_count($firstLine, $testSep);
            if ($count > $maxCount) {
                $maxCount = $count;
                $separator = $testSep;
            }
        }
        
        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) {
            echo json_encode(['success' => false, 'error' => 'Format CSV invalide']);
            exit;
        }
        
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        
        $mapping = [
            'prenom' => array_search('prenom', $headers),
            'nom' => array_search('nom', $headers),
            'email' => array_search('email', $headers),
            'telephone' => array_search('telephone', $headers),
            'ville' => array_search('ville', $headers),
            'adresse' => array_search('adresse', $headers),
            'code_postal' => array_search('code_postal', $headers),
            'pays' => array_search('pays', $headers),
            'date_naissance' => array_search('date_naissance', $headers),
            'no_client' => array_search('no_client', $headers),
            'civilite' => array_search('civilite', $headers),
            'sexe' => array_search('sexe', $headers),
            'tel_portable' => array_search('tel_portable', $headers),
            'commentaire' => array_search('commentaire', $headers),
            'commentaire_prive' => array_search('commentaire_prive', $headers),
            'enseigne' => array_search('enseigne', $headers),
            'numero_siret' => array_search('numero_siret', $headers),
            'mari' => array_search('mari', $headers),
            'anniversaire_mari' => array_search('anniversaire_mari', $headers),
            'femme' => array_search('femme', $headers),
            'anniversaire_femme' => array_search('anniversaire_femme', $headers),
            'points_fidelite' => array_search('points_fidelite', $headers),
            'cumul_cadeau' => array_search('cumul_cadeau', $headers),
            'cumul_achats' => array_search('cumul_achats', $headers),
            'cumul_achat_avant_cadeau' => array_search('cumul_achat_avant_cadeau', $headers),
            'quantite_article' => array_search('quantite_article', $headers),
            'nombre_ticket' => array_search('nombre_ticket', $headers),
            'identifiant_ecommerce' => array_search('identifiant_ecommerce', $headers),
            'valeur_coupon' => array_search('valeur_coupon', $headers),
            'date_fin_validite_coupon' => array_search('date_fin_validite_coupon', $headers)
        ];
        
        if ($mapping['prenom'] === false || $mapping['nom'] === false || $mapping['email'] === false) {
            echo json_encode(['success' => false, 'error' => 'Colonnes requises manquantes: prenom, nom, email']);
            exit;
        }
        
        $importedCount = 0;
        $existingCount = 0;
        $errorCount = 0;
        $errors = [];
        
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
            
            if (empty($prenom) || empty($nom) || empty($email)) {
                $errorCount++;
                continue;
            }
            
            if (!empty($dateNaissance)) {
                $dateFormats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y'];
                $dateConverted = null;
                
                foreach ($dateFormats as $format) {
                    $dateObj = DateTime::createFromFormat($format, $dateNaissance);
                    if ($dateObj) {
                        $dateConverted = $dateObj->format('Y-m-d');
                        break;
                    }
                }
                
                if ($dateConverted) {
                    $dateNaissance = $dateConverted;
                } else {
                    $timestamp = strtotime($dateNaissance);
                    if ($timestamp !== false) {
                        $dateNaissance = date('Y-m-d', $timestamp);
                    } else {
                        $dateNaissance = null;
                    }
                }
            }
            
            if (!empty($anniversaireMari)) {
                $timestamp = strtotime($anniversaireMari);
                if ($timestamp !== false) {
                    $anniversaireMari = date('Y-m-d', $timestamp);
                } else {
                    $anniversaireMari = null;
                }
            }
            
            if (!empty($anniversaireFemme)) {
                $timestamp = strtotime($anniversaireFemme);
                if ($timestamp !== false) {
                    $anniversaireFemme = date('Y-m-d', $timestamp);
                } else {
                    $anniversaireFemme = null;
                }
            }
            
            if (!empty($dateFinValiditeCoupon)) {
                $timestamp = strtotime($dateFinValiditeCoupon);
                if ($timestamp !== false) {
                    $dateFinValiditeCoupon = date('Y-m-d', $timestamp);
                } else {
                    $dateFinValiditeCoupon = null;
                }
            }
            
            if (!empty($dateNaissance)) {
                if (!verifierAge($dateNaissance, 18)) {
                    $errorCount++;
                    continue;
                }
            }
            
            $existing = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existing)) {
                $existingCount++;
                continue;
            }
            
            $telephoneFormatted = null;
            if (!empty($telephone)) {
                if (substr($telephone, 0, 3) === '261') {
                    $telephoneFormatted = $telephone;
                } else {
                    $telephoneFormatted = formatPhoneNumber($telephone);
                }
            }
            
            $telPortableFormatted = null;
            if (!empty($telPortable)) {
                if (substr($telPortable, 0, 3) === '261') {
                    $telPortableFormatted = $telPortable;
                } else {
                    $telPortableFormatted = formatPhoneNumber($telPortable);
                }
            }
            
            $data = [
                'id_compte' => $idCompte,
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
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
                'points_fidelite' => $pointsFidelite,
                'cumul_cadeau' => $cumulCadeau,
                'cumul_achats' => $cumulAchats,
                'cumul_achat_avant_cadeau' => $cumulAchatAvantCadeau,
                'quantite_article' => $quantiteArticle,
                'nombre_ticket' => $nombreTicket,
                'identifiant_ecommerce' => !empty($identifiantEcommerce) ? $identifiantEcommerce : null,
                'valeur_coupon' => !empty($valeurCoupon) ? $valeurCoupon : null,
                'date_fin_validite_coupon' => !empty($dateFinValiditeCoupon) ? $dateFinValiditeCoupon : null
            ];
            
            try {
                $db->insert('contact', $data);
                $importedCount++;
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = $e->getMessage();
            }
        }
        fclose($handle);
        
        if ($importedCount > 0) {
            $message = "$importedCount contact(s) importé(s) avec succès.";
            if ($existingCount > 0) $message .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $message .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) {
                $message .= " Détails: " . implode('; ', array_slice($errors, 0, 3));
            }
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            $errorMsg = "Aucun contact importé.";
            if ($existingCount > 0) $errorMsg .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $errorMsg .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) {
                $errorMsg .= " Erreurs: " . implode('; ', array_slice($errors, 0, 3));
            }
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR IMPORT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Messages flash
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
        .toast-notification.warning .toast-content { background: #f59e0b; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .contact-row.hidden-row { display: none; }
        
        #addContactModal,
        #editContactModal,
        #importModal,
        #addCustomFieldModal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background-color: rgba(0, 0, 0, 0.6) !important;
            backdrop-filter: blur(3px);
            z-index: 9999 !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
            padding: 20px !important;
            margin: 0 !important;
            overflow: hidden !important;
        }

        .modal-add-contact,
        .modal-edit-contact,
        .modal-import-csv,
        .modal-custom-field {
            position: relative !important;
            width: 95% !important;
            max-width: 1400px !important;
            height: 92vh !important;
            max-height: 92vh !important;
            margin: 0 auto !important;
            border-radius: 16px !important;
            background: #f8fafc !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            z-index: 10000 !important;
            animation: modalSlideIn 0.3s ease-out !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(30px) scale(0.98); opacity: 0; }
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
            padding: 24px 32px !important;
            overflow: hidden !important;
            height: 100% !important;
            width: 100% !important;
        }

        .modal-header-sticky {
            flex-shrink: 0 !important;
            background: white !important;
            padding: 16px 24px !important;
            margin: -24px -32px 16px -32px !important;
            border-bottom: 2px solid #e5e7eb !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 20 !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
            border-radius: 16px 16px 0 0 !important;
        }

        .modal-scroll-content {
            flex: 1 !important;
            overflow-y: auto !important;
            padding: 8px 4px 16px 4px !important;
            min-height: 0 !important;
            margin: 0 -4px !important;
        }

        .modal-scroll-content::-webkit-scrollbar {
            width: 8px;
        }
        .modal-scroll-content::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .modal-scroll-content::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .modal-scroll-content::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .modal-footer-sticky {
            flex-shrink: 0 !important;
            background: white !important;
            padding: 16px 24px !important;
            margin: 16px -32px -24px -32px !important;
            border-top: 2px solid #e5e7eb !important;
            position: sticky !important;
            bottom: 0 !important;
            z-index: 20 !important;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.06) !important;
            border-radius: 0 0 16px 16px !important;
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

        .modal-scroll-content input,
        .modal-scroll-content select,
        .modal-scroll-content textarea {
            background: white !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 10px 14px !important;
            transition: all 0.2s ease !important;
            width: 100% !important;
        }

        .modal-scroll-content input:focus,
        .modal-scroll-content select:focus,
        .modal-scroll-content textarea:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
            outline: none !important;
        }

        .modal-scroll-content label {
            font-weight: 500 !important;
            color: #1e293b !important;
            margin-bottom: 4px !important;
            display: block !important;
        }

        .section-title {
            font-size: 16px !important;
            font-weight: 600 !important;
            color: #1e293b !important;
            margin: 16px 0 12px 0 !important;
            padding-bottom: 8px !important;
            border-bottom: 2px solid #e2e8f0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .enfant-item {
            background: #f9fafb !important;
            padding: 12px !important;
            border-radius: 8px !important;
            border: 1px solid #e5e7eb !important;
            position: relative !important;
        }

        .enfant-item .remove-enfant {
            position: absolute !important;
            top: 6px !important;
            right: 6px !important;
            background: #fee2e2 !important;
            color: #dc2626 !important;
            border: none !important;
            border-radius: 50% !important;
            width: 24px !important;
            height: 24px !important;
            cursor: pointer !important;
            font-size: 14px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .enfant-item .remove-enfant:hover {
            background: #fecaca !important;
        }

        .btn-primary {
            background: #3b82f6 !important;
            color: white !important;
            padding: 10px 24px !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
        }

        .btn-primary:hover {
            background: #2563eb !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: white !important;
            color: #1e293b !important;
            padding: 10px 24px !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
            transition: all 0.2s ease !important;
            border: 1px solid #e2e8f0 !important;
            cursor: pointer !important;
        }

        .btn-secondary:hover {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
        }

        .btn-success {
            background: #10b981 !important;
            color: white !important;
            padding: 12px 28px !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
        }

        .btn-success:hover {
            background: #059669 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-sm {
            padding: 4px 12px !important;
            font-size: 12px !important;
        }

        .custom-field-badge {
            display: inline-block;
            background-color: #f3f4f6;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }
        .custom-field-badge strong {
            font-weight: 600;
            color: #4b5563;
        }

        .child-badge {
            display: inline-block;
            background-color: #dbeafe;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }

        .child-badge strong {
            font-weight: 600;
            color: #1e40af;
        }

        .phone-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }
        .date-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        @media (max-width: 768px) {
            #addContactModal,
            #editContactModal,
            #importModal,
            #addCustomFieldModal {
                padding: 8px !important;
            }
            .modal-add-contact,
            .modal-edit-contact,
            .modal-import-csv,
            .modal-custom-field {
                width: 100% !important;
                height: 98vh !important;
                max-height: 98vh !important;
                border-radius: 12px !important;
            }
            .modal-add-contact .p-6,
            .modal-edit-contact .p-6,
            .modal-import-csv .p-6,
            .modal-custom-field .p-6 {
                padding: 16px !important;
            }
            .modal-header-sticky {
                padding: 12px 16px !important;
                margin: -16px -16px 12px -16px !important;
            }
            .modal-footer-sticky {
                padding: 12px 16px !important;
                margin: 12px -16px -16px -16px !important;
            }
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes contacts</h1>
            <p class="text-gray-500">Gérez votre base de contacts</p>
        </div>
        <div class="space-x-2">
            <button type="button" onclick="openAddContactModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Ajouter un contact
            </button>
            <button type="button" onclick="openImportModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-upload mr-2"></i>Importer CSV
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Total des contacts</span><span class="text-2xl font-bold text-gray-800 ml-2" id="totalCount"><?= $totalContacts ?></span></div>
                <div class="text-gray-400"><i class="fas fa-users text-2xl"></i></div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" placeholder="Rechercher par nom, email, téléphone ou ville..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
            </div>
            <div class="flex justify-between items-center mt-2">
                <div class="flex space-x-2 flex-wrap gap-2">
                    <button class="filter-btn active px-3 py-1 text-xs rounded-full bg-blue-600 text-white" data-filter="all">Tous</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 transition" data-filter="email">Avec email</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 transition" data-filter="phone">Avec téléphone</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-red-100 text-red-700 hover:bg-red-200 transition" data-filter="blacklisted">
                        <i class="fas fa-ban mr-1"></i>Blacklistés
                    </button>
                </div>
                <span id="filteredCount" class="text-xs text-gray-500"></span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Infos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Enfants</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fidélité</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Inscription</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody">
                    <?php if (empty($contacts)): ?>
                        <tr id="noContactsRow">
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-address-book text-4xl mb-2 block"></i>
                                Aucun contact pour le moment.
                                <button type="button" onclick="openAddContactModal()" class="text-blue-600 block mt-2">Ajouter votre premier contact →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): 
                            $isBlacklisted = in_array($contact['id_contact'], $blacklistedIds);
                            $customVals = $contactsCustomValues[$contact['id_contact']] ?? [];
                            $enfants = $contactsEnfants[$contact['id_contact']] ?? [];
                        ?>
                            <tr class="contact-row hover:bg-gray-50 transition <?= $isBlacklisted ? 'bg-red-50' : '' ?>" 
                                data-name="<?= strtolower(htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($contact['email'] ?? '')) ?>"
                                data-phone="<?= strtolower(htmlspecialchars($contact['telephone'] ?? '')) ?>"
                                data-city="<?= strtolower(htmlspecialchars($contact['ville'] ?? '')) ?>"
                                data-has-email="<?= !empty($contact['email']) ? 'true' : 'false' ?>"
                                data-has-phone="<?= !empty($contact['telephone']) ? 'true' : 'false' ?>"
                                data-blacklisted="<?= $isBlacklisted ? 'true' : 'false' ?>"
                                data-contact-id="<?= $contact['id_contact'] ?>">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></div>
                                    <?php if (!empty($contact['no_client'])): ?>
                                        <div class="text-xs text-gray-500">N°: <?= htmlspecialchars($contact['no_client']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['civilite']) || !empty($contact['sexe'])): ?>
                                        <div class="text-xs text-gray-500">
                                            <?= htmlspecialchars($contact['civilite'] ?? '') ?> 
                                            <?= !empty($contact['sexe']) ? ($contact['sexe'] === 'M' ? '♂' : '♀') : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm"><?= htmlspecialchars($contact['email'] ?? '-') ?></div>
                                    <div class="text-sm"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></div>
                                    <?php if (!empty($contact['tel_portable'])): ?>
                                        <div class="text-sm text-gray-500">P: <?= htmlspecialchars($contact['tel_portable']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($contact['enseigne'])): ?>
                                        <div class="text-xs"><strong>Enseigne:</strong> <?= htmlspecialchars($contact['enseigne']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['ville'])): ?>
                                        <div class="text-xs"><?= htmlspecialchars($contact['ville']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($customVals)): ?>
                                        <?php foreach (array_slice($customVals, 0, 2) as $field): ?>
                                            <span class="custom-field-badge">
                                                <strong><?= htmlspecialchars($field['label']) ?>:</strong> <?= htmlspecialchars(substr($field['value'], 0, 20)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($customVals) > 2): ?>
                                            <span class="text-xs text-gray-400">+<?= count($customVals) - 2 ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['mari']) || !empty($contact['femme'])): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php if (!empty($contact['mari'])): ?>👨 <?= htmlspecialchars($contact['mari']) ?><?php endif; ?>
                                            <?php if (!empty($contact['femme'])): ?>👩 <?= htmlspecialchars($contact['femme']) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($enfants)): ?>
                                        <?php foreach ($enfants as $enfant): ?>
                                            <span class="child-badge">
                                                <?= htmlspecialchars($enfant['prenom']) ?> 
                                                (<?= $enfant['sexe'] === 'M' ? '♂' : '♀' ?>)
                                                <?php if (!empty($enfant['date_anniversaire'])): ?>
                                                    <?= date('d/m/Y', strtotime($enfant['date_anniversaire'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($contact['points_fidelite'] > 0 || $contact['cumul_achats'] > 0): ?>
                                        <div class="text-xs">
                                            <span class="font-medium">Points:</span> <?= $contact['points_fidelite'] ?>
                                        </div>
                                        <div class="text-xs">
                                            <span class="font-medium">Achats:</span> <?= number_format($contact['cumul_achats'], 2) ?> €
                                        </div>
                                        <?php if (!empty($contact['valeur_coupon'])): ?>
                                            <div class="text-xs text-green-600">
                                                🎫 <?= htmlspecialchars($contact['valeur_coupon']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($contact['date_inscription'])) ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($isBlacklisted): ?>
                                        <button onclick="openUnblacklistModal('<?= $contact['id_contact'] ?>')" class="px-2 py-1 rounded text-xs bg-red-100 text-red-700 hover:bg-red-200 transition cursor-pointer flex items-center gap-1">
                                            <i class="fas fa-ban"></i> Blacklisté
                                        </button>
                                    <?php else: ?>
                                        <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 space-x-2">
                                    <button type="button" onclick="openEditContactModal('<?= $contact['id_contact'] ?>')" class="text-blue-600 hover:text-blue-800 transition" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" onclick="showDeleteModal('<?= $contact['id_contact'] ?>')" class="text-red-600 hover:text-red-800 transition" title="Supprimer">
                                        <i class="fas fa-trash"></i>
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

<!-- MODAL AJOUT CONTACT -->
<div id="addContactModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-[9999]" style="display: none;">
    <div class="modal-add-contact">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-2 rounded-full mr-3">
                            <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Ajouter un contact</h3>
                    </div>
                    <button type="button" onclick="closeAddContactModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <form id="addContactForm" method="POST">
                <input type="hidden" name="action_add_contact" value="1">
                <input type="hidden" id="tempCustomFields" name="temp_custom_fields" value="">
                <div class="modal-scroll-content">
                    <div class="section-title"><i class="fas fa-id-card text-blue-500"></i> Identité</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Civilité</label>
                            <select name="civilite" id="add_civilite" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="">--</option>
                                <option value="M.">M.</option>
                                <option value="Mme">Mme</option>
                                <option value="Mlle">Mlle</option>
                                <option value="Dr">Dr</option>
                                <option value="Pr">Pr</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Sexe</label>
                            <select name="sexe" id="add_sexe" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="">--</option>
                                <option value="M">Masculin</option>
                                <option value="F">Féminin</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                            <input type="text" name="prenom" id="add_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                            <input type="text" name="nom" id="add_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-phone text-green-500"></i> Coordonnées</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                            <input type="email" name="email" id="add_email" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone fixe</label>
                            <input type="tel" name="telephone" id="add_telephone" placeholder="ex: 0612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone portable</label>
                            <input type="tel" name="tel_portable" id="add_tel_portable" placeholder="ex: 0612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-map-marker-alt text-red-500"></i> Adresse</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                            <input type="text" name="adresse" id="add_adresse" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                            <input type="text" name="code_postal" id="add_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                            <input type="text" name="ville" id="add_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pays</label>
                            <input type="text" name="pays" id="add_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-briefcase text-purple-500"></i> Informations client</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">N° client</label>
                            <input type="text" name="no_client" id="add_no_client" placeholder="ex: CLT-2026-001" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Enseigne</label>
                            <input type="text" name="enseigne" id="add_enseigne" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">N° SIRET</label>
                            <input type="text" name="numero_siret" id="add_numero_siret" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID E-commerce</label>
                            <input type="text" name="identifiant_ecommerce" id="add_identifiant_ecommerce" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-birthday-cake text-yellow-500"></i> Date de naissance</div>
                    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                            <input type="date" name="date_naissance" id="add_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                            <p class="date-hint">📅 Le contact doit avoir au moins 18 ans. Les dates futures sont interdites.</p>
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-heart text-pink-500"></i> Conjoint(e)</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom du mari</label>
                            <input type="text" name="mari" id="add_mari" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Anniversaire mari</label>
                            <input type="date" name="anniversaire_mari" id="add_anniversaire_mari" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la femme</label>
                            <input type="text" name="femme" id="add_femme" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Anniversaire femme</label>
                            <input type="date" name="anniversaire_femme" id="add_anniversaire_femme" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-child text-indigo-500"></i> Enfants</div>
                    <div id="enfantsContainer" class="space-y-3">
                        <div id="noEnfantsMessage" class="text-center py-3 text-gray-400 text-sm">
                            <i class="fas fa-info-circle mr-1"></i>
                            Aucun enfant enregistré.
                            <button type="button" onclick="addEnfantRow('add')" class="text-blue-600 hover:underline">
                                Ajouter un enfant
                            </button>
                        </div>
                    </div>
                    <button type="button" onclick="addEnfantRow('add')" class="mt-2 text-sm text-blue-600 hover:text-blue-800 transition">
                        <i class="fas fa-plus-circle mr-1"></i> Ajouter un enfant
                    </button>
                    <div class="section-title"><i class="fas fa-star text-yellow-500"></i> Programme de fidélité</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Points de fidélité</label>
                            <input type="number" name="points_fidelite" id="add_points_fidelite" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul cadeau (€)</label>
                            <input type="number" step="0.01" name="cumul_cadeau" id="add_cumul_cadeau" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul achats (€)</label>
                            <input type="number" step="0.01" name="cumul_achats" id="add_cumul_achats" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul avant cadeau (€)</label>
                            <input type="number" step="0.01" name="cumul_achat_avant_cadeau" id="add_cumul_achat_avant_cadeau" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Qté articles</label>
                            <input type="number" name="quantite_article" id="add_quantite_article" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nb tickets</label>
                            <input type="number" name="nombre_ticket" id="add_nombre_ticket" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Valeur coupon</label>
                            <input type="text" name="valeur_coupon" id="add_valeur_coupon" placeholder="ex: 10,00 € ou 10€" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fin validité coupon</label>
                            <input type="date" name="date_fin_validite_coupon" id="add_date_fin_validite_coupon" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-comment text-gray-500"></i> Commentaires</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Commentaire</label>
                            <textarea name="commentaire" id="add_commentaire" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Commentaire privé</label>
                            <textarea name="commentaire_prive" id="add_commentaire_prive" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-cog text-gray-500"></i> Champs personnalisés <span class="text-xs text-gray-400 font-normal">(max 10)</span></div>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-sm text-gray-500" id="customFieldCountAdd">0 / 10</span>
                        <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" 
                                class="text-sm text-blue-600 hover:text-blue-800 transition flex items-center gap-1 add-field-btn">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="addCustomFieldsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2 text-center py-3 text-gray-400 text-sm" id="noCustomFieldsMessage">
                            <i class="fas fa-info-circle mr-1"></i>
                            Aucun champ personnalisé.
                            <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" 
                                    class="text-blue-600 hover:underline">
                                Ajouter votre premier champ
                            </button>
                        </div>
                    </div>
                    <div id="tempFieldsList" class="mt-3 flex flex-wrap gap-2"></div>
                </div>
                <div class="modal-footer-sticky">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddContactModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Enregistrer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL MODIFICATION CONTACT -->
<div id="editContactModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-[9999]" style="display: none;">
    <div class="modal-edit-contact">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-yellow-100 p-2 rounded-full mr-3">
                            <i class="fas fa-edit text-yellow-600 text-xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Modifier le contact</h3>
                    </div>
                    <button type="button" onclick="closeEditContactModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <form id="editContactForm" method="POST">
                <input type="hidden" name="action_edit_contact" value="1">
                <input type="hidden" name="id_contact" id="edit_id_contact">
                <div class="modal-scroll-content">
                    <!-- MÊME STRUCTURE QUE LE MODAL AJOUT -->
                    <div class="section-title"><i class="fas fa-id-card text-blue-500"></i> Identité</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Civilité</label>
                            <select name="civilite" id="edit_civilite" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="">--</option>
                                <option value="M.">M.</option>
                                <option value="Mme">Mme</option>
                                <option value="Mlle">Mlle</option>
                                <option value="Dr">Dr</option>
                                <option value="Pr">Pr</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Sexe</label>
                            <select name="sexe" id="edit_sexe" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="">--</option>
                                <option value="M">Masculin</option>
                                <option value="F">Féminin</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                            <input type="text" name="prenom" id="edit_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                            <input type="text" name="nom" id="edit_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-phone text-green-500"></i> Coordonnées</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                            <input type="email" name="email" id="edit_email" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone fixe</label>
                            <input type="tel" name="telephone" id="edit_telephone" placeholder="ex: 0612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone portable</label>
                            <input type="tel" name="tel_portable" id="edit_tel_portable" placeholder="ex: 0612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-map-marker-alt text-red-500"></i> Adresse</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                            <input type="text" name="adresse" id="edit_adresse" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                            <input type="text" name="code_postal" id="edit_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                            <input type="text" name="ville" id="edit_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pays</label>
                            <input type="text" name="pays" id="edit_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-briefcase text-purple-500"></i> Informations client</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">N° client</label>
                            <input type="text" name="no_client" id="edit_no_client" placeholder="ex: CLT-2026-001" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Enseigne</label>
                            <input type="text" name="enseigne" id="edit_enseigne" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">N° SIRET</label>
                            <input type="text" name="numero_siret" id="edit_numero_siret" placeholder="14 chiffres" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID E-commerce</label>
                            <input type="text" name="identifiant_ecommerce" id="edit_identifiant_ecommerce" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-birthday-cake text-yellow-500"></i> Date de naissance</div>
                    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                            <input type="date" name="date_naissance" id="edit_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                            <p class="date-hint">📅 Le contact doit avoir au moins 18 ans.</p>
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-heart text-pink-500"></i> Conjoint(e)</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom du mari</label>
                            <input type="text" name="mari" id="edit_mari" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Anniversaire mari</label>
                            <input type="date" name="anniversaire_mari" id="edit_anniversaire_mari" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la femme</label>
                            <input type="text" name="femme" id="edit_femme" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Anniversaire femme</label>
                            <input type="date" name="anniversaire_femme" id="edit_anniversaire_femme" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-child text-indigo-500"></i> Enfants</div>
                    <div id="editEnfantsContainer" class="space-y-3">
                        <div id="editNoEnfantsMessage" class="text-center py-3 text-gray-400 text-sm">
                            <i class="fas fa-info-circle mr-1"></i>
                            Aucun enfant enregistré.
                            <button type="button" onclick="addEnfantRow('edit')" class="text-blue-600 hover:underline">
                                Ajouter un enfant
                            </button>
                        </div>
                    </div>
                    <button type="button" onclick="addEnfantRow('edit')" class="mt-2 text-sm text-blue-600 hover:text-blue-800 transition">
                        <i class="fas fa-plus-circle mr-1"></i> Ajouter un enfant
                    </button>
                    <div class="section-title"><i class="fas fa-star text-yellow-500"></i> Programme de fidélité</div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Points de fidélité</label>
                            <input type="number" name="points_fidelite" id="edit_points_fidelite" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul cadeau (€)</label>
                            <input type="number" step="0.01" name="cumul_cadeau" id="edit_cumul_cadeau" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul achats (€)</label>
                            <input type="number" step="0.01" name="cumul_achats" id="edit_cumul_achats" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cumul avant cadeau (€)</label>
                            <input type="number" step="0.01" name="cumul_achat_avant_cadeau" id="edit_cumul_achat_avant_cadeau" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Qté articles</label>
                            <input type="number" name="quantite_article" id="edit_quantite_article" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nb tickets</label>
                            <input type="number" name="nombre_ticket" id="edit_nombre_ticket" value="0" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Valeur coupon</label>
                            <input type="text" name="valeur_coupon" id="edit_valeur_coupon" placeholder="ex: 10,00 € ou 10€" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fin validité coupon</label>
                            <input type="date" name="date_fin_validite_coupon" id="edit_date_fin_validite_coupon" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-comment text-gray-500"></i> Commentaires</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Commentaire public</label>
                            <textarea name="commentaire" id="edit_commentaire" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Commentaire privé</label>
                            <textarea name="commentaire_prive" id="edit_commentaire_prive" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
                        </div>
                    </div>
                    <div class="section-title"><i class="fas fa-cog text-gray-500"></i> Champs personnalisés <span class="text-xs text-gray-400 font-normal">(max 10)</span></div>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-sm text-gray-500" id="customFieldCountEdit">0 / 10</span>
                        <button type="button" onclick="openAddCustomFieldModalFromEdit()" 
                                class="text-sm text-blue-600 hover:text-blue-800 transition flex items-center gap-1 add-field-btn">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="editCustomFieldsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
                </div>
                <div class="modal-footer-sticky">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditContactModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Enregistrer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL CHAMP PERSONNALISÉ -->
<div id="addCustomFieldModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-[9999]" style="display: none;">
    <div class="modal-custom-field">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-2 rounded-full mr-3">
                            <i class="fas fa-plus-circle text-blue-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Ajouter un champ personnalisé</h3>
                    </div>
                    <button type="button" onclick="closeAddCustomFieldModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <form id="addCustomFieldForm">
                <input type="hidden" id="custom_field_contact_id" value="">
                <input type="hidden" id="custom_field_mode" value="temp">
                <div class="modal-scroll-content">
                    <div class="max-w-2xl mx-auto" style="max-width: 100% !important; padding: 0 12px;">
                        <div style="margin-bottom: 20px !important;">
                            <label style="font-size: 15px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 6px !important; display: block !important;">
                                Nom technique <span style="color: #ef4444; font-size: 16px !important;">*</span>
                            </label>
                            <input type="text" id="new_field_name" required 
                                   style="width: 100% !important; padding: 12px 16px !important; font-size: 15px !important; border-radius: 10px !important; border: 1.5px solid #e2e8f0 !important; background: white !important;"
                                   placeholder="ex: societe, fonction">
                            <p style="font-size: 13px !important; color: #6b7280 !important; margin-top: 6px !important;">Sans accent, sans espace (utilisez _ )</p>
                        </div>
                        <div style="margin-bottom: 20px !important;">
                            <label style="font-size: 15px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 6px !important; display: block !important;">
                                Libellé <span style="color: #ef4444; font-size: 16px !important;">*</span>
                            </label>
                            <input type="text" id="new_field_label" required 
                                   style="width: 100% !important; padding: 12px 16px !important; font-size: 15px !important; border-radius: 10px !important; border: 1.5px solid #e2e8f0 !important; background: white !important;"
                                   placeholder="ex: Société, Fonction">
                        </div>
                        <div style="margin-bottom: 20px !important;">
                            <label style="font-size: 15px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 6px !important; display: block !important;">Type de champ</label>
                            <select id="new_field_type" 
                                    style="width: 100% !important; padding: 12px 16px !important; font-size: 15px !important; border-radius: 10px !important; border: 1.5px solid #e2e8f0 !important; background: white !important;">
                                <option value="text">Texte court</option>
                                <option value="textarea">Zone texte</option>
                                <option value="number">Nombre</option>
                                <option value="date">Date</option>
                                <option value="email">Email</option>
                                <option value="tel">Téléphone</option>
                                <option value="select">Liste déroulante</option>
                            </select>
                        </div>
                        <div id="new_field_options_div" style="display:none; margin-bottom: 20px !important;">
                            <label style="font-size: 15px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 6px !important; display: block !important;">
                                Options <span style="color: #ef4444; font-size: 16px !important;">*</span>
                            </label>
                            <input type="text" id="new_field_options" 
                                   style="width: 100% !important; padding: 12px 16px !important; font-size: 15px !important; border-radius: 10px !important; border: 1.5px solid #e2e8f0 !important; background: white !important;"
                                   placeholder="ex: Option 1|Option 2|Option 3">
                            <p style="font-size: 13px !important; color: #6b7280 !important; margin-top: 6px !important;">Séparez les options par <strong style="color: #3b82f6 !important;">|</strong></p>
                        </div>
                        <div id="new_field_value_div" style="margin-bottom: 20px !important;">
                            <label style="font-size: 15px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 6px !important; display: block !important;">
                                Valeur (optionnel)
                            </label>
                            <input type="text" id="new_field_value" 
                                   style="width: 100% !important; padding: 12px 16px !important; font-size: 15px !important; border-radius: 10px !important; border: 1.5px solid #e2e8f0 !important; background: white !important;"
                                   placeholder="Valeur du champ">
                        </div>
                    </div>
                </div>
                <div class="modal-footer-sticky">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddCustomFieldModal()" class="btn-secondary" style="padding: 12px 28px !important; font-size: 15px !important;">Annuler</button>
                        <button type="submit" id="createFieldBtn" class="btn-primary" style="padding: 12px 28px !important; font-size: 15px !important;">
                            <i class="fas fa-plus mr-2"></i>Ajouter le champ
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL IMPORT CSV -->
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-[9999]" style="display: none;">
    <div class="modal-import-csv">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-green-100 p-2 rounded-full mr-3">
                            <i class="fas fa-file-import text-green-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Importer des contacts</h3>
                    </div>
                    <button type="button" onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <div class="modal-scroll-content">
                    <div style="max-width: 100% !important; padding: 0 12px;">
                        <div class="import-info-box" style="background: #eff6ff !important; padding: 24px 28px !important; border-radius: 12px !important; margin-bottom: 24px !important; border: 1px solid #bfdbfe !important;">
                            <h4 style="font-size: 18px !important; font-weight: 600 !important; color: #1e40af !important; margin-bottom: 12px !important;">
                                <i class="fas fa-info-circle mr-2"></i>Format du fichier
                            </h4>
                            <ul style="font-size: 15px !important; color: #1e3a5f !important; list-style: none !important; padding: 0 !important; margin: 0 !important;">
                                <li style="padding: 6px 0 !important; display: flex !important; align-items: flex-start !important; line-height: 1.5 !important;">
                                    <i class="fas fa-check-circle text-green-500" style="margin-right: 10px !important; margin-top: 2px !important;"></i>
                                    <span>Colonnes requises : <strong>prenom, nom, email</strong></span>
                                </li>
                                <li style="padding: 6px 0 !important; display: flex !important; align-items: flex-start !important; line-height: 1.5 !important;">
                                    <i class="fas fa-check-circle text-green-500" style="margin-right: 10px !important; margin-top: 2px !important;"></i>
                                    <span>Colonnes optionnelles : telephone, tel_portable, ville, adresse, code_postal, pays, date_naissance, no_client, civilite, sexe, enseigne, numero_siret, mari, anniversaire_mari, femme, anniversaire_femme, points_fidelite, cumul_cadeau, cumul_achats, cumul_achat_avant_cadeau, quantite_article, nombre_ticket, identifiant_ecommerce, valeur_coupon, date_fin_validite_coupon, commentaire, commentaire_prive</span>
                                </li>
                                <li style="padding: 6px 0 !important; display: flex !important; align-items: flex-start !important; line-height: 1.5 !important;">
                                    <i class="fas fa-check-circle text-green-500" style="margin-right: 10px !important; margin-top: 2px !important;"></i>
                                    <span>Séparateur : point-virgule (;) ou virgule (,)</span>
                                </li>
                                <li style="padding: 6px 0 !important; display: flex !important; align-items: flex-start !important; line-height: 1.5 !important;">
                                    <i class="fas fa-check-circle text-green-500" style="margin-right: 10px !important; margin-top: 2px !important;"></i>
                                    <span>Les contacts déjà existants (même email) sont ignorés</span>
                                </li>
                                <li style="padding: 6px 0 !important; display: flex !important; align-items: flex-start !important; line-height: 1.5 !important;">
                                    <i class="fas fa-info-circle text-blue-500" style="margin-right: 10px !important; margin-top: 2px !important;"></i>
                                    <span>La date de naissance doit être au format YYYY-MM-DD et le contact doit avoir au moins 18 ans</span>
                                </li>
                            </ul>
                        </div>
                        <div class="file-input-wrapper">
                            <label style="display: block !important; font-size: 16px !important; font-weight: 600 !important; color: #1e293b !important; margin-bottom: 8px !important;">
                                <i class="fas fa-file mr-2"></i>Fichier CSV/Excel
                            </label>
                            <input type="file" name="fichier" id="importFile" accept=".csv,.xls,.xlsx" required
                                   style="width: 100% !important; padding: 16px 20px !important; font-size: 15px !important; min-height: 60px !important; border: 2px dashed #d1d5db !important; border-radius: 12px !important; background: #f9fafb !important; cursor: pointer !important;">
                            <p class="file-hint" style="font-size: 14px !important; color: #6b7280 !important; margin-top: 8px !important;">
                                <i class="fas fa-info-circle mr-1"></i>Formats acceptés : CSV, XLS, XLSX (Taille max : 10MB)
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer-sticky">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeImportModal()" class="btn-secondary" style="padding: 12px 28px !important; font-size: 15px !important;">Annuler</button>
                        <button type="submit" id="importSubmitBtn" class="btn-success"><i class="fas fa-upload mr-2"></i>Importer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL BLACKLIST -->
<div id="unblacklistModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-[9999]" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                <i class="fas fa-unlock-alt text-green-600 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Débloquer le contact</h3>
            <p class="text-gray-500 mb-6">Êtes-vous sûr de vouloir retirer ce contact de la blacklist ?</p>
            <form method="POST" action="?page=contacts/unblacklist">
                <input type="hidden" name="id_contact" id="unblacklistContactId">
                <div class="flex space-x-3">
                    <button type="button" onclick="closeUnblacklistModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition">Débloquer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL SUPPRESSION -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-[9999]" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Confirmer la suppression</h3>
            <p class="text-gray-500 mb-6">Êtes-vous sûr de vouloir supprimer ce contact ?</p>
            <p class="text-sm text-gray-400 mb-6">Cette action est irréversible.</p>
            <div class="flex space-x-3">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                <a href="#" id="confirmDeleteBtn" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition text-center">Supprimer</a>
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
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

<?php if ($flashMessage): ?> showToast('<?= addslashes($flashMessage) ?>', 'success'); <?php endif; ?>
<?php if ($flashError): ?> showToast('<?= addslashes($flashError) ?>', 'error'); <?php endif; ?>

let currentContactIdForEdit = null;
let tempCustomFields = [];
let enfantCounter = 0;

function addEnfantRow(mode) {
    const containerId = mode === 'add' ? 'enfantsContainer' : 'editEnfantsContainer';
    const noMsgId = mode === 'add' ? 'noEnfantsMessage' : 'editNoEnfantsMessage';
    const container = document.getElementById(containerId);
    const noMsg = document.getElementById(noMsgId);
    if (noMsg) noMsg.remove();
    const id = ++enfantCounter;
    const div = document.createElement('div');
    div.className = 'enfant-item grid grid-cols-1 md:grid-cols-4 gap-3';
    div.dataset.enfantId = id;
    div.innerHTML = `
        <button type="button" class="remove-enfant" onclick="this.closest('.enfant-item').remove()">×</button>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Nom</label><input type="text" name="enfants[${id}][nom]" placeholder="Nom" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label><input type="text" name="enfants[${id}][prenom]" placeholder="Prénom" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Sexe</label><select name="enfants[${id}][sexe]" class="w-full border border-gray-300 rounded-lg px-3 py-2"><option value="">--</option><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Date anniversaire</label><input type="date" name="enfants[${id}][date_anniversaire]" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
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
        noMsg.className = 'text-center py-3 text-gray-400 text-sm';
        noMsg.innerHTML = `<i class="fas fa-info-circle mr-1"></i>Aucun enfant enregistré. <button type="button" onclick="addEnfantRow('${mode}')" class="text-blue-600 hover:underline">Ajouter un enfant</button>`;
        container.appendChild(noMsg);
        return;
    }
    enfants.forEach((enfant) => {
        const id = ++enfantCounter;
        const div = document.createElement('div');
        div.className = 'enfant-item grid grid-cols-1 md:grid-cols-4 gap-3';
        div.dataset.enfantId = id;
        div.innerHTML = `
            <button type="button" class="remove-enfant" onclick="this.closest('.enfant-item').remove()">×</button>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Nom</label><input type="text" name="enfants[${id}][nom]" value="${escapeHtml(enfant.nom || '')}" placeholder="Nom" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label><input type="text" name="enfants[${id}][prenom]" value="${escapeHtml(enfant.prenom || '')}" placeholder="Prénom" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Sexe</label><select name="enfants[${id}][sexe]" class="w-full border border-gray-300 rounded-lg px-3 py-2"><option value="">--</option><option value="M" ${enfant.sexe === 'M' ? 'selected' : ''}>Masculin</option><option value="F" ${enfant.sexe === 'F' ? 'selected' : ''}>Féminin</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Date anniversaire</label><input type="date" name="enfants[${id}][date_anniversaire]" value="${escapeHtml(enfant.date_anniversaire || '')}" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
        `;
        container.appendChild(div);
    });
}

function addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue) {
    if (tempCustomFields.length >= 10) {
        showToast('Nombre maximum de champs personnalisés atteint (10 max)', 'warning');
        return false;
    }
    if (tempCustomFields.some(f => f.field_name === fieldName)) {
        showToast('Un champ avec ce nom existe déjà', 'warning');
        return false;
    }
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
        return `<span class="temp-field-badge"><i class="fas fa-tag mr-1"></i>${escapeHtml(label)}${escapeHtml(value)}<span class="remove-temp-field" onclick="removeTempField(${index})">×</span></span>`;
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
    const noMsg = container.querySelector('.col-span-2.text-center');
    if (noMsg) noMsg.remove();
    const fieldNameEscaped = escapeHtml(fieldName);
    const fieldLabelEscaped = escapeHtml(fieldLabel);
    const fieldValueEscaped = escapeHtml(fieldValue || '');
    let fieldHtml = '';
    if (fieldType === 'textarea') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><textarea name="custom_fields[${fieldNameEscaped}]" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${fieldValueEscaped}</textarea></div>`;
    } else if (fieldType === 'select' && fieldOptions) {
        const options = fieldOptions.split('|');
        let optionsHtml = '<option value="">-- Sélectionner --</option>';
        options.forEach(opt => {
            const optTrimmed = opt.trim();
            const selected = fieldValue === optTrimmed ? 'selected' : '';
            optionsHtml += `<option value="${escapeHtml(optTrimmed)}" ${selected}>${escapeHtml(optTrimmed)}</option>`;
        });
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><select name="custom_fields[${fieldNameEscaped}]" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${optionsHtml}</select></div>`;
    } else if (fieldType === 'date') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="date" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>`;
    } else if (fieldType === 'number') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="number" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>`;
    } else {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="text" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" placeholder="${fieldLabelEscaped}"></div>`;
    }
    container.insertAdjacentHTML('beforeend', fieldHtml);
    updateCustomFieldCount(mode);
    setTimeout(() => document.querySelectorAll('.new-field-highlight').forEach(el => el.classList.remove('new-field-highlight')), 2000);
}

function ajouterChampDynamiquementDansEdit(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue) {
    const container = document.getElementById('editCustomFieldsContainer');
    if (!container) return;
    if (container.querySelector(`.custom-field-wrapper[data-field-name="${fieldName}"]`)) {
        showToast('Ce champ existe déjà', 'warning');
        return;
    }
    const noMsg = container.querySelector('.col-span-2.text-center');
    if (noMsg) noMsg.remove();
    const fieldNameEscaped = escapeHtml(fieldName);
    const fieldLabelEscaped = escapeHtml(fieldLabel);
    const fieldValueEscaped = escapeHtml(fieldValue || '');
    let fieldHtml = '';
    if (fieldType === 'textarea') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight" data-field-name="${fieldNameEscaped}"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><textarea name="custom_fields[${fieldNameEscaped}]" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${fieldValueEscaped}</textarea></div>`;
    } else if (fieldType === 'select' && fieldOptions) {
        const options = fieldOptions.split('|');
        let optionsHtml = '<option value="">-- Sélectionner --</option>';
        options.forEach(opt => {
            const optTrimmed = opt.trim();
            const selected = fieldValue === optTrimmed ? 'selected' : '';
            optionsHtml += `<option value="${escapeHtml(optTrimmed)}" ${selected}>${escapeHtml(optTrimmed)}</option>`;
        });
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight" data-field-name="${fieldNameEscaped}"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><select name="custom_fields[${fieldNameEscaped}]" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${optionsHtml}</select></div>`;
    } else if (fieldType === 'date') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight" data-field-name="${fieldNameEscaped}"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="date" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>`;
    } else if (fieldType === 'number') {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight" data-field-name="${fieldNameEscaped}"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="number" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>`;
    } else {
        fieldHtml = `<div class="custom-field-wrapper new-field-highlight" data-field-name="${fieldNameEscaped}"><label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label><input type="text" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" placeholder="${fieldLabelEscaped}"></div>`;
    }
    container.insertAdjacentHTML('beforeend', fieldHtml);
    updateCustomFieldCount('edit');
    setTimeout(() => document.querySelectorAll('.new-field-highlight').forEach(el => el.classList.remove('new-field-highlight')), 2000);
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout...';
    btn.disabled = true;
    try {
        if (mode === 'temp') {
            if (addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue)) {
                ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue, 'add');
                showToast('Champ ajouté temporairement', 'success');
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
                ajouterChampDynamiquementDansEdit(fieldName, fieldLabel, fieldType, fieldOptions, '');
                updateCustomFieldCount('edit');
                currentContactIdForEdit = contactId;
            } else {
                showToast(result.error || 'Erreur inconnue', 'error');
            }
        }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); }
    finally { btn.innerHTML = originalText; btn.disabled = false; }
});

function openAddContactModal() {
    const modal = document.getElementById('addContactModal');
    document.getElementById('addContactForm').reset();
    tempCustomFields = [];
    document.getElementById('tempCustomFields').value = '';
    document.getElementById('tempFieldsList').innerHTML = '';
    document.getElementById('addCustomFieldsContainer').innerHTML = `<div class="col-span-2 text-center py-3 text-gray-400 text-sm" id="noCustomFieldsMessage"><i class="fas fa-info-circle mr-1"></i>Aucun champ personnalisé. <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" class="text-blue-600 hover:underline">Ajouter votre premier champ</button></div>`;
    document.getElementById('enfantsContainer').innerHTML = `<div id="noEnfantsMessage" class="text-center py-3 text-gray-400 text-sm"><i class="fas fa-info-circle mr-1"></i>Aucun enfant enregistré. <button type="button" onclick="addEnfantRow('add')" class="text-blue-600 hover:underline">Ajouter un enfant</button></div>`;
    updateCustomFieldCount('add');
    modal.style.display = 'flex';
}

function closeAddContactModal() { document.getElementById('addContactModal').style.display = 'none'; }

async function openEditContactModal(contactId) {
    currentContactIdForEdit = contactId;
    const modal = document.getElementById('editContactModal');
    try {
        const response = await fetch(`index.php?page=contacts/index&action=get_contact&id=${contactId}`);
        if (!response.ok) { showToast('Erreur serveur: ' + response.status, 'error'); return; }
        const textResponse = await response.text();
        let contact;
        try { contact = JSON.parse(textResponse); } catch(e) { showToast('Erreur de parsing', 'error'); return; }
        if (contact.error) { showToast(contact.error, 'error'); return; }
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
                const required = field.is_required ? '<span class="text-red-500">*</span>' : '';
                let fieldHtml = `<div class="custom-field-wrapper" data-field-name="${escapeHtml(field.field_name)}"><label class="block text-sm font-medium text-gray-700 mb-1">${escapeHtml(field.field_label)} ${required}</label>`;
                if (field.field_type === 'textarea') {
                    fieldHtml += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${escapeHtml(currentValue)}</textarea>`;
                } else if (field.field_type === 'select' && field.field_options) {
                    const options = field.field_options.split('|');
                    fieldHtml += `<select name="custom_fields[${escapeHtml(field.field_name)}]" class="w-full border border-gray-300 rounded-lg px-3 py-2"><option value="">-- Sélectionner --</option>`;
                    for (const opt of options) {
                        const optTrimmed = opt.trim();
                        const selected = currentValue === optTrimmed ? 'selected' : '';
                        fieldHtml += `<option value="${escapeHtml(optTrimmed)}" ${selected}>${escapeHtml(optTrimmed)}</option>`;
                    }
                    fieldHtml += `</select>`;
                } else if (field.field_type === 'date') {
                    fieldHtml += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" value="${escapeHtml(currentValue)}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">`;
                } else if (field.field_type === 'number') {
                    fieldHtml += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" value="${escapeHtml(currentValue)}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">`;
                } else {
                    fieldHtml += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" value="${escapeHtml(currentValue)}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">`;
                }
                fieldHtml += `</div>`;
                container.innerHTML += fieldHtml;
            }
        } else {
            container.innerHTML = `<div class="col-span-2 text-center py-3 text-gray-400 text-sm"><i class="fas fa-info-circle mr-1"></i>Aucun champ personnalisé pour ce contact. <button type="button" onclick="openAddCustomFieldModalFromEdit()" class="text-blue-600 hover:underline">Ajouter un champ</button></div>`;
        }
        updateCustomFieldCount('edit');
        loadEnfants('edit', contact.enfants || []);
        modal.style.display = 'flex';
    } catch(error) { showToast('Erreur lors du chargement du contact', 'error'); }
}

function closeEditContactModal() { document.getElementById('editContactModal').style.display = 'none'; }

document.getElementById('editContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Envoi...';
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

function openImportModal() { document.getElementById('importModal').style.display = 'flex'; }
function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }
function openUnblacklistModal(contactId) { document.getElementById('unblacklistContactId').value = contactId; document.getElementById('unblacklistModal').style.display = 'flex'; }
function closeUnblacklistModal() { document.getElementById('unblacklistModal').style.display = 'none'; }
function showDeleteModal(contactId) { document.getElementById('confirmDeleteBtn').href = 'index.php?page=contacts/supprimer&id=' + contactId; document.getElementById('deleteModal').style.display = 'flex'; }
function closeModal() { document.getElementById('deleteModal').style.display = 'none'; }

document.getElementById('addContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Envoi...';
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
    submitBtn.innerHTML = 'Import en cours...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); closeImportModal(); setTimeout(() => window.location.reload(), 1500); }
        else { showToast(result.error, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
    } catch(error) { showToast('Erreur réseau: ' + error.message, 'error'); submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
});

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
    let noResultRow = document.getElementById('noResultRow');
    if (visibleCount === 0 && contactsRows.length > 0) {
        if (!noResultRow) {
            const tbody = document.getElementById('contactsTableBody');
            if (tbody) {
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="8" class="px-6 py-8 text-center text-gray-500"><i class="fas fa-search text-4xl mb-2 block"></i>Aucun contact ne correspond à votre recherche.</td>';
                tbody.appendChild(noResultRow);
            }
        }
        if (noResultRow) noResultRow.style.display = '';
    } else if (noResultRow) { noResultRow.style.display = 'none'; }
}

if (searchInput) searchInput.addEventListener('input', filterContacts);

filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        filterBtns.forEach(b => { b.classList.remove('bg-blue-600', 'text-white'); b.classList.add('bg-gray-200', 'text-gray-700'); });
        this.classList.remove('bg-gray-200', 'text-gray-700');
        this.classList.add('bg-blue-600', 'text-white');
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