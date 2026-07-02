<?php
// ============================================
// FONCTIONS DE GESTION DES CHAMPS PERSONNALISÉS
// ============================================

/**
 * Récupère les champs personnalisés d'un contact spécifique
 * 
 * @param string $idContact ID du contact (UUID)
 * @param bool $onlyActive Récupérer uniquement les champs actifs
 * @return array Liste des champs personnalisés du contact
 */
function getCustomFieldsForContact($idContact, $onlyActive = true) {
    global $db;
    
    $conditions = ['id_contact' => $idContact];
    if ($onlyActive) {
        $conditions['is_active'] = true;
    }
    
    return $db->select('custom_fields', $conditions, '*', 'field_order ASC');
}

/**
 * Récupère les valeurs des champs personnalisés pour un contact
 * 
 * @param string $idContact ID du contact (UUID)
 * @return array Tableau associatif [nom_champ => ['label' => ..., 'value' => ...]]
 */
function getContactCustomValues($idContact) {
    global $db;
    
    try {
        // Récupérer les champs du contact depuis custom_fields
        $fields = $db->select('custom_fields', ['id_contact' => $idContact]);
        $result = [];
        
        if (empty($fields)) {
            return $result;
        }
        
        foreach ($fields as $field) {
            // Vérifier que l'ID est valide
            if (!isset($field['id_custom_field']) || empty($field['id_custom_field'])) {
                continue;
            }
            
            $idCustomField = $field['id_custom_field'];
            
            // Récupérer la valeur pour ce champ depuis contact_custom_values
            $value = $db->select('contact_custom_values', ['id_custom_field' => $idCustomField]);
            
            $result[$field['field_name']] = [
                'id_custom_field' => $idCustomField,
                'label' => $field['field_label'],
                'type' => $field['field_type'],
                'value' => !empty($value) ? $value[0]['field_value'] : ''
            ];
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("ERREUR getContactCustomValues: " . $e->getMessage());
        return [];
    }
}

/**
 * Sauvegarde les valeurs des champs personnalisés pour un contact
 * 
 * @param string $idContact ID du contact (UUID)
 * @param array $customFieldsData Tableau des données [nom_champ => valeur]
 */
function saveContactCustomValues($idContact, $customFieldsData) {
    global $db;
    
    if (empty($customFieldsData) || !is_array($customFieldsData)) {
        return;
    }
    
    foreach ($customFieldsData as $fieldName => $value) {
        // Chercher le champ associé à ce contact
        $field = $db->select('custom_fields', [
            'id_contact' => $idContact,
            'field_name' => $fieldName
        ]);
        
        if (empty($field)) continue;
        
        $idCustomField = $field[0]['id_custom_field'];
        $value = trim($value);
        
        $existing = $db->select('contact_custom_values', [
            'id_custom_field' => $idCustomField
        ]);
        
        if (empty($existing)) {
            if (!empty($value)) {
                $db->insert('contact_custom_values', [
                    'id_custom_field' => $idCustomField,
                    'field_value' => $value
                ]);
            }
        } else {
            if (empty($value)) {
                // CORRECTION : delete($table, $id, $idField)
                $db->delete('contact_custom_values', $idCustomField, 'id_custom_field');
            } else {
                $db->update('contact_custom_values', [
                    'field_value' => $value
                ], ['id_custom_field' => $idCustomField]);
            }
        }
    }
}

/**
 * Créer un nouveau champ personnalisé pour un contact spécifique
 * 
 * @param string $idCompte ID du compte (UUID)
 * @param string $idContact ID du contact (UUID)
 * @param string $fieldName Nom technique du champ
 * @param string $fieldLabel Libellé du champ
 * @param string $fieldType Type de champ (text, textarea, select, date, number, email, tel)
 * @param string|null $fieldOptions Options pour les listes déroulantes (séparées par |)
 * @return array ['success' => bool, 'message' => string, 'error' => string]
 */
function createCustomFieldForContact($idCompte, $idContact, $fieldName, $fieldLabel, $fieldType = 'text', $fieldOptions = null) {
    global $db;
    
    // LOG DE DEBUG
    error_log("=== createCustomFieldForContact ===");
    error_log("idCompte: $idCompte");
    error_log("idContact: $idContact");
    error_log("fieldName: $fieldName");
    error_log("fieldLabel: $fieldLabel");
    error_log("fieldType: $fieldType");
    error_log("fieldOptions: $fieldOptions");
    
    // Nettoyer le nom du champ
    $fieldName = strtolower(preg_replace('/[^a-z0-9_]/', '_', trim($fieldName)));
    $fieldLabel = trim($fieldLabel);
    
    // Vérifier si le champ existe déjà pour ce contact
    $exists = $db->select('custom_fields', [
        'id_contact' => $idContact,
        'field_name' => $fieldName
    ]);
    
    if (!empty($exists)) {
        error_log("❌ Champ existe déjà");
        return ['success' => false, 'error' => 'Ce nom de champ existe déjà pour ce contact'];
    }
    
    // Récupérer le dernier ordre pour ce contact
    $lastField = $db->select('custom_fields', [
        'id_contact' => $idContact
    ], '*', 'field_order DESC', 1);
    $nextOrder = !empty($lastField) ? $lastField[0]['field_order'] + 1 : 1;
    
    // Créer le champ
    $data = [
        'id_contact' => $idContact,
        'field_name' => $fieldName,
        'field_label' => $fieldLabel,
        'field_type' => $fieldType,
        'field_options' => $fieldOptions,
        'field_order' => $nextOrder,
        'is_required' => false,
        'is_active' => true,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    error_log("📝 Données à insérer: " . json_encode($data));
    
    try {
        // 🔥 CORRECTION : Utiliser insertAndGetId au lieu de request()
        $fieldId = $db->insertAndGetId('custom_fields', $data);
        error_log("✅ fieldId retourné: " . ($fieldId ? $fieldId : 'null'));
        
        if ($fieldId) {
            return ['success' => true, 'message' => 'Champ créé avec succès', 'id_custom_field' => $fieldId];
        } else {
            return ['success' => false, 'error' => 'Erreur lors de l\'insertion du champ'];
        }
    } catch (Exception $e) {
        error_log("❌ ERREUR createCustomFieldForContact: " . $e->getMessage());
        error_log("📄 TRACE: " . $e->getTraceAsString());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
/**
 * Supprime un champ personnalisé pour un contact
 * 
 * @param string $idCustomField ID du champ personnalisé (UUID)
 * @param string $idContact ID du contact (UUID) - pour vérification
 * @return bool Succès de la suppression
 */
function deleteCustomFieldForContact($idCustomField, $idContact) {
    global $db;
    
    // Vérifier que le champ appartient bien au contact
    $field = $db->select('custom_fields', [
        'id_custom_field' => $idCustomField,
        'id_contact' => $idContact
    ]);
    
    if (empty($field)) {
        return false;
    }
    
    try {
        // CORRECTION : delete($table, $id, $idField)
        // Supprimer d'abord les valeurs associées
        $db->delete('contact_custom_values', $idCustomField, 'id_custom_field');
        // Supprimer le champ
        $db->delete('custom_fields', $idCustomField, 'id_custom_field');
        return true;
    } catch (Exception $e) {
        error_log("ERREUR deleteCustomFieldForContact: " . $e->getMessage());
        return false;
    }
}

/**
 * Récupère les valeurs des champs personnalisés pour un contact avec leurs libellés
 * 
 * @param string $idContact ID du contact (UUID)
 * @return array Tableau des champs avec leurs valeurs
 */
function getContactCustomFieldsWithValues($idContact) {
    global $db;
    
    $fields = $db->select('custom_fields', ['id_contact' => $idContact]);
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
    
    return $result;
}

// ============================================
// FONCTIONS UTILITAIRES GÉNÉRALES
// ============================================

/**
 * Vérifie si un email existe déjà dans la base
 * 
 * @param string $email Email à vérifier
 * @param string $idCompte ID du compte (UUID)
 * @param string|null $excludeId ID à exclure (pour les modifications)
 * @return bool True si l'email existe déjà
 */
function emailExists($email, $idCompte, $excludeId = null) {
    global $db;
    
    $conditions = ['email' => $email, 'id_compte' => $idCompte];
    if ($excludeId) {
        $conditions['id_contact !='] = $excludeId;
    }
    
    $result = $db->select('contact', $conditions);
    return !empty($result);
}

/**
 * Vérifie l'âge d'une personne
 * 
 * @param string $dateNaissance Date de naissance (Y-m-d)
 * @param int $ageMinimum Âge minimum requis
 * @return bool True si la personne a l'âge minimum
 */
function verifierAge($dateNaissance, $ageMinimum = 18) {
    if (empty($dateNaissance)) {
        return true;
    }
    $dateNaissanceObj = DateTime::createFromFormat('Y-m-d', $dateNaissance);
    if (!$dateNaissanceObj) {
        return false;
    }
    $now = new DateTime();
    if ($dateNaissanceObj > $now) {
        return false;
    }
    $age = $now->diff($dateNaissanceObj)->y;
    return $age >= $ageMinimum;
}

/**
 * Formate un numéro de téléphone
 * 
 * @param string $telephone Numéro à formater
 * @return string Numéro formaté
 */
function formatPhoneNumber($telephone) {
    if (empty($telephone)) {
        return '';
    }
    
    // Nettoyer
    $telephone = preg_replace('/[^0-9]/', '', $telephone);
    $longueur = strlen($telephone);
    
    // Si le numéro commence par 0
    if (substr($telephone, 0, 1) == '0') {
        // Enlever le 0
        $telephone = substr($telephone, 1);
        $longueur = strlen($telephone);
        
        // Remplacer par le bon préfixe
        if ($longueur == 10) {
            $telephone = '33' . $telephone;      // France métropolitaine
        } elseif ($longueur == 9) {
            $telephone = '261' . $telephone;     // Madagascar
        } elseif ($longueur == 8) {
            $telephone = '33' . $telephone;      // France (numéro à 8 chiffres)
        } elseif ($longueur == 7) {
            $telephone = '261' . $telephone;     // Madagascar (numéro à 7 chiffres)
        }
    }
    
    // Si le numéro a déjà un indicatif (33 ou 261) mais commence par 33
    if (substr($telephone, 0, 2) == '33' && strlen($telephone) == 12) {
        return $telephone;
    }
    
    // Si le numéro a déjà un indicatif (261) mais commence par 261
    if (substr($telephone, 0, 3) == '261' && strlen($telephone) == 12) {
        return $telephone;
    }
    
    return $telephone;
}

/**
 * Génère un UUID v4
 * 
 * @return string UUID
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Nettoie une chaîne pour l'utiliser comme nom de champ
 * 
 * @param string $str Chaîne à nettoyer
 * @return string Chaîne nettoyée
 */
function sanitizeFieldName($str) {
    return strtolower(preg_replace('/[^a-z0-9_]/', '_', trim($str)));
}

/**
 * Vérifie si une chaîne est un UUID valide
 * 
 * @param string $uuid Chaîne à vérifier
 * @return bool True si c'est un UUID valide
 */
function isValidUUID($uuid) {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1;
}

/**
 * Truncate un texte avec une longueur maximale
 * 
 * @param string $text Texte à tronquer
 * @param int $length Longueur maximale
 * @param string $suffix Suffixe à ajouter
 * @return string Texte tronqué
 */
function truncateText($text, $length = 50, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

/**
 * Formate une date pour l'affichage
 * 
 * @param string $date Date au format ISO
 * @param string $format Format de sortie
 * @return string Date formatée
 */
function formatDate($date, $format = 'd/m/Y H:i') {
    if (empty($date)) {
        return '-';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    return date($format, $timestamp);
}

/**
 * Récupère les champs personnalisés d'un compte
 * 
 * @param string $idCompte ID du compte (UUID)
 * @param bool $onlyActive Récupérer uniquement les champs actifs
 * @return array Liste des champs personnalisés du compte
 */
function getCustomFields($idCompte, $onlyActive = true) {
    global $db;
    
    $conditions = ['id_compte' => $idCompte];
    if ($onlyActive) {
        $conditions['is_active'] = true;
    }
    
    return $db->select('custom_fields', $conditions, '*', 'field_order ASC');
}
?>