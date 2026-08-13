<?php

global $db;

// ============================================
// DÉTECTION PRÉCOCE DE LA REQUÊTE AJAX (UPLOAD)
// ============================================
$isAjaxUpload = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_file']));

if ($isAjaxUpload) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    header('Content-Type: application/json');

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

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log("PHP Fatal Error [upload]: " . $err['message'] . " in " . $err['file'] . ":" . $err['line']);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            if (ob_get_length() === false || ob_get_length() === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Erreur fatale côté serveur. Consultez les logs.'
                ]);
            }
        }
    });
}

function respondJsonAndExit($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}

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

$emailTypeId = null;
$typeMessageEmail = $db->select('type_message', ['libelle_type' => 'Email']);
if (empty($typeMessageEmail)) {
    $typeMessageEmail = $db->select('type_message', ['libelle_type' => 'email']);
}
if (!empty($typeMessageEmail)) {
    $emailTypeId = $typeMessageEmail[0]['id_type_message'];
}

$blacklistIds = [];
if ($emailTypeId) {
    $blacklist = $db->select('blacklist', ['id_type_message' => $emailTypeId]);
    foreach ($blacklist as $b) {
        if (!empty($b['id_contact'])) {
            $blacklistIds[] = $b['id_contact'];
        }
    }
}

$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

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

$formData = $_SESSION['form_data'] ?? [];
$formData['objet'] = $formData['objet'] ?? '';
$formData['corps'] = $formData['corps'] ?? '';
$formData['liste_id'] = $formData['liste_id'] ?? '';
$formData['from_email'] = $formData['from_email'] ?? 'noreply@votre-domaine.com';
$formData['from_name'] = $formData['from_name'] ?? 'Votre Entreprise';

$uploadedMediaId = $_SESSION['uploaded_media_id'] ?? null;
$uploadedFileName = $_SESSION['uploaded_file_name'] ?? null;
$uploadedMediaUrl = $_SESSION['uploaded_media_url'] ?? null;
$uploadError = $_SESSION['upload_error'] ?? null;
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;

unset($_SESSION['upload_error']);

if ($isAjaxUpload) {
    $hasFile = isset($_FILES['piece_jointe']) && $_FILES['piece_jointe']['error'] === UPLOAD_ERR_OK;

    if (!$hasFile) {
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

    if ($file['size'] > 10 * 1024 * 1024) {
        respondJsonAndExit(['success' => false, 'message' => "Le fichier est trop volumineux. Maximum 10 Mo."]);
    }

    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv'
    ];

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

    $uploadDir = __DIR__ . '/uploads/pieces_jointes/';
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

    $apiUrl = 'http://164.68.103.147:9005/api/media';
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

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

if (isset($_GET['remove_upload']) && $_GET['remove_upload'] == 1) {
    unset($_SESSION['uploaded_media_id']);
    unset($_SESSION['uploaded_file_name']);
    unset($_SESSION['uploaded_media_url']);
    unset($_SESSION['upload_error']);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=campagnes/composer&campagne_config_id=' . $campagneConfigId);
    exit;
}

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

    if (!empty($campaignData['attachments']) && is_array($campaignData['attachments'])) {
        $payload['attachments'] = $campaignData['attachments'];
    }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enregistrer'])) {
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

    if (empty($media_id) && !empty($_SESSION['uploaded_media_id'])) {
        $media_id = $_SESSION['uploaded_media_id'];
    }

    $mediaUrl = $_SESSION['uploaded_media_url'] ?? null;

    if (empty($from_email)) {
        $from_email = 'noreply@votre-domaine.com';
    }
    if (empty($from_name)) {
        $from_name = 'Votre Entreprise';
    }

    if (empty($objet)) {
        $error = "Veuillez saisir un objet";
    } elseif (empty($corps)) {
        $error = "Veuillez saisir le corps du message";
    } elseif (empty($liste_id)) {
        $error = "Veuillez sélectionner une liste de diffusion";
    } else {
        $destinataires = [];
        $destinatairesNoms = [];
        $contactsSansEmailDansListe = 0;
        $listmonkListId = null;

        foreach ($listes as $l) {
            if ($l['id_liste'] == $liste_id) {
                $listmonkListId = $l['listmonk_id'];
                break;
            }
        }

        if (!$listmonkListId) {
            $error = "Cette liste n'est pas liée à Listmonk. Veuillez d'abord synchroniser la liste.";
        } else {
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
            $bodyContent = $corps;

            if (!empty($media_id) && !empty($mediaUrl)) {
                $isImage = false;
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                if (in_array($extension, $imageExtensions)) {
                    $isImage = true;
                }

                if ($isImage) {
                    $bodyContent .= '<br><br><img src="' . $mediaUrl . '" alt="' . htmlspecialchars($uploadedFileName ?? 'Image') . '" style="max-width:100%;">';
                } else {
                    $bodyContent .= '<br><br><strong>Télecharger ici la Pièce jointe :</strong> <a href="' . htmlspecialchars($mediaUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($uploadedFileName ?? 'Fichier') . '</a>';                
                }
            }

            $campaignData = [
                'name' => $campagne['nom_campagne'] . ' - ' . date('Y-m-d H:i'),
                'subject' => $objet,
                'list_id' => $listmonkListId,
                'body' => $bodyContent,
                'from_email' => $from_email,
                'from_name' => $from_name
            ];

            if (!empty($media_id)) {
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (!in_array($extension, $imageExtensions)) {
                    $campaignData['attachments'] = [(int)$media_id];
                }
            }

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
                $datetime = new DateTime($scheduleDate);
                $datetime->setTimezone(new DateTimeZone('+03:00'));
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

                    if (!empty($media_id)) {
                        $updateData['listmonk_media_id'] = $media_id;
                    }

                    $db->update('campagne_config', $updateData, ['id_campagne_config' => $campagneConfigId]);

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <style>
        /* ============================================
           STYLES PRINCIPAUX - FULL WIDTH
        ============================================ */
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        
        .container-full {
            max-width: 100%;
            margin: 0 auto;
            padding: 16px 32px;
            width: 100%;
        }
        
        /* ============================================
           TOAST
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
            max-width: 500px;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        .toast-notification.warning .toast-content { background: #f59e0b; }
        
        /* ============================================
           STEP INDICATOR
        ============================================ */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 12px 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            flex-wrap: wrap;
            width: 100%;
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
            flex-shrink: 0;
        }
        .step.active .number {
            background: #d97706;
            color: white;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
        }
        .step.done .number {
            background: #10b981;
            color: white;
        }
        .step.active {
            color: #1f2937;
            font-weight: 500;
        }
        .step.done {
            color: #6b7280;
        }
        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
            border-radius: 2px;
            flex-shrink: 0;
        }
        .step-line.done {
            background: #10b981;
        }
        
        /* ============================================
           EN-TÊTE
        ============================================ */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            width: 100%;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-section .back-link {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .header-section .back-link:hover {
            color: #374151;
            background: #f3f4f6;
        }
        .header-section .icon-wrapper {
            background: #fef3c7;
            padding: 10px 12px;
            border-radius: 12px;
            flex-shrink: 0;
        }
        .header-section .icon-wrapper i {
            color: #d97706;
            font-size: 22px;
        }
        .header-section .header-text {
            flex: 1;
            min-width: 150px;
        }
        .header-section .title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
        }
        .header-section .subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* ============================================
           CARD PRINCIPALE
        ============================================ */
        .main-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            padding: 24px 28px;
            width: 100%;
        }
        
        /* ============================================
           INFO CAMPAGNE
        ============================================ */
        .campagne-info {
            background: #f3e8ff;
            border: 2px solid #d8b4fe;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
        }
        .campagne-info .info-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .campagne-info .info-left .campagne-name {
            font-size: 15px;
            font-weight: 700;
            color: #5b21b6;
        }
        .campagne-info .info-left .email-badge {
            background: #d97706;
            color: white;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .campagne-info .info-left .info-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            gap: 4px;
        }
        .info-badge.success { background: #dcfce7; color: #166534; }
        .info-badge.warning { background: #fef3c7; color: #92400e; }
        .info-badge.danger { background: #fee2e2; color: #991b1b; }
        .info-badge.info { background: #dbeafe; color: #1e40af; }
        
        .campagne-info .info-right {
            font-size: 14px;
            color: #6b21a8;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .campagne-info .info-right i {
            font-size: 16px;
        }
        
        /* ============================================
           FORMULAIRES
        ============================================ */
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        .form-label i {
            margin-right: 6px;
        }
        .form-label .required {
            color: #ef4444;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        /* ============================================
           SENDER INFO
        ============================================ */
        .sender-info {
            background: #f0fdf4;
            border: 2px solid #86efac;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 16px;
            width: 100%;
        }
        .sender-info .sender-title {
            font-weight: 700;
            color: #166534;
            margin-bottom: 12px;
            font-size: 15px;
        }
        .sender-info .sender-title i {
            margin-right: 6px;
        }
        
        /* ============================================
           LISTE INFO
        ============================================ */
        .liste-info {
            background: #eff6ff;
            border: 2px solid #93c5fd;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 16px;
            width: 100%;
        }
        .liste-info .liste-title {
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 12px;
            font-size: 15px;
        }
        .liste-info .liste-title i {
            margin-right: 6px;
        }
        
        /* ============================================
           SELECT2
        ============================================ */
        .select2-container--default .select2-selection--single {
            border: 2px solid #d1d5db;
            border-radius: 8px;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
            font-size: 14px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
            width: 32px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-width: 5px 5px 0 5px;
            border-color: #6b7280 transparent transparent transparent;
        }
        .select2-dropdown {
            border-radius: 8px;
            border-color: #d1d5db;
            font-size: 14px;
        }
        .select2-search__field {
            border-radius: 6px !important;
            border: 2px solid #d1d5db !important;
            padding: 6px 10px !important;
            font-size: 14px !important;
        }
        .select2-search__field:focus {
            border-color: #d97706 !important;
        }
        .select2-results__option {
            padding: 8px 12px !important;
            font-size: 14px !important;
        }
        .select2-results__option--highlighted {
            background-color: #d97706 !important;
        }
        
        /* ============================================
           SUMMERNOTE
        ============================================ */
        .note-editor {
            border-radius: 8px !important;
            border: 2px solid #d1d5db !important;
            width: 100%;
        }
        .note-editor .note-toolbar {
            background: #f9fafb !important;
            border-radius: 8px 8px 0 0 !important;
            border-bottom: 1px solid #d1d5db !important;
        }
        .note-editor .note-editable {
            min-height: 300px !important;
            font-size: 14px;
        }
        .note-editor:focus-within {
            border-color: #d97706 !important;
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }
        
        /* ============================================
           FILE UPLOAD
        ============================================ */
        .file-upload-container {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
            width: 100%;
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
        
        #fileUploadArea {
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 80px;
            width: 100%;
        }
        #fileUploadArea:hover {
            border-color: #d97706;
            background-color: #fffbeb;
        }
        #fileUploadArea.drag-over {
            border-color: #d97706;
            background-color: #fef3c7;
        }
        #fileUploadArea .upload-icon {
            font-size: 32px;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        #fileUploadArea .upload-title {
            font-size: 14px;
            color: #4b5563;
            font-weight: 500;
        }
        #fileUploadArea .upload-desc {
            font-size: 12px;
            color: #9ca3af;
        }
        
        .btn-upload {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
            height: 44px;
            white-space: nowrap;
        }
        .btn-upload:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-2px);
        }
        .btn-upload:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        .btn-upload.loading {
            background: #93c5fd;
            cursor: wait;
        }
        
        .btn-upload-remove {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
            height: 44px;
            white-space: nowrap;
        }
        .btn-upload-remove:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        .uploaded-file-info {
            background: #dcfce7;
            border: 2px solid #86efac;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            width: 100%;
        }
        .uploaded-file-info .file-details {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .uploaded-file-info .file-details i {
            color: #16a34a;
            font-size: 24px;
        }
        .uploaded-file-info .file-details .media-id {
            font-size: 12px;
            color: #6b7280;
            background: #e5e7eb;
            padding: 2px 10px;
            border-radius: 12px;
        }
        
        /* ============================================
           PLANIFICATION ZONE
        ============================================ */
        .planification-zone {
            background: #fef3c7;
            border: 2px solid #fcd34d;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 12px;
            width: 100%;
        }
        
        /* ============================================
           BLACKLIST WARNING
        ============================================ */
        .blacklist-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .blacklist-warning i {
            color: #ef4444;
            font-size: 16px;
            flex-shrink: 0;
        }
        .blacklist-warning span {
            font-size: 13px;
            color: #991b1b;
            font-weight: 500;
        }
        
        /* ============================================
           SUCCESS / ERROR BOX
        ============================================ */
        .success-box {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            width: 100%;
        }
        .success-box i {
            color: #10b981;
            font-size: 18px;
            margin-right: 8px;
        }
        .success-box .success-text {
            color: #166534;
            font-size: 14px;
            font-weight: 500;
        }
        .success-box .success-link {
            margin-top: 8px;
            display: block;
        }
        .success-box .success-link a {
            color: #166534;
            font-weight: 600;
            text-decoration: underline;
        }
        
        .error-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }
        .error-box i {
            color: #ef4444;
            font-size: 18px;
            flex-shrink: 0;
        }
        .error-box span {
            color: #991b1b;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* ============================================
           ACTION BUTTONS
        ============================================ */
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 2px solid #f3f4f6;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .btn-primary {
            background: #d97706;
            color: white;
            padding: 11px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }
        .btn-primary:hover {
            background: #b45309;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(217, 119, 6, 0.3);
        }
        
        .btn-secondary {
            background: #10b981;
            color: white;
            padding: 11px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }
        .btn-secondary:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }
        
        .btn-outline {
            background: transparent;
            color: #6b7280;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            min-width: 120px;
            justify-content: center;
        }
        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #374151;
        }
        
        /* ============================================
           RADIO GROUP
        ============================================ */
        .radio-group {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #4b5563;
            cursor: pointer;
        }
        .radio-group input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #d97706;
            cursor: pointer;
        }
        
        /* ============================================
           UTILITIES
        ============================================ */
        .mb-2 { margin-bottom: 8px; }
        .mb-3 { margin-bottom: 12px; }
        .mb-4 { margin-bottom: 16px; }
        .mb-5 { margin-bottom: 20px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mt-3 { margin-top: 12px; }
        .mr-1 { margin-right: 4px; }
        .mr-2 { margin-right: 8px; }
        .text-xs { font-size: 12px; }
        .text-sm { font-size: 14px; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .text-green-600 { color: #16a34a; }
        .text-red-600 { color: #dc2626; }
        .text-yellow-600 { color: #d97706; }
        .text-blue-600 { color: #2563eb; }
        .w-full { width: 100%; }
        .hidden { display: none !important; }
        
        .grid-cols-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 1200px) {
            .container-full { padding: 16px 24px; }
        }
        
        @media (max-width: 992px) {
            .container-full { padding: 14px 20px; }
            .main-card { padding: 20px; }
            .step-indicator { padding: 10px 16px; gap: 8px; }
            .step { font-size: 12px; }
            .step .number { width: 24px; height: 24px; font-size: 10px; }
            .step-line { width: 28px; }
            .grid-cols-2 { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container-full { padding: 12px 16px; }
            
            .header-section {
                padding: 14px 16px;
                gap: 8px;
            }
            .header-section .title { font-size: 19px; }
            .header-section .subtitle { font-size: 13px; }
            .header-section .icon-wrapper { padding: 8px 10px; }
            .header-section .icon-wrapper i { font-size: 18px; }
            
            .main-card { padding: 16px; }
            
            .campagne-info {
                flex-direction: column;
                align-items: flex-start;
                padding: 12px 16px;
                gap: 6px;
            }
            .campagne-info .info-left .campagne-name { font-size: 14px; }
            .campagne-info .info-right { font-size: 13px; }
            
            .sender-info,
            .liste-info {
                padding: 12px 14px;
            }
            
            .file-upload-container {
                flex-direction: column;
            }
            .file-upload-container .upload-actions {
                width: 100%;
            }
            .file-upload-container .upload-actions button {
                flex: 1;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons .btn-primary,
            .action-buttons .btn-secondary,
            .action-buttons .btn-outline {
                width: 100%;
                justify-content: center;
                min-width: unset;
            }
            
            .step-indicator {
                gap: 6px;
                padding: 8px 12px;
            }
            .step { font-size: 11px; gap: 4px; }
            .step .number { width: 20px; height: 20px; font-size: 9px; }
            .step-line { width: 16px; }
            .step span:last-child { display: none; }
            
            .radio-group {
                flex-direction: column;
                gap: 8px;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .container-full { padding: 8px 10px; }
            .header-section { padding: 10px 12px; }
            .header-section .title { font-size: 17px; }
            .header-section .subtitle { font-size: 12px; }
            .header-section .back-link { font-size: 12px; padding: 3px 8px; }
            
            .main-card { padding: 12px; }
            
            .campagne-info { padding: 10px 12px; }
            .campagne-info .info-left .campagne-name { font-size: 13px; }
            .campagne-info .info-left .email-badge { font-size: 10px; padding: 2px 10px; }
            .campagne-info .info-right { font-size: 12px; }
            
            .sender-info,
            .liste-info {
                padding: 10px 12px;
            }
            .sender-info .sender-title,
            .liste-info .liste-title {
                font-size: 13px;
            }
            
            #fileUploadArea { padding: 14px; }
            #fileUploadArea .upload-icon { font-size: 24px; }
            #fileUploadArea .upload-title { font-size: 13px; }
            #fileUploadArea .upload-desc { font-size: 11px; }
            
            .btn-upload,
            .btn-upload-remove {
                padding: 8px 14px;
                font-size: 13px;
                height: 38px;
            }
            
            .btn-primary,
            .btn-secondary {
                padding: 10px 20px;
                font-size: 14px;
            }
            .btn-outline {
                padding: 10px 18px;
                font-size: 13px;
            }
            
            .planification-zone { padding: 12px 14px; }
            
            .note-editor .note-editable {
                min-height: 200px !important;
            }
        }
    </style>
</head>
<body>

<div class="container-full">
    <!-- ===== STEP INDICATOR ===== -->
    <div class="step-indicator">
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Type</span>
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

    <!-- ===== EN-TÊTE ===== -->
    <div class="header-section">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-envelope"></i>
        </div>
        <div class="header-text">
            <div class="title">Composer l'email</div>
            <div class="subtitle">Rédigez votre email et choisissez une liste de diffusion</div>
        </div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 16px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="email-badge"><i class="fas fa-envelope"></i> Email</span>
                <?php if (!empty($campagne['listmonk_id'])): ?>
                    <span class="info-badge info">
                        <i class="fab fa-listmonk"></i> Listmonk ID: <?= $campagne['listmonk_id'] ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($campagne['date_planification'])): ?>
                    <span class="info-badge warning">
                        <i class="fas fa-calendar-alt"></i>
                        Planifiée le <?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="info-right">
                <i class="fas fa-users"></i> <?= count($contacts) ?> contact(s) avec email
                <?php if (count($contactsSansEmail) > 0): ?>
                    <span class="info-badge warning" style="margin-left:4px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= count($contactsSansEmail) ?> sans email
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Success / Error -->
        <?php if ($success): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <span class="success-text"><?= $success ?></span>
                <div class="success-link">
                    <a href="index.php?page=campagnes/details&id=<?= $campagneConfigId ?>">
                        Voir la campagne →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashMessage): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <span class="success-text"><?= $flashMessage ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $flashError ?></span>
            </div>
        <?php endif; ?>

        <?php if ($uploadError): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span>❌ Erreur d'import: <?= htmlspecialchars($uploadError) ?></span>
            </div>
        <?php endif; ?>

        <!-- Avertissement blacklist -->
        <?php if (count($tousContacts) - count($contacts) - count($contactsSansEmail) > 0): ?>
            <div class="blacklist-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
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
                <div class="sender-title">
                    <i class="fas fa-user-circle"></i> Informations de l'expéditeur
                </div>
                <div class="grid-cols-2">
                    <div>
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Email expéditeur <span class="required">*</span>
                        </label>
                        <input type="email" name="from_email" id="from_email" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                               placeholder="expediteur@votre-domaine.com"
                               value="<?= htmlspecialchars($formData['from_email']) ?>">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i>
                            L'email qui apparaîtra dans le champ "De" du message
                        </p>
                    </div>
                    <div>
                        <label class="form-label">
                            <i class="fas fa-user"></i> Nom expéditeur <span class="required">*</span>
                        </label>
                        <input type="text" name="from_name" id="from_name" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                               placeholder="Votre Entreprise"
                               value="<?= htmlspecialchars($formData['from_name']) ?>">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i>
                            Le nom qui apparaîtra dans le champ "De" du message
                        </p>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- SÉLECTION DE LA LISTE -->
            <!-- ============================================ -->
            <div class="liste-info">
                <div class="liste-title">
                    <i class="fas fa-list"></i> Liste de diffusion <span class="required">*</span>
                </div>
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
                                <span class="text-green-600 text-xs">✓ Synchronisée</span>
                            <?php else: ?>
                                <span class="text-red-500 text-xs">⚠️ Non synchronisée</span>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle"></i>
                    Seuls les contacts avec une adresse email valide seront inclus dans l'envoi.
                    Les contacts blacklistés pour les emails sont automatiquement exclus.
                </p>
                <?php if (count($listes) === 0): ?>
                    <p class="text-sm text-red-600 mt-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        Aucune liste disponible. <a href="index.php?page=listes/creer" class="text-blue-600 underline">Créez une liste</a> avant de continuer.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Objet -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-tag"></i> Objet <span class="required">*</span>
                </label>
                <input type="text" name="objet" id="objet" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                       placeholder="Objet de l'email..."
                       value="<?= htmlspecialchars($formData['objet']) ?>">
            </div>

            <!-- Corps du message avec Summernote -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-comment"></i> Corps du message <span class="required">*</span>
                </label>
                <textarea name="corps" id="corps" rows="10"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                          placeholder="Contenu de l'email..."><?= htmlspecialchars($formData['corps']) ?></textarea>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-code"></i> Le contenu supporte le HTML (mise en forme, images, liens...)
                </p>
            </div>

            <!-- Pièce jointe -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-paperclip"></i> Pièce jointe <span class="text-gray-400 text-sm font-normal">(optionnel)</span>
                </label>

                <div class="file-upload-container">
                    <div class="file-input-area">
                        <div id="fileUploadArea">
                            <input type="file" name="piece_jointe" id="piece_jointe" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-title" id="fileLabel">Cliquez ou glissez un fichier ici</div>
                            <div class="upload-desc">Images, PDF, Word, Excel, CSV, TXT </div>
                        </div>
                    </div>

                    <div class="upload-actions">
                        <button type="button" id="uploadButton" class="btn-upload" disabled>
                            <i class="fas fa-upload"></i> Importer
                        </button>
                        <?php if ($uploadedMediaId): ?>
                            <a href="?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1" class="btn-upload-remove">
                                <i class="fas fa-trash"></i> Supprimer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info fichier uploadé -->
                <?php if ($uploadedMediaId && $uploadedFileName): ?>
                    <div class="uploaded-file-info">
                        <div class="file-details">
                            <i class="fas fa-file"></i>
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

                <p class="text-xs text-blue-600 mt-2">
                    <i class="fas fa-info-circle"></i>
                    Importez d'abord votre fichier sur Listmonk, puis il sera attaché à l'email.
                    <br>Les images seront intégrées directement dans le message.
                </p>
            </div>

            <!-- Options d'envoi -->
            <div class="form-group">
                <div class="radio-group">
                </div>

                <div id="planificationZone" class="planification-zone" style="display: none;">
                    <label class="form-label">
                        <i class="fas fa-calendar-alt"></i> Date et heure de planification <span class="required">*</span>
                    </label>
                    <input type="datetime-local" name="date_planification" id="date_planification"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500 focus:ring-2 focus:ring-yellow-200 transition"
                           min="<?= date('Y-m-d\TH:i') ?>">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i>
                        La campagne sera envoyée automatiquement à la date et heure sélectionnées.
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="action-buttons">
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                    <i class="fas fa-times"></i> Annuler
                </a>
                <button type="submit" name="action_enregistrer" value="1" class="btn-primary">
                    <i class="fas fa-save"></i> Enregistrer &amp; préparer
                </button>
                <button type="submit" name="action_enregistrer" value="1" onclick="document.querySelector('input[name=envoyer_maintenant][value=1]').checked = true; document.getElementById('date_planification').value = ''; this.form.submit();" class="btn-secondary">
                    <i class="fas fa-paper-plane"></i> Envoyer immédiatement
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

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
    console.log('Taille du fichier en octets :', file.size);
    console.log('Taille en Mo :', (file.size / 1024 / 1024).toFixed(2));
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
    const icon = fileUploadArea.querySelector('.upload-icon i');
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
    const icon = fileUploadArea.querySelector('.upload-icon i');
    if (icon) {
        icon.className = 'fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2';
    }
}

uploadButton.addEventListener('click', function() {
    if (!selectedFile) {
        showToast('Veuillez sélectionner un fichier', 'error');
        return;
    }

    uploadButton.disabled = true;
    uploadButton.classList.add('loading');
    uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importation...';

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
        return response.text().then(function(text) {
            return { ok: response.ok, status: response.status, text: text };
        });
    })
    .then(function(result) {
        uploadButton.disabled = false;
        uploadButton.classList.remove('loading');
        uploadButton.innerHTML = '<i class="fas fa-upload"></i> Importer';

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
        uploadButton.innerHTML = '<i class="fas fa-upload"></i> Importer';
    });
});

function updateUploadedFileInfo(mediaId, fileName) {
    const oldInfo = document.querySelector('.uploaded-file-info');
    if (oldInfo) {
        oldInfo.remove();
    }

    const infoDiv = document.createElement('div');
    infoDiv.className = 'uploaded-file-info';
    infoDiv.innerHTML = `
        <div class="file-details">
            <i class="fas fa-file"></i>
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

    const removeBtn = document.querySelector('.btn-upload-remove');
    if (!removeBtn) {
        const actions = document.querySelector('.upload-actions');
        if (actions) {
            const newRemoveBtn = document.createElement('a');
            newRemoveBtn.href = '?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1';
            newRemoveBtn.className = 'btn-upload-remove';
            newRemoveBtn.innerHTML = '<i class="fas fa-trash"></i> Supprimer';
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