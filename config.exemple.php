<?php
// Configuration Supabase (mettez VOS vraies clés)
//define('SUPABASE_URL', 'https://hrihltqnakwzyafvbrik.supabase.co/'); 
define('SUPABASE_URL', 'http://65.109.30.163:3000/'); 

//define('SUPABASE_KEY', 'sb_publishable_Dj5SOiz3Bq1iNmlkFaYnCA_YyA_DmmS');     
define('SUPABASE_KEY', 'eyJyb2xlIjoiYW5vbiJ9.IEDKHmyTDV7eVyL1bwA');     



// Configuration application
define('APP_NAME', 'Mailing Platform');
define('APP_URL', 'http://localhost/mailing');
define('TIMEZONE', 'Europe/Paris');
define('DEBUG_MODE', true);

// Session PHP
session_start();

// Fuseau horaire
date_default_timezone_set(TIMEZONE);

// Afficher les erreurs PHP (pour le debug)
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
?>