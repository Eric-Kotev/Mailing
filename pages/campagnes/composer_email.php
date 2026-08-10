<?php
global $db;

// ============================================
// DÉTECTION PRÉCOCE DE LA REQUÊTE AJAX (UPLOAD)
// ============================================
// On doit savoir dès le début si c'est une requête AJAX d'upload,
// pour ne JAMAIS rediriger vers du HTML dans ce cas (c'est la cause
// de "Unexpected token '<' is not valid JSON" en prod).
$isAjaxUpload = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_file']));

if ($isAjaxUpload) {
    // On désactive l'affichage des erreurs à l'écran : toute erreur PHP
    // (warning/notice/deprecated) doit partir dans les logs, jamais dans
    // la réponse, sinon elle casse le JSON avec du HTML.
    ini_set('display_errors', 0);
    error_reporting(E_ALL); // on log quand même tout

    header('Content-Type: application/json');

    // Attrape les erreurs "classiques" (warning, notice...) et les transforme
    // en réponse JSON propre au lieu de laisser fuir du HTML.
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        error_log("PHP Error [upload]: $errstr in $errfile:$errline");
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Erreur serveur interne lors du traitement du fichier.'
        ]);
        exit;
    });

    // Attrape les erreurs FATALES (function not found, etc.) qui ne passent
    // pas par set_error_handler. Sans ça, PHP/Apache/Nginx renvoie sa propre
    // page d'erreur HTML -> exactement le bug que tu as en prod.
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log("PHP Fatal Error [upload]: " . $err['message'] . " in " . $err['file'] . ":" . $err['line']);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            // On ne peut pas faire de exit propre ici (on est déjà en shutdown),
            // mais on peut encore émettre du contenu si rien n'a été envoyé.
            if (ob_get_length() === false || ob_get_length() === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Erreur fatale côté serveur. Consultez les logs.'
                ]);
            }
        }
    });
}

// ============================================
// FONCTION UTILITAIRE : répondre en JSON et quitter (pour l'AJAX)
// ============================================
function respondJsonAndExit($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}

// ============================================
// VÉRIFICATION DE SESSION
// ============================================
if (empty($_SESSION['user_id'])) {
    if ($isAjaxUpload) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Votre session a expiré. Veuillez recharger la page et vous reconnecter."
        ]);
    }
    header('Location: index.php?page=auth/login');
    exit;
}

$idCompte = $_SESSION['user_id'];

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? $_GET['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    if ($isAjaxUpload) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Identifiant de campagne manquant. Veuillez recharger la page."
        ]);
    }
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    if ($isAjaxUpload) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Campagne non trouvée. Veuillez recharger la page."
        ]);
    }
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagne = $campagneConfig[0];

// Vérifier que le type de message est Email
$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'email') {
    if ($isAjaxUpload) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Type de message non valide pour cette page. Veuillez recharger la page."
        ]);
    }
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
        'nombre_sans_email' => $nbSansEmail,
        'listmonk_id' => $liste['listmonk_id'] ?? null
    ];
}

$error = '';
$success = '';
$uploadedMediaId = null;
$uploadedFileName = null;
$uploadError = null;

// Récupérer les données du formulaire en session
$formData = $_SESSION['form_data'] ?? [];
$formData['objet'] = $formData['objet'] ?? '';
$formData['corps'] = $formData['corps'] ?? '';
$formData['liste_id'] = $formData['liste_id'] ?? '';
$formData['from_email'] = $formData['from_email'] ?? 'noreply@votre-domaine.com';
$formData['from_name'] = $formData['from_name'] ?? 'Votre Entreprise';

// Récupérer les infos de l'upload en session
$uploadedMediaId = $_SESSION['uploaded_media_id'] ?? null;
$uploadedFileName = $_SESSION['uploaded_file_name'] ?? null;
$uploadedMediaUrl = $_SESSION['uploaded_media_url'] ?? null;
$uploadError = $_SESSION['upload_error'] ?? null;
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;

// Nettoyer les erreurs d'upload après affichage
unset($_SESSION['upload_error']);

// ============================================
// TRAITEMENT DE L'UPLOAD DE FICHIER (AJAX)
// ============================================
if ($isAjaxUpload) {

    $hasFile = isset($_FILES['piece_jointe']) && $_FILES['piece_jointe']['error'] === UPLOAD_ERR_OK;

    if (!$hasFile) {
        // On donne un message plus précis selon le code d'erreur PHP d'upload,
        // ça aide beaucoup à diagnostiquer les soucis de config prod
        // (post_max_size, upload_max_filesize, etc.)
        $uploadErrCode = $_FILES['piece_jointe']['error'] ?? null;
        $msg = "Veuillez sélectionner un fichier à importer";
        if ($uploadErrCode === UPLOAD_ERR_INI_SIZE || $uploadErrCode === UPLOAD_ERR_FORM_SIZE) {
            $msg = "Le fichier dépasse la taille maximale autorisée par le serveur.";
        } elseif ($uploadErrCode === UPLOAD_ERR_PARTIAL) {
            $msg = "Le fichier n'a été que partiellement téléchargé. Réessayez.";
        } elseif ($uploadErrCode === UPLOAD_ERR_NO_TMP_DIR) {
            $msg = "Erreur serveur : dossier temporaire manquant.";
        } elseif ($uploadErrCode === UPLOAD_ERR_CANT_WRITE) {
            $msg = "Erreur serveur : impossible d'écrire le fichier sur le disque.";
        }
        respondJsonAndExit(['success' => false, 'message' => $msg]);
    }

    $file = $_FILES['piece_jointe'];

    // Vérifier la taille du fichier (max 10 Mo)
    if ($file['size'] > 10 * 1024 * 1024) {
        respondJsonAndExit(['success' => false, 'message' => "Le fichier est trop volumineux. Maximum 10 Mo."]);
    }

    // Vérifier le type de fichier
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv'
    ];

    // Vérifier que l'extension fileinfo est bien disponible en prod
    if (!function_exists('mime_content_type')) {
        error_log("=== ERREUR: extension 'fileinfo' non disponible sur ce serveur ===");
        respondJsonAndExit([
            'success' => false,
            'message' => "Configuration serveur incomplète (extension fileinfo manquante). Contactez l'administrateur."
        ]);
    }

    $mimeType = @mime_content_type($file['tmp_name']);
    if ($mimeType === false) {
        error_log("=== ERREUR: mime_content_type a échoué pour " . $file['tmp_name'] . " ===");
        respondJsonAndExit(['success' => false, 'message' => "Impossible de déterminer le type du fichier."]);
    }

    if (!in_array($file['type'], $allowedTypes) && !in_array($mimeType, $allowedTypes)) {
        respondJsonAndExit(['success' => false, 'message' => "Type de fichier non autorisé. Types autorisés: images, PDF, Word, Excel, CSV, TXT"]);
    }

    // Créer le dossier d'upload si nécessaire
    $uploadDir =  __DIR__ . '/uploads/pieces_jointes/';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            error_log("=== ERREUR: impossible de créer le dossier $uploadDir (droits d'écriture ?) ===");
            respondJsonAndExit(['success' => false, 'message' => "Erreur serveur : impossible de créer le dossier d'upload. Vérifiez les droits d'écriture."]);
        }
    }
    if (!is_writable($uploadDir)) {
        error_log("=== ERREUR: dossier $uploadDir non accessible en écriture ===");
        respondJsonAndExit(['success' => false, 'message' => "Erreur serveur : dossier d'upload non accessible en écriture."]);
    }

    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        error_log("=== ERREUR: move_uploaded_file a échoué vers $filePath ===");
        respondJsonAndExit(['success' => false, 'message' => "Impossible de déplacer le fichier uploadé."]);
    }

    // Uploader le fichier vers Listmonk avec multipart/form-data
    $apiUrl = 'http://164.68.103.147:9005/api/media';
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_VERBOSE, false); // le mode verbose écrit sur stderr, inutile en prod

    // Créer le fichier CURLFile pour l'upload multipart
    $fileInfo = new CURLFile($filePath, mime_content_type($filePath), $file['name']);
    $postFields = ['file' => $fileInfo];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    $responseData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("=== ERREUR CURL (upload media): $curlError ===");
        respondJsonAndExit(['success' => false, 'message' => "Erreur de connexion au serveur Listmonk: " . $curlError]);
    }

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($responseData, true);
        $mediaId = null;
        $mediaUrl = null;

        if (isset($data['id'])) {
            $mediaId = $data['id'];
            if (isset($data['url'])) {
                $mediaUrl = $data['url'];
            } elseif (isset($data['data']['url'])) {
                $mediaUrl = $data['data']['url'];
            }
        } elseif (isset($data['data']['id'])) {
            $mediaId = $data['data']['id'];
            if (isset($data['data']['url'])) {
                $mediaUrl = $data['data']['url'];
            }
        } elseif (isset($data['result']['id'])) {
            $mediaId = $data['result']['id'];
            if (isset($data['result']['url'])) {
                $mediaUrl = $data['result']['url'];
            }
        }

        if ($mediaId) {
            // Stocker en session pour utilisation ultérieure
            $_SESSION['uploaded_media_id'] = $mediaId;
            $_SESSION['uploaded_file_name'] = $file['name'];
            $_SESSION['uploaded_media_url'] = $mediaUrl;

            respondJsonAndExit([
                'success' => true,
                'message' => "Fichier importé avec succès (ID: " . $mediaId . ")",
                'media_id' => $mediaId,
                'file_name' => $file['name'],
                'media_url' => $mediaUrl
            ]);
        } else {
            error_log("=== Upload OK mais pas d'ID média reçu. Réponse brute: " . $responseData . " ===");
            respondJsonAndExit([
                'success' => false,
                'message' => "Fichier uploadé mais aucun ID média reçu. Réponse: " . substr($responseData, 0, 200)
            ]);
        }
    } else {
        $msg = "Erreur Listmonk (HTTP " . $httpCode . "): " . substr($responseData, 0, 500);
        error_log("=== ERREUR LISTMONK (upload media): $msg ===");
        $_SESSION['upload_error'] = $msg;
        respondJsonAndExit(['success' => false, 'message' => $msg]);
    }
}

// ============================================
// TRAITEMENT POUR SUPPRIMER LE FICHIER UPLOADÉ
// ============================================
if (isset($_GET['remove_upload']) && $_GET['remove_upload'] == 1) {
    unset($_SESSION['uploaded_media_id']);
    unset($_SESSION['uploaded_file_name']);
    unset($_SESSION['uploaded_media_url']);
    unset($_SESSION['upload_error']);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=campagnes/composer&campagne_config_id=' . $campagneConfigId);
    exit;
}

// ============================================
// FONCTION POUR CRÉER UNE CAMPAGNE SUR LISTMONK
// ============================================
function createListmonkCampaign($campaignData) {
    $apiUrl = 'http://164.68.103.147:9005/api/campaigns';
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';

    $payload = [
        'name' => $campaignData['name'],
        'subject' => $campaignData['subject'],
        'lists' => [(int)$campaignData['list_id']],
        'type' => 'regular',
        'content_type' => 'richtext',
        'body' => $campaignData['body'],
        'from_email' => $campaignData['from_email'] ?? 'noreply@votre-domaine.com',
        'from_name' => $campaignData['from_name'] ?? 'Votre Entreprise',
        'messenger' => 'email',
        'enabled' => true
    ];

    // Ajouter les pièces jointes si présentes (pour les fichiers non-image)
    if (!empty($campaignData['attachments']) && is_array($campaignData['attachments'])) {
        $payload['attachments'] = $campaignData['attachments'];
    }

    // Gestion de la planification
    if (!empty($campaignData['send_at'])) {
        $payload['send_at'] = $campaignData['send_at'];
        $payload['status'] = 'scheduled';
    } else {
        $payload['status'] = 'draft';
    }

    error_log("=== PAYLOAD LISTMONK ===");
    error_log(json_encode($payload, JSON_PRETTY_PRINT));

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "Erreur CURL: $curlError"];
    }

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        $campaignId = null;
        if (isset($data['id'])) {
            $campaignId = $data['id'];
        } elseif (isset($data['data']['id'])) {
            $campaignId = $data['data']['id'];
        } elseif (isset($data['result']['id'])) {
            $campaignId = $data['result']['id'];
        }

        return [
            'success' => true,
            'campaign_id' => $campaignId,
            'data' => $data
        ];
    } else {
        error_log("=== ERREUR LISTMONK RESPONSE ===");
        error_log($response);
        return ['success' => false, 'error' => "HTTP $httpCode: " . substr($response, 0, 500)];
    }
}

// ============================================
// FONCTION POUR METTRE À JOUR LE STATUT D'UNE CAMPAGNE
// ============================================
function updateListmonkCampaignStatus($campaignId, $status) {
    $apiUrl = "http://164.68.103.147:9005/api/campaigns/{$campaignId}/status";
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';

    $payload = ['status' => $status];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200 || $httpCode === 201 || $httpCode === 204;
}

// ============================================
// TRAITEMENT DU FORMULAIRE PRINCIPAL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enregistrer'])) {
    // Sauvegarder les données du formulaire en session
    $_SESSION['form_data'] = [
        'objet' => $_POST['objet'] ?? '',
        'corps' => $_POST['corps'] ?? '',
        'liste_id' => $_POST['liste_id'] ?? '',
        'from_email' => $_POST['from_email'] ?? 'noreply@votre-domaine.com',
        'from_name' => $_POST['from_name'] ?? 'Votre Entreprise'
    ];

    $objet = trim($_POST['objet'] ?? '');
    $corps = trim($_POST['corps'] ?? '');
    $liste_id = $_POST['liste_id'] ?? null;
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    $envoyer_maintenant = isset($_POST['envoyer_maintenant']) && $_POST['envoyer_maintenant'] === '1';
    $date_planification = $_POST['date_planification'] ?? null;
    $media_id = $_POST['media_id'] ?? null;

    // Si media_id n'est pas dans le POST mais existe en session, l'utiliser
    if (empty($media_id) && !empty($_SESSION['uploaded_media_id'])) {
        $media_id = $_SESSION['uploaded_media_id'];
    }

    // Récupérer l'URL du média
    $mediaUrl = $_SESSION['uploaded_media_url'] ?? null;

    // Validation des emails expéditeurs
    if (empty($from_email)) {
        $from_email = 'noreply@votre-domaine.com';
    }
    if (empty($from_name)) {
        $from_name = 'Votre Entreprise';
    }

    // Validation
    if (empty($objet)) {
        $error = "Veuillez saisir un objet";
    } elseif (empty($corps)) {
        $error = "Veuillez saisir le corps du message";
    } elseif (empty($liste_id)) {
        $error = "Veuillez sélectionner une liste de diffusion";
    } else {
        // Préparer les données
        $destinataires = [];
        $destinatairesNoms = [];
        $contactsSansEmailDansListe = 0;
        $listmonkListId = null;

        // Récupérer le listmonk_id de la liste sélectionnée
        foreach ($listes as $l) {
            if ($l['id_liste'] == $liste_id) {
                $listmonkListId = $l['listmonk_id'];
                break;
            }
        }

        if (!$listmonkListId) {
            $error = "Cette liste n'est pas liée à Listmonk. Veuillez d'abord synchroniser la liste.";
        } else {
            // Récupérer les contacts de la liste
            $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
            foreach ($listeContacts as $lc) {
                if (!in_array($lc['id_contact'], $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $idCompte]);
                    if (!empty($contact) && !empty($contact[0]['email'])) {
                        $destinataires[] = $contact[0];
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

        if (empty($error) && $listmonkListId) {
            // Préparer le corps du message avec l'image si présente
            $bodyContent = $corps;

            // Si une image est uploadée, l'insérer dans le corps du message
            if (!empty($media_id) && !empty($mediaUrl)) {
                // Déterminer si c'est une image
                $isImage = false;
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                if (in_array($extension, $imageExtensions)) {
                    $isImage = true;
                }

                if ($isImage) {
                    // Insérer l'image dans le corps du message
                    $bodyContent .= '<br><br><img src="' . $mediaUrl . '" alt="' . htmlspecialchars($uploadedFileName ?? 'Image') . '" style="max-width:100%;">';
                } else {
                    // Pour les fichiers non-image, ajouter un lien de téléchargement
                    $bodyContent .= '<br><br><strong>Pièce jointe :</strong> <a href="' . $mediaUrl . '">' . htmlspecialchars($uploadedFileName ?? 'Fichier') . '</a>';
                }
            }

            // 1. CRÉER LA CAMPAGNE SUR LISTMONK
            $campaignData = [
                'name' => $campagne['nom_campagne'] . ' - ' . date('Y-m-d H:i'),
                'subject' => $objet,
                'list_id' => $listmonkListId,
                'body' => $bodyContent,
                'from_email' => $from_email,
                'from_name' => $from_name
            ];

            // Ajouter les pièces jointes pour les fichiers non-image (PDF, DOC, etc.)
            if (!empty($media_id)) {
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (!in_array($extension, $imageExtensions)) {
                    // Pour les fichiers non-image, on les attache comme pièce jointe
                    $campaignData['attachments'] = [(int)$media_id];
                }
                // Pour les images, on les inclut déjà dans le body
            }

            // VÉRIFIER LA DATE DE PLANIFICATION
            $hasSchedule = false;
            $scheduleDate = null;

            if (!empty($campagne['date_planification'])) {
                $scheduleDate = $campagne['date_planification'];
                $hasSchedule = true;
            } elseif (!empty($date_planification) && !$envoyer_maintenant) {
                $scheduleDate = $date_planification;
                $hasSchedule = true;
            }

            if ($hasSchedule && $scheduleDate) {
                // Format: 2026-08-01T09:00:00.000000+03:00
                $datetime = new DateTime($scheduleDate);
                $datetime->setTimezone(new DateTimeZone('+03:00')); // Fuseau horaire de Madagascar
                $campaignData['send_at'] = $datetime->format('Y-m-d\TH:i:s.000000P');
            }

            $result = createListmonkCampaign($campaignData);
            $listmonkCampaignId = null;

            if ($result['success']) {
                $listmonkCampaignId = $result['campaign_id'];
                error_log("=== CAMPAGNE CRÉÉE SUR LISTMONK: ID=" . $listmonkCampaignId);
                if (!empty($media_id)) {
                    error_log("=== AVEC MÉDIA ID: " . $media_id);
                    error_log("=== URL DU MÉDIA: " . $mediaUrl);
                }

                if ($hasSchedule && $listmonkCampaignId) {
                    $statusUpdated = updateListmonkCampaignStatus($listmonkCampaignId, 'scheduled');
                    if ($statusUpdated) {
                        error_log("=== STATUT MIS À JOUR EN 'scheduled' POUR ID=" . $listmonkCampaignId);
                    } else {
                        error_log("=== ÉCHEC DE MISE À JOUR DU STATUT POUR ID=" . $listmonkCampaignId);
                    }
                }
            } else {
                $error = "Erreur Listmonk : " . $result['error'];
                error_log("=== ERREUR LISTMONK: " . $result['error']);
            }

            // 2. ENREGISTRER DANS LA BASE DE DONNÉES
            if (empty($error) || $listmonkCampaignId) {
                $finalStatut = 'pret_a_envoyer';
                if ($hasSchedule) {
                    $finalStatut = 'planifiee';
                }

                $campagneData = [
                    'id_compte' => $idCompte,
                    'id_campagne_config' => $campagneConfigId,
                    'type_campagne' => 'email',
                    'titre' => "Email: " . (strlen($objet) > 40 ? substr($objet, 0, 40) . '...' : $objet),
                    'message' => $bodyContent,
                    'objet' => $objet,
                    'destinataires' => json_encode($destinatairesNoms),
                    'nb_destinataires' => count($destinataires),
                    'nb_envoyes' => 0,
                    'nb_succes' => 0,
                    'nb_erreurs' => 0,
                    'statut' => $finalStatut,
                    'listmonk_campaign_id' => $listmonkCampaignId,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // Ajouter les informations de la pièce jointe UNIQUEMENT si présente
                if (!empty($media_id)) {
                    $campagneData['listmonk_media_id'] = $media_id;
                    $campagneData['piece_jointe'] = json_encode([
                        'media_id' => $media_id,
                        'nom' => $uploadedFileName ?? 'Fichier joint',
                        'url' => $mediaUrl
                    ]);
                }

                try {
                    $db->insert('campagne', $campagneData);

                    $updateData = [
                        'statut' => $finalStatut,
                        'message_content' => $bodyContent,
                        'objet' => $objet,
                        'listmonk_campaign_id' => $listmonkCampaignId
                    ];

                    // Ajouter le media_id UNIQUEMENT si présent
                    if (!empty($media_id)) {
                        $updateData['listmonk_media_id'] = $media_id;
                    }

                    $db->update('campagne_config', $updateData, ['id_campagne_config' => $campagneConfigId]);

                    // Nettoyer la session
                    unset($_SESSION['form_data']);
                    unset($_SESSION['uploaded_media_id']);
                    unset($_SESSION['uploaded_file_name']);
                    unset($_SESSION['uploaded_media_url']);

                    $successMsg = "Email enregistré avec succès !";
                    $successMsg .= "<br>" . count($destinataires) . " destinataire(s) dans la liste Listmonk";

                    if ($listmonkCampaignId) {
                        $successMsg .= "<br>ID Listmonk: <strong>" . $listmonkCampaignId . "</strong>";
                    }

                    if (!empty($media_id)) {
                        $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                        if (in_array($extension, $imageExtensions)) {
                            $successMsg .= "<br>Image intégrée dans le message (ID: $media_id)";
                        } else {
                            $successMsg .= "<br>📎 Pièce jointe attachée (ID: $media_id)";
                        }
                    }

                    if ($hasSchedule) {
                        $dateDisplay = $scheduleDate;
                        $successMsg .= "<br>Campagne planifiée pour " . date('d/m/Y H:i', strtotime($dateDisplay));
                    } elseif ($listmonkCampaignId) {
                        $successMsg .= "<br>Campagne enregistrée en brouillon sur Listmonk (ID: $listmonkCampaignId)";
                    }

                    if ($contactsSansEmailDansListe > 0) {
                        $successMsg .= "<br><small>$contactsSansEmailDansListe contact(s) n'ont pas d'email et ont été exclus de l'affichage.</small>";
                    }
                    $success = $successMsg;

                    // Redirection automatique après succès
                    echo '<meta http-equiv="refresh" content="3;url=index.php?page=campagnes/details&id=' . $campagneConfigId . '">';

                } catch (Exception $e) {
                    $error = "Erreur lors de l'enregistrement en base : " . $e->getMessage();
                    error_log("=== ERREUR BASE DE DONNÉES: " . $e->getMessage());
                }
            }
        } elseif (empty($error)) {
            $error = "Aucune liste Listmonk liée à cette campagne.";
        }
    }
}

// Nettoyer les messages flash après affichage
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
    <title>Composer l'email - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            max-width: 500px;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        .toast-notification.warning .toast-content { background: #f59e0b; }

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

        .campagne-info {
            background: #f3e8ff;
            border: 1px solid #d8b4fe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
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
        .btn-secondary {
            background: #10b981;
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .btn-secondary:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
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

        .btn-upload {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
            height: 44px;
            white-space: nowrap;
        }
        .btn-upload:hover:not(:disabled) {
            background: #2563eb;
        }
        .btn-upload:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-upload.loading {
            background: #93c5fd;
            cursor: wait;
        }
        .btn-upload-remove {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
            height: 44px;
            white-space: nowrap;
        }
        .btn-upload-remove:hover {
            background: #dc2626;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            flex-wrap: wrap;
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
            min-height: 80px;
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
        .info-badge.info { background: #dbeafe; color: #1e40af; }

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

        .planification-zone {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .sender-info {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .sender-info label {
            font-weight: 500;
            color: #166534;
        }

        .liste-info {
            background: #eff6ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .liste-info label {
            font-weight: 500;
            color: #1e40af;
        }

        .file-upload-container {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .file-upload-container .file-input-area {
            flex: 1;
            min-width: 200px;
        }
        .file-upload-container .upload-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .uploaded-file-info {
            background: #dcfce7;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .uploaded-file-info .file-details {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .uploaded-file-info .file-details i {
            color: #16a34a;
        }
        .uploaded-file-info .file-details .media-id {
            font-size: 12px;
            color: #6b7280;
            background: #e5e7eb;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .text-center { text-align: center; }
        .text-gray-500 { color: #6b7280; }
        .font-bold { font-weight: 700; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mt-3 { margin-top: 12px; }
        .mb-1 { margin-bottom: 4px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }
        .mb-6 { margin-bottom: 24px; }
        .mr-1 { margin-right: 4px; }
        .mr-2 { margin-right: 8px; }
        .mr-4 { margin-right: 16px; }
        .ml-2 { margin-left: 8px; }
        .hidden { display: none; }
        .w-full { width: 100%; }
        .max-w-4xl { max-width: 56rem; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .py-8 { padding-top: 32px; padding-bottom: 32px; }
        .px-4 { padding-left: 16px; padding-right: 16px; }
        .p-3 { padding: 12px; }
        .p-4 { padding: 16px; }
        .p-6 { padding: 24px; }
        .rounded { border-radius: 4px; }
        .rounded-lg { border-radius: 8px; }
        .rounded-full { border-radius: 9999px; }
        .shadow { box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .bg-white { background: #ffffff; }
        .bg-yellow-100 { background: #fef3c7; }
        .bg-green-100 { background: #d1fae5; }
        .bg-red-100 { background: #fee2e2; }
        .text-yellow-600 { color: #d97706; }
        .text-green-700 { color: #047857; }
        .text-red-700 { color: #b91c1c; }
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .text-white { color: #ffffff; }
        .text-sm { font-size: 0.875rem; }
        .text-xs { font-size: 0.75rem; }
        .text-xl { font-size: 1.25rem; }
        .text-2xl { font-size: 1.5rem; }
        .font-medium { font-weight: 500; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }
        .border { border: 1px solid #e5e7eb; }
        .border-2 { border-width: 2px; }
        .border-gray-200 { border-color: #e5e7eb; }
        .border-gray-300 { border-color: #d1d5db; }
        .border-yellow-500 { border-color: #d97706; }
        .border-green-500 { border-color: #10b981; }
        .border-red-500 { border-color: #ef4444; }
        .border-l-4 { border-left-width: 4px; }
        .border-dashed { border-style: dashed; }
        .grid { display: grid; }
        .grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)); }
        .grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .justify-center { justify-content: center; }
        .justify-end { justify-content: flex-end; }
        .justify-between { justify-content: space-between; }
        .cursor-pointer { cursor: pointer; }
        .transition { transition: all 0.2s ease; }
        .hover\:border-yellow-300:hover { border-color: #fcd34d; }
        .hover\:text-gray-700:hover { color: #374151; }
        .hover\:text-red-700:hover { color: #b91c1c; }
        .focus\:outline-none:focus { outline: none; }
        .focus\:border-yellow-500:focus { border-color: #d97706; }
        .focus\:ring-2:focus { ring-width: 2px; }
        .focus\:ring-yellow-200:focus { ring-color: #fde68a; }
        .focus\:ring-yellow-500:focus { ring-color: #d97706; }

        @media (min-width: 768px) {
            .md\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
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
            <p class="text-gray-500">Rédigez votre email et choisissez une liste de diffusion</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="campagne-info-title">
                <i class="fas fa-bullhorn mr-2"></i>
                Campagne : <?= htmlspecialchars($campagne['nom_campagne']) ?>
                <span class="email-badge ml-2"><i class="fas fa-envelope mr-1"></i>Email</span>
                <?php if (!empty($campagne['listmonk_id'])): ?>
                    <span class="info-badge info ml-2">
                        <i class="fab fa-listmonk mr-1"></i> Listmonk ID: <?= $campagne['listmonk_id'] ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($campagne['date_planification'])): ?>
                    <span class="info-badge warning ml-2">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Planifiée le <?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?>
                    </span>
                <?php endif; ?>
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
                    <a href="index.php?page=campagnes/details&id=<?= $campagneConfigId ?>" class="text-green-700 underline font-semibold">
                        Voir la campagne →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if ($flashMessage): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
                <i class="fas fa-check-circle mr-2"></i> <?= $flashMessage ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= $flashError ?>
            </div>
        <?php endif; ?>

        <?php if ($uploadError): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i> ❌ Erreur d'import: <?= $uploadError ?>
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
            <input type="hidden" name="media_id" id="media_id" value="<?= $uploadedMediaId ?>">

            <!-- ============================================ -->
            <!-- INFORMATIONS EXPÉDITEUR -->
            <!-- ============================================ -->
            <div class="sender-info">
                <h4 class="font-bold text-green-700 mb-2">
                    <i class="fas fa-user-circle mr-1"></i> Informations de l'expéditeur
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-envelope mr-1"></i> Email expéditeur *
                        </label>
                        <input type="email" name="from_email" id="from_email" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                               placeholder="expediteur@votre-domaine.com"
                               value="<?= htmlspecialchars($formData['from_email']) ?>">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            L'email qui apparaîtra dans le champ "De" du message
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user mr-1"></i> Nom expéditeur *
                        </label>
                        <input type="text" name="from_name" id="from_name" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                               placeholder="Votre Entreprise"
                               value="<?= htmlspecialchars($formData['from_name']) ?>">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Le nom qui apparaîtra dans le champ "De" du message
                        </p>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SÉLECTION DE LA LISTE -->
            <!-- ============================================ -->
            <div class="liste-info">
                <h4 class="font-bold text-blue-700 mb-2">
                    <i class="fas fa-list mr-1"></i> Liste de diffusion *
                </h4>
                <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;" required>
                    <option value="">-- Sélectionnez une liste --</option>
                    <?php foreach ($listes as $liste): ?>
                        <option value="<?= $liste['id_liste'] ?>" <?= ($formData['liste_id'] == $liste['id_liste']) ? 'selected' : '' ?>
                                data-listmonk-id="<?= $liste['listmonk_id'] ?>">
                            <?= htmlspecialchars($liste['nom_liste']) ?>
                            (<?= $liste['nombre_contacts'] ?> avec email
                            <?php if ($liste['nombre_sans_email'] > 0): ?>
                                , <span class="text-yellow-600"><?= $liste['nombre_sans_email'] ?> sans email</span>
                            <?php endif; ?>)
                            <?php if ($liste['listmonk_id']): ?>
                                <span class="text-green-600 text-xs">✓ Synchronisée avec Listmonk</span>
                            <?php else: ?>
                                <span class="text-red-500 text-xs">⚠️ Non synchronisée</span>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Seuls les contacts avec une adresse email valide seront inclus dans l'envoi.
                    Les contacts blacklistés pour les emails sont automatiquement exclus.
                </p>
                <?php if (count($listes) === 0): ?>
                    <p class="text-sm text-red-600 mt-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Aucune liste disponible. <a href="index.php?page=listes/creer" class="text-blue-600 underline">Créez une liste</a> avant de continuer.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Objet -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-tag mr-1"></i> Objet *
                </label>
                <input type="text" name="objet" id="objet" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                       placeholder="Objet de l'email..."
                       value="<?= htmlspecialchars($formData['objet']) ?>">
            </div>

            <!-- Corps du message avec Summernote -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-comment mr-1"></i> Corps du message *
                </label>
                <textarea name="corps" id="corps" rows="10"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                          placeholder="Contenu de l'email..."><?= htmlspecialchars($formData['corps']) ?></textarea>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-code mr-1"></i> Le contenu supporte le HTML (mise en forme, images, liens...)
                </p>
            </div>

            <!-- Pièce jointe -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-paperclip mr-1"></i> Pièce jointe (optionnel)
                </label>

                <div class="file-upload-container">
                    <div class="file-input-area">
                        <div id="fileUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <input type="file" name="piece_jointe" id="piece_jointe" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                            <p class="text-gray-500 text-sm" id="fileLabel">Cliquez ou glissez un fichier ici</p>
                            <p class="text-xs text-gray-400">Images, PDF, Word, Excel, CSV, TXT (Max 10 Mo)</p>
                        </div>
                    </div>

                    <div class="upload-actions">
                        <button type="button" id="uploadButton" class="btn-upload" disabled>
                            <i class="fas fa-upload mr-1"></i> Importer
                        </button>
                        <?php if ($uploadedMediaId): ?>
                            <a href="?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1" class="btn-upload-remove">
                                <i class="fas fa-trash mr-1"></i> Supprimer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info fichier uploadé -->
                <?php if ($uploadedMediaId && $uploadedFileName): ?>
                    <div class="uploaded-file-info">
                        <div class="file-details">
                            <i class="fas fa-file fa-2x"></i>
                            <div>
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($uploadedFileName) ?></div>
                                <div class="text-xs text-gray-500">
                                    <i class="fas fa-check-circle text-green-600"></i> Importé sur Listmonk
                                    <span class="media-id">ID: <?= $uploadedMediaId ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-blue-600 mt-1">
                    <i class="fas fa-info-circle mr-1"></i>
                    Importez d'abord votre fichier sur Listmonk, puis il sera attaché à l'email.
                    <br>Les images seront intégrées directement dans le message.
                </p>
            </div>

            <!-- Options d'envoi -->
            <div class="mb-4">
                <div class="flex items-center gap-4">
                    <div class="flex items-center">
                        <input type="radio" name="envoyer_maintenant" id="envoyerMaintenant" value="1" checked
                               class="h-4 w-4 text-yellow-600 focus:ring-yellow-500">
                        <label for="envoyerMaintenant" class="ml-2 text-sm text-gray-700">Envoyer maintenant</label>
                    </div>
                    <div class="flex items-center">
                        <input type="radio" name="envoyer_maintenant" id="envoyerPlusTard" value="0"
                               class="h-4 w-4 text-yellow-600 focus:ring-yellow-500">
                        <label for="envoyerPlusTard" class="ml-2 text-sm text-gray-700">Planifier</label>
                    </div>
                </div>

                <div id="planificationZone" class="planification-zone" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-calendar-alt mr-1"></i> Date et heure de planification *
                    </label>
                    <input type="datetime-local" name="date_planification" id="date_planification"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                           min="<?= date('Y-m-d\TH:i') ?>">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>
                        La campagne sera envoyée automatiquement à la date et heure sélectionnées.
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="action-buttons">
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                    <i class="fas fa-times mr-2"></i>Annuler
                </a>
                <button type="submit" name="action_enregistrer" value="1" class="btn-primary">
                    <i class="fas fa-save mr-2"></i>Enregistrer & préparer
                </button>
                <button type="submit" name="action_enregistrer" value="1" onclick="document.querySelector('input[name=envoyer_maintenant][value=1]').checked = true; document.getElementById('date_planification').value = ''; this.form.submit();" class="btn-secondary">
                    <i class="fas fa-paper-plane mr-2"></i>Envoyer immédiatement
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

<script>
$(document).ready(function() {
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });

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

// Gestion de la planification
document.querySelectorAll('input[name="envoyer_maintenant"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        const planificationZone = document.getElementById('planificationZone');
        if (this.value === '0') {
            planificationZone.style.display = 'block';
        } else {
            planificationZone.style.display = 'none';
            document.getElementById('date_planification').value = '';
        }
    });
});

// ============================================
// GESTION DU FICHIER AVEC AJAX
// ============================================
const fileUploadArea = document.getElementById('fileUploadArea');
const pieceJointeInput = document.getElementById('piece_jointe');
const uploadButton = document.getElementById('uploadButton');
const fileLabel = document.getElementById('fileLabel');
let selectedFile = null;

function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);

    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }

    selectedFile = file;
    uploadButton.disabled = false;
    fileLabel.textContent = file.name + ' (' + sizeMB + ' Mo)';
    fileLabel.style.color = '#16a34a';
    const icon = fileUploadArea.querySelector('i');
    if (icon) {
        icon.className = 'fas fa-file text-3xl text-green-500 mb-2';
    }

    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    pieceJointeInput.files = dataTransfer.files;
}

function resetFileUpload() {
    pieceJointeInput.value = '';
    selectedFile = null;
    uploadButton.disabled = true;
    fileLabel.textContent = 'Cliquez ou glissez un fichier ici';
    fileLabel.style.color = '#6b7280';
    const icon = fileUploadArea.querySelector('i');
    if (icon) {
        icon.className = 'fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2';
    }
}

// Upload du fichier via AJAX
uploadButton.addEventListener('click', function() {
    if (!selectedFile) {
        showToast('Veuillez sélectionner un fichier', 'error');
        return;
    }

    uploadButton.disabled = true;
    uploadButton.classList.add('loading');
    uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Importation...';

    const formData = new FormData();
    formData.append('action_upload_file', '1');
    formData.append('piece_jointe', selectedFile);
    formData.append('campagne_config_id', '<?= $campagneConfigId ?>');

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        // On lit toujours en texte d'abord : ça permet de détecter
        // proprement une réponse non-JSON (session expirée -> redirect HTML,
        // erreur 500, erreur Nginx 413, etc.) au lieu de planter avec
        // "Unexpected token '<'".
        return response.text().then(function(text) {
            return { ok: response.ok, status: response.status, text: text };
        });
    })
    .then(function(result) {
        uploadButton.disabled = false;
        uploadButton.classList.remove('loading');
        uploadButton.innerHTML = '<i class="fas fa-upload mr-1"></i> Importer';

        let data;
        try {
            data = JSON.parse(result.text);
        } catch (e) {
            console.error('Réponse non-JSON reçue du serveur (HTTP ' + result.status + '):', result.text);
            if (result.text.trim().startsWith('<')) {
                showToast('❌ Session expirée ou erreur serveur. Rechargez la page et réessayez.', 'error');
            } else {
                showToast('❌ Réponse serveur invalide (voir console).', 'error');
            }
            return;
        }

        if (data.success) {
            document.getElementById('media_id').value = data.media_id;
            showToast('✅ ' + data.message, 'success');

            // Mettre à jour l'affichage
            updateUploadedFileInfo(data.media_id, data.file_name);
            resetFileUpload();
        } else {
            showToast('❌ ' + data.message, 'error');
        }
    })
    .catch(function(error) {
        showToast('❌ Erreur de connexion: ' + error.message, 'error');
        uploadButton.disabled = false;
        uploadButton.classList.remove('loading');
        uploadButton.innerHTML = '<i class="fas fa-upload mr-1"></i> Importer';
    });
});

function updateUploadedFileInfo(mediaId, fileName) {
    // Supprimer l'ancienne info
    const oldInfo = document.querySelector('.uploaded-file-info');
    if (oldInfo) {
        oldInfo.remove();
    }

    // Créer la nouvelle info
    const infoDiv = document.createElement('div');
    infoDiv.className = 'uploaded-file-info';
    infoDiv.innerHTML = `
        <div class="file-details">
            <i class="fas fa-file fa-2x"></i>
            <div>
                <div class="font-medium text-gray-800">${escapeHtml(fileName)}</div>
                <div class="text-xs text-gray-500">
                    <i class="fas fa-check-circle text-green-600"></i> Importé sur Listmonk
                    <span class="media-id">ID: ${mediaId}</span>
                </div>
            </div>
        </div>
    `;

    const container = document.querySelector('.file-upload-container');
    if (container) {
        container.parentNode.insertBefore(infoDiv, container.nextSibling);
    }

    // Ajouter le bouton supprimer s'il n'existe pas
    const removeBtn = document.querySelector('.btn-upload-remove');
    if (!removeBtn) {
        const actions = document.querySelector('.upload-actions');
        if (actions) {
            const newRemoveBtn = document.createElement('a');
            newRemoveBtn.href = '?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1';
            newRemoveBtn.className = 'btn-upload-remove';
            newRemoveBtn.innerHTML = '<i class="fas fa-trash mr-1"></i> Supprimer';
            actions.appendChild(newRemoveBtn);
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

fileUploadArea.addEventListener('click', function(e) {
    if (e.target.closest('button')) return;
    pieceJointeInput.click();
});

pieceJointeInput.addEventListener('change', function(e) {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

fileUploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('drag-over');
});

fileUploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('drag-over');
});

fileUploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('drag-over');
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
});

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());

    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(function() {
            toast.remove();
        }, 500);
    }, 5000);
}

// Validation du formulaire
document.getElementById('composerForm').addEventListener('submit', function(e) {
    const listeId = document.getElementById('liste_id').value;
    const objet = document.getElementById('objet').value.trim();
    const corps = $('#corps').summernote('code');
    const fromEmail = document.getElementById('from_email').value.trim();
    const fromName = document.getElementById('from_name').value.trim();

    if (!fromEmail) {
        e.preventDefault();
        showToast('Veuillez saisir l\'email de l\'expéditeur', 'error');
        return false;
    }
    if (!fromName) {
        e.preventDefault();
        showToast('Veuillez saisir le nom de l\'expéditeur', 'error');
        return false;
    }
    if (!listeId || listeId === '') {
        e.preventDefault();
        showToast('Veuillez sélectionner une liste de diffusion', 'error');
        return false;
    }

    const selectedOption = $('#liste_id option:selected');
    const listmonkId = selectedOption.data('listmonk-id');
    if (!listmonkId) {
        e.preventDefault();
        showToast('Cette liste n\'est pas synchronisée avec Listmonk. Veuillez d\'abord la synchroniser.', 'warning');
        return false;
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

    const envoyerMaintenant = document.querySelector('input[name="envoyer_maintenant"]:checked');
    if (envoyerMaintenant && envoyerMaintenant.value === '0') {
        const datePlanif = document.getElementById('date_planification').value;
        if (!datePlanif) {
            e.preventDefault();
            showToast('Veuillez sélectionner une date et heure de planification', 'warning');
            return false;
        }
    }

    $('#corps').val(corps);
});
</script>

</body>
</html>