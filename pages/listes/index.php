<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');

// Désactiver le cache pour cette page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// TRAITEMENT DE L'AJOUT DE LISTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_liste']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $nom_liste = trim($_POST['nom_liste'] ?? '');
    
    if (empty($nom_liste)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez saisir un nom de liste']);
        exit;
    }
    
    try {
        $data = [
            'id_compte' => $idCompte,
            'nom_liste' => $nom_liste
        ];
        $db->insert('liste', $data);
        echo json_encode(['success' => true, 'message' => 'Liste créée avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'AJOUT DE CONTACT À UNE LISTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_contacts_to_list']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $id_liste = $_POST['id_liste'] ?? null;
    $selectedContacts = $_POST['selected_contacts'] ?? [];
    
    if (!$id_liste) {
        echo json_encode(['success' => false, 'error' => 'Liste invalide']);
        exit;
    }
    
    if (empty($selectedContacts)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez sélectionner au moins un contact']);
        exit;
    }
    
    $listeExists = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
    if (empty($listeExists)) {
        echo json_encode(['success' => false, 'error' => 'Liste invalide']);
        exit;
    }
    
    $existingContacts = $db->select('liste_contact', ['id_liste' => $id_liste]);
    $existingIds = array_column($existingContacts, 'id_contact');
    
    $addedCount = 0;
    $alreadyExists = 0;
    
    foreach ($selectedContacts as $id_contact) {
        if (!in_array($id_contact, $existingIds)) {
            try {
                $db->insert('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $id_contact
                ]);
                $addedCount++;
            } catch (Exception $e) {
                // Erreur silencieuse
            }
        } else {
            $alreadyExists++;
        }
    }
    
    if ($addedCount > 0) {
        $message = "$addedCount contact(s) ajouté(s) à la liste";
        if ($alreadyExists > 0) {
            $message .= " ($alreadyExists déjà présent(s))";
        }
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucun contact ajouté (ils sont peut-être déjà dans la liste)']);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    error_log("=== IMPORT CSV DEMARRÉ ===");
    error_log("POST: " . print_r($_POST, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    try {
        $id_liste = isset($_POST['id_liste']) ? $_POST['id_liste'] : null;
        $separator = isset($_POST['separator']) ? $_POST['separator'] : ';';
        
        if (!$id_liste) {
            echo json_encode(['success' => false, 'error' => 'Veuillez sélectionner une liste']);
            exit;
        }
        
        $listeExists = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
        if (empty($listeExists)) {
            echo json_encode(['success' => false, 'error' => 'Liste invalide']);
            exit;
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['csv_file']['error'] ?? 'Aucun fichier';
            error_log("Erreur upload: code " . $errorCode);
            echo json_encode(['success' => false, 'error' => 'Erreur lors du téléchargement du fichier (code: ' . $errorCode . ')']);
            exit;
        }
        
        $file = $_FILES['csv_file'];
        error_log("Fichier: " . $file['name'] . ", taille: " . $file['size'] . " bytes");
        
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 5MB)']);
            exit;
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez un fichier CSV']);
            exit;
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']);
            exit;
        }
        
        $firstLine = fgets($handle);
        rewind($handle);
        error_log("Première ligne: " . $firstLine);
        
        $separators = [';', ',', "\t", '|'];
        $separator = ';';
        $maxCount = 0;
        
        foreach ($separators as $testSep) {
            $count = substr_count($firstLine, $testSep);
            error_log("Séparateur '$testSep' trouvé $count fois");
            if ($count > $maxCount) {
                $maxCount = $count;
                $separator = $testSep;
            }
        }
        error_log("Séparateur détecté: '" . $separator . "'");
        
        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) {
            echo json_encode(['success' => false, 'error' => 'Format CSV invalide: impossible de lire les en-têtes']);
            exit;
        }
        
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        error_log("Headers: " . print_r($headers, true));
        
        // ============================================
        // MAPPING DES COLONNES AVEC TOUTES LES NOUVELLES COLONNES
        // ============================================
        $mapping = [
            'prenom' => array_search('prenom', $headers),
            'nom' => array_search('nom', $headers),
            'email' => array_search('email', $headers),
            'telephone' => array_search('telephone', $headers),
            'tel_portable' => array_search('tel_portable', $headers),
            'ville' => array_search('ville', $headers),
            'adresse' => array_search('adresse', $headers),
            'code_postal' => array_search('code_postal', $headers),
            'pays' => array_search('pays', $headers),
            'date_naissance' => array_search('date_naissance', $headers),
            // NOUVELLES COLONNES
            'no_client' => array_search('no_client', $headers),
            'civilite' => array_search('civilite', $headers),
            'sexe' => array_search('sexe', $headers),
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
        error_log("Mapping: " . print_r($mapping, true));
        
        if ($mapping['nom'] === false && $mapping['prenom'] === false) {
            echo json_encode(['success' => false, 'error' => 'Colonnes requises manquantes: nom ou prenom']);
            exit;
        }
        
        $importCount = 0;
        $createdCount = 0;
        $existingCount = 0;
        $errors = [];
        $rowNumber = 1;
        
        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            $rowNumber++;
            $data = array_map('trim', $data);
            
            // ============================================
            // EXTRACTION DES DONNÉES DE BASE
            // ============================================
            $prenom = $mapping['prenom'] !== false ? trim($data[$mapping['prenom']] ?? '') : '';
            $nom = $mapping['nom'] !== false ? trim($data[$mapping['nom']] ?? '') : '';
            $email = $mapping['email'] !== false ? trim($data[$mapping['email']] ?? '') : '';
            $telephone = $mapping['telephone'] !== false ? trim($data[$mapping['telephone']] ?? '') : '';
            $dateNaissance = $mapping['date_naissance'] !== false ? trim($data[$mapping['date_naissance']] ?? '') : '';
            $ville = $mapping['ville'] !== false ? trim($data[$mapping['ville']] ?? '') : '';
            $adresse = $mapping['adresse'] !== false ? trim($data[$mapping['adresse']] ?? '') : '';
            $code_postal = $mapping['code_postal'] !== false ? trim($data[$mapping['code_postal']] ?? '') : '';
            $pays = $mapping['pays'] !== false ? trim($data[$mapping['pays']] ?? 'France') : 'France';
            
            // ============================================
            // EXTRACTION DES NOUVELLES COLONNES
            // ============================================
            $noClient = $mapping['no_client'] !== false ? trim($data[$mapping['no_client']] ?? '') : '';
            $civilite = $mapping['civilite'] !== false ? trim($data[$mapping['civilite']] ?? '') : '';
            $sexe = $mapping['sexe'] !== false ? trim($data[$mapping['sexe']] ?? '') : '';
            $telPortable = $mapping['tel_portable'] !== false ? trim($data[$mapping['tel_portable']] ?? '') : '';
            $commentaire = $mapping['commentaire'] !== false ? trim($data[$mapping['commentaire']] ?? '') : '';
            $commentairePrive = $mapping['commentaire_prive'] !== false ? trim($data[$mapping['commentaire_prive']] ?? '') : '';
            $enseigne = $mapping['enseigne'] !== false ? trim($data[$mapping['enseigne']] ?? '') : '';
            $numeroSiret = $mapping['numero_siret'] !== false ? trim($data[$mapping['numero_siret']] ?? '') : '';
            $mari = $mapping['mari'] !== false ? trim($data[$mapping['mari']] ?? '') : '';
            $anniversaireMari = $mapping['anniversaire_mari'] !== false ? trim($data[$mapping['anniversaire_mari']] ?? '') : '';
            $femme = $mapping['femme'] !== false ? trim($data[$mapping['femme']] ?? '') : '';
            $anniversaireFemme = $mapping['anniversaire_femme'] !== false ? trim($data[$mapping['anniversaire_femme']] ?? '') : '';
            $pointsFidelite = $mapping['points_fidelite'] !== false ? intval(trim($data[$mapping['points_fidelite']] ?? 0)) : 0;
            $cumulCadeau = $mapping['cumul_cadeau'] !== false ? floatval(trim($data[$mapping['cumul_cadeau']] ?? 0)) : 0;
            $cumulAchats = $mapping['cumul_achats'] !== false ? floatval(trim($data[$mapping['cumul_achats']] ?? 0)) : 0;
            $cumulAchatAvantCadeau = $mapping['cumul_achat_avant_cadeau'] !== false ? floatval(trim($data[$mapping['cumul_achat_avant_cadeau']] ?? 0)) : 0;
            $quantiteArticle = $mapping['quantite_article'] !== false ? intval(trim($data[$mapping['quantite_article']] ?? 0)) : 0;
            $nombreTicket = $mapping['nombre_ticket'] !== false ? intval(trim($data[$mapping['nombre_ticket']] ?? 0)) : 0;
            $identifiantEcommerce = $mapping['identifiant_ecommerce'] !== false ? trim($data[$mapping['identifiant_ecommerce']] ?? '') : '';
            $valeurCoupon = $mapping['valeur_coupon'] !== false ? trim($data[$mapping['valeur_coupon']] ?? '') : '';
            $dateFinValiditeCoupon = $mapping['date_fin_validite_coupon'] !== false ? trim($data[$mapping['date_fin_validite_coupon']] ?? '') : '';
            
            if (empty($nom) && empty($prenom) && empty($email)) {
                continue;
            }
            
            // ============================================
            // CONVERSION DES DATES
            // ============================================
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
            
            // Conversion anniversaire mari
            if (!empty($anniversaireMari)) {
                $timestamp = strtotime($anniversaireMari);
                if ($timestamp !== false) {
                    $anniversaireMari = date('Y-m-d', $timestamp);
                } else {
                    $anniversaireMari = null;
                }
            }
            
            // Conversion anniversaire femme
            if (!empty($anniversaireFemme)) {
                $timestamp = strtotime($anniversaireFemme);
                if ($timestamp !== false) {
                    $anniversaireFemme = date('Y-m-d', $timestamp);
                } else {
                    $anniversaireFemme = null;
                }
            }
            
            // Conversion date fin validité coupon
            if (!empty($dateFinValiditeCoupon)) {
                $timestamp = strtotime($dateFinValiditeCoupon);
                if ($timestamp !== false) {
                    $dateFinValiditeCoupon = date('Y-m-d', $timestamp);
                } else {
                    $dateFinValiditeCoupon = null;
                }
            }
            
            // ============================================
            // VÉRIFICATION DE L'ÂGE
            // ============================================
            if (!empty($dateNaissance)) {
                if (!verifierAge($dateNaissance, 18)) {
                    $errors[] = "Ligne $rowNumber: Âge minimum 18 ans requis";
                    continue;
                }
            }
            
            // ============================================
            // RECHERCHE DU CONTACT EXISTANT
            // ============================================
            $contactId = null;
            $isExisting = false;
            
            if (!empty($email)) {
                $existingContacts = $db->select('contact', [
                    'id_compte' => $idCompte,
                    'email' => $email
                ]);
                if (!empty($existingContacts)) {
                    $contactId = $existingContacts[0]['id_contact'];
                    $isExisting = true;
                }
            }
            
            if (!$contactId && !empty($nom) && !empty($prenom)) {
                $existingContacts = $db->select('contact', [
                    'id_compte' => $idCompte,
                    'nom' => $nom,
                    'prenom' => $prenom
                ]);
                if (!empty($existingContacts)) {
                    $contactId = $existingContacts[0]['id_contact'];
                    $isExisting = true;
                }
            }
            
            // ============================================
            // CRÉATION DU CONTACT S'IL N'EXISTE PAS
            // ============================================
            if (!$contactId) {
                // Formater le téléphone fixe
                $telephoneFormatted = null;
                if (!empty($telephone)) {
                    if (substr($telephone, 0, 3) === '261') {
                        $telephoneFormatted = $telephone;
                    } else {
                        $telephoneFormatted = formatPhoneNumber($telephone);
                    }
                }
                
                // Formater le téléphone portable
                $telPortableFormatted = null;
                if (!empty($telPortable)) {
                    if (substr($telPortable, 0, 3) === '261') {
                        $telPortableFormatted = $telPortable;
                    } else {
                        $telPortableFormatted = formatPhoneNumber($telPortable);
                    }
                }
                
                // ============================================
                // DONNÉES COMPLÈTES DU CONTACT AVEC NOUVELLES COLONNES
                // ============================================
                $contactData = [
                    'id_compte' => $idCompte,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => !empty($email) ? $email : null,
                    'telephone' => $telephoneFormatted,
                    'tel_portable' => $telPortableFormatted,
                    'adresse' => !empty($adresse) ? $adresse : null,
                    'ville' => !empty($ville) ? $ville : null,
                    'code_postal' => !empty($code_postal) ? $code_postal : null,
                    'pays' => !empty($pays) ? $pays : 'France',
                    'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
                    'date_inscription' => date('Y-m-d H:i:s'),
                    // NOUVELLES COLONNES
                    'no_client' => !empty($noClient) ? $noClient : null,
                    'civilite' => !empty($civilite) ? $civilite : null,
                    'sexe' => !empty($sexe) ? $sexe : null,
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
                    $contactId = $db->insertAndGetId('contact', $contactData);
                    if ($contactId) {
                        $createdCount++;
                    } else {
                        $errors[] = "Ligne $rowNumber: Impossible de créer le contact";
                        continue;
                    }
                } catch (Exception $e) {
                    error_log("Erreur création contact ligne $rowNumber: " . $e->getMessage());
                    $errors[] = "Ligne $rowNumber: Erreur création contact";
                    continue;
                }
            }
            
            // ============================================
            // AJOUT DU CONTACT À LA LISTE
            // ============================================
            if ($contactId) {
                $existingInList = $db->select('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $contactId
                ]);
                
                if (empty($existingInList)) {
                    try {
                        $db->insert('liste_contact', [
                            'id_liste' => $id_liste,
                            'id_contact' => $contactId
                        ]);
                        $importCount++;
                        
                        if ($isExisting) {
                            $existingCount++;
                        }
                    } catch (Exception $e) {
                        error_log("Erreur ajout liste ligne $rowNumber: " . $e->getMessage());
                        $errors[] = "Ligne $rowNumber: Erreur ajout liste";
                    }
                } else {
                    $errors[] = "Ligne $rowNumber: Contact déjà dans la liste";
                }
            }
        }
        
        fclose($handle);
        
        error_log("Import terminé: $importCount importés, $createdCount créés, $existingCount existants, " . count($errors) . " erreurs");
        
        if ($importCount > 0) {
            $message = "$importCount contact(s) importé(s) dans la liste";
            if ($createdCount > 0) {
                $message .= " ($createdCount nouveau(x) créé(s))";
            }
            if ($existingCount > 0) {
                $message .= " ($existingCount existant(s) ajouté(s))";
            }
            if (!empty($errors)) {
                $message .= " (" . count($errors) . " non importé(s))";
            }
            echo json_encode(['success' => true, 'message' => $message, 'imported' => $importCount]);
        } else {
            $errorMsg = "Aucun contact importé.";
            if (!empty($errors)) {
                $errorMsg .= " Erreurs: " . implode('; ', array_slice($errors, 0, 5));
            } else {
                $errorMsg .= " Vérifiez le format de votre fichier CSV.";
            }
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR IMPORT: " . $e->getMessage());
        error_log("TRACE: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DU RENOMMAGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_rename'])) {
    $id_liste = $_POST['id_liste'];
    $nouveau_nom = trim($_POST['nom_liste']);
    
    if (!empty($id_liste) && !empty($nouveau_nom)) {
        try {
            $db->update('liste', ['nom_liste' => $nouveau_nom], ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
            header('Location: index.php?page=listes/index&success=renamed');
        } catch (Exception $e) {
            header('Location: index.php?page=listes/index&error=rename');
        }
    } else {
        header('Location: index.php?page=listes/index&error=empty_name');
    }
    exit();
}

// ============================================
// TRAITEMENT DU VIDAGE DE LISTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clear'])) {
    $id_liste = $_POST['id_liste'];
    
    if (!empty($id_liste)) {
        try {
            $db->deleteWithConditions('liste_contact', ['id_liste' => $id_liste]);
            header('Location: index.php?page=listes/index&success=cleared');
        } catch (Exception $e) {
            header('Location: index.php?page=listes/index&error=clear');
        }
    } else {
        header('Location: index.php?page=listes/index&error=invalid_id');
    }
    exit();
}

// ============================================
// TRAITEMENT DE LA SUPPRESSION DE LISTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $id_liste = $_POST['id_liste'];
    
    if (!empty($id_liste)) {
        try {
            $db->deleteWithConditions('liste_contact', ['id_liste' => $id_liste]);
            $db->delete('liste', $id_liste, 'id_liste');
            header('Location: index.php?page=listes/index&success=deleted');
        } catch (Exception $e) {
            header('Location: index.php?page=listes/index&error=delete');
        }
    } else {
        header('Location: index.php?page=listes/index&error=invalid_id');
    }
    exit();
}

// ============================================
// RÉCUPÉRATION DES LISTES
// ============================================
$listes = $db->select('liste', ['id_compte' => $idCompte], '*', 'date_creation.desc');

foreach ($listes as $key => $listeItem) {
    $contactsCount = $db->select('liste_contact', ['id_liste' => $listeItem['id_liste']]);
    $listes[$key]['nb_contacts'] = count($contactsCount);
}

// Récupérer tous les contacts pour la modale d'ajout
$tousContacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'nom.asc');

$totalListes = count($listes);

// Gérer les messages de succès/erreur via les paramètres GET
$successType = isset($_GET['success']) ? $_GET['success'] : null;
$errorType = isset($_GET['error']) ? $_GET['error'] : null;

// Messages flash
$flashMessage = null;
$flashError = null;

if ($successType) {
    switch ($successType) {
        case 'renamed':
            $flashMessage = 'Liste renommée avec succès';
            break;
        case 'cleared':
            $flashMessage = 'La liste a été vidée avec succès';
            break;
        case 'deleted':
            $flashMessage = 'La liste a été supprimée avec succès';
            break;
    }
}

if ($errorType) {
    switch ($errorType) {
        case 'rename':
            $flashError = 'Erreur lors du renommage';
            break;
        case 'clear':
            $flashError = 'Erreur lors du vidage';
            break;
        case 'delete':
            $flashError = 'Erreur lors de la suppression';
            break;
        case 'empty_name':
            $flashError = 'Le nom ne peut pas être vide';
            break;
        case 'invalid_id':
            $flashError = 'ID de liste invalide';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes listes - <?= APP_NAME ?></title>
    <style>
        /* ============================================
           TOAST NOTIFICATION
           ============================================ */
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
        .liste-row.hidden-row { display: none; }
        
        /* ============================================
           STYLES POUR LES MODALES EN PLEIN ÉCRAN
           ============================================ */
        
        /* Overlay des modales */
        #addListeModal,
        #addContactToListModal,
        #renameModal,
        #clearModal,
        #deleteModal,
        #importModal {
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

        /* Conteneur des modales */
        .modal-add-liste,
        #addContactToListModal > div,
        #renameModal > div,
        #clearModal > div,
        #deleteModal > div,
        #importModal > div {
            position: relative !important;
            width: 95% !important;
            max-width: 800px !important;
            height: auto !important;
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
            from {
                transform: translateY(30px) scale(0.98);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        /* Contenu interne */
        #addListeModal .p-6,
        #addContactToListModal .p-6,
        #renameModal .p-6,
        #clearModal .p-6,
        #deleteModal .p-6,
        #importModal .p-6 {
            padding: 24px 32px !important;
        }

        /* Header */
        .modal-header-sticky {
            flex-shrink: 0 !important;
            background: white !important;
            padding: 16px 24px !important;
            border-bottom: 2px solid #e5e7eb !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 20 !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
        }

        .modal-header-sticky .flex.justify-between.items-center {
            width: 100% !important;
        }

        /* Zone de contenu scrollable */
        .modal-scroll-content {
            flex: 1 !important;
            overflow-y: auto !important;
            padding: 16px 4px 20px 4px !important;
            min-height: 0 !important;
        }

        /* Footer */
        .modal-footer-sticky {
            flex-shrink: 0 !important;
            background: white !important;
            padding: 16px 24px !important;
            border-top: 2px solid #e5e7eb !important;
            position: sticky !important;
            bottom: 0 !important;
            z-index: 20 !important;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.06) !important;
        }

        /* ============================================
           STYLES POUR LES BOUTONS D'ACTION
           ============================================ */
        .action-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 8px 14px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
            min-width: 36px !important;
            min-height: 36px !important;
        }

        .action-btn i {
            font-size: 14px !important;
        }

        .action-btn:hover {
            transform: translateY(-1px) !important;
        }

        .action-btn-edit {
            background: #fef3c7 !important;
            color: #d97706 !important;
        }
        .action-btn-edit:hover {
            background: #fde68a !important;
        }

        .action-btn-add {
            background: #dbeafe !important;
            color: #2563eb !important;
        }
        .action-btn-add:hover {
            background: #bfdbfe !important;
        }

        .action-btn-view {
            background: #d1fae5 !important;
            color: #059669 !important;
        }
        .action-btn-view:hover {
            background: #a7f3d0 !important;
        }

        .action-btn-import {
            background: #ede9fe !important;
            color: #7c3aed !important;
        }
        .action-btn-import:hover {
            background: #ddd6fe !important;
        }

        .action-btn-clear {
            background: #ffedd5 !important;
            color: #ea580c !important;
        }
        .action-btn-clear:hover {
            background: #fed7aa !important;
        }

        .action-btn-delete {
            background: #fee2e2 !important;
            color: #dc2626 !important;
        }
        .action-btn-delete:hover {
            background: #fecaca !important;
        }

        /* ============================================
           BOUTONS PRINCIPAUX
           ============================================ */
        .btn-primary {
            background: #3b82f6 !important;
            color: white !important;
            padding: 12px 28px !important;
            border-radius: 10px !important;
            font-weight: 600 !important;
            font-size: 15px !important;
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
            padding: 12px 28px !important;
            border-radius: 10px !important;
            font-weight: 500 !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
            border: 1.5px solid #e2e8f0 !important;
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
            border-radius: 10px !important;
            font-weight: 600 !important;
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

        .btn-danger {
            background: #ef4444 !important;
            color: white !important;
            padding: 12px 28px !important;
            border-radius: 10px !important;
            font-weight: 600 !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
        }

        .btn-danger:hover {
            background: #dc2626 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-warning {
            background: #f59e0b !important;
            color: white !important;
            padding: 12px 28px !important;
            border-radius: 10px !important;
            font-weight: 600 !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            cursor: pointer !important;
        }

        .btn-warning:hover {
            background: #d97706 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        /* ============================================
           CHAMPS DE FORMULAIRE AGRANDIS
           ============================================ */
        .modal-scroll-content input,
        .modal-scroll-content select,
        .modal-scroll-content textarea {
            width: 100% !important;
            padding: 12px 16px !important;
            font-size: 15px !important;
            border: 1.5px solid #e2e8f0 !important;
            border-radius: 10px !important;
            background: white !important;
            transition: all 0.2s ease !important;
        }

        .modal-scroll-content input:focus,
        .modal-scroll-content select:focus,
        .modal-scroll-content textarea:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
            outline: none !important;
        }

        .modal-scroll-content label {
            font-size: 14px !important;
            font-weight: 600 !important;
            color: #1e293b !important;
            margin-bottom: 6px !important;
            display: block !important;
        }

        /* ============================================
           STYLES SPÉCIFIQUES POUR LE MODAL IMPORT
           ============================================ */
        .file-upload-wrapper {
            position: relative;
            overflow: hidden;
        }
        .file-upload-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-upload-wrapper .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            transition: all 0.3s;
            min-height: 60px;
        }
        .file-upload-wrapper .file-info:hover {
            border-color: #8b5cf6;
            background: #f5f3ff;
        }
        .file-upload-wrapper .file-info .file-name {
            font-size: 15px;
            color: #1f2937;
            font-weight: 500;
        }
        .file-upload-wrapper .file-info .file-size {
            font-size: 13px;
            color: #6b7280;
        }
        .file-upload-wrapper .file-info i {
            font-size: 28px !important;
        }

        /* ============================================
           CONTACTS LIST
           ============================================ */
        .contact-item {
            padding: 12px 16px !important;
            transition: background 0.2s ease !important;
        }
        .contact-item:hover {
            background: #f8fafc !important;
        }
        .contact-item .contact-checkbox {
            width: 18px !important;
            height: 18px !important;
            margin-right: 12px !important;
        }

        /* ============================================
           VERSION MOBILE
           ============================================ */
        @media (max-width: 768px) {
            #addListeModal,
            #addContactToListModal,
            #renameModal,
            #clearModal,
            #deleteModal,
            #importModal {
                padding: 8px !important;
            }
            
            .modal-add-liste,
            #addContactToListModal > div,
            #renameModal > div,
            #clearModal > div,
            #deleteModal > div,
            #importModal > div {
                width: 100% !important;
                max-height: 98vh !important;
                border-radius: 12px !important;
            }
            
            #addListeModal .p-6,
            #addContactToListModal .p-6,
            #renameModal .p-6,
            #clearModal .p-6,
            #deleteModal .p-6,
            #importModal .p-6 {
                padding: 16px !important;
            }

            .action-btn {
                padding: 6px 10px !important;
                font-size: 12px !important;
                min-width: 32px !important;
                min-height: 32px !important;
            }

            .action-btn i {
                font-size: 12px !important;
            }

            .btn-primary,
            .btn-secondary,
            .btn-success,
            .btn-danger,
            .btn-warning {
                padding: 10px 20px !important;
                font-size: 14px !important;
            }
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes listes</h1>
            <p class="text-gray-500">Organisez vos contacts par groupes</p>
        </div>
        <button type="button" onclick="openAddListeModal()" class="btn-primary">
            <i class="fas fa-plus mr-2"></i>Nouvelle liste
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Total des listes</span><span class="text-2xl font-bold text-gray-800 ml-2" id="totalListesCount"><?= $totalListes ?></span></div>
                <div class="text-gray-400"><i class="fas fa-list text-2xl"></i></div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" placeholder="Rechercher par nom de liste..." class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition text-base">
            </div>
            <div class="mt-2 text-right"><span id="filteredCount" class="text-sm text-gray-500"></span></div>
        </div>
    </div>

    <?php if (empty($listes)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-list text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">Aucune liste pour le moment.</p>
            <button onclick="openAddListeModal()" class="text-blue-600 mt-2 inline-block font-medium">Créer votre première liste →</button>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom de la liste</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contacts</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date de création</th>
                            <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="listesTableBody">
                        <?php foreach ($listes as $liste): ?>
                            <tr class="liste-row hover:bg-gray-50 transition" data-name="<?= strtolower(htmlspecialchars($liste['nom_liste'])) ?>" data-id="<?= $liste['id_liste'] ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="bg-blue-100 rounded-full p-2.5 mr-3">
                                            <i class="fas fa-list text-blue-600 text-sm"></i>
                                        </div>
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($liste['nom_liste']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-gray-600"><?= $liste['nb_contacts'] ?> contact(s)</td>
                                <td class="px-6 py-4 text-gray-500 text-sm"><?= date('d/m/Y', strtotime($liste['date_creation'])) ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-end gap-2 flex-wrap">
                                        <button type="button" onclick="openRenameModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="action-btn action-btn-edit" title="Renommer">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" onclick="openAddContactToListModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="action-btn action-btn-add" title="Ajouter un contact">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <a href="index.php?page=listes/details&id=<?= $liste['id_liste'] ?>" class="action-btn action-btn-view" title="Voir les contacts">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" onclick="openImportModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="action-btn action-btn-import" title="Importer CSV">
                                            <i class="fas fa-file-import"></i>
                                        </button>
                                        <button type="button" onclick="openClearModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" data-count="<?= $liste['nb_contacts'] ?>" class="action-btn action-btn-clear" title="Vider">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        <button type="button" onclick="openDeleteModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="action-btn action-btn-delete" title="Supprimer">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noResultRow" style="display: none;">
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-search text-4xl mb-2 block"></i>
                                Aucune liste ne correspond à votre recherche.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODAL D'AJOUT DE LISTE -->
<!-- ============================================ -->
<div id="addListeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 modal-add-liste">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-2.5 rounded-full mr-3">
                            <i class="fas fa-list text-blue-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Créer une nouvelle liste</h3>
                    </div>
                    <button type="button" onclick="closeAddListeModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="modal-scroll-content">
                <form id="addListeForm" method="POST">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la liste *</label>
                        <input type="text" name="nom_liste" id="nom_liste" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:border-blue-500 text-base"
                               placeholder="Ex: Newsletter, Clients VIP, Prospects...">
                        <p class="text-xs text-gray-500 mt-1">Choisissez un nom explicite pour votre liste</p>
                    </div>
                    
                    <div class="modal-footer-sticky">
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeAddListeModal()" class="btn-secondary">
                                Annuler
                            </button>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-plus mr-2"></i>Créer la liste
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL AJOUTER CONTACT À UNE LISTE -->
<!-- ============================================ -->
<div id="addContactToListModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 max-h-[95vh] flex flex-col">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-2.5 rounded-full mr-3">
                            <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Ajouter des contacts</h3>
                    </div>
                    <button type="button" onclick="closeAddContactToListModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p class="text-gray-500 mt-1 text-base">Ajouter des contacts à la liste : <strong id="addContactListName"></strong></p>
            </div>
            
            <div class="modal-scroll-content">
                <div class="mb-4">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" id="searchContactInput" placeholder="Rechercher un contact par nom, email ou téléphone..." 
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-base">
                    </div>
                </div>
                
                <div class="border rounded-lg overflow-hidden">
                    <div class="max-h-96 overflow-y-auto" id="contactsListContainer">
                        <div id="loadingContacts" class="p-4 text-center text-gray-500">
                            <i class="fas fa-spinner fa-spin mr-2"></i>Chargement des contacts...
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 flex justify-between items-center">
                    <div>
                        <span class="text-sm text-gray-500">
                            <span id="selectedCount">0</span> contact(s) sélectionné(s)
                        </span>
                    </div>
                    <div class="flex space-x-3">
                        <button type="button" onclick="selectAllContacts()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                            <i class="fas fa-check-double"></i> Tout sélectionner
                        </button>
                        <button type="button" onclick="selectNoneContacts()" class="text-sm text-gray-500 hover:text-gray-700">
                            <i class="fas fa-times"></i> Tout désélectionner
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer-sticky">
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeAddContactToListModal()" class="btn-secondary">
                        Annuler
                    </button>
                    <button type="button" onclick="submitAddContactsToList()" id="submitAddContactsBtn" class="btn-primary">
                        <i class="fas fa-plus mr-2"></i>Ajouter les contacts sélectionnés
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL RENOMMER -->
<!-- ============================================ -->
<div id="renameModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-yellow-100 p-2.5 rounded-full mr-3">
                            <i class="fas fa-edit text-yellow-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Renommer la liste</h3>
                    </div>
                    <button type="button" onclick="closeRenameModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="modal-scroll-content">
                <form method="POST">
                    <input type="hidden" name="action_rename" value="1">
                    <input type="hidden" name="id_liste" id="renameListId">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau nom</label>
                        <input type="text" name="nom_liste" id="renameListName" required 
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:border-blue-500 text-base">
                    </div>
                    <div class="modal-footer-sticky">
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeRenameModal()" class="btn-secondary">Annuler</button>
                            <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Renommer</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL VIDER -->
<!-- ============================================ -->
<div id="clearModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-orange-100 mb-4">
                    <i class="fas fa-trash-alt text-orange-600 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-2">Vider la liste</h3>
                <p class="text-gray-500 mb-6 text-base">Êtes-vous sûr de vouloir vider la liste <strong id="clearListName"></strong> ?</p>
                <p class="text-sm text-gray-400 mb-6">Cette action est irréversible.</p>
                <form method="POST">
                    <input type="hidden" name="action_clear" value="1">
                    <input type="hidden" name="id_liste" id="clearListId">
                    <div class="flex justify-center space-x-3">
                        <button type="button" onclick="closeClearModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-warning"><i class="fas fa-trash-alt mr-2"></i>Vider</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL SUPPRIMER -->
<!-- ============================================ -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-red-100 mb-4">
                    <i class="fas fa-times-circle text-red-600 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-2">Supprimer la liste</h3>
                <p class="text-gray-500 mb-6 text-base">Êtes-vous sûr de vouloir supprimer la liste <strong id="deleteListName"></strong> ?</p>
                <p class="text-sm text-gray-400 mb-6">Les contacts ne seront pas supprimés.</p>
                <form method="POST">
                    <input type="hidden" name="action_delete" value="1">
                    <input type="hidden" name="id_liste" id="deleteListId">
                    <div class="flex justify-center space-x-3">
                        <button type="button" onclick="closeDeleteModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-danger"><i class="fas fa-trash mr-2"></i>Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL IMPORT CSV -->
<!-- ============================================ -->
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4">
        <div class="p-6">
            <div class="modal-header-sticky">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-2.5 rounded-full mr-3">
                            <i class="fas fa-file-import text-purple-600 text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800">Importer des contacts</h3>
                    </div>
                    <button type="button" onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="modal-scroll-content">
                <div class="bg-blue-50 p-4 rounded-lg mb-4 text-sm">
                    <strong class="text-blue-800">Format attendu :</strong>
                    <ul class="mt-2 text-gray-600 space-y-1">
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Colonnes: prenom, nom, email, telephone, tel_portable, ville, adresse, code_postal, pays, date_naissance, no_client, civilite, sexe, commentaire, commentaire_prive, enseigne, numero_siret, mari, anniversaire_mari, femme, anniversaire_femme, points_fidelite, cumul_cadeau, cumul_achats, cumul_achat_avant_cadeau, quantite_article, nombre_ticket, identifiant_ecommerce, valeur_coupon, date_fin_validite_coupon</li>
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Séparateur: ; ou ,</li>
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Date: YYYY-MM-DD ou DD/MM/YYYY</li>
                        <li><i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>Âge minimum: 18 ans</li>
                    </ul>
                </div>
                
                <form id="importForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_liste" id="importListId">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Liste cible</label>
                        <span id="importListName" class="text-base font-semibold text-gray-800"></span>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fichier CSV</label>
                        <div class="file-upload-wrapper">
                            <div class="file-info" id="fileInfo">
                                <i class="fas fa-cloud-upload-alt text-purple-500"></i>
                                <span class="file-name" id="fileName">Cliquez ou glissez votre fichier CSV ici</span>
                                <span class="file-size" id="fileSize"></span>
                            </div>
                            <input type="file" name="csv_file" accept=".csv" required id="csvFileInput">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Taille max : 5MB</p>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Séparateur</label>
                        <select name="separator" class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:border-purple-500 text-base">
                            <option value=";">Point-virgule (;)</option>
                            <option value=",">Virgule (,)</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer-sticky">
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeImportModal()" class="btn-secondary">Annuler</button>
                            <button type="submit" id="importSubmitBtn" class="btn-success">
                                <i class="fas fa-upload mr-2"></i>Importer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// TOAST NOTIFICATION
// ============================================
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

// ============================================
// AFFICHAGE DES MESSAGES FLASH VIA TOAST
// ============================================
<?php if ($flashMessage): ?>
showToast('<?= addslashes($flashMessage) ?>', 'success');
<?php endif; ?>
<?php if ($flashError): ?>
showToast('<?= addslashes($flashError) ?>', 'error');
<?php endif; ?>

// ============================================
// MODAL D'AJOUT DE LISTE
// ============================================
function openAddListeModal() {
    const modal = document.getElementById('addListeModal');
    const modalContent = modal.querySelector('.modal-add-liste');
    document.getElementById('addListeForm').reset();
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddListeModal() {
    const modal = document.getElementById('addListeModal');
    const modalContent = modal.querySelector('.modal-add-liste');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODAL AJOUTER CONTACT À UNE LISTE
// ============================================
let currentListId = null;
let allContacts = <?= json_encode($tousContacts) ?>;
let contactsNotInList = [];

async function openAddContactToListModal(button) {
    currentListId = button.getAttribute('data-id');
    const listName = button.getAttribute('data-name');
    document.getElementById('addContactListName').innerHTML = listName;
    
    document.getElementById('contactsListContainer').innerHTML = '<div class="p-4 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement des contacts...</div>';
    document.getElementById('addContactToListModal').style.display = 'flex';
    
    try {
        const response = await fetch(`index.php?page=listes/details&id=${currentListId}&get_contacts=1`, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        
        if (result.success && result.contacts_ids) {
            const existingIds = result.contacts_ids;
            contactsNotInList = allContacts.filter(c => !existingIds.includes(c.id_contact));
        } else {
            contactsNotInList = allContacts;
        }
        
        renderContactsList();
    } catch (error) {
        contactsNotInList = allContacts;
        renderContactsList();
    }
}

function renderContactsList() {
    const container = document.getElementById('contactsListContainer');
    const searchTerm = document.getElementById('searchContactInput')?.value.toLowerCase() || '';
    
    let filteredContacts = contactsNotInList.filter(contact => {
        const fullName = (contact.prenom + ' ' + contact.nom).toLowerCase();
        const email = (contact.email || '').toLowerCase();
        const telephone = (contact.telephone || '').toLowerCase();
        return fullName.includes(searchTerm) || email.includes(searchTerm) || telephone.includes(searchTerm);
    });
    
    if (filteredContacts.length === 0) {
        container.innerHTML = '<div class="p-4 text-center text-gray-500"><i class="fas fa-users"></i> Aucun contact disponible à ajouter</div>';
        document.getElementById('selectedCount').innerText = '0';
        return;
    }
    
    let html = '';
    filteredContacts.forEach(contact => {
        html += `
            <label class="contact-item flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100">
                <input type="checkbox" name="selected_contact" value="${contact.id_contact}" class="contact-checkbox w-4 h-4 text-blue-600 rounded mr-3" onchange="updateContactSelectedCount()">
                <div class="flex-1">
                    <div class="font-medium text-gray-800">${escapeHtml(contact.prenom)} ${escapeHtml(contact.nom)}</div>
                    <div class="text-xs text-gray-500">
                        ${contact.email ? '<i class="fas fa-envelope mr-1"></i>' + escapeHtml(contact.email) : ''}
                        ${contact.telephone ? '<i class="fas fa-phone ml-2 mr-1"></i>' + escapeHtml(contact.telephone) : ''}
                    </div>
                </div>
            </label>
        `;
    });
    
    container.innerHTML = html;
    updateContactSelectedCount();
}

function updateContactSelectedCount() {
    const checkboxes = document.querySelectorAll('#contactsListContainer .contact-checkbox:checked');
    document.getElementById('selectedCount').innerText = checkboxes.length;
}

function selectAllContacts() {
    document.querySelectorAll('#contactsListContainer .contact-checkbox').forEach(cb => cb.checked = true);
    updateContactSelectedCount();
}

function selectNoneContacts() {
    document.querySelectorAll('#contactsListContainer .contact-checkbox').forEach(cb => cb.checked = false);
    updateContactSelectedCount();
}

function closeAddContactToListModal() {
    document.getElementById('addContactToListModal').style.display = 'none';
}

async function submitAddContactsToList() {
    const selectedContacts = Array.from(document.querySelectorAll('#contactsListContainer .contact-checkbox:checked')).map(cb => cb.value);
    
    if (selectedContacts.length === 0) {
        showToast('Veuillez sélectionner au moins un contact', 'warning');
        return;
    }
    
    const submitBtn = document.getElementById('submitAddContactsBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout...';
    submitBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action_add_contacts_to_list', '1');
    formData.append('id_liste', currentListId);
    selectedContacts.forEach(id => formData.append('selected_contacts[]', id));
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddContactToListModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

document.getElementById('searchContactInput')?.addEventListener('input', function() {
    renderContactsList();
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ============================================
// AJOUT DE LISTE AJAX
// ============================================
document.getElementById('addListeForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_add_liste', '1');
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Création...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddListeModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// RECHERCHE
// ============================================
const searchInput = document.getElementById('searchInput');
const listeRows = document.querySelectorAll('.liste-row');
const noResultRow = document.getElementById('noResultRow');
const filteredCountSpan = document.getElementById('filteredCount');
const totalListesCount = parseInt(document.getElementById('totalListesCount')?.textContent || 0);

function filterListes() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    let visibleCount = 0;
    listeRows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        if (searchTerm === '' || name.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    if (visibleCount === 0 && listeRows.length > 0) noResultRow.style.display = '';
    else noResultRow.style.display = 'none';
    if (filteredCountSpan) filteredCountSpan.textContent = `${visibleCount} liste(s) affichée(s) sur ${totalListesCount}`;
}
if (searchInput) searchInput.addEventListener('input', filterListes);

// ============================================
// MODALS
// ============================================
function openRenameModal(button) {
    document.getElementById('renameListId').value = button.getAttribute('data-id');
    document.getElementById('renameListName').value = button.getAttribute('data-name');
    document.getElementById('renameModal').style.display = 'flex';
}
function closeRenameModal() { document.getElementById('renameModal').style.display = 'none'; }

function openClearModal(button) {
    if (parseInt(button.getAttribute('data-count')) === 0) {
        showToast('Cette liste est déjà vide.', 'warning');
        return;
    }
    document.getElementById('clearListId').value = button.getAttribute('data-id');
    document.getElementById('clearListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('clearModal').style.display = 'flex';
}
function closeClearModal() { document.getElementById('clearModal').style.display = 'none'; }

function openDeleteModal(button) {
    document.getElementById('deleteListId').value = button.getAttribute('data-id');
    document.getElementById('deleteListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }

function openImportModal(button) {
    document.getElementById('importListId').value = button.getAttribute('data-id');
    document.getElementById('importListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('importModal').style.display = 'flex';
}
function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }

// ============================================
// GESTION DU FICHIER DANS LE MODAL IMPORT
// ============================================
document.getElementById('csvFileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        fileName.textContent = file.name;
        fileSize.textContent = `(${sizeMB} MB)`;
        fileInfo.style.borderColor = '#8b5cf6';
        fileInfo.style.background = '#f5f3ff';
        
        const extension = file.name.split('.').pop().toLowerCase();
        if (extension !== 'csv') {
            showToast('Veuillez sélectionner un fichier CSV', 'warning');
            this.value = '';
            resetFileInfo();
        }
        
        if (file.size > 5 * 1024 * 1024) {
            showToast('Fichier trop volumineux (max 5MB)', 'warning');
            this.value = '';
            resetFileInfo();
        }
    }
});

function resetFileInfo() {
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    fileName.textContent = 'Cliquez ou glissez votre fichier CSV ici';
    fileSize.textContent = '';
    fileInfo.style.borderColor = '#d1d5db';
    fileInfo.style.background = 'transparent';
}

// ============================================
// IMPORT CSV AJAX
// ============================================
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('csvFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Veuillez sélectionner un fichier CSV', 'warning');
        return;
    }
    
    const formData = new FormData(this);
    const submitBtn = document.getElementById('importSubmitBtn');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Import en cours...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            }, 
            body: formData 
        });
        
        const textResponse = await response.text();
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseError) {
            console.error('Erreur de parsing JSON:', parseError);
            console.error('Réponse reçue:', textResponse);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeImportModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
        
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// Fermeture des modales
document.getElementById('addListeModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddListeModal(); });
document.getElementById('addContactToListModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddContactToListModal(); });
document.getElementById('renameModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeRenameModal(); });
document.getElementById('clearModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeClearModal(); });
document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeDeleteModal(); });
document.getElementById('importModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeImportModal(); });
document.addEventListener('keydown', e => { 
    if (e.key === 'Escape') { 
        closeAddListeModal(); 
        closeAddContactToListModal(); 
        closeRenameModal(); 
        closeClearModal(); 
        closeDeleteModal(); 
        closeImportModal(); 
    } 
});
</script>

</body>
</html>