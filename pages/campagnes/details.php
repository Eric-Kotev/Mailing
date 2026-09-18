<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['id'] ?? null;

if (!$campagneId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// ============================================
// TRAITEMENT AJAX : RÉCUPÉRATION STATUT SMS EN TEMPS RÉEL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajax_statut_sms'])) {
    header('Content-Type: application/json');
    
    $id_campagne_historique = $_POST['id_campagne_historique'] ?? null;
    
    if (!$id_campagne_historique) {
        echo json_encode(['success' => false, 'error' => 'ID manquant']);
        exit;
    }
    
    $historique = $db->select('campagne', [
        'id_campagne' => $id_campagne_historique,
        'id_compte' => $idCompte
    ]);
    
    if (empty($historique)) {
        echo json_encode(['success' => false, 'error' => 'Message introuvable']);
        exit;
    }
    
    $campagneData = $historique[0];
    $reponseApi = json_decode($campagneData['reponse_api'] ?? '{}', true);
    
    $messageIds = $reponseApi['message_ids'] ?? [];
    $recipientsMapping = $reponseApi['recipients_mapping'] ?? [];
    
    if (empty($messageIds)) {
        echo json_encode(['success' => false, 'error' => 'Aucun message_id trouvé pour cette campagne.']);
        exit;
    }
    
    $appareilId = $campagneData['appareil_id'] ?? null;
    if (!$appareilId) {
        $campagneConfigData = $db->select('campagne_config', ['id_campagne_config' => $campagneData['id_campagne_config'] ?? 0]);
        $appareilId = $campagneConfigData[0]['appareil_id'] ?? null;
    }
    
    $apiUsername = null;
    $apiPassword = null;
    
    if ($appareilId) {
        $appareil = $db->select('sms_appareils', ['id_appareil' => $appareilId, 'id_compte' => $idCompte]);
        if (!empty($appareil)) {
            $apiUsername = $appareil[0]['api_username'];
            $apiPassword = $appareil[0]['api_password'];
        }
    }
    
    if (empty($apiUsername)) {
        $apiUsername = $reponseApi['api_username'] ?? null;
        $apiPassword = $reponseApi['api_password'] ?? null;
    }
    
    if (empty($apiUsername) || empty($apiPassword)) {
        echo json_encode(['success' => false, 'error' => 'Identifiants API SMS manquants.']);
        exit;
    }
    
    $resultat = recupererStatutSMS($messageIds, $apiUsername, $apiPassword);
    
    // ============================================
    // REMPLACEMENT DES NUMÉROS HASHÉS PAR LES VRAIS NUMÉROS
    // grâce au mapping stocké au moment de l'envoi
    // ============================================
    if ($resultat['success'] && !empty($recipientsMapping) && !empty($resultat['results'])) {
        foreach ($resultat['results'] as &$result) {
            $msgId = $result['id'] ?? null;
            if ($msgId && isset($recipientsMapping[$msgId])) {
                $result['phone_original'] = $result['phone'];
                $result['phone'] = $recipientsMapping[$msgId];
            }
        }
        unset($result);
    }
    
    if ($resultat['success']) {
        $reponseApi['statuts_recuperes'] = $resultat['results'];
        $reponseApi['statuts_recuperes_at'] = date('Y-m-d H:i:s');
        
        $db->update('campagne', [
            'reponse_api' => json_encode($reponseApi)
        ], ['id_campagne' => $id_campagne_historique]);
    }
    
    echo json_encode($resultat);
    exit;
}

// ============================================
// TRAITEMENT AJAX : RÉCUPÉRATION STATUT EMAIL (LISTMONK)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajax_statut_email'])) {
    header('Content-Type: application/json');
    
    $id_campagne_historique = $_POST['id_campagne_historique'] ?? null;
    
    if (!$id_campagne_historique) {
        echo json_encode(['success' => false, 'error' => 'ID manquant']);
        exit;
    }
    
    $historique = $db->select('campagne', [
        'id_campagne' => $id_campagne_historique,
        'id_compte' => $idCompte
    ]);
    
    if (empty($historique)) {
        echo json_encode(['success' => false, 'error' => 'Message introuvable']);
        exit;
    }
    
    $campagneData = $historique[0];
    $listmonkCampaignId = $campagneData['listmonk_campaign_id'] ?? null;
    
    if (empty($listmonkCampaignId)) {
        echo json_encode(['success' => false, 'error' => 'Aucun identifiant de campagne Listmonk trouvé.']);
        exit;
    }
    
    $resultat = recupererStatutListmonk($listmonkCampaignId);
    
    if ($resultat['success']) {
        $reponseApi = json_decode($campagneData['reponse_api'] ?? '{}', true);
        if (!is_array($reponseApi)) $reponseApi = [];
        $reponseApi['listmonk_stats'] = $resultat['data'];
        $reponseApi['listmonk_stats_at'] = date('Y-m-d H:i:s');
        
        $db->update('campagne', [
            'reponse_api' => json_encode($reponseApi)
        ], ['id_campagne' => $id_campagne_historique]);
    }
    
    echo json_encode($resultat);
    exit;
}

// ============================================
// TRAITEMENT AJAX : RÉCUPÉRATION STATUT SMS VIA WEBHOOK (par sms_ticket)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajax_statut_sms_webhook'])) {
    header('Content-Type: application/json');
    
    $smsTicket = trim($_POST['sms_ticket'] ?? '');
    
    if (empty($smsTicket)) {
        echo json_encode(['success' => false, 'error' => 'Ticket SMS manquant']);
        exit;
    }
    
    $campagneCheck = $db->select('campagne', [
        'sms_ticket' => $smsTicket,
        'id_compte' => $idCompte
    ]);
    
    if (empty($campagneCheck)) {
        echo json_encode(['success' => false, 'error' => 'Ticket non trouvé ou non autorisé']);
        exit;
    }
    
    $statuts = $db->select('sms_status_webhook', [
        'sms_ticket' => $smsTicket
    ], '*', 'numero ASC');
    
    $resultats = [];
    $stats = [
        'delivered' => 0,
        'pending' => 0,
        'failed' => 0,
        'other' => 0,
        'total' => 0
    ];
    
    foreach ($statuts as $s) {
        $statutRaw = strtolower(trim($s['statut'] ?? 'pending'));
        
        if (in_array($statutRaw, ['delivered', 'delivred', 'livre', 'livré'])) {
            $category = 'delivered';
            $stats['delivered']++;
        } elseif (in_array($statutRaw, ['pending', 'sent', 'queued', 'submitted', 'en_cours', 'en_attente'])) {
            $category = 'pending';
            $stats['pending']++;
        } elseif (in_array($statutRaw, ['failed', 'undelivered', 'rejected', 'expired', 'echoue', 'échec'])) {
            $category = 'failed';
            $stats['failed']++;
        } else {
            $category = 'other';
            $stats['other']++;
        }
        
        $stats['total']++;
        
        $resultats[] = [
            'id' => $s['id'] ?? null,
            'sms_ticket' => $s['sms_ticket'],
            'phone' => $s['numero'],
            'state' => $s['statut'],
            'category' => $category,
            'created_at' => $s['created_at'] ?? null,
            'updated_at' => $s['updated_at'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'sms_ticket' => $smsTicket,
        'count' => count($resultats),
        'results' => $resultats,
        'stats' => $stats
    ]);
    exit;
}

// ============================================
// TRAITEMENT AJAX : VÉRIFICATION DES STATUTS (polling léger)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajax_check_statuts'])) {
    
    // Nettoyer tout buffer en cours (efface les éventuels warnings PHP)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $ids = $_POST['ids'] ?? [];
        
        if (empty($ids) || !is_array($ids)) {
            echo json_encode(['success' => false, 'error' => 'Aucun ID fourni']);
            exit;
        }
        
        // ⚠️ IMPORTANT : les IDs sont des UUID, PAS des entiers.
        // On les nettoie (au cas où) mais on ne les convertit JAMAIS en int.
        $ids = array_filter(array_map(function($id) {
            $id = trim((string)$id);
            // Garder uniquement les caractères valides d'un UUID
            return preg_replace('/[^a-f0-9\-]/i', '', $id);
        }, $ids));
        
        $statuts = [];
        
        foreach ($ids as $id) {
            if (empty($id)) continue;
            
            try {
                $rows = $db->select('campagne', [
                    'id_campagne' => $id,
                    'id_compte' => $idCompte
                ]);
                
                if (!empty($rows) && isset($rows[0])) {
                    $statuts[] = [
                        'id_campagne' => $id,
                        'statut'      => $rows[0]['statut'] ?? 'inconnu',
                        'nb_succes'   => (int)($rows[0]['nb_succes'] ?? 0),
                        'nb_erreurs'  => (int)($rows[0]['nb_erreurs'] ?? 0),
                    ];
                }
            } catch (Throwable $e) {
                // ID invalide ou erreur ponctuelle → on ignore et on continue
                continue;
            }
        }
        
        echo json_encode([
            'success' => true,
            'messages' => $statuts
        ]);
        
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Exception : ' . $e->getMessage()
        ]);
    }
    
    exit;
}

// ============================================
// TRAITEMENT DE LA REPRISE D'UN BROUILLON
// ============================================
if (isset($_GET['reprendre']) && !empty($_GET['reprendre'])) {
    $idMessage = $_GET['reprendre'];

    $msgReprendre = $db->select('campagne', [
        'id_campagne' => $idMessage,
        'id_compte' => $idCompte
    ]);

    if (empty($msgReprendre)) {
        $_SESSION['flash_error'] = "Message introuvable";
        header('Location: index.php?page=campagnes/details&id=' . $campagneId);
        exit;
    }

    $msg = $msgReprendre[0];
    $typeReprise = $msg['type_campagne'];
    $idCampagneConfig = $msg['id_campagne_config'];

    if ($msg['statut'] !== 'brouillon') {
        $_SESSION['flash_error'] = "Ce message n'est plus un brouillon (statut actuel : " . $msg['statut'] . ")";
        header('Location: index.php?page=campagnes/details&id=' . $campagneId);
        exit;
    }

    $_SESSION['type_message'] = $typeReprise;
    $_SESSION['campagne_config_id'] = $idCampagneConfig;
    $_SESSION['campagne_id'] = $idCampagneConfig;

    $donneesReprise = [
        'id_campagne' => $msg['id_campagne'],
        'type_campagne' => $msg['type_campagne'],
        'titre' => $msg['titre'] ?? '',
        'message' => $msg['message'] ?? '',
        'objet' => $msg['objet'] ?? '',
        'destinataires' => $msg['destinataires'] ?? '[]',
        'from_email' => $msg['from_email'] ?? '',
        'from_name' => $msg['from_name'] ?? '',
        'piece_jointe' => $msg['piece_jointe'] ?? null,
        'listmonk_media_id' => $msg['listmonk_media_id'] ?? null,
    ];

    $_SESSION['form_data'] = [
        'objet' => $donneesReprise['objet'],
        'corps' => $donneesReprise['message'],
        'from_email' => $donneesReprise['from_email'],
        'from_name' => $donneesReprise['from_name'],
    ];
    $_SESSION['reprendre_message_id'] = $idMessage;
    $_SESSION['reprendre_data'] = $donneesReprise;

    if (!empty($msg['listmonk_media_id'])) {
        $_SESSION['uploaded_media_id'] = $msg['listmonk_media_id'];

        if (!empty($msg['piece_jointe'])) {
            $pj = json_decode($msg['piece_jointe'], true);
            if (is_array($pj)) {
                $_SESSION['uploaded_file_name'] = $pj['nom'] ?? 'Fichier joint';
                $_SESSION['uploaded_media_url'] = $pj['url'] ?? null;
            }
        }
    }

    switch ($typeReprise) {
        case 'whatsapp':
            header('Location: index.php?page=campagnes/choix_provider_whatsapp&campagne_id=' . $idCampagneConfig);
            exit;
        case 'sms':
            header('Location: index.php?page=campagnes/choix_provider_sms&campagne_id=' . $idCampagneConfig);
            exit;
        case 'email':
            header('Location: index.php?page=campagnes/choix_provider_email&campagne_id=' . $idCampagneConfig);
            exit;
        default:
            header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $idCampagneConfig);
            exit;
    }
}

// Récupérer la campagne
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (empty($campagne)) {
    header('Location: index.php?page=campagnes/index');
    exit;
}
$campagne = $campagne[0];

// Récupérer TOUS les envois liés à cette campagne
$allEnvois = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');
$envois = array_values($allEnvois);

// ============================================
// DÉTECTION DES ENVOIS RÉCENTS (par le cron)
// Pour afficher un toast informatif au chargement de la page
// ============================================
$envoisRecents = [];
$ilYA5Min = date('Y-m-d H:i:s', strtotime('-5 minutes'));

// Envois que l'utilisateur a lui-même déclenchés → le flash PHP les couvre déjà
$envoisManuels = $_SESSION['envois_manuels_recents'] ?? [];

foreach ($envois as $e) {
    // Ne pas re-notifier un envoi manuel
    if (in_array($e['id_campagne'], $envoisManuels)) {
        continue;
    }
    
    if (in_array($e['statut'], ['envoye', 'partiel', 'echoue'])
        && !empty($e['updated_at'])
        && $e['updated_at'] >= $ilYA5Min) {
        $envoisRecents[] = $e;
    }
}

$envoisRecentsJson = json_encode(array_map(function($e) {
    return [
        'id' => $e['id_campagne'],
        'statut' => $e['statut'],
        'type' => $e['type_campagne'],
        'nb_succes' => (int)$e['nb_succes'],
        'nb_erreurs' => (int)$e['nb_erreurs'],
    ];
}, $envoisRecents));

// Liste des IDs d'envois manuels encore dans la campagne (pour le JS)
$envoisManuelsJson = json_encode(array_values($envoisManuels));

$totalEnvois = count($envois);
$totalSucces = 0;
$totalErreurs = 0;
$totalWhatsApp = 0;
$totalSms = 0;
$totalEmail = 0;
$totalAPreparer = 0;
$totalPlanifies = 0;
$totalBrouillons = 0;

foreach ($envois as $e) {
    $totalSucces += $e['nb_succes'];
    $totalErreurs += $e['nb_erreurs'];
    if ($e['type_campagne'] == 'whatsapp') {
        $totalWhatsApp++;
    } elseif ($e['type_campagne'] == 'email') {
        $totalEmail++;
    } else {
        $totalSms++;
    }
    if ($e['statut'] == 'pret_a_envoyer') {
        $totalAPreparer++;
    }
    if ($e['statut'] == 'planifiee') {
        $totalPlanifies++;
    }
    if ($e['statut'] == 'brouillon') {
        $totalBrouillons++;
    }
}

// ============================================
// FONCTION DE VÉRIFICATION DU CRÉDIT CLIENT
// ============================================
function verifierCreditClient($idCompte, $idProvider, $quantite) {
    global $db;
    
    if (empty($idProvider) || $quantite <= 0) {
        return [
            'suffisant' => false,
            'solde' => 0,
            'cout_total' => 0,
            'message' => 'Paramètres invalides pour la vérification du crédit'
        ];
    }
    
    $provider = $db->select('provider', ['id_provider' => $idProvider]);
    if (empty($provider)) {
        return [
            'suffisant' => false,
            'solde' => 0,
            'cout_total' => 0,
            'message' => 'Opérateur non trouvé'
        ];
    }
    
    $tarifPersonnalise = $db->select('tarif', [
        'id_compte' => $idCompte,
        'id_provider' => $idProvider
    ]);
    
    $tarif = !empty($tarifPersonnalise) 
        ? (float)$tarifPersonnalise[0]['prix'] 
        : (float)$provider[0]['tarif'];
    
    if ($tarif <= 0) {
        return [
            'suffisant' => false,
            'solde' => 0,
            'cout_total' => 0,
            'message' => 'Tarif invalide pour cet opérateur'
        ];
    }
    
    $montant = $tarif * $quantite;
    
    $compte = $db->select('compte', ['id_compte' => $idCompte]);
    if (empty($compte)) {
        return [
            'suffisant' => false,
            'solde' => 0,
            'cout_total' => $montant,
            'message' => 'Compte client non trouvé'
        ];
    }
    
    $creditsActuels = (float)($compte[0]['credits_total'] ?? 0);
    $creditsDisponibles = $creditsActuels;
    $suffisant = $creditsDisponibles >= $montant;
    
    return [
        'suffisant' => $suffisant,
        'solde' => $creditsDisponibles,
        'cout_total' => $montant,
        'tarif_unitaire' => $tarif,
        'quantite' => $quantite,
        'message' => $suffisant 
            ? "Crédit suffisant : {$creditsDisponibles}€ disponible(s) pour un coût de {$montant}€" 
            : "Crédit insuffisant : {$creditsDisponibles}€ disponible(s) pour un coût de {$montant}€"
    ];
}

// ============================================
// FONCTION DE DÉDUCTION DU CRÉDIT CLIENT
// ============================================
function deduireCreditClient($idCompte, $idProvider, $quantite, $description = null) {
    global $db;

    if (empty($idProvider) || $quantite <= 0) {
        return false;
    }

    $verification = verifierCreditClient($idCompte, $idProvider, $quantite);
    if (!$verification['suffisant']) {
        return false;
    }

    $provider = $db->select('provider', ['id_provider' => $idProvider]);
    if (empty($provider)) {
        return false;
    }

    $tarifPersonnalise = $db->select('tarif', [
        'id_compte' => $idCompte,
        'id_provider' => $idProvider
    ]);

    $tarif = !empty($tarifPersonnalise) 
        ? (float)$tarifPersonnalise[0]['prix'] 
        : (float)$provider[0]['tarif'];

    if ($tarif <= 0) {
        return false;
    }

    $montant = $tarif * $quantite;

    // Déduction atomique côté PostgreSQL : aucune race condition possible
    try {
        $rpcResult = $db->rpc('deduire_credit', [
            'p_id_compte' => (string)$idCompte,
            'p_montant'   => $montant
        ]);
    } catch (Exception $e) {
        // Solde insuffisant détecté au moment exact de l'UPDATE
        error_log("deduire_credit RPC error: " . $e->getMessage());
        return false;
    }

    // La fonction RPC retourne directement la valeur NUMERIC (nouveau solde)
    $nouveauSolde = is_array($rpcResult) ? (float)($rpcResult[0] ?? $rpcResult) : (float)$rpcResult;
    $creditsActuels = $nouveauSolde + $montant;

    $nomProvider = $provider[0]['nom_providers'] ?? 'Inconnu';
    $descriptionTransaction = $description ?? "Envoi de {$quantite} message(s) via {$nomProvider}";

    $transactionData = [
        'id_compte_auteur' => $_SESSION['user_id'] ?? null,
        'id_compte' => $idCompte,
        'id_provider' => $idProvider,
        'type_transaction' => 'debit',
        'montant' => $montant,
        'description' => $descriptionTransaction,
        'solde_avant' => $creditsActuels,
        'solde_apres' => $nouveauSolde,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $db->insert('transactions', $transactionData);
    
    return true;
}

// ============================================
// FONCTION DE RÉSOLUTION DU PROVIDER PAR FOURNISSEUR
// ============================================
function getProviderByFournisseur($fournisseurLabel) {
    global $db;
    
    $providers = $db->select('provider', [], '*', 'description ASC');
    
    if (empty($providers)) {
        return null;
    }
    
    $target = mb_strtolower(trim($fournisseurLabel));
    
    foreach ($providers as $p) {
        if (mb_strtolower(trim($p['description'])) === $target) {
            return $p;
        }
    }
    
    return null;
}

// ============================================
// FONCTION DE MISE À JOUR DU STATUT GLOBAL
// ============================================
function mettreAJourStatutCampagne($idCampagneConfig, $idCompte) {
    global $db;
    
    $campagneConfig = $db->select('campagne_config', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
    
    if (empty($campagneConfig)) {
        return;
    }
    
    $campagneActuelle = $campagneConfig[0];
    $statutActuel = $campagneActuelle['statut'] ?? 'brouillon';
    
    if ($statutActuel === 'planifiee') {
        $messages = $db->select('campagne', [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
        
        if (empty($messages)) {
            return;
        }
        
        $nbEnvoyes = 0;
        $nbEchoues = 0;
        $nbTotal = count($messages);
        
        foreach ($messages as $msg) {
            $statut = strtolower(trim($msg['statut']));
            if ($statut === 'envoye') {
                $nbEnvoyes++;
            } elseif ($statut === 'echoue') {
                $nbEchoues++;
            }
        }
        
        if ($nbEnvoyes == $nbTotal && $nbTotal > 0) {
            $db->update('campagne_config', [
                'statut' => 'envoyee',
                'sent_at' => date('Y-m-d H:i:s')
            ], [
                'id_campagne_config' => $idCampagneConfig,
                'id_compte' => $idCompte
            ]);
            return;
        }
        
        if ($nbEchoues == $nbTotal && $nbTotal > 0) {
            $db->update('campagne_config', [
                'statut' => 'echoue',
                'sent_at' => null
            ], [
                'id_campagne_config' => $idCampagneConfig,
                'id_compte' => $idCompte
            ]);
            return;
        }
        
        return;
    }
    
    $messages = $db->select('campagne', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
    
    if (empty($messages)) {
        $db->update('campagne_config', [
            'statut' => 'brouillon'
        ], [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
        return;
    }
    
    $nbTotal = count($messages);
    $nbEnvoyes = 0;
    $nbEchoues = 0;
    $nbPret = 0;
    $nbBrouillon = 0;
    $nbPlanifie = 0;
    $nbPartiel = 0;
    
    foreach ($messages as $msg) {
        $statut = strtolower(trim($msg['statut']));
        switch ($statut) {
            case 'envoye':
                $nbEnvoyes++;
                break;
            case 'echoue':
                $nbEchoues++;
                break;
            case 'partiel':
                $nbPartiel++;
                break;
            case 'pret_a_envoyer':
                $nbPret++;
                break;
            case 'brouillon':
                $nbBrouillon++;
                break;
            case 'planifiee':
                $nbPlanifie++;
                break;
        }
    }
    
    if ($nbEnvoyes == $nbTotal && $nbTotal > 0) {
        $statut = 'envoyee';
        $sent_at = date('Y-m-d H:i:s');
    } elseif ($nbEchoues == $nbTotal && $nbTotal > 0) {
        $statut = 'echoue';
        $sent_at = null;
    } elseif ($nbEnvoyes > 0 || $nbEchoues > 0 || $nbPartiel > 0) {
        $statut = 'partiel';
        $sent_at = null;
    } elseif ($nbPret > 0) {
        $statut = 'pret_a_envoyer';
        $sent_at = null;
    } elseif ($nbPlanifie > 0) {
        $statut = 'planifiee';
        $sent_at = null;
    } else {
        $statut = 'brouillon';
        $sent_at = null;
    }
    
    $updateData = ['statut' => $statut];
    if ($statut === 'envoyee') {
        $updateData['sent_at'] = $sent_at;
    } else {
        $updateData['sent_at'] = null;
    }
    
    $db->update('campagne_config', $updateData, [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
}

// ============================================
// FONCTION POUR FORMATER LES NUMÉROS (UNIQUEMENT FRANCE)
// ============================================
function formaterNumerosOctopush($destinataires) {
    $formatted = [];
    
    foreach ($destinataires as $dest) {
        $telephone = null;
        
        if (is_array($dest) && isset($dest['phone_number'])) {
            $telephone = $dest['phone_number'];
        } elseif (is_string($dest) && preg_match('/\(([^)]+)\)/', $dest, $matches)) {
            $telephone = $matches[1];
        } elseif (is_string($dest) && preg_match('/[0-9+\s]+/', $dest, $matches)) {
            $telephone = trim($matches[0]);
        }
        
        if (empty($telephone)) {
            continue;
        }
        
        $telephone = trim($telephone);
        
        if (substr($telephone, 0, 1) == '+') {
            $checkNumber = preg_replace('/[^0-9]/', '', $telephone);
            if (strlen($checkNumber) >= 9 && strlen($checkNumber) <= 15) {
                $formatted[] = $telephone;
            }
            continue;
        }
        
        $telephone = preg_replace('/[^0-9]/', '', $telephone);
        
        if (substr($telephone, 0, 1) == '0') {
            $telephone = '+33' . substr($telephone, 1);
        } else {
            if (strlen($telephone) == 10) {
                $telephone = '+33' . $telephone;
            } elseif (strlen($telephone) == 11 && substr($telephone, 0, 2) == '33') {
                $telephone = '+' . $telephone;
            } elseif (strlen($telephone) == 12 && substr($telephone, 0, 3) == '261') {
                $telephone = '+' . $telephone;
            } elseif (strlen($telephone) > 10 && substr($telephone, 0, 2) == '33') {
                $telephone = '+' . $telephone;
            } else {
                $telephone = '+33' . $telephone;
            }
        }
        
        $checkNumber = preg_replace('/[^0-9]/', '', $telephone);
        if (strlen($checkNumber) >= 9 && strlen($checkNumber) <= 15) {
            $formatted[] = $telephone;
        }
    }
    
    return $formatted;
}

// ============================================
// FONCTION POUR RÉCUPÉRER LE STATUT RÉEL DES SMS
// ============================================
function recupererStatutSMS($messageIds, $apiUsername, $apiPassword) {
    if (empty($messageIds) || empty($apiUsername) || empty($apiPassword)) {
        return [
            'success' => false,
            'error' => 'Paramètres manquants pour la récupération du statut'
        ];
    }
    
    $url = 'http://164.68.103.147:8085/status.php';
    
    $data = [
        'message_ids' => array_values($messageIds),
        'api_username' => $apiUsername,
        'api_password' => $apiPassword
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Status SMS cURL Error: " . $curlError);
        return ['success' => false, 'error' => 'Erreur cURL: ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Erreur HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];
    }
    
    $responseData = json_decode($response, true);
    
    if (!is_array($responseData)) {
        return ['success' => false, 'error' => 'Réponse non-JSON'];
    }
    
    if (($responseData['status'] ?? '') !== 'ok') {
        return ['success' => false, 'error' => 'Statut API non OK: ' . ($responseData['status'] ?? 'inconnu')];
    }
    
    $results = [];
    $nbDelivered = 0;
    $nbPending = 0;
    $nbFailed = 0;
    $nbOther = 0;
    
    foreach (($responseData['results'] ?? []) as $result) {
        $state = $result['state'] ?? 'Unknown';
        $recipients = $result['recipients'] ?? [];
        
        foreach ($recipients as $recipient) {
            $recipientState = $recipient['state'] ?? $state;
            $phoneNumber = $recipient['phoneNumber'] ?? 'Inconnu';
            
            $normalizedState = strtolower($recipientState);
            if (in_array($normalizedState, ['delivered', 'delivred'])) {
                $nbDelivered++;
                $statusCategory = 'delivered';
            } elseif (in_array($normalizedState, ['pending', 'sent', 'queued', 'submitted'])) {
                $nbPending++;
                $statusCategory = 'pending';
            } elseif (in_array($normalizedState, ['failed', 'undelivered', 'rejected', 'expired'])) {
                $nbFailed++;
                $statusCategory = 'failed';
            } else {
                $nbOther++;
                $statusCategory = 'other';
            }
            
            $results[] = [
                'id' => $result['id'] ?? '',
                'phone' => $phoneNumber,
                'state' => $recipientState,
                'state_raw' => $state,
                'category' => $statusCategory,
                'http_code' => $result['http_code'] ?? null,
                'success' => $result['success'] ?? false,
                'error' => $result['error'] ?? null
            ];
        }
    }
    
    return [
        'success' => true,
        'count' => $responseData['count'] ?? count($results),
        'results' => $results,
        'stats' => [
            'delivered' => $nbDelivered,
            'pending' => $nbPending,
            'failed' => $nbFailed,
            'other' => $nbOther,
            'total' => count($results)
        ]
    ];
}

// ============================================
// FONCTION POUR EXTRAIRE LE MAPPING message_id => vrai_numéro
// depuis une réponse de status.php (numéros encore en clair)
// ============================================
function extraireMappingRecipients($reponseStatut) {
    $mapping = [];
    
    if (empty($reponseStatut['results']) || !is_array($reponseStatut['results'])) {
        return $mapping;
    }
    
    foreach ($reponseStatut['results'] as $result) {
        $msgId = $result['id'] ?? null;
        if (empty($msgId)) continue;
        
        $phone = $result['phone'] ?? null;
        
        // On ne garde que les numéros qui ressemblent à de vrais numéros
        // (le hash anonymisé ne ressemble pas à un numéro de téléphone)
        if ($phone && preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
            $mapping[$msgId] = $phone;
        }
    }
    
    return $mapping;
}

// ============================================
// FONCTION POUR RÉCUPÉRER LE STATUT D'UNE CAMPAGNE LISTMONK
// ============================================
function recupererStatutListmonk($campaignId) {
    if (empty($campaignId)) {
        return ['success' => false, 'error' => 'ID de campagne manquant'];
    }
    
    $apiUrl = "http://164.68.103.147:9005/api/campaigns/" . intval($campaignId);
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Listmonk getStatus cURL Error (#{$curlErrno}): " . $curlError . " | URL: " . $apiUrl);
        return ['success' => false, 'error' => 'Erreur cURL #' . $curlErrno . ': ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Erreur HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];
    }
    
    $responseData = json_decode($response, true);
    
    if (!is_array($responseData)) {
        return ['success' => false, 'error' => 'Réponse non-JSON'];
    }
    
    $data = $responseData['data'] ?? null;
    
    if (!$data || !is_array($data)) {
        return ['success' => false, 'error' => 'Données de campagne Listmonk manquantes'];
    }
    
    $statusLabels = [
        'draft' => 'Brouillon',
        'scheduled' => 'Planifiée',
        'running' => 'En cours',
        'paused' => 'En pause',
        'cancelled' => 'Annulée',
        'finished' => 'Terminée'
    ];
    
    $statusRaw = $data['status'] ?? 'unknown';
    $statusLabel = $statusLabels[$statusRaw] ?? $statusRaw;
    
    return [
        'success' => true,
        'data' => [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? '',
            'subject' => $data['subject'] ?? '',
            'status' => $statusRaw,
            'status_label' => $statusLabel,
            'sent' => (int)($data['sent'] ?? 0),
            'to_send' => (int)($data['to_send'] ?? 0),
            'views' => (int)($data['views'] ?? 0),
            'clicks' => (int)($data['clicks'] ?? 0),
            'bounces' => (int)($data['bounces'] ?? 0),
        ]
    ];
}

// ============================================
// FONCTION POUR ENVOYER AVEC OCTOPUSH
// ============================================
function envoyerOctopush($message, $destinataires, $apiLogin, $apiKey, $idCompte, $idProvider) {
    global $db;
    
    $url = 'https://api.octopush.com/v1/public/sms-campaign/send';
    
    $recipients = [];
    $formattedNumbers = formaterNumerosOctopush($destinataires);
    
    foreach ($formattedNumbers as $numero) {
        $recipients[] = ['phone_number' => $numero];
    }
    
    if (empty($recipients)) {
        return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé.'];
    }
    
    $quantite = count($recipients);
    $verification = verifierCreditClient($idCompte, $idProvider, $quantite);
    
    if (!$verification['suffisant']) {
        return [
            'success' => false, 
            'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' SMS. ' . $verification['message'],
            'solde' => $verification['solde'],
            'cout_total' => $verification['cout_total'],
            'credit_insuffisant' => true
        ];
    }
    
    $config = $db->select('octopush_config', [
        'id_compte' => $idCompte,
        'est_active' => 1
    ]);
    
    $sender = 'IFB';
    $type = 'sms_premium';
    $purpose = 'alert';
    
    if (!empty($config)) {
        $sender = !empty($config[0]['sender_name']) ? $config[0]['sender_name'] : 'IFB';
        $type = !empty($config[0]['type']) ? $config[0]['type'] : 'sms_premium';
        $purpose = !empty($config[0]['purpose']) ? $config[0]['purpose'] : 'alert';
    }
    
    $data = [
        'text' => $message,
        'recipients' => $recipients,
        'sender' => $sender,
        'type' => $type,
        'purpose' => $purpose,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-login: ' . $apiLogin,
        'api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Octopush cURL Error: " . $error);
        return ['success' => false, 'error' => 'Erreur cURL: ' . $error];
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode === 200 || $httpCode === 201) {
        // ============================================
        // Extraction du sms_ticket depuis la réponse
        // ============================================
        $smsTicket = $responseData['sms_ticket'] ?? null;
        
        if (!empty($idProvider)) {
            $description = "Envoi Octopush - {$quantite} SMS";
            deduireCreditClient($idCompte, $idProvider, $quantite, $description);
        }
        
        return [
            'success' => true,
            'data' => $responseData,
            'http_code' => $httpCode,
            'sms_envoyes' => $quantite,
            'credits_utilises' => $verification['cout_total'],
            'sms_ticket' => $smsTicket
        ];
    } else {
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : $response;
        if (isset($responseData['errors'])) {
            $errorMsg .= ' - Détails: ' . json_encode($responseData['errors']);
        }
        
        error_log("Octopush Erreur API: " . $errorMsg);
        
        return [
            'success' => false,
            'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . $errorMsg,
            'http_code' => $httpCode,
            'credit_insuffisant' => false
        ];
    }
}

// ============================================
// FONCTION POUR METTRE À JOUR LE STATUT D'UNE CAMPAGNE LISTMONK
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Listmonk updateStatus cURL Error (#{$curlErrno}): " . $curlError . " | URL: " . $apiUrl);
    }
    
    return [
        'success' => ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) && empty($curlError),
        'http_code' => $httpCode,
        'response' => $response,
        'curl_error' => $curlError,
        'curl_errno' => $curlErrno
    ];
}

// ============================================
// Mettre à jour le statut au chargement de la page
// ============================================
mettreAJourStatutCampagne($campagneId, $idCompte);
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (!empty($campagne)) {
    $campagne = $campagne[0];
}

// ============================================
// TRAITEMENT DE L'ENVOI D'UN MESSAGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_envoyer_message'])) {
    $id_campagne_historique = $_POST['id_campagne_historique'] ?? null;
    $isOctopush = isset($_POST['is_octopush']) && $_POST['is_octopush'] === '1';
    
    if (!$id_campagne_historique) {
        $_SESSION['flash_error'] = "Message non trouvé";
        header('Location: index.php?page=campagnes/details&id=' . $campagneId);
        exit;
    }
    
    try {
        $historique = $db->select('campagne', [
            'id_campagne' => $id_campagne_historique,
            'id_compte' => $idCompte
        ]);
        
        if (empty($historique)) {
            $_SESSION['flash_error'] = "Message non trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagneData = $historique[0];
        $typeMessage = $campagneData['type_campagne'] ?? 'sms';
        
        $destinataires = json_decode($campagneData['destinataires'] ?? '[]', true);
        if (empty($destinataires)) {
            $_SESSION['flash_error'] = "Aucun destinataire trouvé pour ce message";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $message = $campagneData['message'] ?? '';
        if (empty($message)) {
            $_SESSION['flash_error'] = "Aucun message trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagneConfig = $db->select('campagne_config', [
            'id_campagne_config' => $campagneId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($campagneConfig)) {
            $_SESSION['flash_error'] = "Campagne non trouvée";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagne = $campagneConfig[0];
        
        if ($isOctopush) {
            $apiLogin = $_SESSION['octopush_api_login'] ?? null;
            $apiKey = $_SESSION['octopush_api_key'] ?? null;
            $sessionName = $_SESSION['octopush_session_name'] ?? 'Octopush API';
            
            $providerOctopush = getProviderByFournisseur('Octopush');
            $idProvider = $providerOctopush['id_provider'] ?? null;

            if (empty($idProvider)) {
                $_SESSION['flash_error'] = "❌ Provider Octopush non configuré dans la base de données.";
                header('Location: index.php?page=campagnes/details&id=' . $campagneId);
                exit;
            }

            if (empty($apiLogin) || empty($apiKey)) {
                $campagneDb = $db->select('campagne', [
                    'id_campagne' => $id_campagne_historique,
                    'id_compte' => $idCompte
                ]);
                
                if (!empty($campagneDb) && !empty($campagneDb[0]['api_login']) && !empty($campagneDb[0]['api_key'])) {
                    $apiLogin = $campagneDb[0]['api_login'];
                    $apiKey = $campagneDb[0]['api_key'];
                } else {
                    $octopushConfigId = $campagneData['octopush_config_id'] ?? $campagne['octopush_config_id'] ?? null;
                    
                    if ($octopushConfigId) {
                        $config = $db->select('octopush_config', [
                            'id_config' => $octopushConfigId,
                            'id_compte' => $idCompte
                        ]);
                        
                        if (!empty($config)) {
                            $apiLogin = $config[0]['api_login'];
                            $apiKey = $config[0]['api_key'];
                            $sessionName = $config[0]['nom_config'];
                        }
                    }
                }
            }
            
            if (empty($apiLogin) || empty($apiKey)) {
                $_SESSION['flash_error'] = "❌ Identifiants Octopush manquants.";
                header('Location: index.php?page=campagnes/choix_session_octopush&campagne_id=' . $campagneId);
                exit;
            }
            
            $resultat = envoyerOctopush($message, $destinataires, $apiLogin, $apiKey, $idCompte, $idProvider);
            
            if ($resultat['success']) {
                $db->update('campagne', [
                    'statut' => 'envoye',
                    'nb_envoyes' => count($destinataires),
                    'nb_succes' => count($destinataires),
                    'nb_erreurs' => 0,
                    'appareil_utilise' => 'Octopush - ' . $sessionName,
                    'reponse_api' => json_encode($resultat['data']),
                    'sms_ticket' => $resultat['sms_ticket'] ?? null,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $id_campagne_historique]);
                
                $_SESSION['octopush_response'] = $resultat['data'];
                $_SESSION['flash_message'] = "✅ SMS envoyés avec succès via Octopush (Session: " . $sessionName . ")! Coût: " . number_format($resultat['credits_utilises'], 3) . "€";
                
                // Marque cet envoi comme manuel → pas de toast "envoi récent"
                $_SESSION['envois_manuels_recents'][] = $id_campagne_historique;
            } else {
                if (isset($resultat['credit_insuffisant']) && $resultat['credit_insuffisant'] === true) {
                    $_SESSION['flash_error'] = "❌ " . $resultat['error'];
                    $db->update('campagne', [
                        'statut' => 'echoue',
                        'nb_erreurs' => count($destinataires),
                        'erreur' => $resultat['error'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id_campagne' => $id_campagne_historique]);
                } else {
                    $db->update('campagne', [
                        'statut' => 'echoue',
                        'nb_erreurs' => count($destinataires),
                        'erreur' => $resultat['error'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id_campagne' => $id_campagne_historique]);
                    
                    $_SESSION['flash_error'] = "❌ " . $resultat['error'];
                }
            }
            
            mettreAJourStatutCampagne($campagneId, $idCompte);
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $min_delay = $_SESSION['min_delay'] ?? $campagne['min_delay'] ?? 60;
        $max_delay = $_SESSION['max_delay'] ?? $campagne['max_delay'] ?? 180;
        
        $pieceJointe = null;
        if (!empty($campagneData['piece_jointe'])) {
            $pieceJointe = json_decode($campagneData['piece_jointe'], true);
        }
        
        switch ($typeMessage) {
            case 'sms':
                $resultat = envoyerSMS($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            case 'whatsapp':
                $resultat = envoyerWhatsApp($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires, $pieceJointe, $min_delay, $max_delay);
                break;
            case 'email':
                $resultat = envoyerEmail($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            default:
                $_SESSION['flash_error'] = "Type de message non supporté: " . $typeMessage;
                header('Location: index.php?page=campagnes/details&id=' . $campagneId);
                exit;
        }
        
        if ($resultat['success']) {
            $_SESSION['flash_message'] = "✅ " . $resultat['message'];
            
            // Marque cet envoi comme manuel → pas de toast "envoi récent"
            $_SESSION['envois_manuels_recents'][] = $id_campagne_historique;
        } else {
            $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $resultat['error'];
        }
        
        mettreAJourStatutCampagne($campagneId, $idCompte);
        
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $e->getMessage();
    }
    
    header('Location: index.php?page=campagnes/details&id=' . $campagneId);
    exit;
}

// ============================================
// FONCTIONS D'ENVOI
// ============================================

function envoyerSMS($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        $device_id = $campagne['device_id'] ?? null;
        $appareilId = $campagne['appareil_id'] ?? null;
        
        $providerSms = getProviderByFournisseur('SMS API Gateway');
        $providerId = $providerSms['id_provider'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider SMS API Gateway non configuré'];
        }
        
        if (empty($device_id)) {
            return ['success' => false, 'error' => 'device_id non configuré.'];
        }
        
        if (empty($appareilId)) {
            return ['success' => false, 'error' => 'appareil_id non configuré.'];
        }
        
        $appareil = $db->select('sms_appareils', [
            'id_appareil' => $appareilId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($appareil)) {
            return ['success' => false, 'error' => 'Appareil non trouvé'];
        }
        
        $device_name = $appareil[0]['device_name'] ?? 'Appareil SMS';
        $api_username = $appareil[0]['api_username'];
        $api_password = $appareil[0]['api_password'];
        
        if (empty($api_username) || empty($api_password)) {
            return ['success' => false, 'error' => 'Identifiants API SMS manquants.'];
        }
        
        $recipients = [];
        foreach ($destinataires as $dest) {
            if (preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261' && strlen($telephone) > 0) {
                    $telephone = '261' . $telephone;
                }
                $recipients[] = '+' . $telephone;
            }
        }
        
        if (empty($recipients)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé'];
        }
        
        $quantite = count($recipients);
        $verification = verifierCreditClient($idCompte, $providerId, $quantite);
        
        if (!$verification['suffisant']) {
            return [
                'success' => false, 
                'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' SMS. ' . $verification['message'],
                'solde' => $verification['solde'],
                'cout_total' => $verification['cout_total'],
                'credit_insuffisant' => true
            ];
        }
        
        $apiUrl = 'http://164.68.103.147:8085/api.php/sendBulk';
        
        $data = [
            'text' => $message,
            'recipients' => $recipients,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'device_id' => $device_id,
            'user_id' => 'campagne_' . $id_campagne . '_' . date('Ymd_His')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        $messageIds = [];
        if (is_array($responseData)) {
            if (isset($responseData['message_ids']) && is_array($responseData['message_ids'])) {
                $messageIds = $responseData['message_ids'];
            } elseif (isset($responseData['results']) && is_array($responseData['results'])) {
                foreach ($responseData['results'] as $r) {
                    if (isset($r['id'])) $messageIds[] = $r['id'];
                    if (isset($r['message_id'])) $messageIds[] = $r['message_id'];
                }
            } elseif (isset($responseData['ids']) && is_array($responseData['ids'])) {
                $messageIds = $responseData['ids'];
            } elseif (isset($responseData['data']) && is_array($responseData['data'])) {
                foreach ($responseData['data'] as $r) {
                    if (isset($r['id'])) $messageIds[] = $r['id'];
                    if (isset($r['message_id'])) $messageIds[] = $r['message_id'];
                }
            }
        }
        
        // ============================================
        // CAPTURE IMMÉDIATE DU MAPPING message_id => vrai_numéro
        // On appelle status.php tout de suite après l'envoi, tant que
        // les numéros sont encore en clair côté passerelle.
        // ============================================
        $recipientsMapping = [];
        if ($httpCode === 200 && !empty($messageIds)) {
            $statutImmediat = recupererStatutSMS($messageIds, $api_username, $api_password);
            if ($statutImmediat['success']) {
                $recipientsMapping = extraireMappingRecipients($statutImmediat);
            }
        }
        
        $statut = ($httpCode === 200) ? 'envoye' : 'echoue';
        $nb_succes = ($httpCode === 200) ? count($recipients) : 0;
        $nb_erreurs = ($httpCode === 200) ? 0 : count($recipients);
        
        $reponseApiData = [
            'api_response' => $responseData,
            'message_ids' => $messageIds,
            'recipients_mapping' => $recipientsMapping,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'device_id' => $device_id,
            'appareil_id' => $appareilId,
            'sent_at' => date('Y-m-d H:i:s')
        ];
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($recipients),
            'nb_succes' => $nb_succes,
            'nb_erreurs' => $nb_erreurs,
            'appareil_utilise' => $device_name . ' (' . $device_id . ')',
            'appareil_id' => $appareilId,
            'reponse_api' => json_encode($reponseApiData),
            'erreur' => ($httpCode !== 200) ? $response : null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($httpCode === 200) {
            $description = "Envoi SMS via {$device_name} - {$nb_succes} message(s)";
            deduireCreditClient($idCompte, $providerId, $nb_succes, $description);
            
            $nbMapping = count($recipientsMapping);
            $extraInfo = $nbMapping > 0 ? " ({$nbMapping} numéro(s) associé(s))" : "";
            
            return [
                'success' => true, 
                'message' => count($recipients) . ' SMS envoyés avec succès' . $extraInfo . '. Coût: ' . number_format($verification['cout_total'], 3) . '€'
            ];
        } else {
            return ['success' => false, 'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . substr($response, 0, 200)];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function envoyerWhatsApp($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires, $pieceJointe = null, $min_delay = 60, $max_delay = 180) {
    global $db;
    
    try {
        $providerWaha = getProviderByFournisseur('WAHA');
        $providerId = $providerWaha['id_provider'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider WAHA non configuré'];
        }
        
        $session = $db->select('whatsapp_sessions', [
            'id_compte' => $idCompte,
            'est_active' => true
        ]);
        
        if (empty($session)) {
            $session = $db->select('whatsapp_sessions', [
                'id_compte' => $idCompte
            ], '*', 'created_at DESC', 1);
            
            if (empty($session)) {
                return ['success' => false, 'error' => 'Aucune session WhatsApp configurée'];
            }
            
            $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $session[0]['id_session']]);
        }
        
        $whatsappSession = $session[0]['nom_session'];
        
        $contacts = [];
        
        foreach ($destinataires as $dest) {
            $telephone = null;
            
            if (is_array($dest) && isset($dest['phone_number'])) {
                $telephone = $dest['phone_number'];
            } elseif (is_string($dest) && preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
            } elseif (is_string($dest) && preg_match('/[0-9+\s]+/', $dest, $matches)) {
                $telephone = trim($matches[0]);
            }
            
            if (empty($telephone)) {
                continue;
            }
            
            $telephone = preg_replace('/[^0-9]/', '', $telephone);
            
            if (strlen($telephone) >= 9 && strlen($telephone) <= 10) {
                if (substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                } elseif (strlen($telephone) == 9) {
                    $telephone = '261' . $telephone;
                } elseif (strlen($telephone) == 10 && substr($telephone, 0, 3) != '261') {
                    $telephone = '261' . $telephone;
                }
            } else {
                if (substr($telephone, 0, 3) != '261' && strlen($telephone) > 0) {
                    $telephone = '261' . $telephone;
                }
            }
            
            $contacts[] = $telephone;
        }
        
        if (empty($contacts)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé.'];
        }
        
        $quantite = count($contacts);
        $verification = verifierCreditClient($idCompte, $providerId, $quantite);
        
        if (!$verification['suffisant']) {
            return [
                'success' => false, 
                'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' messages WhatsApp. ' . $verification['message'],
                'solde' => $verification['solde'],
                'cout_total' => $verification['cout_total'],
                'credit_insuffisant' => true
            ];
        }
        
        $apiUrl = 'http://164.68.103.147:8081/api/controller.php/messages/send-bulk';
        $apiKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $fichierData = null;
        if ($pieceJointe && isset($pieceJointe['url']) && !empty($pieceJointe['url'])) {
            $fileUrl = $pieceJointe['url'];
            $fileMimeType = $pieceJointe['mime_type'] ?? 'application/octet-stream';
            $fileName = $pieceJointe['nom'] ?? 'fichier';
            
            $ch = curl_init($fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($fileContent)) {
                $fileData = base64_encode($fileContent);
                
                $mediaType = 'file';
                if (strpos($fileMimeType, 'image/') !== false) {
                    $mediaType = 'image';
                } elseif (strpos($fileMimeType, 'video/') !== false) {
                    $mediaType = 'video';
                } elseif (strpos($fileMimeType, 'audio/') !== false) {
                    $mediaType = 'voice';
                }
                
                $fichierData = [
                    'type' => $mediaType,
                    'payload' => [
                        'data' => $fileData,
                        'mimetype' => $fileMimeType,
                        'filename' => $fileName
                    ],
                    'fichier_pret' => true
                ];
            }
        }
        
        if ($fichierData && $fichierData['fichier_pret']) {
            $data = [
                'session' => $whatsappSession,
                'type' => $fichierData['type'],
                'contacts' => $contacts,
                'payload' => $fichierData['payload'],
                'min_delay' => (int)$min_delay,
                'max_delay' => (int)$max_delay
            ];
            
            if ($fichierData['type'] !== 'text' && !empty($message) && $fichierData['type'] !== 'voice') {
                $data['payload']['caption'] = $message;
            }
        } else {
            $data = [
                'session' => $whatsappSession,
                'type' => 'text',
                'contacts' => $contacts,
                'payload' => ['text' => $message],
                'min_delay' => (int)$min_delay,
                'max_delay' => (int)$max_delay
            ];
        }
        
        $jsonData = json_encode($data);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Controller-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if (empty($response) || $response === null) {
            $db->update('campagne', [
                'statut' => 'echoue',
                'nb_envoyes' => 0,
                'nb_succes' => 0,
                'nb_erreurs' => count($contacts),
                'appareil_utilise' => $whatsappSession,
                'reponse_api' => $response,
                'erreur' => 'Réponse vide. HTTP: ' . $httpCode . ' cURL: ' . $curlError,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            return ['success' => false, 'error' => 'Réponse vide ou invalide. HTTP: ' . $httpCode];
        }
        
        $responseData = json_decode($response, true);
        
        if (!is_array($responseData)) {
            $db->update('campagne', [
                'statut' => 'echoue',
                'nb_envoyes' => 0,
                'nb_succes' => 0,
                'nb_erreurs' => count($contacts),
                'appareil_utilise' => $whatsappSession,
                'reponse_api' => $response,
                'erreur' => 'Réponse non-JSON. HTTP: ' . $httpCode,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            return ['success' => false, 'error' => 'Réponse API non-JSON (HTTP ' . $httpCode . ')'];
        }
        
        $succes = 0;
        $echecs = 0;
        $erreurs = [];
        $details = [];
        $statut = 'echoue';
        $messageReponse = '';
        
        $invalidContacts = $responseData['invalid_contacts'] ?? [];
        $restriction = $responseData['account_restriction'] ?? null;
        $messageApi = $responseData['message'] ?? '';
        
        $resultsArray = null;
        
        if (isset($responseData['results'])) {
            $resultsRaw = $responseData['results'];
            
            if (is_array($resultsRaw) 
                && isset($resultsRaw['results']) 
                && is_array($resultsRaw['results'])) {
                
                $resultsArray = $resultsRaw['results'];
                
                if (empty($invalidContacts) && isset($resultsRaw['invalid_contacts'])) {
                    $invalidContacts = $resultsRaw['invalid_contacts'];
                }
                if ($restriction === null && isset($resultsRaw['account_restriction'])) {
                    $restriction = $resultsRaw['account_restriction'];
                }
            }
            elseif (is_array($resultsRaw) && !empty($resultsRaw)) {
                $firstKey = array_key_first($resultsRaw);
                if (is_int($firstKey)) {
                    $resultsArray = $resultsRaw;
                }
            }
        }
        
        if (!empty($resultsArray)) {
            foreach ($resultsArray as $result) {
                if (isset($result['success']) && $result['success'] === true) {
                    $succes++;
                    $details[] = [
                        'phone' => $result['phone'] ?? 'Inconnu',
                        'chatId' => $result['chatId'] ?? 'Inconnu',
                        'firstName' => $result['firstName'] ?? '',
                        'statut' => $result['status'] ?? 'sent',
                        'success' => true,
                        'error' => null
                    ];
                } else {
                    $echecs++;
                    $errorMsg = $result['error'] ?? 'Erreur inconnue';
                    $phone = $result['phone'] ?? 'Inconnu';
                    $erreurs[] = $phone . ': ' . $errorMsg;
                    $details[] = [
                        'phone' => $phone,
                        'chatId' => $result['chatId'] ?? 'Inconnu',
                        'firstName' => $result['firstName'] ?? '',
                        'statut' => $result['status'] ?? 'failed',
                        'success' => false,
                        'error' => $errorMsg
                    ];
                }
            }
        }
        
        if (!empty($invalidContacts) && is_array($invalidContacts)) {
            foreach ($invalidContacts as $invalidContact) {
                $echecs++;
                $erreurs[] = $invalidContact . ': Numéro invalide';
                $details[] = [
                    'phone' => $invalidContact,
                    'chatId' => $invalidContact,
                    'firstName' => '',
                    'statut' => 'invalid',
                    'success' => false,
                    'error' => 'Numéro invalide'
                ];
            }
        }
        
        if (empty($details)) {
            $echecs = count($contacts);
            $errorMsg = !empty($messageApi) ? $messageApi : 'Réponse inexploitable';
            $erreurs[] = 'Erreur API (HTTP ' . $httpCode . '): ' . $errorMsg;
            
            foreach ($contacts as $contact) {
                $details[] = [
                    'phone' => $contact,
                    'chatId' => $contact,
                    'firstName' => '',
                    'statut' => 'error',
                    'success' => false,
                    'error' => $errorMsg
                ];
            }
        }
        
        if ($succes > 0 && $echecs === 0) {
            $statut = 'envoye';
            $messageReponse = "✅ " . $succes . " message(s) WhatsApp envoyé(s) avec succès";
        } elseif ($succes > 0 && $echecs > 0) {
            $statut = 'partiel';
            $messageReponse = "⚠️ " . $succes . " message(s) envoyé(s), " . $echecs . " échec(s)";
        } else {
            $statut = 'echoue';
            $messageReponse = "❌ Aucun message n'a pu être envoyé";
        }
        
        if ($restriction) {
            $restrictionLabels = [
                'capping' => 'Limite WhatsApp atteinte (capping)',
                'ban' => 'Compte WhatsApp restreint',
                'rate_limit' => 'Trop de requêtes (rate limit)'
            ];
            $restrictionLabel = $restrictionLabels[$restriction] ?? $restriction;
            
            if ($succes > 0) {
                $messageReponse = "⚠️ " . $succes . " envoyé(s), " . $echecs . " échec(s) — " . $restrictionLabel;
            } else {
                $messageReponse .= " — " . $restrictionLabel;
            }
        } elseif (!empty($messageApi) && $succes > 0 && $echecs > 0) {
            $messageReponse .= " — " . $messageApi;
        }
        
        $erreurFinale = !empty($erreurs) ? json_encode($erreurs) : null;
        
        $reponseComplete = [
            'global' => $responseData,
            'details' => $details,
            'statut_global' => $statut,
            'message_global' => $messageReponse,
            'nb_succes' => $succes,
            'nb_echecs' => $echecs,
            'restriction' => $restriction ?? null
        ];
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($contacts),
            'nb_succes' => $succes,
            'nb_erreurs' => $echecs,
            'appareil_utilise' => $whatsappSession,
            'reponse_api' => json_encode($reponseComplete),
            'erreur' => $erreurFinale,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($succes > 0) {
            $description = "Envoi WhatsApp via {$whatsappSession} - {$succes} message(s)";
            deduireCreditClient($idCompte, $providerId, $succes, $description);
        }
        
        if ($succes > 0) {
            return [
                'success' => true, 
                'message' => $messageReponse,
                'details' => $details,
                'statut' => $statut
            ];
        } else {
            return [
                'success' => false, 
                'error' => $messageReponse,
                'details' => $details,
                'erreurs' => $erreurs,
                'statut' => $statut
            ];
        }
        
    } catch (Exception $e) {
        try {
            $db->update('campagne', [
                'statut' => 'echoue',
                'nb_erreurs' => isset($contacts) ? count($contacts) : 0,
                'erreur' => 'Exception: ' . $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
        } catch (Exception $dbError) {}
        
        return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
    }
}

function envoyerEmail($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        $providerListmonk = getProviderByFournisseur('Listmonk');
        $providerId = $providerListmonk['id_provider'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider Listmonk non configuré'];
        }
        
        $from_email = $campagneData['from_email'] ?? 'noreply@votre-domaine.com';
        $from_name = $campagneData['from_name'] ?? 'Votre Entreprise';
        $objet = $campagneData['objet'] ?? 'Email';
        $listmonkCampaignId = $campagneData['listmonk_campaign_id'] ?? null;
        
        if (!$listmonkCampaignId) {
            return ['success' => false, 'error' => 'ID de campagne Listmonk manquant.'];
        }
        
        $nbDestinataires = (int)$campagneData['nb_destinataires'];
        
        $verification = verifierCreditClient($idCompte, $providerId, $nbDestinataires);
        
        if (!$verification['suffisant']) {
            return [
                'success' => false, 
                'error' => '❌ Crédit insuffisant pour envoyer ' . $nbDestinataires . ' emails. ' . $verification['message'],
                'solde' => $verification['solde'],
                'cout_total' => $verification['cout_total'],
                'credit_insuffisant' => true
            ];
        }
        
        $result = updateListmonkCampaignStatus($listmonkCampaignId, 'running');
        
        if ($result['success']) {
            $db->update('campagne', [
                'statut' => 'envoye',
                'nb_envoyes' => $nbDestinataires,
                'nb_succes' => $nbDestinataires,
                'nb_erreurs' => 0,
                'appareil_utilise' => 'Listmonk (ID: ' . $listmonkCampaignId . ')'
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            $description = "Envoi Email via Listmonk - {$nbDestinataires} email(s)";
            deduireCreditClient($idCompte, $providerId, $nbDestinataires, $description);
            
            return [
                'success' => true, 
                'message' => $nbDestinataires . ' emails envoyés avec succès via Listmonk. Coût: ' . number_format($verification['cout_total'], 3) . '€'
            ];
        } else {
            $errorMsg = 'Erreur Listmonk';
            if (!empty($result['curl_error'])) {
                $errorMsg .= ' (cURL #' . $result['curl_errno'] . '): ' . $result['curl_error'];
            } elseif (!empty($result['http_code'])) {
                $errorMsg .= ' (HTTP ' . $result['http_code'] . '): ' . substr($result['response'], 0, 300);
            } else {
                $errorMsg .= ' : aucune réponse du serveur. Vérifiez que Listmonk est accessible sur http://164.68.103.147:9005';
            }
            
            $db->update('campagne', [
                'statut' => 'echoue',
                'erreur' => $errorMsg,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            return ['success' => false, 'error' => $errorMsg];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// FLASH MESSAGES
// ============================================
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
$octopushResponse = isset($_SESSION['octopush_response']) ? $_SESSION['octopush_response'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
unset($_SESSION['octopush_response']);

// ============================================
// VÉRIFIER SI LE PROVIDER EST OCTOPUSH
// ============================================
$isOctopush = isset($campagne['provider_id']) && !empty($campagne['provider_id']);
if ($isOctopush) {
    $provider = $db->select('provider', ['id_provider' => $campagne['provider_id']]);
    if (!empty($provider)) {
        $isOctopush = stripos($provider[0]['nom_providers'], 'octopush') !== false;
    } else {
        $isOctopush = false;
    }
}

$octopushSessionName = $_SESSION['octopush_session_name'] ?? null;
if (!$octopushSessionName && isset($campagne['octopush_config_id'])) {
    $config = $db->select('octopush_config', [
        'id_config' => $campagne['octopush_config_id'],
        'id_compte' => $idCompte
    ]);
    if (!empty($config)) {
        $octopushSessionName = $config[0]['nom_config'];
    }
}

// ============================================
// FONCTION HELPER POUR L'URL DE REPRISE
// ============================================
function getReprendreUrl($idMessage, $campagneConfigId) {
    return 'index.php?page=campagnes/details&id=' . urlencode($campagneConfigId) . '&reprendre=' . urlencode($idMessage);
}

// Nettoyer les IDs d'envois manuels qui ne sont plus dans la campagne courante
if (!empty($_SESSION['envois_manuels_recents'])) {
    $idsCampagne = array_column($envois, 'id_campagne');
    $_SESSION['envois_manuels_recents'] = array_values(array_filter(
        $_SESSION['envois_manuels_recents'],
        function($id) use ($idsCampagne) { return in_array($id, $idsCampagne); }
    ));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campagne['nom_campagne']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { margin: 0; background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; min-height: 100vh; }
        .container { max-width: 100%; margin: 0 auto; padding: 16px 32px; width: 100%; }
        
        .status-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-brouillon { background: #f3f4f6; color: #4b5563; }
        .status-planifiee { background: #fef3c7; color: #92400e; }
        .status-envoyee { background: #dcfce7; color: #166534; }
        .status-pret_a_envoyer { background: #dbeafe; color: #1e40af; }
        .status-partiel { background: #fef3c7; color: #92400e; }
        .status-echoue { background: #fee2e2; color: #991b1b; }
        
        .badge-octopush { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: 700; background: #f97316; color: white; text-transform: uppercase; letter-spacing: 0.3px; margin-left: 4px; }
        .badge-octopush-session { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; margin-left: 4px; }
        .badge-octopush-session i { margin-right: 4px; color: #ea580c; }
        
        .stat-type { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .stat-type-whatsapp { background: #d1fae5; color: #065f46; }
        .stat-type-sms { background: #dbeafe; color: #1e40af; }
        .stat-type-email { background: #fef3c7; color: #92400e; }
        
        .toast-notification { position: fixed; top: 20px; right: 20px; z-index: 9999; animation: slideInRight 0.3s ease-out; }
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .toast-notification .toast-content { color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 14px; font-weight: 500; }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        
        .btn-send-message { background: #10b981; color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-send-message:hover { background: #059669; }
        .btn-send-message:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .btn-send-email { background: #3b82f6; color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-send-email:hover { background: #2563eb; }
        .btn-send-email:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .btn-send-octopush { background: #f97316; color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-send-octopush:hover { background: #ea580c; }
        
        .btn-reprendre { background: #8b5cf6; color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
        .btn-reprendre:hover { background: #7c3aed; }
        
        .stat-card { padding: 16px 20px; border-radius: 12px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card .stat-number { font-size: 28px; font-weight: 800; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; margin-top: 2px; }
        
        .table-container { overflow-x: auto; width: 100%; }
        .table-container table { width: 100%; font-size: 14px; border-collapse: collapse; min-width: 700px; }
        .table-container th { padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
        .table-container td { padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #f3f4f6; }
        .table-container th.text-center, .table-container td.text-center { text-align: center; }
        
        .envoi-row { cursor: pointer; transition: background 0.15s; }
        .envoi-row:hover { background-color: #f9fafb; }
        .envoi-row.row-brouillon { background-color: #faf5ff; }
        .envoi-row.row-brouillon:hover { background-color: #f3e8ff; }
        
        .filter-container { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .filter-container label { font-size: 13px; font-weight: 600; color: #374151; }
        .filter-container select { padding: 6px 14px; border: 1.5px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; cursor: pointer; min-width: 140px; }
        .filter-container select:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12); }
        .filter-container .filter-info { font-size: 13px; color: #6b7280; }
        .filter-container .btn-clear-filter { background: #e5e7eb; color: #4b5563; padding: 6px 14px; border-radius: 6px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .filter-container .btn-clear-filter:hover { background: #d1d5db; }
        
        #searchInput { padding: 8px 12px 8px 38px; font-size: 14px; border-radius: 8px; border: 1.5px solid #d1d5db; width: 100%; background: white; }
        #searchInput:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }
        
        .modal-octopush { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-octopush.active { display: flex; }
        .modal-octopush .modal-content { background: white; border-radius: 16px; max-width: 600px; width: 92%; max-height: 80vh; overflow-y: auto; padding: 0; animation: modalFadeIn 0.3s ease; }
        @keyframes modalFadeIn { from { transform: scale(0.9) translateY(-20px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
        .modal-octopush .modal-header { padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-radius: 16px 16px 0 0; }
        .modal-octopush .modal-header h3 { margin: 0; font-size: 18px; color: #1f2937; }
        .modal-octopush .modal-header .close { background: none; border: none; font-size: 24px; cursor: pointer; color: #9ca3af; transition: color 0.2s; }
        .modal-octopush .modal-header .close:hover { color: #4b5563; }
        .modal-octopush .modal-body { padding: 24px; }
        .modal-octopush .modal-body .response-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .modal-octopush .modal-body .response-item:last-child { border-bottom: none; }
        .modal-octopush .modal-body .response-item .label { font-weight: 600; color: #6b7280; }
        .modal-octopush .modal-body .response-item .value { font-weight: 500; color: #1f2937; text-align: right; }
        .modal-octopush .modal-body .response-item .value.success { color: #10b981; }
        .modal-octopush .modal-footer { padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px; background: #f8fafc; border-radius: 0 0 16px 16px; }
        .modal-octopush .modal-footer button { padding: 8px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; }
        .modal-octopush .modal-footer .btn-cancel { background: #e5e7eb; color: #4b5563; }
        .modal-octopush .modal-footer .btn-cancel:hover { background: #d1d5db; }
        
        .html-render { border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; background: white; max-height: 400px; overflow-y: auto; }
        .html-render h1, .html-render h2, .html-render h3, .html-render h4, .html-render h5, .html-render h6 { margin-top: 0.5em; margin-bottom: 0.5em; }
        .html-render p { margin-bottom: 0.75em; }
        .html-render ul, .html-render ol { margin-left: 1.5em; margin-bottom: 0.75em; }
        .html-render a { color: #3b82f6; text-decoration: underline; }
        .html-render img { max-width: 100%; height: auto; }
        .html-render table { border-collapse: collapse; width: 100%; margin-bottom: 0.75em; }
        .html-render table td, .html-render table th { border: 1px solid #d1d5db; padding: 6px 12px; }
        
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }
        .mb-6 { margin-bottom: 24px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mr-2 { margin-right: 8px; }
        .mr-4 { margin-right: 16px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-xs { font-size: 12px; }
        .text-sm { font-size: 14px; }
        .text-lg { font-size: 18px; }
        .text-xl { font-size: 20px; }
        .text-2xl { font-size: 24px; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }
        .uppercase { text-transform: uppercase; }
        .whitespace-nowrap { white-space: nowrap; }
        .overflow-hidden { overflow: hidden; }
        .overflow-y-auto { overflow-y: auto; }
        .max-h-48 { max-height: 192px; }
        
        .bg-white { background: white; }
        .bg-gray-50 { background: #f9fafb; }
        .bg-gray-100 { background: #f3f4f6; }
        .bg-purple-100 { background: #f3e8ff; }
        .bg-blue-50 { background: #eff6ff; }
        .bg-blue-100 { background: #dbeafe; }
        .bg-green-50 { background: #f0fdf4; }
        .bg-green-100 { background: #dcfce7; }
        .bg-red-50 { background: #fef2f2; }
        .bg-red-100 { background: #fee2e2; }
        .bg-yellow-50 { background: #fffbeb; }
        .bg-yellow-100 { background: #fef3c7; }
        .bg-orange-50 { background: #fff7ed; }
        .bg-orange-100 { background: #ffedd5; }
        
        .text-blue-600 { color: #2563eb; }
        .text-green-600 { color: #16a34a; }
        .text-red-600 { color: #dc2626; }
        .text-yellow-600 { color: #ca8a04; }
        .text-yellow-700 { color: #a16207; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .text-purple-600 { color: #7c3aed; }
        .text-purple-700 { color: #6d28d9; }
        .text-orange-600 { color: #ea580c; }
        .text-orange-800 { color: #9a3412; }
        
        .rounded-xl { border-radius: 12px; }
        .rounded-lg { border-radius: 8px; }
        .rounded-full { border-radius: 9999px; }
        .shadow-md { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .p-3 { padding: 12px; }
        .p-4 { padding: 16px; }
        .p-5 { padding: 20px; }
        .px-2 { padding-left: 8px; padding-right: 8px; }
        .px-3 { padding-left: 12px; padding-right: 12px; }
        .px-4 { padding-left: 16px; padding-right: 16px; }
        .py-1 { padding-top: 4px; padding-bottom: 4px; }
        .py-2 { padding-top: 8px; padding-bottom: 8px; }
        .py-3 { padding-top: 12px; padding-bottom: 12px; }
        .py-8 { padding-top: 32px; padding-bottom: 32px; }
        .py-12 { padding-top: 48px; padding-bottom: 48px; }
        
        .grid-cols-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .grid-cols-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .grid-cols-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .grid-cols-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
        
        .border { border: 1px solid #e5e7eb; }
        .border-b { border-bottom: 1px solid #e5e7eb; }
        .border-t { border-top: 1px solid #e5e7eb; }
        
        .relative { position: relative; }
        .absolute { position: absolute; }
        .top-1\/2 { top: 50%; }
        .left-3 { left: 12px; }
        .transform { transform: translateY(-50%); }
        .w-full { width: 100%; }
        .min-w-full { min-width: 100%; }
        .max-w-xs { max-width: 320px; }
        .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        
        #detailsModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 9999; }
        #detailsModal.flex { display: flex; }
        #detailsModal .modal-container { background: white; border-radius: 16px; width: 92%; max-width: 1024px; max-height: 90vh; overflow-y: auto; }
        #detailsModal .modal-container .modal-header-sticky { position: sticky; top: 0; background: white; border-bottom: 1px solid #e5e7eb; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-radius: 16px 16px 0 0; z-index: 10; }
        #detailsModal .modal-container .modal-body-content { padding: 24px; }
        #detailsModal .modal-container .modal-footer-sticky { position: sticky; bottom: 0; background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 12px 24px; display: flex; justify-content: flex-end; border-radius: 0 0 16px 16px; }
        
        .recipient-result-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; border-radius: 8px; margin-bottom: 4px; border-left: 3px solid; transition: background 0.15s; gap: 10px; }
        .recipient-result-row.success-row { background: #f0fdf4; border-left-color: #10b981; }
        .recipient-result-row.fail-row { background: #fef2f2; border-left-color: #ef4444; }
        .recipient-result-row.skipped-row { background: #fffbeb; border-left-color: #f59e0b; }
        .recipient-result-row .recipient-info { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
        .recipient-result-row .recipient-phone { font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace; font-size: 13px; font-weight: 600; color: #1f2937; }
        .recipient-result-row .recipient-name { font-size: 12px; color: #6b7280; font-weight: 500; }
        .recipient-result-row .recipient-status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .recipient-status-badge.status-sent { background: #dcfce7; color: #166534; }
        .recipient-status-badge.status-failed { background: #fee2e2; color: #991b1b; }
        .recipient-status-badge.status-skipped { background: #fef3c7; color: #92400e; }
        .recipient-status-badge.status-pending { background: #dbeafe; color: #1e40af; }
        .recipient-status-badge.status-delivered { background: #dcfce7; color: #166534; }
        .recipient-status-badge.status-unknown { background: #f3f4f6; color: #4b5563; }
        .recipient-result-row .recipient-error { font-size: 11px; color: #dc2626; max-width: 280px; text-align: right; line-height: 1.3; }
        .recipient-result-row .recipient-chatid { font-size: 10px; color: #9ca3af; font-family: monospace; }
        
        .results-summary-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 12px; background: #e5e7eb; }
        .results-summary-bar .bar-success { background: #10b981; transition: width 0.3s; }
        .results-summary-bar .bar-fail { background: #ef4444; transition: width 0.3s; }
        .results-summary-bar .bar-pending { background: #3b82f6; transition: width 0.3s; }
        
        .restriction-alert { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 10px; }
        .restriction-alert i { color: #ea580c; font-size: 18px; }
        
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid #e5e7eb; border-top-color: #8b5cf6; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        @media (max-width: 1200px) {
            .container { padding: 16px 24px; }
            .grid-cols-5 { grid-template-columns: repeat(4, 1fr); }
        }
        @media (max-width: 992px) {
            .container { padding: 12px 20px; }
            .grid-cols-5 { grid-template-columns: repeat(3, 1fr); }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr); }
            .stat-card .stat-number { font-size: 24px; }
        }
        @media (max-width: 768px) {
            .container { padding: 12px 16px; }
            .grid-cols-5 { grid-template-columns: repeat(2, 1fr); }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-cols-3 { grid-template-columns: 1fr 1fr; }
            .grid-cols-2 { grid-template-columns: 1fr; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .filter-container select { width: 100%; }
            .stat-card .stat-number { font-size: 22px; }
            .table-container table { min-width: 600px; font-size: 13px; }
            .table-container th, .table-container td { padding: 8px 12px; }
            #detailsModal .modal-container { width: 96%; margin: 10px; }
            #detailsModal .modal-container .modal-header-sticky { padding: 12px 16px; }
            #detailsModal .modal-container .modal-body-content { padding: 16px; }
            #detailsModal .modal-container .modal-footer-sticky { padding: 10px 16px; }
        }
        @media (max-width: 480px) {
            .container { padding: 8px 10px; }
            .grid-cols-5 { grid-template-columns: 1fr 1fr; gap: 8px; }
            .grid-cols-4 { grid-template-columns: 1fr 1fr; gap: 8px; }
            .grid-cols-3 { grid-template-columns: 1fr; }
            .stat-card { padding: 12px 16px; }
            .stat-card .stat-number { font-size: 20px; }
            .stat-card .stat-label { font-size: 11px; }
            .table-container table { min-width: 500px; font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 10px; }
            .btn-send-message, .btn-send-email, .btn-send-octopush, .btn-reprendre { font-size: 10px; padding: 3px 8px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex items-center">
            <a href="index.php?page=campagnes/creer" class="text-blue-600 hover:text-blue-800 mr-4 font-medium">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <div class="bg-purple-100 p-3 rounded-full mr-4">
                <i class="fas fa-bullhorn text-purple-600 text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></h1>
                <p class="text-sm text-gray-500">Gérez les messages de cette campagne</p>
            </div>
        </div>
        <div>
            <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition font-semibold text-sm">
                <i class="fas fa-plus mr-2"></i>Nouveau message
            </a>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <script>
            window.__flashMessageDejaAffiche = true;
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashMessage) ?>', 'success');
            });
        </script>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <script>
            window.__flashMessageDejaAffiche = true;
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashError) ?>', 'error');
            });
        </script>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Date de création</label>
                <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?></div>
            </div>
            <?php if ($campagne['date_planification']): ?>
                <div>
                    <label class="text-xs text-gray-500 uppercase font-semibold">Planifiée le</label>
                    <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?></div>
                </div>
            <?php endif; ?>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Statut</label>
                <div class="mt-1">
                    <span class="status-badge status-<?= $campagne['statut'] ?>">
                        <?php
                        $statusText = [
                            'brouillon' => 'Brouillon',
                            'planifiee' => 'Planifiée',
                            'envoyee' => 'Envoyée',
                            'pret_a_envoyer' => 'Prêt à envoyer',
                            'partiel' => 'Partiel',
                            'echoue' => 'Échoué'
                        ];
                        echo $statusText[$campagne['statut']] ?? $campagne['statut'];
                        ?>
                    </span>
                </div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Messages en attente</label>
                <div class="mt-1 text-lg font-bold text-orange-600"><?= $totalAPreparer ?></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-5 gap-4 mb-6">
        <div class="stat-card text-center">
            <div class="stat-number text-blue-950"><?= $totalEnvois ?></div>
            <div class="stat-label">Messages</div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-purple-600"><?= $totalSucces ?></div>
            <div class="stat-label">Destinataires touchés</div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-green-600"><?= $totalWhatsApp ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-whatsapp"><i class="fas fa-mobile-alt mr-1"></i> WhatsApp</span>
            </div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-blue-600"><?= $totalSms ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-sms"><i class="fas fa-comment-dots mr-1"></i> SMS</span>
            </div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-orange-800"><?= $totalEmail ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-email"><i class="fas fa-envelope mr-1"></i> Email</span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Rechercher un message (date, contenu, statut...)" 
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
        </div>
        
        <div class="filter-container">
            <label for="filterType"><i class="fas fa-filter mr-1"></i> Type :</label>
            <select id="filterType">
                <option value="all">Tous les types</option>
                <option value="whatsapp">📱 WhatsApp</option>
                <option value="sms">💬 SMS</option>
                <option value="email">✉️ Email</option>
            </select>
            
            <label for="filterStatus" class="ml-1"><i class="fas fa-check-circle mr-1"></i> Statut :</label>
            <select id="filterStatus">
                <option value="all">Tous les statuts</option>
                <option value="brouillon">Brouillon</option>
                <option value="envoye">Envoyé</option>
                <option value="echoue">Échoué</option>
                <option value="partiel">Partiel</option>
                <option value="pret_a_envoyer">Prêt à envoyer</option>
                <option value="planifiee">Planifié</option>
            </select>
            
            <button id="clearFilters" class="btn-clear-filter">
                <i class="fas fa-times mr-1"></i> Effacer
            </button>
            
            <span class="filter-info">
                <span id="visibleCount"><?= $totalEnvois ?></span> message(s)
            </span>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center flex-wrap gap-2">
            <div>
                <h1 class="text-lg">Historique des messages liés à cette campagne</h1>
                <p class="text-sm text-gray-500">Cliquez sur un message pour voir les détails</p>
            </div>
            <?php if ($totalBrouillons > 0): ?>
                <div class="text-sm bg-purple-100 text-purple-700 px-3 py-1 rounded-full font-semibold">
                    <i class="fas fa-pen mr-1"></i>
                    <?= $totalBrouillons ?> brouillon(s) à finaliser
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (empty($envois)): ?>
            <div class="text-center py-12">
                <i class="fas fa-envelope text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500">Aucun message.</p>
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
                   class="text-green-600 mt-2 inline-block font-semibold">
                    <i class="fas fa-plus mr-1"></i>Créer votre premier message
                </a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Message</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Destinataires</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Demande d'envoi</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="envoisTableBody">
                        <?php foreach ($envois as $envoi): 
                            $statutClass = 'text-gray-600';
                            $statutIcon = 'fa-circle';
                            $statutLabel = 'Inconnu';
                            
                            switch ($envoi['statut']) {
                                case 'envoye':
                                    $statutClass = 'text-green-600';
                                    $statutIcon = 'fa-check-circle';
                                    $statutLabel = 'Envoyé';
                                    break;
                                case 'partiel':
                                    $statutClass = 'text-yellow-600';
                                    $statutIcon = 'fa-exclamation-triangle';
                                    $statutLabel = 'Partiel';
                                    break;
                                case 'pret_a_envoyer':
                                    $statutClass = 'text-blue-600';
                                    $statutIcon = 'fa-clock';
                                    $statutLabel = 'Prêt à envoyer';
                                    break;
                                case 'planifiee':
                                    $statutClass = 'text-yellow-700';
                                    $statutIcon = 'fa-calendar-clock';
                                    $statutLabel = 'Planifié';
                                    break;
                                case 'echoue':
                                    $statutClass = 'text-red-600';
                                    $statutIcon = 'fa-exclamation-circle';
                                    $statutLabel = 'Échoué';
                                    break;
                                case 'brouillon':
                                    $statutClass = 'text-purple-600';
                                    $statutIcon = 'fa-pen';
                                    $statutLabel = 'Brouillon';
                                    break;
                                default:
                                    $statutClass = 'text-gray-600';
                                    $statutIcon = 'fa-circle';
                                    $statutLabel = $envoi['statut'] ?? 'Inconnu';
                            }
                            
                            if ($envoi['type_campagne'] == 'whatsapp') {
                                $typeClass = 'bg-green-100 text-green-700';
                                $typeIcon = 'fas fa-mobile-alt';
                                $typeLabel = 'WhatsApp';
                            } elseif ($envoi['type_campagne'] == 'email') {
                                $typeClass = 'bg-yellow-100 text-yellow-700';
                                $typeIcon = 'fas fa-envelope';
                                $typeLabel = 'Email';
                            } else {
                                $typeClass = 'bg-blue-100 text-blue-700';
                                $typeIcon = 'fas fa-comment-dots';
                                $typeLabel = 'SMS';
                            }
                            
                            $messageDisplay = strip_tags($envoi['message']);
                            if (strlen($messageDisplay) > 50) {
                                $messageDisplay = substr($messageDisplay, 0, 50) . '...';
                            }
                            
                            $showSendButton = false;
                            $showReprendreButton = false;
                            $buttonClass = 'btn-send-message';
                            $buttonIcon = 'fa-paper-plane';
                            $buttonText = 'Envoyer';
                            $isOctopushMessage = false;
                            $isBrouillon = ($envoi['statut'] === 'brouillon');
                            
                            if ($envoi['statut'] == 'pret_a_envoyer') {
                            $showSendButton = true;
                            
                            // Détection Octopush UNIQUEMENT pour les SMS
                            // et uniquement si ce message a bien un octopush_config_id
                            if ($envoi['type_campagne'] === 'sms' 
                                && !empty($envoi['octopush_config_id'])) {
                                $isOctopushMessage = true;
                                $buttonClass = 'btn-send-octopush';
                                $buttonIcon = 'fa-bolt';
                                $buttonText = 'Envoyer';
                                }
                            }
                            if ($envoi['statut'] == 'planifiee' && $envoi['type_campagne'] == 'email') {
                                $showSendButton = true;
                                $buttonClass = 'btn-send-email';
                                $buttonIcon = 'fa-envelope';
                                $buttonText = 'Envoyer Email';
                            }
                            if ($isBrouillon) {
                                $showReprendreButton = true;
                            }
                            
                            $reprendreUrl = getReprendreUrl($envoi['id_campagne'], $campagneId);
                            
                            $envoiJson = htmlspecialchars(json_encode($envoi), ENT_QUOTES, 'UTF-8');
                            $rowClass = $isBrouillon ? 'envoi-row row-brouillon' : 'envoi-row';
                        ?>
                            <tr class="<?= $rowClass ?>" 
                                data-id="<?= $envoi['id_campagne'] ?>"
                                data-type="<?= $envoi['type_campagne'] ?>"
                                data-status="<?= $envoi['statut'] ?>"
                                onclick="if(event.target.closest('button, form, a')) return; showDetailsFromRow(this)">
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($envoi['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="<?= $typeClass ?> px-2 py-1 rounded-full text-xs font-semibold">
                                        <i class="<?= $typeIcon ?> mr-1"></i>
                                        <?= $typeLabel ?>
                                    </span>
                                    <?php if ($isOctopushMessage): ?>
                                        <span class="badge-octopush"><i class="fas fa-bolt mr-1"></i>Octopush</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($messageDisplay) ?>">
                                        <?= htmlspecialchars($messageDisplay) ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-medium"><?= $envoi['nb_destinataires'] ?></td>
                                <td class="px-4 py-3 text-center">
                                    <i class="fas <?= $statutIcon ?> <?= $statutClass ?> mr-1"></i>
                                    <span class="text-sm font-medium <?= $statutClass ?>"><?= $statutLabel ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ($showReprendreButton): ?>
                                            <a href="<?= htmlspecialchars($reprendreUrl) ?>" 
                                               class="btn-reprendre" 
                                               title="Reprendre la configuration de ce brouillon"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-pen-to-square"></i> Reprendre
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($showSendButton): ?>
                                            <?php if ($isOctopushMessage): ?>
                                                <form method="POST" style="display:inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="action_envoyer_message" value="1">
                                                    <input type="hidden" name="id_campagne_historique" value="<?= $envoi['id_campagne'] ?>">
                                                    <input type="hidden" name="is_octopush" value="1">
                                                    <button type="submit" class="<?= $buttonClass ?>" title="Envoyer le message via Octopush">
                                                        <i class="fas <?= $buttonIcon ?>"></i> <?= $buttonText ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="action_envoyer_message" value="1">
                                                    <input type="hidden" name="id_campagne_historique" value="<?= $envoi['id_campagne'] ?>">
                                                    <button type="submit" class="<?= $buttonClass ?>" title="Envoyer le message">
                                                        <i class="fas <?= $buttonIcon ?>"></i> <?= $buttonText ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <button onclick="event.stopPropagation(); showDetails(<?= $envoiJson ?>)" 
                                                class="text-blue-600 hover:text-blue-800" title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL OCTOPUSH ===== -->
<div id="octopushModal" class="modal-octopush <?= $octopushResponse ? 'active' : '' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-bolt text-orange-500 mr-2"></i>Accusé de réception</h3>
            <button class="close" onclick="closeOctopushModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($octopushResponse): ?>
                <div style="text-align:center;margin-bottom:16px;">
                    <i class="fas fa-check-circle" style="font-size:48px;color:#10b981;"></i>
                    <p style="color:#10b981;font-weight:600;margin-top:8px;">✅ Envoi effectué avec succès</p>
                </div>
                <div class="response-item">
                    <span class="label">Ticket SMS</span>
                    <span class="value"><?= htmlspecialchars($octopushResponse['sms_ticket'] ?? '-') ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Nombre de contacts</span>
                    <span class="value"><?= htmlspecialchars($octopushResponse['number_of_contacts'] ?? '-') ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Coût total</span>
                    <span class="value"><?= isset($octopushResponse['total_cost']) ? number_format($octopushResponse['total_cost'],3) . ' €' : '-' ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Message</span>
                    <span class="value success"><?= htmlspecialchars($octopushResponse['message'] ?? 'Succès') ?></span>
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:20px;">
                    <i class="fas fa-info-circle" style="font-size:48px;color:#3b82f6;"></i>
                    <p style="color:#6b7280;margin-top:12px;">Aucune réponse disponible</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button onclick="closeOctopushModal()" class="btn-cancel">Fermer</button>
        </div>
    </div>
</div>

<!-- ===== MODAL DÉTAILS ===== -->
<div id="detailsModal">
    <div class="modal-container" id="modalContainer">
        <div class="modal-header-sticky">
            <div class="flex items-center">
                <div id="modalIcon" class="w-10 h-10 rounded-full flex items-center justify-center mr-3">
                    <i id="modalIconImg" class="text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
                    <p class="text-sm text-gray-500" id="modalSubtitle"></p>
                </div>
            </div>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body-content" id="modalContent"></div>
        
        <div class="modal-footer-sticky">
            <button onclick="closeModal()" class="px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition font-medium">
                Fermer
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

function closeOctopushModal() {
    document.getElementById('octopushModal').classList.remove('active');
}

const searchInput = document.getElementById('searchInput');
const filterType = document.getElementById('filterType');
const filterStatus = document.getElementById('filterStatus');
const envoisRows = document.querySelectorAll('.envoi-row');
const visibleCountSpan = document.getElementById('visibleCount');

function applyFilters() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    const typeFilter = filterType.value;
    const statusFilter = filterStatus.value;
    let visibleCount = 0;
    
    envoisRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const type = row.dataset.type || '';
        const status = row.dataset.status || '';
        let show = true;
        
        if (searchTerm !== '' && !text.includes(searchTerm)) show = false;
        if (show && typeFilter !== 'all' && type !== typeFilter) show = false;
        if (show && statusFilter !== 'all' && status !== statusFilter) show = false;
        
        if (show) { row.style.display = ''; visibleCount++; } 
        else { row.style.display = 'none'; }
    });
    
    visibleCountSpan.textContent = visibleCount;
    
    const noResult = document.getElementById('noResultMessage');
    if (visibleCount === 0 && envoisRows.length > 0) {
        if (!noResult) {
            const tbody = document.getElementById('envoisTableBody');
            const tr = document.createElement('tr');
            tr.id = 'noResultMessage';
            tr.innerHTML = `
                <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                    <i class="fas fa-search text-3xl mb-2 block"></i>
                    Aucun message ne correspond aux filtres sélectionnés.
                    <div class="mt-2">
                        <button onclick="resetFilters()" class="text-purple-600 hover:text-purple-800 font-semibold">
                            <i class="fas fa-undo mr-1"></i> Réinitialiser
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        }
    } else {
        if (noResult) noResult.remove();
    }
}

function resetFilters() {
    searchInput.value = '';
    filterType.value = 'all';
    filterStatus.value = 'all';
    applyFilters();
}

searchInput.addEventListener('input', applyFilters);
filterType.addEventListener('change', applyFilters);
filterStatus.addEventListener('change', applyFilters);
document.getElementById('clearFilters').addEventListener('click', resetFilters);

function showDetailsFromRow(row) {
    const eyeButton = row.querySelector('button[title="Voir détails"]');
    if (eyeButton) {
        eyeButton.click();
    }
}

// ============================================
// FONCTION POUR RÉCUPÉRER LES STATUTS SMS EN TEMPS RÉEL
// ============================================
function chargerStatutsSMS(idCampagne, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = `
        <div class="text-center py-6">
            <div class="loading-spinner"></div>
            <p class="text-sm text-gray-500 mt-3">Récupération des statuts réels en cours...</p>
        </div>
    `;
    
    fetch('index.php?page=campagnes/details&id=<?= urlencode($campagneId) ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_ajax_statut_sms=1&id_campagne_historique=' + encodeURIComponent(idCampagne)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            container.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-sm text-red-700">${escapeHtml(data.error || 'Erreur inconnue')}</span>
                    </div>
                </div>
            `;
            return;
        }
        
        const results = data.results || [];
        const stats = data.stats || { delivered: 0, pending: 0, failed: 0, other: 0, total: 0 };
        
        let recipientsHtml = '';
        results.forEach(r => {
            let rowClass = 'recipient-result-row ';
            let statusBadge = '';
            let icon = '';
            
            switch (r.category) {
                case 'delivered':
                    rowClass += 'success-row';
                    statusBadge = '<span class="recipient-status-badge status-delivered"><i class="fas fa-check-double"></i> Délivré</span>';
                    icon = 'fa-check-circle text-green-500';
                    break;
                case 'pending':
                    rowClass += 'skipped-row';
                    statusBadge = '<span class="recipient-status-badge status-pending"><i class="fas fa-clock"></i> En cours</span>';
                    icon = 'fa-clock text-blue-500';
                    break;
                case 'failed':
                    rowClass += 'fail-row';
                    statusBadge = '<span class="recipient-status-badge status-failed"><i class="fas fa-times"></i> Échec</span>';
                    icon = 'fa-times-circle text-red-500';
                    break;
                default:
                    rowClass += 'skipped-row';
                    statusBadge = `<span class="recipient-status-badge status-unknown"><i class="fas fa-question"></i> ${escapeHtml(r.state || 'Inconnu')}</span>`;
                    icon = 'fa-question-circle text-gray-500';
            }
            
            const errorHtml = r.error 
                ? `<div class="recipient-error" title="${escapeHtml(r.error)}">${escapeHtml(r.error.length > 80 ? r.error.substring(0, 80) + '...' : r.error)}</div>` 
                : '';
            
            const phoneDisplay = r.phone || 'Inconnu';
            
            recipientsHtml += `
                <div class="${rowClass}">
                    <div class="recipient-info">
                        <i class="fas ${icon}"></i>
                        <div>
                            <div class="recipient-phone">${escapeHtml(phoneDisplay)}</div>
                        </div>
                    </div>
                    ${statusBadge}
                    ${errorHtml}
                </div>
            `;
        });
        
        const total = stats.total || 1;
        const pctDelivered = Math.round((stats.delivered / total) * 100);
        const pctPending = Math.round((stats.pending / total) * 100);
        const pctFailed = Math.round((stats.failed / total) * 100);
        
        let summaryBar = `
            <div class="results-summary-bar">
                <div class="bar-success" style="width: ${pctDelivered}%"></div>
                <div class="bar-pending" style="width: ${pctPending}%"></div>
                <div class="bar-fail" style="width: ${pctFailed}%"></div>
            </div>
        `;
        
        let statsHtml = `
            <div class="grid grid-cols-4 gap-2 mb-4">
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-green-600">${stats.delivered}</div>
                    <div class="text-xs text-gray-500 font-medium">Délivrés</div>
                </div>
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-blue-600">${stats.pending}</div>
                    <div class="text-xs text-gray-500 font-medium">En cours</div>
                </div>
                <div class="bg-red-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-red-600">${stats.failed}</div>
                    <div class="text-xs text-gray-500 font-medium">Échecs</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-gray-600">${stats.other}</div>
                    <div class="text-xs text-gray-500 font-medium">Autres</div>
                </div>
            </div>
        `;
        
        container.innerHTML = `
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-list-check text-gray-500"></i>
                        <span class="font-semibold text-gray-700 text-sm">Statut réel des envois SMS</span>
                    </div>
                    <span class="text-xs text-gray-500">${stats.total} destinataire(s)</span>
                </div>
                <div class="p-4">
                    ${summaryBar}
                    ${statsHtml}
                    <div class="space-y-1 max-h-80 overflow-y-auto pr-1">
                        ${recipientsHtml}
                    </div>
                </div>
            </div>
        `;
    })
    .catch(error => {
        console.error('Erreur:', error);
        container.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                    <span class="text-sm text-red-700">Erreur de connexion : ${escapeHtml(error.message)}</span>
                </div>
            </div>
        `;
    });
}

// ============================================
// FONCTION POUR RÉCUPÉRER LES STATUTS SMS OCTOPUSH VIA WEBHOOK
// ============================================
function chargerStatutsSmsOctopush(smsTicket, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = `
        <div class="text-center py-6">
            <div class="loading-spinner"></div>
            <p class="text-sm text-gray-500 mt-3">Récupération des statuts Octopush en cours...</p>
        </div>
    `;
    
    fetch('index.php?page=campagnes/details&id=<?= urlencode($campagneId) ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_ajax_statut_sms_webhook=1&sms_ticket=' + encodeURIComponent(smsTicket)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            container.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-sm text-red-700">${escapeHtml(data.error || 'Erreur inconnue')}</span>
                    </div>
                </div>
            `;
            return;
        }
        
        const results = data.results || [];
        const stats = data.stats || { delivered: 0, pending: 0, failed: 0, other: 0, total: 0 };
        
        if (results.length === 0) {
            container.innerHTML = `
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-bolt text-orange-500"></i>
                            <span class="font-semibold text-gray-700 text-sm">Statut Octopush</span>
                        </div>
                        <span class="text-xs text-gray-500 font-mono">${escapeHtml(smsTicket)}</span>
                    </div>
                    <div class="p-6 text-center">
                        <i class="fas fa-hourglass-half text-3xl text-gray-300 mb-2"></i>
                        <p class="text-sm text-gray-500">Aucun statut reçu pour le moment.</p>
                        <p class="text-xs text-gray-400 mt-1">Les statuts arriveront via le webhook Octopush.</p>
                    </div>
                </div>
            `;
            return;
        }
        
        let recipientsHtml = '';
        results.forEach(r => {
            let rowClass = 'recipient-result-row ';
            let statusBadge = '';
            let icon = '';
            
            switch (r.category) {
                case 'delivered':
                    rowClass += 'success-row';
                    statusBadge = '<span class="recipient-status-badge status-delivered"><i class="fas fa-check-double"></i> Délivré</span>';
                    icon = 'fa-check-circle text-green-500';
                    break;
                case 'pending':
                    rowClass += 'skipped-row';
                    statusBadge = '<span class="recipient-status-badge status-pending"><i class="fas fa-clock"></i> En cours</span>';
                    icon = 'fa-clock text-blue-500';
                    break;
                case 'failed':
                    rowClass += 'fail-row';
                    statusBadge = '<span class="recipient-status-badge status-failed"><i class="fas fa-times"></i> Échec</span>';
                    icon = 'fa-times-circle text-red-500';
                    break;
                default:
                    rowClass += 'skipped-row';
                    statusBadge = `<span class="recipient-status-badge status-unknown"><i class="fas fa-question"></i> ${escapeHtml(r.state || 'Inconnu')}</span>`;
                    icon = 'fa-question-circle text-gray-500';
            }
            
            recipientsHtml += `
                <div class="${rowClass}">
                    <div class="recipient-info">
                        <i class="fas ${icon}"></i>
                        <div>
                            <div class="recipient-phone">${escapeHtml(r.phone)}</div>
                        </div>
                    </div>
                    ${statusBadge}
                </div>
            `;
        });
        
        const total = stats.total || 1;
        const pctDelivered = Math.round((stats.delivered / total) * 100);
        const pctPending = Math.round((stats.pending / total) * 100);
        const pctFailed = Math.round((stats.failed / total) * 100);
        
        let summaryBar = `
            <div class="results-summary-bar">
                <div class="bar-success" style="width: ${pctDelivered}%"></div>
                <div class="bar-pending" style="width: ${pctPending}%"></div>
                <div class="bar-fail" style="width: ${pctFailed}%"></div>
            </div>
        `;
        
        let statsHtml = `
            <div class="grid grid-cols-4 gap-2 mb-4">
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-green-600">${stats.delivered}</div>
                    <div class="text-xs text-gray-500 font-medium">Délivrés</div>
                </div>
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-blue-600">${stats.pending}</div>
                    <div class="text-xs text-gray-500 font-medium">En cours</div>
                </div>
                <div class="bg-red-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-red-600">${stats.failed}</div>
                    <div class="text-xs text-gray-500 font-medium">Échecs</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xl font-bold text-gray-600">${stats.other}</div>
                    <div class="text-xs text-gray-500 font-medium">Autres</div>
                </div>
            </div>
        `;
        
        container.innerHTML = `
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-orange-50 px-4 py-3 border-b border-orange-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-bolt text-orange-500"></i>
                        <span class="font-semibold text-orange-800 text-sm">Statut réel venant d'Octopush</span>
                    </div>
                    <span class="text-xs text-orange-700 font-mono">${escapeHtml(smsTicket)}</span>
                </div>
                <div class="p-4">
                    ${summaryBar}
                    ${statsHtml}
                    <div class="space-y-1 max-h-80 overflow-y-auto pr-1">
                        ${recipientsHtml}
                    </div>
                </div>
            </div>
        `;
    })
    .catch(error => {
        console.error('Erreur:', error);
        container.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                    <span class="text-sm text-red-700">Erreur de connexion : ${escapeHtml(error.message)}</span>
                </div>
            </div>
        `;
    });
}

// ============================================
// FONCTION POUR RÉCUPÉRER LES STATUTS EMAIL (LISTMONK) EN TEMPS RÉEL
// ============================================
function chargerStatutsEmail(idCampagne, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = `
        <div class="text-center py-6">
            <div class="loading-spinner"></div>
            <p class="text-sm text-gray-500 mt-3">Récupération des statistiques email en cours...</p>
        </div>
    `;
    
    fetch('index.php?page=campagnes/details&id=<?= urlencode($campagneId) ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_ajax_statut_email=1&id_campagne_historique=' + encodeURIComponent(idCampagne)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            container.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-sm text-red-700">${escapeHtml(data.error || 'Erreur inconnue')}</span>
                    </div>
                </div>
            `;
            return;
        }
        
        const stats = data.data || {};
        const sent = stats.sent || 0;
        const toSend = stats.to_send || 0;
        const views = stats.views || 0;
        const clicks = stats.clicks || 0;
        const bounces = stats.bounces || 0;
        const statusRaw = stats.status || 'unknown';
        const statusLabel = stats.status_label || statusRaw;
        
        let statusBadgeClass = 'bg-gray-100 text-gray-700';
        let statusIcon = 'fa-circle';
        
        switch (statusRaw) {
            case 'finished':
                statusBadgeClass = 'bg-green-100 text-green-700';
                statusIcon = 'fa-check-circle';
                break;
            case 'running':
                statusBadgeClass = 'bg-blue-100 text-blue-700';
                statusIcon = 'fa-spinner';
                break;
            case 'scheduled':
                statusBadgeClass = 'bg-yellow-100 text-yellow-700';
                statusIcon = 'fa-calendar-clock';
                break;
            case 'paused':
                statusBadgeClass = 'bg-orange-100 text-orange-700';
                statusIcon = 'fa-pause-circle';
                break;
            case 'cancelled':
                statusBadgeClass = 'bg-red-100 text-red-700';
                statusIcon = 'fa-times-circle';
                break;
            case 'draft':
                statusBadgeClass = 'bg-gray-100 text-gray-700';
                statusIcon = 'fa-pen';
                break;
        }
        
        const pctEnvoye = toSend > 0 ? Math.round((sent / toSend) * 100) : 0;
        
        const tauxOuverture = sent > 0 ? Math.round((views / sent) * 100) : 0;
        const tauxClic = sent > 0 ? Math.round((clicks / sent) * 100) : 0;
        const tauxBounce = sent > 0 ? Math.round((bounces / sent) * 100) : 0;
        
        container.innerHTML = `
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-chart-bar text-gray-500"></i>
                        <span class="font-semibold text-gray-700 text-sm">Statistiques d'envoi Email (Listmonk)</span>
                    </div>
                    <span class="text-xs text-gray-500">ID: ${stats.id || '-'}</span>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <span class="${statusBadgeClass} px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1">
                                <i class="fas ${statusIcon}"></i>
                                ${escapeHtml(statusLabel)}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500">
                            Campagne: <span class="font-semibold text-gray-700">${escapeHtml(stats.name || '-')}</span>
                        </div>
                    </div>
                    
                    <div class="results-summary-bar mb-4">
                        <div class="bar-success" style="width: ${pctEnvoye}%"></div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="bg-blue-50 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-blue-600">${sent}</div>
                            <div class="text-xs text-gray-500 font-medium">Messages envoyés</div>
                        </div>
                        <div class="bg-orange-50 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-orange-600">${toSend}</div>
                            <div class="text-xs text-gray-500 font-medium">Messages prévus</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    })
    .catch(error => {
        console.error('Erreur:', error);
        container.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                    <span class="text-sm text-red-700">Erreur de connexion : ${escapeHtml(error.message)}</span>
                </div>
            </div>
        `;
    });
}

function showDetails(envoi) {
    const modal = document.getElementById('detailsModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalSubtitle = document.getElementById('modalSubtitle');
    const modalContent = document.getElementById('modalContent');
    const modalIcon = document.getElementById('modalIcon');
    const modalIconImg = document.getElementById('modalIconImg');
    
    if (envoi.type_campagne === 'whatsapp') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-mobile-alt text-green-600 text-xl';
    } else if (envoi.type_campagne === 'email') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-envelope text-yellow-600 text-xl';
    } else {
        modalIcon.className = 'w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-comment-dots text-blue-600 text-xl';
    }
    
    modalTitle.textContent = envoi.titre || 'Détails du message';
    modalSubtitle.textContent = formatDate(envoi.created_at) + ' • ' + (envoi.nb_destinataires || 0) + ' destinataire(s)';
    
    let destinataires = [];
    try { destinataires = JSON.parse(envoi.destinataires); } 
    catch(e) { destinataires = [envoi.destinataires]; }
    
    let statusBadge;
    switch (envoi.statut) {
        case 'envoye':
            statusBadge = '<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-check-circle"></i>Envoyé</span>';
            break;
        case 'partiel':
            statusBadge = '<span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-exclamation-triangle"></i>Partiel</span>';
            break;
        case 'pret_a_envoyer':
            statusBadge = '<span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-clock"></i>Prêt à envoyer</span>';
            break;
        case 'planifiee':
            statusBadge = '<span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-calendar-clock"></i>Planifié</span>';
            break;
        case 'echoue':
            statusBadge = '<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-exclamation-circle"></i>Échoué</span>';
            break;
        case 'brouillon':
            statusBadge = '<span class="bg-purple-100 text-purple-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-pen"></i>Brouillon</span>';
            break;
        default:
            statusBadge = '<span class="bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-bold">' + escapeHtml(envoi.statut) + '</span>';
    }
    
    let typeBadge;
    if (envoi.type_campagne === 'whatsapp') {
        typeBadge = '<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-mobile-alt"></i>WhatsApp</span>';
    } else if (envoi.type_campagne === 'email') {
        typeBadge = '<span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-envelope"></i>Email</span>';
    } else {
        typeBadge = '<span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-comment-dots"></i>SMS</span>';
    }
    
    let messageContent = escapeHtml(envoi.message || '-');
    let isHtml = false;
    if (envoi.message && (
        envoi.message.includes('<p>') || 
        envoi.message.includes('<div>') || 
        envoi.message.includes('<br>') ||
        envoi.message.includes('<strong>') ||
        envoi.message.includes('<a href') ||
        envoi.message.includes('<img')
    )) {
        isHtml = true;
        messageContent = envoi.message;
    }
    
    let messageHtml = isHtml 
        ? `<div class="html-render">${messageContent}</div>`
        : `<div class="bg-gray-50 rounded-lg p-3 max-h-32 overflow-y-auto"><p class="text-sm text-gray-700 whitespace-pre-wrap">${messageContent}</p></div>`;
    
    let sessionInfo = '';
    if (envoi.appareil_utilise && envoi.appareil_utilise.includes('Octopush')) {
        sessionInfo = `
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mb-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-bolt text-orange-500"></i>
                    <span class="font-semibold text-orange-800">Session Octopush:</span>
                    <span class="text-orange-700">${escapeHtml(envoi.appareil_utilise)}</span>
                </div>
            </div>
        `;
    }
    
    if (envoi.type_campagne === 'email' && envoi.listmonk_campaign_id) {
        sessionInfo += `
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-envelope text-yellow-600"></i>
                    <span class="font-semibold text-yellow-800">Listmonk Campaign ID:</span>
                    <span class="text-yellow-700">#${escapeHtml(envoi.listmonk_campaign_id)}</span>
                </div>
            </div>
        `;
    }
    
    let actionHtml = '';
    if (envoi.statut === 'brouillon') {
        const reprendreUrl = 'index.php?page=campagnes/details&id=' + encodeURIComponent('<?= $campagneId ?>') + '&reprendre=' + encodeURIComponent(envoi.id_campagne);
        actionHtml = `
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 mb-4">
                <a href="${reprendreUrl}" class="btn-reprendre">
                    <i class="fas fa-pen-to-square"></i> Reprendre ce brouillon
                </a>
            </div>
        `;
    }
    
    let resultatsParDestinataireHtml = '';
    let resultatsDetails = [];
    let restriction = null;
    
    if (envoi.reponse_api && envoi.type_campagne === 'whatsapp') {
        try {
            const parsed = JSON.parse(envoi.reponse_api);
            
            if (parsed.details && Array.isArray(parsed.details)) {
                resultatsDetails = parsed.details;
                restriction = parsed.restriction || null;
            }
        } catch(e) {
            console.log('Erreur parsing reponse_api:', e);
        }
    }
    
    if (resultatsDetails.length > 0) {
        const nbSucces = envoi.nb_succes || 0;
        const nbEchecs = envoi.nb_erreurs || 0;
        const nbTotal = nbSucces + nbEchecs;
        const pourcentageSucces = nbTotal > 0 ? Math.round((nbSucces / nbTotal) * 100) : 0;
        const pourcentageEchecs = nbTotal > 0 ? Math.round((nbEchecs / nbTotal) * 100) : 0;
        
        let summaryBar = `
            <div class="results-summary-bar">
                <div class="bar-success" style="width: ${pourcentageSucces}%"></div>
                <div class="bar-fail" style="width: ${pourcentageEchecs}%"></div>
            </div>
        `;
        
        let restrictionAlert = '';
        if (restriction) {
            const restrictionLabels = {
                'capping': 'Limite d\'envoi WhatsApp atteinte (capping)',
                'ban': 'Compte WhatsApp restreint (bannissement)',
                'rate_limit': 'Trop de requêtes (rate limit)'
            };
            const label = restrictionLabels[restriction] || restriction;
            restrictionAlert = `
                <div class="restriction-alert mb-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <div class="font-semibold text-orange-800 text-sm">Restriction détectée</div>
                        <div class="text-orange-700 text-xs">${escapeHtml(label)}</div>
                    </div>
                </div>
            `;
        }
        
        let statsHtml = `
            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-blue-600">${nbTotal}</div>
                    <div class="text-xs text-gray-500 font-medium">Total</div>
                </div>
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-green-600">${nbSucces}</div>
                    <div class="text-xs text-gray-500 font-medium">Succès</div>
                </div>
                <div class="bg-red-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-red-600">${nbEchecs}</div>
                    <div class="text-xs text-gray-500 font-medium">Échecs</div>
                </div>
            </div>
        `;
        
        let recipientsHtml = '';
        resultatsDetails.forEach((r) => {
            let rowClass = 'recipient-result-row ';
            let statusBadgeRecipient = '';
            
            if (r.success) {
                rowClass += 'success-row';
                statusBadgeRecipient = '<span class="recipient-status-badge status-sent"><i class="fas fa-check"></i> Envoyé</span>';
            } else if (r.statut === 'skipped_capping' || r.statut === 'skipped') {
                rowClass += 'skipped-row';
                statusBadgeRecipient = '<span class="recipient-status-badge status-skipped"><i class="fas fa-forward"></i> Ignoré</span>';
            } else {
                rowClass += 'fail-row';
                statusBadgeRecipient = '<span class="recipient-status-badge status-failed"><i class="fas fa-times"></i> Échec</span>';
            }
            
            const phoneDisplay = r.phone || r.chatId || 'Inconnu';
            const nameDisplay = r.firstName && r.firstName.trim() ? `<span class="recipient-name">${escapeHtml(r.firstName)}</span>` : '';
            const chatIdDisplay = r.chatId && r.chatId !== r.phone ? `<div class="recipient-chatid">${escapeHtml(r.chatId)}</div>` : '';
            
            let errorHtml = '';
            if (r.error) {
                errorHtml = `<div class="recipient-error" title="${escapeHtml(r.error)}">${escapeHtml(r.error.length > 80 ? r.error.substring(0, 80) + '...' : r.error)}</div>`;
            }
            
            recipientsHtml += `
                <div class="${rowClass}">
                    <div class="recipient-info">
                        <i class="fas ${r.success ? 'fa-check-circle text-green-500' : (r.statut === 'skipped_capping' ? 'fa-forward text-yellow-500' : 'fa-times-circle text-red-500')}"></i>
                        <div>
                            <div class="recipient-phone">${escapeHtml(phoneDisplay)}</div>
                            ${nameDisplay}
                            ${chatIdDisplay}
                        </div>
                    </div>
                    ${statusBadgeRecipient}
                    ${errorHtml}
                </div>
            `;
        });
        
        resultatsParDestinataireHtml = `
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-list-check text-gray-500"></i>
                        <span class="font-semibold text-gray-700 text-sm">Résultats par destinataire</span>
                    </div>
                    <span class="text-xs text-gray-500">${resultatsDetails.length} destinataire(s)</span>
                </div>
                <div class="p-4">
                    ${restrictionAlert}
                    ${summaryBar}
                    ${statsHtml}
                    <div class="space-y-1 max-h-80 overflow-y-auto pr-1">
                        ${recipientsHtml}
                    </div>
                </div>
            </div>
        `;
    }
    
    let octopushSmsStatutContainerId = '';
    let octopushSmsStatutHtml = '';

    const isSmsOctopush = (envoi.type_campagne === 'sms' 
        && envoi.statut === 'envoye' 
        && envoi.appareil_utilise 
        && envoi.appareil_utilise.includes('Octopush')
        && envoi.sms_ticket);

    if (isSmsOctopush) {
        octopushSmsStatutContainerId = 'octopushSmsStatut_' + envoi.id_campagne;
        octopushSmsStatutHtml = `
            <div id="${octopushSmsStatutContainerId}">
                <div class="text-center py-6">
                    <div class="loading-spinner"></div>
                    <p class="text-sm text-gray-500 mt-3">Récupération des statuts Octopush...</p>
                </div>
            </div>
        `;
    }

    let smsStatutContainerId = '';
    let smsStatutHtml = '';
    
    const isSmsNonOctopush = (envoi.type_campagne === 'sms' 
        && envoi.statut === 'envoye' 
        && (!envoi.appareil_utilise || !envoi.appareil_utilise.includes('Octopush')));
    
    if (isSmsNonOctopush) {
        let hasMessageIds = false;
        try {
            const parsed = JSON.parse(envoi.reponse_api || '{}');
            if (parsed.message_ids && Array.isArray(parsed.message_ids) && parsed.message_ids.length > 0) {
                hasMessageIds = true;
            }
        } catch(e) {}
        
        smsStatutContainerId = 'smsStatutContainer_' + envoi.id_campagne;
        
        if (hasMessageIds) {
            smsStatutHtml = `
                <div id="${smsStatutContainerId}">
                    <div class="text-center py-6">
                        <div class="loading-spinner"></div>
                        <p class="text-sm text-gray-500 mt-3">Récupération des statuts réels en cours...</p>
                    </div>
                </div>
            `;
        } else {
            smsStatutHtml = `
                <div id="${smsStatutContainerId}">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-info-circle text-yellow-600"></i>
                            <span class="text-sm text-yellow-700">Aucun identifiant de message disponible pour vérifier le statut réel.</span>
                        </div>
                    </div>
                </div>
            `;
        }
    }
    
    let emailStatutContainerId = '';
    let emailStatutHtml = '';
    
    const isEmailWithListmonk = (envoi.type_campagne === 'email' 
        && envoi.listmonk_campaign_id);
    
    if (isEmailWithListmonk) {
        emailStatutContainerId = 'emailStatutContainer_' + envoi.id_campagne;
        emailStatutHtml = `
            <div id="${emailStatutContainerId}">
                <div class="text-center py-6">
                    <div class="loading-spinner"></div>
                    <p class="text-sm text-gray-500 mt-3">Récupération des statistiques email en cours...</p>
                </div>
            </div>
        `;
    }
    
    let destHtml = '';
    if (resultatsDetails.length === 0 && !isSmsNonOctopush && !isEmailWithListmonk) {
        if (destinataires && destinataires.length > 0) {
            destHtml = '<div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto">';
            for (let i = 0; i < Math.min(destinataires.length, 20); i++) {
                destHtml += '<div class="flex items-center p-2 bg-gray-50 rounded-lg">' +
                            '<i class="fas fa-user-circle text-gray-400 mr-2"></i>' +
                            '<span class="text-sm">' + escapeHtml(destinataires[i]) + '</span>' +
                            '</div>';
            }
            if (destinataires.length > 20) {
                destHtml += '<div class="text-center text-gray-500 text-sm col-span-2">+ ' + (destinataires.length - 20) + ' autres</div>';
            }
            destHtml += '</div>';
        } else {
            destHtml = '<p class="text-gray-500 italic">Aucun destinataire enregistré</p>';
        }
    }
    
    modalContent.innerHTML = `
        <div class="space-y-4">
            ${sessionInfo}
            ${actionHtml}
            
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Type</div>
                    <div>${typeBadge}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Statut global</div>
                    <div>${statusBadge}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Appareil / Session</div>
                    <div class="text-sm font-medium">${escapeHtml(envoi.appareil_utilise || '-')}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Date d'envoi</div>
                    <div class="font-medium">${formatDate(envoi.created_at)}</div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1 flex items-center gap-2">
                    <i class="fas fa-comment"></i> Message ${isHtml ? '(HTML)' : ''}
                </div>
                ${messageHtml}
            </div>
            
            ${resultatsParDestinataireHtml}
            
            ${smsStatutHtml}

            ${octopushSmsStatutHtml}
            
            ${emailStatutHtml}
            
            ${resultatsDetails.length === 0 && !isSmsNonOctopush && !isEmailWithListmonk ? `
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1 flex items-center gap-2">
                    <i class="fas fa-users"></i> Destinataires (${envoi.nb_destinataires || 0})
                </div>
                <div class="bg-gray-50 rounded-lg p-3">${destHtml}</div>
            </div>
            ` : ''}
            
            ${envoi.erreur && resultatsDetails.length === 0 ? `
            <div>
                <div class="text-xs text-red-500 font-semibold mb-1 flex items-center gap-2">
                    <i class="fas fa-exclamation-circle"></i> Message d'erreur
                </div>
                <div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-3">
                    <p class="text-sm text-red-700">${escapeHtml(envoi.erreur)}</p>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.classList.add('flex');
    
    if (isSmsNonOctopush && smsStatutContainerId) {
        setTimeout(() => {
            chargerStatutsSMS(envoi.id_campagne, smsStatutContainerId);
        }, 100);
    }
    
    if (isEmailWithListmonk && emailStatutContainerId) {
        setTimeout(() => {
            chargerStatutsEmail(envoi.id_campagne, emailStatutContainerId);
        }, 100);
    }
    if (isSmsOctopush && octopushSmsStatutContainerId) {
    setTimeout(() => {
        chargerStatutsSmsOctopush(envoi.sms_ticket, octopushSmsStatutContainerId);
        }, 100);
    }
}

function closeModal() {
    document.getElementById('detailsModal').classList.remove('flex');
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeOctopushModal();
    }
});

document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('octopushModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeOctopushModal();
});

document.addEventListener('DOMContentLoaded', function() {
    applyFilters();
});

// ============================================
// AFFICHER UN TOAST POUR LES ENVOIS RÉCENTS (faits par le cron)
// ============================================
(function() {
    // Si un flash message PHP s'affiche, le toast des envois récents est redondant
    if (window.__flashMessageDejaAffiche) return;
    
    const envoisRecents = <?= $envoisRecentsJson ?>;
    
    if (!envoisRecents || envoisRecents.length === 0) return;
    
    // Marquer en sessionStorage pour ne pas répéter le toast à chaque reload
    const dejaAffiches = JSON.parse(sessionStorage.getItem('toastsAffiches') || '[]');
    
    let nbAffiches = 0;
    
    envoisRecents.forEach(envoi => {
        // Clé basée uniquement sur l'ID → un envoi toasté une fois ne le sera plus jamais
        const key = envoi.id;
        if (dejaAffiches.includes(key)) return;
        
        let message = '';
        let type = 'success';
        
        if (envoi.statut === 'envoye' || envoi.statut === 'partiel') {
            message = '✅ Message envoyé (' + envoi.nb_succes + ' destinataire(s))';
        } else if (envoi.statut === 'echoue') {
            message = '❌ Échec d\'envoi (' + envoi.nb_erreurs + ' erreur(s))';
            type = 'error';
        } else {
            return;
        }
        
        setTimeout(() => showToast(message, type), 500 + (nbAffiches * 300));
        nbAffiches++;
        dejaAffiches.push(key);
    });
    
    sessionStorage.setItem('toastsAffiches', JSON.stringify(dejaAffiches));
})();

// ============================================
// SURVEILLANCE DES STATUTS
// Vérifie en arrière-plan si le cron a terminé.
// Affiche un toast + recharge quand un statut change.
// ============================================
(function() {
    // ============================================
    // Anti-doublon : ne jamais re-notifier un envoi déjà notifié
    // (persiste à travers les rechargements via sessionStorage)
    // ============================================
    const CLE_STORAGE = 'envois_notifies_surveillance';
    let dejaNotifies = [];
    try {
        dejaNotifies = JSON.parse(sessionStorage.getItem(CLE_STORAGE) || '[]');
        if (!Array.isArray(dejaNotifies)) dejaNotifies = [];
    } catch (e) {
        dejaNotifies = [];
    }
    
    // Nettoyage : on ne garde que les IDs encore présents sur la page
    // (évite que le sessionStorage grossisse indéfiniment)
    const idsPresents = Array.from(document.querySelectorAll('tr.envoi-row')).map(r => r.dataset.id);
    dejaNotifies = dejaNotifies.filter(id => idsPresents.includes(id));
    sessionStorage.setItem(CLE_STORAGE, JSON.stringify(dejaNotifies));
    
    // IDs des envois manuels récents → déjà gérés par le flash, ne pas re-surveiller
    const envoisManuels = <?= $envoisManuelsJson ?>;
    
    // Récupérer les IDs des lignes non finalisées, en excluant :
    // - les envois manuels (flash PHP déjà affiché)
    // - les envois déjà notifiés par la surveillance (dans un reload précédent)
    const rowsAPoll = Array.from(document.querySelectorAll(
        'tr.envoi-row[data-status="pret_a_envoyer"], tr.envoi-row[data-status="planifiee"]'
    )).filter(r => 
        !envoisManuels.includes(r.dataset.id) 
        && !dejaNotifies.includes(r.dataset.id)
    );
    
    if (rowsAPoll.length === 0) return; // Rien à surveiller
    
    const ids = rowsAPoll.map(r => r.dataset.id);
    const statutsInitiaux = {};
    rowsAPoll.forEach(r => { statutsInitiaux[r.dataset.id] = r.dataset.status; });
    
    console.log('[Surveillance] ' + ids.length + ' message(s) à surveiller');
    
    const interval = setInterval(() => {
        const formData = new URLSearchParams();
        formData.append('action_ajax_check_statuts', '1');
        ids.forEach(id => formData.append('ids[]', id));
        
        fetch('index.php?page=campagnes/details&id=<?= urlencode($campagneId) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            let aChange = false;
            let messagesEnvoyes = 0;
            let messagesEchoues = 0;
            const idsChanges = [];
            
            data.messages.forEach(msg => {
                const ancien = statutsInitiaux[msg.id_campagne];
                if (ancien && ancien !== msg.statut) {
                    console.log('[Surveillance] #' + msg.id_campagne + ' : ' + ancien + ' → ' + msg.statut);
                    aChange = true;
                    idsChanges.push(msg.id_campagne);
                    
                    if (msg.statut === 'envoye' || msg.statut === 'partiel') {
                        messagesEnvoyes++;
                    } else if (msg.statut === 'echoue') {
                        messagesEchoues++;
                    }
                }
            });
            
            if (aChange) {
                clearInterval(interval);
                
                // ✅ Marquer ces IDs comme déjà notifiés AVANT le reload
                // pour que la page rechargée ne les re-surveille pas.
                dejaNotifies = dejaNotifies.concat(idsChanges);
                sessionStorage.setItem(CLE_STORAGE, JSON.stringify(dejaNotifies));
                
                console.log('[Surveillance] Changement détecté → toast + rechargement');
                
                let message = '';
                let type = 'success';
                
                if (messagesEnvoyes > 0 && messagesEchoues === 0) {
                    message = '✅ Envoi terminé : ' + messagesEnvoyes + ' message(s) envoyé(s)';
                } else if (messagesEchoues > 0 && messagesEnvoyes === 0) {
                    message = '❌ Envoi terminé : ' + messagesEchoues + ' échec(s)';
                    type = 'error';
                } else if (messagesEnvoyes > 0 && messagesEchoues > 0) {
                    message = '⚠️ Envoi terminé : ' + messagesEnvoyes + ' succès, ' + messagesEchoues + ' échec(s)';
                } else {
                    message = '✅ Envoi terminé';
                }
                
                showToast(message, type);
                
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        })
        .catch(err => console.error('[Surveillance] Erreur:', err));
    }, 5000);
})();
</script>

</body>
</html>