 <?php

require_once 'intake.civix.php';
require_once __DIR__ . '/intake.helpers.php';
require_once __DIR__ . '/intake.activities.php';
require_once __DIR__ . '/intake.logic.fot.php';
require_once __DIR__ . '/intake.logic.ref.php';
require_once __DIR__ . '/intake.logic.vog.php';
require_once __DIR__ . '/intake.status.ref.php';
require_once __DIR__ . '/intake.status.vog.php';

use CRM_Intake_ExtensionUtil as E;

function intake_civicrm_pre($op, $objectName, $id, &$params) {

    // 1. CHECK HETZELFDE SLOT
    // Als customPre bezig is, stoppen we hier ook.
    if (intake_recursion_lock()) {
        return; 
    }

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    if ($objectName === 'Individual' && $op === 'edit') {
    
        // 1. Check of er een image_URL in de update-poging zit
        if (isset($params['image_URL'])) {

            // 2. SLOT DICHTDOEN
            intake_recursion_lock(true);

            wachthond($extdebug,3, "########################################################################");
            wachthond($extdebug,3, "### INTAKE CORE [PRE] CHECK OF ER EEN NIEUWE FOTO IS", "[objectName: $objectName / op: $op]");
            wachthond($extdebug,3, "########################################################################");            
            
            $newImage = $params['image_URL'];

            // --- NIEUWE CHECK: IS HET EEN PLACEHOLDER? ---
            // Als de nieuwe foto een placeholder is, hoeven we de 'fot_update' datum niet aan te passen.
            if (str_contains(strtolower($newImage), 'placeholder')) {
                
                wachthond($extdebug, 1, "SKIP: Nieuwe afbeelding is een placeholder. Datum en status worden NIET bijgewerkt.");

            } else { 
                // ALLEEN UITVOEREN ALS HET GEEN PLACEHOLDER IS

                // 2. Setup params voor legacy APIv4 get actie
                $params_get_old = [
                    'checkPermissions' => FALSE,
                    'debug'     => $apidebug,
                    'select'    => ['image_URL'],
                    'where'     => [
                        ['id', '=', $id],
                    ],
                ];

                wachthond($extdebug, 7, 'params_get_old_photo', $params_get_old);

                try {
                    // Voer de APIv4 get uit
                    $result_old = civicrm_api4('Contact', 'get', $params_get_old);
                    $oldImage   = $result_old->first()['image_URL'] ?? NULL;

                    // Fallback / Extra check via DAO
                    $oldImage   = CRM_Core_DAO::singleValueQuery("SELECT image_URL FROM civicrm_contact WHERE id = %1", [1 => [$id, 'Integer']]);

                    wachthond($extdebug, 3, "FOTO Vergelijking: [OUD: $oldImage]");
                    wachthond($extdebug, 3, "FOTO Vergelijking: [NEW: $newImage]");

                    // 3. Vergelijk oud met nieuw
                    if ($newImage !== $oldImage) {
                        
                        wachthond($extdebug, 1, "MATCH: Foto gewijzigd (en geen placeholder). Datum-veld wordt klaargezet in params.");

                        // We voegen de datum direct toe aan de lopende transactie
                        // Gebruik de exacte API-naam: Groep_Naam.Veld_Naam
                        $params['Intake.fot_update_2253'] = format_civicrm_smart('now', 'fot_update_2253');
                        $params['Intake.fot_status_1798'] = 1;

                        // DIRECT SCHONEN voor APIv4 / Drupal Entity compatibiliteit
                        if (function_exists('drupal_timestamp_sweep')) {
                            drupal_timestamp_sweep($params);
                        }
                        
                    } else {
                        wachthond($extdebug, 3, "SKIP: Geen wijziging in foto gedetecteerd.");
                    }

                } catch (\Exception $e) {
                    wachthond($extdebug, 1, "FATAAL: Fout in APIv4 photo get: " . $e->getMessage());
                }
            } // Einde else (geen placeholder)

        } else {
            wachthond($extdebug, 4, "Geen image_URL in params gevonden.");
        }
    }

    // DOE DE TIMESTAMP SWEEP OM DE PARAMS TE SANITIZEN
    if (function_exists('drupal_timestamp_sweep')) {
        drupal_timestamp_sweep($params);
    }

    // 3. SLOT OPENEN
    intake_recursion_lock(false);
}

function intake_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    // 1. Initialiseer debug (voorkom TypeError)
    $extdebug = 0; 

    // 2. Definieer relevante groepen
    $profilecontintake = array(181);
    $profilepartref    = array(213);
    $profilepartvog    = array(140);
    $profilepartintake = array_merge($profilepartref,  $profilepartvog);    
    $profileintakeall  = array_merge($profilecontintake, $profilepartref, $profilepartvog);

    // 3. Eerst de snelle check: Alleen doorgaan voor de intake-groepen
    if (!in_array($groupID, $profileintakeall)) {
        return;
    }

    // 4. Lokale lock (Static)
    static $processing_intake_custompre = false;
    
    if ($processing_intake_custompre) {
        return;
    }

    // 5. Globale lock check (Recursion Lock)
    if (intake_recursion_lock()) {
        wachthond($extdebug, 1, "### DEBUG: GLOBAL LOCK IS ACTIEF - SCRIPT STOPT");
        return; 
    }

    // 6. Zet alles op slot
    $processing_intake_custompre = true; 
    intake_recursion_lock(true);    

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug, 1, "### START INTAKE PROCESSING voor Entity: $entityID", "[group: $groupID]");
    wachthond($extdebug,4, "########################################################################");

    $intake_start_tijd      = microtime(TRUE);

    $extdebug_general       = 0;    // ALGEMENE DELEN DIE ALTIJD PLAATSVINDEN
    $extdebug_intake_cont   = 0;    //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $extdebug_intake_part   = 0;    //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $extdebug_cont_ref      = 0;    //  DEBUG profiel contact_intake
    $extdebug_cont_vog      = 0;    //  DEBUG profiel contact_intake
    $extdebug_part_ref      = 0;    //  DEBUG participant profielen VOG & REF
    $extdebug_part_vog      = 0;    //  DEBUG participant profielen VOG & REF
    $extdebug               = $extdebug_general;

    $apidebug               = FALSE;
    $extwrite               = 1;

    $today_datetime         = date("Y-m-d H:i:s");

    wachthond($extdebug,4, "params",        $params);

    // ##########################################################################################
    // --- STAP 1: DEFINIEER TRIGGERS & POORTWACHTER ---
    // ##########################################################################################

    $arraysize = is_array($params) ? count($params) : 0;

    // lijst met velden die ook als ze enige veld in params zijn rest van de functie mogen triggeren
    $intake_trigger_fields = [
        //  (CONT) TRIGGERS FOT/NAW/BIO
        'fot_update_2253', 
        'naw_gecheckt_1505', 
        'bio_ingevuld_1496', 
        'bio_gecheckt_1497', 
        'intake_trigger_2250',
        //  (PART) TRIGGERS VOG
        'vog_verzoek_599', 
        'vog_aanvraag_600', 
        'vog_ontvangst_601', 
        'vog_vognieuw_603',
        //  (PART) TRIGGERS REF
        'ref_persoon_1301', 
        'ref_gevraagd_1295', 
        'ref_feedback_1296'
    ];

    // De Poortwachter: alleen doorgaan bij arraysize = 1 indien het een relevante trigger is
    if ($arraysize === 1 && (in_array($groupID, $profilecontintake) || in_array($groupID, $profilepartintake))) {
        $column_name = $params[0]['column_name'] ?? ''; 
        
        if (!in_array($column_name, $intake_trigger_fields)) {
            // Geen relevante trigger gevonden: locks vrijgeven en stoppen
            $processing_intake_custompre = false;
            intake_recursion_lock(false);
            
            wachthond($extdebug, 2, "SKIP: Veld '$column_name' is geen intake trigger.");
            return;
        }
    }    

    ##########################################################################################
    // --- Stap 1: Identificeer de aanwezige intake-prefixes ---
    ##########################################################################################

    $found_prefixes = [];
    foreach ($params as $item) {
        $col = $item['column_name'] ?? '';
        foreach (['fot_', 'naw_', 'bio_', 'ref_', 'vog_'] as $p) {
            if (strpos($col, $p) === 0 && !in_array($p, $found_prefixes)) {
                $found_prefixes[] = $p;
            }
        }
    }

    // Korte vlaggen maken (0 of 1)
    $foundIntakePrefixes_FOT = in_array('fot_', $found_prefixes) ? 1 : 0;
    $foundIntakePrefixes_NAW = in_array('naw_', $found_prefixes) ? 1 : 0;
    $foundIntakePrefixes_BIO = in_array('bio_', $found_prefixes) ? 1 : 0;
    $foundIntakePrefixes_REF = in_array('ref_', $found_prefixes) ? 1 : 0;
    $foundIntakePrefixes_VOG = in_array('vog_', $found_prefixes) ? 1 : 0;

    // Eén duidelijke log-regel in plaats van zes losse blokken
    wachthond($extdebug, 4, "INTAKE CATEGORIEËN IN PUSH: " . (implode(', ', $found_prefixes) ?: 'geen'));

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.X VERWERK DATA IN PROFILE INTAKE","[groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.1 BEPAAL CONTACT_ID ADHV CONT OF PART","[groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    $contact_id = NULL;
    $part_id    = NULL; // Dit wordt de 'huidige' ID van de trigger

    // A. We komen binnen via een Contact Profiel
    if (in_array($groupID, $profilecontintake)) {
        $contact_id = $entityID;
        $context    = 'hook_cont';
        // part_id weten we nog niet, die zoeken we zo via 'find_allpart'
    } 
    // B. We komen binnen via een Participatie Profiel
    elseif (in_array($groupID, $profilepartintake)) {
        $part_id    = $entityID;
        $context    = 'hook_part';
        // We moeten even snel het contact_id weten om de rest te kunnen vinden. 
        // Dit is een hele lichte API call (alleen 1 veld), veel sneller dan full pid2part.
        $check_cid = civicrm_api4('Participant', 'get', [
            'select' => ['contact_id', 'contact_id.display_name'],
            'where'  => [['id', '=', $part_id]],
            'checkPermissions' => FALSE
        ])->first();
        
        $contact_id  = $check_cid['contact_id']              ?? NULL;
        $displayname = $check_cid['contact_id.display_name'] ?? 'Onbekend';
    }

    // Veiligheidscheck: Hebben we een contact? Zo niet, stop.
    if (!$contact_id) {
        wachthond($extdebug, 1, "ERROR: Geen Contact ID gevonden voor group $groupID / entity $entityID");
        return;
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.2 HAAL CONTACT GEGEVENS OP",          "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    // Nu we zeker weten wie het is, halen we de NAW/Foto/Bio info op
    $array_contditjaar  = base_cid2cont($contact_id);
    $displayname        = $array_contditjaar['displayname']             ?? NULL;
    wachthond($extdebug,4, "array_contditjaar", $array_contditjaar);
    
    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.3 BEPAAL PRIMAIRE REGISTRATIE",            "[PART_ID]");
    wachthond($extdebug,2, "########################################################################");

    // We kijken wat deze persoon dit jaar allemaal doet
    $allpart_array = base_find_allpart($contact_id, $today_datetime);
    wachthond($extdebug,4, 'allpart_array',         $allpart_array);
    
    $pos_part_id_deel = $allpart_array['result_allpart_pos_deel_part_id'] ?? 0;
    $pos_part_id_leid = $allpart_array['result_allpart_pos_leid_part_id'] ?? 0;
    $one_part_id_deel = $allpart_array['result_allpart_one_deel_part_id'] ?? 0;
    $one_part_id_leid = $allpart_array['result_allpart_one_leid_part_id'] ?? 0;

    wachthond($extdebug,3, 'pos_part_id_deel',              $pos_part_id_deel);
    wachthond($extdebug,3, 'pos_part_id_leid',              $pos_part_id_leid); 
    wachthond($extdebug,3, 'one_part_id_deel',              $one_part_id_deel);
    wachthond($extdebug,3, 'one_part_id_leid',              $one_part_id_leid); 
        
    // 2. Bepaal de 'Target ID' op basis van rangorde
    //    De volgorde van de IF-statements bepaalt de voorrang.
    //    Zodra hij een 'match' heeft, stopt hij met zoeken.
    // DOEL: Vind de primaire registratie die we gaan gebruiken voor deze intake module

    $target_part_id = 0;

    if ($pos_part_id_leid > 0) {
        $target_part_id = $pos_part_id_leid;
        wachthond($extdebug, 3, "ID SELECTIE: PRIO 1 - Bevestigde Leiding gekozen (dominant over deelnemer)",   "ID: $target_part_id");
    } 
    elseif ($one_part_id_leid > 0) {
        $target_part_id = $one_part_id_leid;
        wachthond($extdebug, 3, "ID SELECTIE: PRIO 2 - Enige Leiding registratie gekozen (intentie is leiding)", "ID: $target_part_id");
    } 
    elseif ($pos_part_id_deel > 0) {
        $target_part_id = $pos_part_id_deel;
        wachthond($extdebug, 3, "ID SELECTIE: PRIO 3 - Bevestigde Deelnemer gekozen (geen leiding gevonden)",   "ID: $target_part_id");
    } 
    elseif ($one_part_id_deel > 0) {
        $target_part_id = $one_part_id_deel;
        wachthond($extdebug, 3, "ID SELECTIE: PRIO 4 - Enige Deelnemer registratie gekozen (bv. wachtlijst)",   "ID: $target_part_id");
    } 
    elseif ($part_id > 0) {
        // PRIO 5: Fallback naar de trigger ID (als find_allpart niets vindt voor dit jaar)
        $target_part_id = $part_id;
        wachthond($extdebug, 3, "ID SELECTIE: PRIO 5 - Fallback naar Trigger ID (geen andere inschrijvingen)",  "ID: $target_part_id");
    }
    else {
        // Veiligheidje voor als er echt helemaal niets is
        wachthond($extdebug, 1, "ID SELECTIE: ERROR - Geen geschikte Target Part ID kunnen bepalen");
    }

    $ditjaar_pos_leid_kampfunctie = (string)($allpart_array['result_allpart_pos_leid_kampfunctie']  ?? '');
    $ditjaar_pos_leid_kampkort    = (string)($allpart_array['result_allpart_pos_leid_kampkort']     ?? '');

    wachthond($extdebug, 3, "CHECK: Deelnemer ID: $pos_part_id_deel | Leiding ID: $pos_part_id_leid");
    wachthond($extdebug, 3, "DATA: Kamp: $ditjaar_pos_leid_kampkort | Functie: $ditjaar_pos_leid_kampfunctie");

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.3 HAAL PART DATA OP VAN PRIMAIRE REGISTRATIE","[PART]");
    wachthond($extdebug,2, "########################################################################");

    $part_array = []; // Standaard leeg

    if ($target_part_id > 0) {
        // Pas NU roepen we de zware functie aan, met de JUISTE id
        $part_array = base_pid2part($target_part_id);
        wachthond($extdebug,4, "part_array", $part_array);

        wachthond($extdebug, 4, "PART DATA OPGEHAALD voor ID $target_part_id", $part_array);
    } else {
        wachthond($extdebug, 3, "GEEN PARTICIPATIE DATA OP TE HALEN");
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.1 TOEWIJZEN CONTACT VALUES",  "[$displayname - groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    $extdebug = $extdebug_general;  //  1 = basic // 2 = verbose // 3 = params / 4 = resultsl

    if ($array_contditjaar) {

        $displayname            = $array_contditjaar['displayname']             ?? NULL;
        $voornaam               = $array_contditjaar['first_name']              ?? NULL;
        $geslacht               = $array_contditjaar['gender']                  ?? NULL;
        $leeftijd_decimaal      = $array_contditjaar['leeftijd_nextkamp_deci']  ?? NULL;
        $curcv_keer_leid        = $array_contditjaar['curcv_keer_leid']         ?? NULL;

        $contact_foto           = $array_contditjaar['contact_foto']            ?? NULL;
        $cont_fotupdate         = $array_contditjaar['cont_fotupdate']          ?? NULL;
        $cont_fotstatus         = $array_contditjaar['cont_fotstatus']          ?? NULL;

        $cont_belangstelling    = $array_contditjaar['datum_belangstelling']    ?? NULL;
        $cont_nawgecheckt       = $array_contditjaar['cont_nawgecheckt']        ?? NULL;
        $cont_bioingevuld       = $array_contditjaar['cont_bioingevuld']        ?? NULL;
        $cont_biogecheckt       = $array_contditjaar['cont_biogecheckt']        ?? NULL;

        $cont_nawnodig          = $array_contditjaar['cont_nawnodig']           ?? NULL;
        $cont_bionodig          = $array_contditjaar['cont_bionodig']           ?? NULL;
        $cont_nawstatus         = $array_contditjaar['cont_nawstatus']          ?? NULL;
        $cont_biostatus         = $array_contditjaar['cont_biostatus']          ?? NULL;

        $cont_reflaatste        = $array_contditjaar['cont_refdatum']           ?? NULL;
        $cont_voglaatste        = $array_contditjaar['cont_voglaatste']         ?? NULL;
        $cont_refnodig          = $array_contditjaar['cont_refnodig']           ?? NULL;
        $cont_vognodig          = $array_contditjaar['cont_vognodig']           ?? NULL;
        $cont_refstatus         = $array_contditjaar['cont_refstatus']          ?? NULL;
        $cont_vogstatus         = $array_contditjaar['cont_vogstatus']          ?? NULL;

        wachthond($extdebug,2, 'displayname',           $displayname);
        wachthond($extdebug,3, 'voornaam',              $voornaam);
        wachthond($extdebug,3, 'leeftijd_decimaal',     $leeftijd_decimaal);
        wachthond($extdebug,3, 'curcv_keer_leid',       $curcv_keer_leid);

        wachthond($extdebug,4, 'contact_foto',          $contact_foto);
        wachthond($extdebug,3, 'cont_fotupdate',        $cont_fotupdate);
        wachthond($extdebug,3, 'cont_fotstatus',        $cont_fotstatus);

        wachthond($extdebug,3, 'cont_belangstelling',   $cont_belangstelling);
        wachthond($extdebug,3, 'cont_nawgecheckt',      $cont_nawgecheckt);
        wachthond($extdebug,3, 'cont_bioingevuld',      $cont_bioingevuld);
        wachthond($extdebug,3, 'cont_biogecheckt',      $cont_biogecheckt);

        wachthond($extdebug,3, 'cont_nawnodig',         $cont_nawnodig);
        wachthond($extdebug,3, 'cont_bionodig',         $cont_bionodig);
        wachthond($extdebug,3, 'cont_nawstatus',        $cont_nawstatus);
        wachthond($extdebug,3, 'cont_biostatus',        $cont_biostatus);

        if ($curcv_keer_leid > 0) {
            wachthond($extdebug,3, 'cont_reflaatste',       $cont_reflaatste);
            wachthond($extdebug,3, 'cont_voglaatste',       $cont_voglaatste);
            wachthond($extdebug,3, 'cont_refnodig',         $cont_refnodig);
            wachthond($extdebug,3, 'cont_vognodig',         $cont_vognodig);
            wachthond($extdebug,3, 'cont_refstatus',        $cont_refstatus);
            wachthond($extdebug,3, 'cont_vogstatus',        $cont_vogstatus);
        }

        $new_cont_belangstelling    = $cont_belangstelling;
        $new_cont_nawgecheckt       = $cont_nawgecheckt;
        $new_cont_bioingevuld       = $cont_bioingevuld;
        $new_cont_biogecheckt       = $cont_biogecheckt;
        $new_cont_reflaatste        = $cont_reflaatste;
        $new_cont_voglaatste        = $cont_voglaatste;

        $new_cont_nawnodig          = 'elkjaar';

        if ($curcv_keer_leid > 0) {
            $new_cont_bionodig      = 'elkjaar';
        } else {
            $new_cont_bionodig      = '';
        }

        $new_cont_refnodig          = $cont_refnodig;
        $new_cont_vognodig          = $cont_vognodig;
        $new_cont_nawstatus         = $cont_nawstatus;
        $new_cont_biostatus         = $cont_biostatus;
        $new_cont_refstatus         = $cont_refstatus;
        $new_cont_vogstatus         = $cont_vogstatus;
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.2 TOEWIJZEN PART VALUES",  "[$displayname - groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    if ($part_array) {

        $event_fiscalyear_start     = $part_array['event_fiscalyear_start']?? NULL;
        $event_fiscalyear_einde     = $part_array['event_fiscalyear_einde']?? NULL;
        $part_regdate               = $part_array['register_date']         ?? NULL;
        $part_kampstart             = $part_array['part_kampstart']        ?? NULL;
        $part_kampeinde             = $part_array['part_kampeinde']        ?? NULL;
        $part_kampkort              = $part_array['part_kampkort']         ?? NULL;
        $part_functie               = $part_array['part_functie']          ?? NULL;
        $part_rol                   = $part_array['part_rol']              ?? NULL;

        if ($part_rol == 'leiding') {

//          $part_refnodig          = $part_array['part_refnodig']         ?? NULL;
            $part_refstatus         = $part_array['part_refstatus']        ?? NULL;
//          $part_refpersoon        = $part_array['part_refpersoon']       ?? NULL;
//          $part_refgevraagd       = $part_array['part_refgevraagd']      ?? NULL;
//          $part_reffeedback       = $part_array['part_reffeedback']      ?? NULL;

//          $part_vognodig          = $part_array['part_vognodig']         ?? NULL;
            $part_vogstatus         = $part_array['part_vogstatus']        ?? NULL;
//          $part_vogverzoek        = $part_array['part_vogverzoek']       ?? NULL;
//          $part_vogaanvraag       = $part_array['part_vogaanvraag']      ?? NULL;
            $part_vogdatum          = $part_array['part_vogdatum']         ?? NULL;

            $new_part_vogverzoek    = $part_vogverzoek;         
            $new_part_vogaanvraag   = $part_vogaanvraag;         
            $new_part_vogdatum      = $part_vogdatum;
        }

        wachthond($extdebug,4, 'event_fiscalyear',  $event_fiscalyear);
        wachthond($extdebug,2, 'part_regdate',      $part_regdate);

        wachthond($extdebug,2, 'part_kampstart',    $part_kampstart);
        wachthond($extdebug,3, 'part_kampeinde',    $part_kampeinde);
        wachthond($extdebug,3, 'part_kampkort',     $part_kampkort);
        wachthond($extdebug,3, 'part_functie',      $part_functie);
        wachthond($extdebug,3, 'part_rol',          $part_rol);

        if ($part_rol == 'leiding') {

            wachthond($extdebug,3, 'cont_refdatum',     $cont_refdatum);
            wachthond($extdebug,3, 'part_refnodig',     $part_refnodig);
            wachthond($extdebug,3, 'part_refstatus',    $part_refstatus);
            wachthond($extdebug,4, 'part_refpersoon',   $part_refpersoon);
            wachthond($extdebug,4, 'part_refgevraagd',  $part_refgevraagd);
            wachthond($extdebug,4, 'part_reffeedback',  $part_reffeedback);

            wachthond($extdebug,3, 'cont_vogdatum',     $cont_vogdatum);
            wachthond($extdebug,4, 'part_vognodig',     $part_vognodig);
            wachthond($extdebug,3, 'part_vogstatus',    $part_vogstatus);
            wachthond($extdebug,4, 'part_vogverzoek',   $part_vogverzoek);
            wachthond($extdebug,4, 'part_vogaanvraag',  $part_vogaanvraag);
            wachthond($extdebug,3, 'part_vogdatum',     $part_vogdatum);
        }
    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE [PRE] 0.5 RETREIVE TODAY FISCAL YEAR START VALUE FROM CACHE");
    wachthond($extdebug,3, "########################################################################");

    // 1. Haal alle config-data in één keer op (komt uit RAM of Cache)
    $intake_config = find_fiscalyear();

    // 2. Wijs de variabelen toe die je lokaal nodig hebt
    $today_fiscalyear_start = $intake_config['today_start'] ?? NULL;
    $today_fiscalyear_einde = $intake_config['today_einde'] ?? NULL;
    $today_kampjaar         = $intake_config['today_jaar']  ?? NULL;

    wachthond($extdebug,3, 'today_fiscalyear_start',    $today_fiscalyear_start);
    wachthond($extdebug,3, 'today_fiscalyear_einde',    $today_fiscalyear_einde);
    wachthond($extdebug,4, 'today_kampjaar',            $today_kampjaar);
    wachthond($extdebug,3, 'intake_config',             $intake_config);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE [PRE] 0.6 BEPAAL GRENSNOGGOED & FISCALYEAR");
    wachthond($extdebug,3, "########################################################################");

    $grensnoggoed1       = $intake_config['noggoed1'] ?? NULL;
    $grensnoggoed3       = $intake_config['noggoed3'] ?? NULL;

    wachthond($extdebug,3, 'grensnoggoed1',          $grensnoggoed1);
    wachthond($extdebug,3, 'grensnoggoed3',          $grensnoggoed3);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE [PRE] 0.6 BEPAAL GRENSDATUMS REF & VOG VOOR HL & STAF");
    wachthond($extdebug,3, "########################################################################");

    if ($part_rol == 'leiding') {
        $is_hoofdleiding  = in_array($part_functie, ['hoofdleiding', 'bestuurslid']);
        $grensnoggoedjaar = $is_hoofdleiding ? 1 : 3;

        if ($grensnoggoedjaar === 1) {          // Hoofdleiding: Strengere eis (1 jaar)
            $grensvognoggoed = $grensnoggoed1;
            $grensrefnoggoed = $grensnoggoed3;  // elk jaar nieuwe VOG & elke 3 jaar nieuwe referentie
        } else {                                // Overige leiding: Standaard eis (3 jaar)
            $grensvognoggoed = $grensnoggoed3;
            $grensrefnoggoed = $grensnoggoed3;  // trek voor groepsleiding vog & ref samen
        }

        wachthond($extdebug, 3, "LIMIT CHECK ROL",   "Functie: $part_functie ($grensnoggoedjaar jr)");
        wachthond($extdebug, 3, "LIMIT CHECK GRENS", "VOG Grens: $grensvognoggoed | REF Grens: $grensrefnoggoed");
    }

    #########################################################################
    ### INTAKE CONT [PRE]                                             [START]
    #########################################################################

    if (in_array($groupID, $profilecontintake)) {

       $extdebug = $extdebug_intake_cont;  //  1 = basic // 2 = verbose // 3 = params / 4 = results

        ##########################################################################################
        ### RETURN IF ONLY FOTOSTATUS IS UPDATED
        ##########################################################################################        

        $arraysize = sizeof($params);
        wachthond($extdebug,4, 'arraysize',             $arraysize);

        if ($arraysize == 1 AND $params[0]['column_name'] == 'fot_status_1798') {
            wachthond($extdebug,3, "########################################################################");
            wachthond($extdebug,3, "### INTAKE [PRE] EXIT: arraysize: $arraysize", "[$params[0]['column_name']]");
            wachthond($extdebug,3, "########################################################################");            
            // --- VLAG WEER UITZETTEN ---
            $processing_intake_custompre = false;
            return;
        }

        if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?
            wachthond($extdebug,3, "########################################################################");
            wachthond($extdebug,3, "### INTAKE [PRE] EXIT: op != create OR op != edit", "(op: $op)");
            wachthond($extdebug,3, "########################################################################");
            // --- VLAG WEER UITZETTEN ---
            $processing_intake_custompre = false;
            return; //  if not, get out of here
        }

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 1.1 START RETRIEVE VALUES FROM PARAMS", "[$displayname - groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

        // 1. Definieer de mapping: 'CiviCRM_Veld' => 'Lokale_Variabele'
        $intake_field_map = [
            'int_nodig_1494'        => 'val_int_nodig',
            'int_status_1492'       => 'val_int_status',
            'fot_status_1798'       => 'val_fot_status',
            'fot_update_2253'       => 'val_fot_update',
            'naw_nodig_1664'        => 'val_naw_nodig',
            'naw_gecheckt_1505'     => 'val_naw_gecheckt',
            'naw_status_1508'       => 'val_naw_status',
            'bio_nodig_1495'        => 'val_bio_nodig',
            'bio_ingevuld_1496'     => 'val_bio_ingevuld',
            'bio_gecheckt_1497'     => 'val_bio_gecheckt',
            'bio_status_1498'       => 'val_bio_status',

            'ref_nodig_1019'        => 'val_ref_nodig',
            'ref_status_1490'       => 'val_ref_status',
            'ref_persoon_1782'      => 'val_ref_persoon',
            'ref_laatste_1004'      => 'val_ref_laatste',
            'ref_naam_1003'         => 'val_ref_naam',
            'ref_cid_1727'          => 'val_ref_cid',

            'vog_nodig_998'         => 'val_vog_nodig',
            'vog_laatste_56'        => 'val_vog_laatste',
            'vog_status_1489'       => 'val_vog_status',

            'intake_modified_2249'  => 'val_int_modified',
        ];

        // 2. Initialiseer hulp-arrays
        $keys    = [];
        $indexed = []; 

        // Initialiseer alle mogelijke variabelen op NULL
        foreach ($intake_field_map as $friendly_name) {
            ${$friendly_name} = NULL;
        }

        // 3. De Loop: Verwerk ALLEEN de velden die de gebruiker nu opslaat
        foreach ($params as $index => $item) {
            $col = $item['column_name'] ?? '';

            // Altijd opslaan in indexed voor algemene doeleinden
            $indexed[$index] = [
                'key'           => $index,
                'column_name'   => $col,
                'value'         => $item['value'] ?? NULL
            ];

            // Controleer of dit veld in onze "Intake Mapping" zit
            if (isset($intake_field_map[$col])) {
                $varName = $intake_field_map[$col];
                
                // Sla de index op voor latere injectie (Sectie 2.3/3.3)
                $keys[$varName] = $index; 

                // DE SMART HELPER: Maakt datums leesbaar en splitst checkbox-arrays
                $rawVal     = $item['value'] ?? NULL;
                $$varName   = format_civicrm_smart($rawVal, $col);

                // Uitgebreide Watchdog voor debugging van de transformatie
                $displayRaw     = is_array($rawVal)   ? 'ARRAY' : (string)$rawVal;
                $displayClean   = is_array($$varName) ? 'ARRAY' : (string)$$varName;
                
                wachthond($extdebug, 4, "Smart Processing [$col]", 
                    "Var: $$varName | Index: $index | Raw: $displayRaw | Clean: $displayClean"
                );
            }
        }

        // 4. Toon samenvatting van alle gevonden wijzigingen in deze call
        wachthond($extdebug,2, "Intake 1.1 Summary: Found " . count($keys) . " fields in params", array_keys($keys));

        wachthond($extdebug,4, 'params',    $params);
        wachthond($extdebug,4, 'keys',      $keys);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 2.0 CONFIGURE FOT STATUS",              "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $calc_fot_update = $val_fot_update ?? $array_contditjaar['cont_fotupdate'] ?? '';

        $foto_res = intake_check_fotostatus(
            $array_contditjaar['contact_foto'] ?? '', 
            $calc_fot_update, 
            $geslacht, 
            $today_fiscalyear_start
        );
        wachthond($extdebug, 2, 'foto_res', $foto_res); 

        // --- 2.1 FOTO INJECTIE (CUSTOM FIELDS) ---
        // Injecteer status en update-datum in de params
        $fot_data = [
            'val_fot_status'    => $foto_res['status']  ?? NULL,
            'val_fot_update'    => $calc_fot_update     ?? NULL,
            'val_int_modified'  => $today_datetime      ?? NULL,
        ];
        wachthond($extdebug, 2, 'fot_data', $fot_data); 
        intake_inject_params($params,$keys, $fot_data, "FOT");

        // --- 2.2 IMAGE URL INJECTIE (CORE FIELD) ---
        // Als de status 0 is (geen foto), injecteren we de placeholder direct in de params
        if ($foto_res['status'] == 0 && ($foto_res['current_url'] !== $foto_res['placeholder_url'])) {
            
            // We zoeken of image_URL al in de params zit, anders voegen we hem toe aan de waarden
            // In een pre-hook voor Contact.create/update kun je vaak simpelweg dit doen:
            $params['image_URL'] = $foto_res['placeholder_url'];
            
            wachthond($extdebug, 2, "FOT Inject Core", "image_URL gezet naar placeholder");
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE [PRE] 3.0 CONFIGURE NAW STATUS",                "[$displayname]");
        wachthond($extdebug, 2, "########################################################################");

        if (isset($keys['val_naw_gecheckt'])) {
            
            // 1. Bereken de nieuwe status
            $naw_res = intake_status_naw(
                $contact_id, 
                ($val_naw_gecheckt ?? NULL), 
                $curcv_keer_leid, 
                ($part_array['register_date'] ?? NULL), 
                $today_datetime
            );
            wachthond($extdebug,2, 'naw_res',  $naw_res);

            // 2. Data voorbereiden voor injectie
            $naw_data = [
                'val_naw_gecheckt'  => $naw_res['gecheckt'] ?? NULL,
                'val_naw_status'    => $naw_res['status']   ?? NULL,
                'val_naw_nodig'     => $naw_res['nodig']    ?? NULL,
                'val_int_modified'  => $today_datetime      ?? NULL,
            ];
            wachthond($extdebug,2, 'naw_data', $naw_data);
            // 3. Injecteren in de lopende transactie
            intake_inject_params($params,$keys,$naw_data, "NAW");

            // 4. Hulpvariabelen voor vervolgproces (bijv. sync naar deelname)
            $new_part_nawgecheckt = $naw_res['part_gecheckt'] ?? NULL;
            $new_cont_nawstatus   = $naw_res['status']        ?? NULL;

        } else {
            wachthond($extdebug, 4, 'NAW Inject Skip', 'Veld val_naw_gecheckt niet aanwezig in formulier.');
        }

        if (isset($new_part_nawgecheckt)) { wachthond($extdebug, 2, 'new_part_nawgecheckt', $new_part_nawgecheckt); }
        if (isset($new_cont_nawstatus))   { wachthond($extdebug, 2, 'new_cont_nawstatus',   $new_cont_nawstatus);   }        

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 4.0 CONFIGURE BIO STATUS",                "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        if (isset($keys['val_bio_gecheckt']) || isset($keys['val_bio_ingevuld'])) {
            
            // 1. Berekening
            $bio_res = intake_status_bio(
                $contact_id, 
                ($val_bio_ingevuld ?? NULL), 
                ($val_bio_gecheckt ?? NULL), 
                $today_datetime
            );
            wachthond($extdebug, 2, 'bio_res', $bio_res);

            // 2. Data voorbereiden
            $bio_data = [
                'val_bio_status'    => $bio_res['status']   ?? NULL,
                'val_bio_ingevuld'  => $bio_res['ingevuld'] ?? NULL,
                'val_bio_gecheckt'  => $bio_res['gecheckt'] ?? NULL,
                'val_bio_nodig'     => $bio_res['nodig']    ?? NULL,
                'val_int_modified'  => $today_datetime      ?? NULL,
            ];
            wachthond($extdebug,3, 'bio_data', $bio_data);
            // 3. Injecteren in de lopende transactie
            intake_inject_params($params, $keys, $bio_data, "BIO");

            // 4. Variabelen vullen voor eventuele sync later in het script
            $new_part_biogecheckt = $bio_res['part_gecheckt'] ?? NULL;
            $new_cont_biostatus   = $bio_res['status']        ?? NULL;

            wachthond($extdebug,4, 'params', $params);
        } else {
            wachthond($extdebug, 4, 'BIO Inject Skip', 'Veld val_naw_gecheckt niet aanwezig in formulier.');
        }

        if (isset($new_part_biogecheckt)) { wachthond($extdebug, 2, 'new_part_biogecheckt', $new_part_biogecheckt); }
        if (isset($new_cont_biostatus))   { wachthond($extdebug, 2, 'new_cont_biostatus',   $new_cont_biostatus); }
        
        // Voer alleen uit als er een actieve deelname is gevonden ($pos_part_id_leid > 0)
        if ($pos_part_id_leid > 0) {
            
            wachthond($extdebug,4, "########################################################################");
            wachthond($extdebug,3, "### INTAKE - CONFIGURE - REF",                   "[VOOR PARTID $part_id]");
            wachthond($extdebug,4, "########################################################################");

            $ref = intake_ref_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $grensrefnoggoed);

            wachthond($extdebug,3, 'result_ref', $ref);

            // De keys (links) moeten exact matchen met de rechterkant van jouw $intake_field_map
            $ref_data = [
                'val_ref_nodig'     => $ref['cont_refnodig']    ?? NULL,
                'val_ref_status'    => $ref['cont_refstatus']   ?? NULL,
                'val_ref_persoon'   => $ref['cont_refpersoon']  ?? NULL,
                'val_ref_laatste'   => $ref['cont_refdatum']    ?? NULL,
                'val_ref_naam'      => $ref['cont_refnaam']     ?? NULL,
                'val_ref_cid'       => $ref['cont_refcid']      ?? NULL,
                'val_int_modified'  => $today_datetime          ?? NULL,
            ];
            wachthond($extdebug,3, 'ref_data', $ref_data);
            intake_inject_params($params,$keys,$ref_data, "REF");

            // Filter de params zodat alleen velden met 'ref' in de kolomnaam overblijven
            $ref_params_only = array_filter($params, function($p) {
                return str_contains(strtolower($p['column_name'] ?? ''), 'ref');
            });
            wachthond($extdebug,3, "FILTERED params (REF ONLY)", $ref_params_only);
        }

        // Voer alleen uit als er een actieve deelname is gevonden ($pos_part_id_leid > 0)
        if ($pos_part_id_leid > 0) {
            
            wachthond($extdebug, 4, "########################################################################");
            wachthond($extdebug, 3, "### INTAKE - CONFIGURE - VOG",                   "[VOOR PARTID $part_id]");
            wachthond($extdebug, 4, "########################################################################");

            /**
             * Let op: we geven $params mee zodat de VOG-functie direct de formulierwaarden 
             * kan terugschrijven (zoals de nieuwe status of datums).
             */

            $vog = intake_vog_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $context, $array_contditjaar);
            wachthond($extdebug, 3, 'result_vog', $vog);

            $vog_data = [
                'val_vog_nodig'     => $vog['nodig']        ?? NULL,
                'val_vog_status'    => $vog['status']       ?? NULL,
                'val_vog_laatste'   => $vog['laatste']      ?? NULL,
                'val_int_modified'  => $today_datetime      ?? NULL,
            ];
            wachthond($extdebug,3, 'vog_data', $vog_data);
            intake_inject_params($params,$keys,$vog_data, "VOG");

            // Filter de params zodat alleen velden met 'vog' in de kolomnaam overblijven
            $vog_params_only = array_filter($params, function($p) {
                return str_contains(strtolower($p['column_name'] ?? ''), 'vog');
            });
            wachthond($extdebug,3, "FILTERED params (VOG ONLY)", $vog_params_only);
        }        

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 6.1 INT - BEPAAL WAARDE NODIG",                  "[INT]");
        wachthond($extdebug,2, "########################################################################");

        $extdebug           = $extdebug_intake_cont;

        $part_functie       = $part_array['part_functie'] ?? '';
        $new_cont_intnodig  = 'elkjaar'; // Default

        if ($curcv_keer_leid == 1) {
            $new_cont_intnodig = 'eerstex';
        } elseif ($curcv_keer_leid >= 2) { 
            $new_cont_intnodig = 'opnieuw';
        }
        if (in_array($part_functie, array('hoofdleiding', 'bestuurslid'))) {
            $new_cont_intnodig = 'elkjaar';
        }
        wachthond($extdebug,3, "new_cont_intnodig", $new_cont_intnodig);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 6.2 INT - BEPAAL STATUS",                        "[INT]");
        wachthond($extdebug,2, "########################################################################");

        // Bepaal INT STATUS (Gebruik berekende waarden of fallback naar DB waarden)
        $intakestatusnawdone = 0;
        $intakestatusbiodone = 0;
        $intakestatusrefdone = 0;
        $intakestatusvogdone = 0;

        if (in_array($new_cont_nawstatus, array("noggoed", "bijgewerkt")))           { $intakestatusnawdone  = 1; }
        if (in_array($new_cont_biostatus, array("noggoed", "bijgewerkt")))           { $intakestatusbiodone  = 1; }
        if (in_array($new_cont_refstatus, array("noggoed", "vragen", "ontvangen")))  { $intakestatusrefdone  = 1; }
        // M61: wat te doen met status: bekend
        if (in_array($new_cont_vogstatus, array("noggoed", "ontvangen")))            { $intakestatusvogdone  = 1; }
        // M61: wat te doen met status: ingediend

        if ($intakestatusnawdone == 1 AND $intakestatusbiodone == 1 AND $intakestatusrefdone == 1 AND $intakestatusvogdone == 1) { 
            $new_cont_intstatus = 'compleet';
        } else {
            $new_cont_intstatus = 'gedeeltelijk';            
        }
        wachthond($extdebug,4, "new_cont_intstatus", $new_cont_intstatus);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 6.3 INT - INJECTEER WAARDE IN PARAMS",           "[INT]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,2, 'new_cont_intnodig',         $new_cont_intnodig);
        wachthond($extdebug,2, 'new_cont_intstatus',        $new_cont_intstatus);

        $int_data = [
            'val_int_nodig'     => $new_cont_intnodig   ?? NULL,
            'val_int_status'    => $new_cont_intstatus  ?? NULL,
            'val_int_modified'  => $today_datetime      ?? NULL,
        ];
        wachthond($extdebug,2, 'int_data', $int_data);
        intake_inject_params($params,$keys,$int_data, "INT");

        wachthond($extdebug, 4, "FINAL params voor update", $params); 
    }

    #########################################################################
    ### INTAKE PART REF [PRE]                                         [START]
    #########################################################################

    if (in_array($groupID, $profilepartref)) {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE PART REF [PRE] START MODULE AANROEP", "[$displayname | GroupID: $groupID]");
        wachthond($extdebug, 2, "########################################################################");

        /**
         * AANROEP NIEUWE REF FUNCTIE
         * --------------------------
         * Deze functie regelt alles:
         * 1. Extractie van datums uit het formulier.
         * 2. Bepalen of REF nodig is.
         * 3. Syncen van activiteiten (139 en 117).
         * 4. Updaten van Contact en Participant in de DB.
         * 5. Terugschrijven van schone waarden in $params (zodat de UI update).
         */
        
        $ref_resultaat = intake_ref_configure(
            $contact_id, 
            $part_id, 
            $params,            // <--- ESSENTIEEL: Wordt 'by reference' doorgegeven en direct bijgewerkt
            $allpart_array,
            $part_array,           
            $groupID
        );

        wachthond($extdebug, 3, "grensrefnoggoed",  $grensrefnoggoed);
        $ref_resultaat = intake_ref_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $grensrefnoggoed);
        wachthond($extdebug, 3, "### INTAKE PART REF [PRE] RESULTAAT", $ref_resultaat);

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE PART REF [PRE] EINDE MODULE AANROEP");
        wachthond($extdebug, 2, "########################################################################");
    }

    #########################################################################
    ### INTAKE PART REF [PRE]                                         [EINDE]
    #########################################################################

    #########################################################################
    ### INTAKE PART VOG [PRE]                                         [START]
    #########################################################################

    if (in_array($groupID, $profilepartvog)) {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE PART VOG [PRE] START MODULE AANROEP", "[$displayname | GroupID: $groupID]");
        wachthond($extdebug, 2, "########################################################################");

        /**
         * AANROEP NIEUWE FUNCTIE
         * ----------------------
         * Deze functie regelt nu autonoom:
         * 1. Het uitlezen van de datums uit $params ($vog_date_mapping).
         * 2. Het ophalen van historische VOG data (1.5).
         * 3. Het bepalen van de status (Nodig/Klaarzetten/Completed).
         * 4. Het syncen van Activities (118, 119, 120).
         * 5. Het updaten van Contact & Participant records.
         * 6. Het terugschrijven van schone waarden in $params (voor de UI).
         */

        $vog_resultaat = intake_vog_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $context);
        wachthond($extdebug, 3, "### INTAKE PART VOG [PRE] RESULTAAT", $vog_resultaat);

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE PART VOG [PRE] EINDE MODULE AANROEP");
        wachthond($extdebug, 2, "########################################################################");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### INTAKE [PRE] GEEF DE DEFINITIEVE WAARDEN WEER",            "[PARAMS]");
    wachthond($extdebug,2, "########################################################################");

    // --- SWEEP VOOR DRUPAL ENTITY CONTROLLER (UNIX TIMESTAMPS) ---
    drupal_timestamp_sweep($params);

    wachthond($extdebug,1, "params",      $params);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.X VERWERK DATA IN PROFILE INTAKE",           "[EINDE]");
    wachthond($extdebug,1, "########################################################################");

    $intake_duur = number_format(microtime(TRUE) - $intake_start_tijd, 3);
    wachthond($extdebug, 1, "### TOTALE VERWERKINGSTIJD INTAKE MODULE: " . $intake_duur . " sec");
    wachthond($extdebug,1, "########################################################################");

    // --- VLAG WEER UITZETTEN ---
    $processing_intake_custompre = false; // Lokale lock vrijgeven

    // 3. SLOT WEER OPENEN (ALTIJD!)
    intake_recursion_lock(false);         // Globale lock vrijgeven

}

/**
 * Configureert de Intake status voor een contact (Foto, NAW, BIO).
 * Accepteert NULL voor part_id (?int).
 */
function intake_civicrm_configure(int $contact_id, ?int $part_id = 0): array {

    $extdebug           = 3;
    $today              = date("Y-m-d H:i:s");
    $context            = 'direct';

    if (!Civi::cache()->get('cache_today_fiscalyear_start')) { find_fiscalyear(); }

    $contact_values     = [];

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - CONFIGURE",                                        "[START]");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - GET CID2CONT",                               "[$contact_id]");
    wachthond($extdebug,3, "########################################################################");

    $allcont_array      = base_cid2cont($contact_id);
    $displayname        = $allcont_array['displayname']                             ?? NULL;
    $fiscalyear         = Civi::cache()->get('cache_today_fiscalyear_start');

    wachthond($extdebug,4, 'allcont_array',     $allcont_array);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 0.1 GET ALLPART",                               "[$displayname]");
    wachthond($extdebug,3, "########################################################################");

    $allpart_array      = base_find_allpart($contact_id, $today);
    $part_id            = (int)($allpart_array['result_allpart_pos_part_id']        ?? 0);
    $pos_part_id_deel   = (int)($allpart_array['result_allpart_pos_deel_part_id']   ?? 0);
    $pos_part_id_leid   = (int)($allpart_array['result_allpart_pos_leid_part_id']   ?? 0);
    wachthond($extdebug,4, 'allpart_array',     $allpart_array);

    wachthond($extdebug,3, 'pos_part_id',       $part_id);
    wachthond($extdebug,3, 'pos_part_id_deel',  $pos_part_id_deel);
    wachthond($extdebug,3, 'pos_part_id_leid',  $pos_part_id_leid);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 0.2 GET PID2PART",                                  "[$part_id]");
    wachthond($extdebug,3, "########################################################################");

    if ($part_id) {
        $part_array    = $part_id > 0 ? base_pid2part($part_id) : [];
        wachthond($extdebug,4, 'result_pid2part', $part_array);
    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 0.3 RETREIVE TODAY FISCAL YEAR START VALUE FROM CACHE");
    wachthond($extdebug,3, "########################################################################");

    // 1. Haal alle config-data in één keer op (komt uit RAM of Cache)
    $intake_config = find_fiscalyear();

    // 2. Wijs de variabelen toe die je lokaal nodig hebt
    $today_fiscalyear_start = $intake_config['today_start'] ?? NULL;
    $today_fiscalyear_einde = $intake_config['today_einde'] ?? NULL;
    $today_kampjaar         = $intake_config['today_jaar']  ?? NULL;

    wachthond($extdebug,3, 'today_fiscalyear_start',    $today_fiscalyear_start);
    wachthond($extdebug,3, 'today_fiscalyear_einde',    $today_fiscalyear_einde);
    wachthond($extdebug,4, 'today_kampjaar',            $today_kampjaar);
    wachthond($extdebug,3, 'intake_config',             $intake_config);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 0.4 BEPAAL GRENSNOGGOED & FISCALYEAR");
    wachthond($extdebug,3, "########################################################################");

    $grensnoggoed1          = $intake_config['noggoed1'] ?? NULL; // De 1-jaar grens
    $grensnoggoed3          = $intake_config['noggoed3'] ?? NULL; // De 3-jaar grens

    wachthond($extdebug, 3, 'grensnoggoed1', $grensnoggoed1);
    wachthond($extdebug, 3, 'grensnoggoed3', $grensnoggoed3);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 0.5 BEPAAL GRENSDATUMS REF & VOG VOOR HL & STAF");
    wachthond($extdebug,3, "########################################################################");

    // --- SCENARIO CHECK: WELKE ROL? ---
    if ($part_rol == 'leiding') {
        
        $is_hoofdleiding  = in_array($part_functie, ['hoofdleiding', 'bestuurslid']);
        $grensnoggoedjaar = $is_hoofdleiding ? 1 : 3;

        // Scenario 1: Hoofdleiding (Streng: VOG=1jr, REF=3jr)
        if ($grensnoggoedjaar === 1) {              
            wachthond($extdebug, 2, "LIMIT CHECK SCENARIO", "Hoofdleiding/Bestuur (1jr VOG / 3jr REF)");
            
            $grensvognoggoed = $grensnoggoed1; // <--- Gebruik algemene 1-jaar grens
            $grensrefnoggoed = $grensnoggoed3; // <--- Gebruik algemene 3-jaar grens
        } 
        // Scenario 2: Overige Leiding (Standaard: VOG=3jr, REF=3jr)
        else {                                      
            wachthond($extdebug, 2, "LIMIT CHECK SCENARIO", "Overige Leiding (3jr VOG / 3jr REF)");
            
            $grensvognoggoed = $grensnoggoed3; // <--- Gebruik algemene 3-jaar grens
            $grensrefnoggoed = $grensnoggoed3; // <--- Gebruik algemene 3-jaar grens
        }

        // Resultaat loggen
        wachthond($extdebug, 3, "LIMIT CHECK ROL",   "Functie: $part_functie ($grensnoggoedjaar jr)");
        wachthond($extdebug, 3, "LIMIT CHECK GRENS", "VOG Grens: $grensvognoggoed | REF Grens: $grensrefnoggoed");

    } else {
        // Scenario 3: Geen leiding
        wachthond($extdebug, 3, "LIMIT CHECK SKIP",  "Rol is '$part_rol' (geen leiding), check overgeslagen.");
    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 1.0 CONFIGURE - FOT",                                "[FOT]");
    wachthond($extdebug,3, "########################################################################");

    $foto_url           = $allcont_array['contact_foto']                            ?? '';
    $foto_datum         = $allcont_array['cont_fotupdate']                          ?? '';
    $gender             = $allcont_array['gender']                                  ?? '';

    $fot                = intake_check_fotostatus($foto_url, $foto_datum, $gender, $fiscalyear);
    $contact_values['INTAKE.FOT_status']   = $fot['status']   ?? '';

    if ($fot['status'] == 0 && ($fot['current_url'] !== $fot['placeholder_url'])) {
        $contact_values['image_URL'] = $fot['placeholder_url'];
    }

    wachthond($extdebug,3, 'result_fot', $fot);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 2.0 CONFIGURE - NAW",                                "[NAW]");
    wachthond($extdebug,3, "########################################################################");

    $naw_gecheckt   = $allcont_array['cont_nawgecheckt'] ?? NULL;
    $keer_leid      = $allcont_array['curcv_keer_leid']  ?? 0;
    $register_date  = $part_array['register_date']      ?? NULL;

    $naw            = intake_status_naw($contact_id, $naw_gecheckt, $keer_leid, $register_date, $today);
    wachthond($extdebug,3, 'result_naw', $naw);

    $contact_values['INTAKE.NAW_nodig']     = $naw['nodig']     ?? '';
    $contact_values['INTAKE.NAW_gecheckt']  = $naw['gecheckt']  ?? '';
    $contact_values['INTAKE.NAW_status']    = $naw['status']    ?? '';

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 3.0 CONFIGURE - BIO",                                "[BIO]");
    wachthond($extdebug,3, "########################################################################");

    $bio_ingevuld = $allcont_array['cont_bioingevuld'] ?? NULL;
    $bio_gecheckt = $allcont_array['cont_biogecheckt'] ?? NULL;

    $bio = intake_status_bio($contact_id, $bio_ingevuld, $bio_gecheckt, $today);
    wachthond($extdebug,3, 'result_bio', $bio);

    $contact_values['INTAKE.BIO_nodig']     = $bio['nodig']     ?? '';
    $contact_values['INTAKE.BIO_gecheckt']  = $bio['gecheckt']  ?? '';
    $contact_values['INTAKE.BIO_status']    = $bio['status']    ?? '';

    // Voer alleen uit als er een actieve deelname is gevonden ($pos_part_id_leid > 0)
    if ($pos_part_id_leid > 0) {
        
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,2, "### INTAKE - 4.0 CONFIGURE - REF",               "[VOOR PARTID $part_id]");
        wachthond($extdebug,3, "########################################################################");

        $ref = intake_ref_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $grensrefnoggoed);
        wachthond($extdebug,3, 'result_ref', $ref);

        $contact_values['INTAKE.REF_nodig']     = $ref['nodig']     ?? '';
        $contact_values['INTAKE.REF_status']    = $ref['status']    ?? '';
    }

    // Voer alleen uit als er een actieve deelname is gevonden ($pos_part_id_leid > 0)
    if ($pos_part_id_leid > 0) {
        
        wachthond($extdebug, 3, "########################################################################");
        wachthond($extdebug, 2, "### INTAKE - 5.0 CONFIGURE - VOG",               "[VOOR PARTID $part_id]");
        wachthond($extdebug, 3, "########################################################################");

        /**
         * Let op: we geven $params mee zodat de VOG-functie direct de formulierwaarden 
         * kan terugschrijven (zoals de nieuwe status of datums).
         */
        $vog = intake_vog_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $part_array, $context);
        wachthond($extdebug, 3, 'result_vog', $vog);

        // De centrale $contact_values array bijwerken met de resultaten uit de VOG module
        $contact_values['INTAKE.VOG_nodig']     = $vog['nodig']     ?? '';
        $contact_values['INTAKE.VOG_status']    = $vog['status']    ?? '';
        $contact_values['INTAKE.VOG_laatste']   = $vog['laatste']   ?? '';
    }

    wachthond($extdebug, 4, "########################################################################");
    wachthond($extdebug, 3, "### INTAKE - 6.0 CONFIGURE - INT",                                "[INT]");
    wachthond($extdebug, 4, "########################################################################");

    // We controleren of ALLES (&&) in orde is voor de status 'compleet'
    if (in_array(($fot['status'] ?? ''), ['geupload', 'vierkant']) 
        && ($naw['status'] ?? '') === 'bijgewerkt' 
        && ($bio['status'] ?? '') === 'bijgewerkt'
        && ($ref['status'] ?? '') === 'ontvangen' 
        && ($vog['status'] ?? '') === 'ontvangen' 
    ) {
        $new_int_status = 'compleet';
    } else {
        $new_int_status = 'gedeeltelijk';
    }

    $contact_values['INTAKE.INT_nodig']  = $vog['nodig'];
    $contact_values['INTAKE.INT_status'] = $new_int_status;

    wachthond($extdebug, 3, "contact_values", $contact_values);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 7.0 CONFIGURE - UPDATE CONTACT",             "[UPDATE CONT]");
    wachthond($extdebug,3, "########################################################################");

    // --- DIRTY CHECK CONTACT ---
    $params_get_c = [
        'checkPermissions' => FALSE,
        'select'    => array_keys($contact_values),
        'where'     => [['id', '=', $contact_id]],
    ];

    wachthond($extdebug, 3, "params_get_c",         $params_get_c);
    $current_data_c = civicrm_api4('Contact','get', $params_get_c)->first();
    wachthond($extdebug, 3, "current_data_c",       $current_data_c);

    $clean_values_c = [];
    $has_changes_c  = false;

    wachthond($extdebug, 4, "START DIRTY CHECK LOOP", "Velden: " . count($contact_values));

    foreach ($contact_values as $key => $new_val) {
        $old_val = $current_data_c[$key] ?? '';
        
        // DEBUG: Uncomment deze regel als je ÉLK veld wilt zien dat vergeleken wordt
        wachthond($extdebug, 3, "CHECK [$key]", "Old: '$old_val' vs New: '$new_val'");

        // 1. Beide leeg? -> Skip
        if (empty($new_val) && empty($old_val)) {
            // Nuttig om te weten dat hij hier 'early exit' doet
            wachthond($extdebug, 3, "SKIP [$key]", "Beide leeg/NULL");
            continue;
        }

        // 2. Exact gelijk? -> Skip
        // Let op: Loose comparison (==), dus 0 is gelijk aan "0", maar "2023-01-01" is NIET "2023-01-01 00:00:00"
        if ($new_val == $old_val) {
            wachthond($extdebug, 3, "SKIP [$key]", "Identiek");
            continue;
        }

        // 3. Wijziging gevonden
        $clean_values_c[$key]   = $new_val;
        $has_changes_c          = true;
        
        // UITGEBREIDE DEBUG BIJ WIJZIGING
        // We loggen hier ook de lengte (strlen). Dit verraadt direct issues met
        // verborgen spaties of datum vs timestamp (10 vs 19 tekens).
        $len_old = strlen((string)$old_val);
        $len_new = strlen((string)$new_val);
        
        wachthond($extdebug, 2, "CHANGE DETECTED [$key]", 
            "Old: '$old_val' ($len_old chars) -> New: '$new_val' ($len_new chars)"
        );
    }

    if ($has_changes_c) {
        // FIX 1: Voorkom TypeError in de CiviCRM core door lege strings om te zetten naar puur NULL.
        // APIv4 en de onderliggende BAO handelen NULL correct af voor het leegmaken van velden.
        foreach ($clean_values_c as $k => $v) {
            if ($v === "") {
                $clean_values_c[$k] = null;
            }
        }

        $params_c_config = [
            'checkPermissions' => FALSE,
            'where'            => [['id', '=', $contact_id]],
            'values'           => $clean_values_c
        ];

        try {
            wachthond($extdebug, 3, 'params_contact_configure', $params_c_config);
            $result_c_config = civicrm_api4('Contact', 'update', $params_c_config);
            wachthond($extdebug, 9, 'result_contact_configure', $result_c_config);
            wachthond($extdebug, 3, 'result_contact_configure', "EXECUTED (wijzigingen gevonden)");

        } catch (\Throwable $e) {
            // FIX 2: \Throwable vangt zowel Exceptions als PHP 8 Errors (zoals de TypeError) af!
            // Hierdoor crasht het formulier NIET meer voor de gebruiker als er een data-mismatch is.
            wachthond($extdebug, 1, "FOUT/TYPE ERROR genegeerd tijdens Contact update: " . $e->getMessage(), $clean_values_c);
        }
    }    

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,2, "### INTAKE - 8.0 CONFIGURE - UPDATE PARTICIPANT",         "[UPDATE PART]");
    wachthond($extdebug,3, "########################################################################");

    if ($part_id > 0) {
        $part_values = [
            'PART.NAW_gecheckt'             => $naw['gecheckt'], 
            'PART.BIO_gecheckt'             => $bio['gecheckt'],

            'PART_LEID_INTERN.INT_nodig'    => $vog['nodig'],
            'PART_LEID_INTERN.INT_status'   => $new_int_status,

            'PART_LEID_INTERN.NAW_nodig'    => $naw['nodig'],
            'PART_LEID_INTERN.NAW_status'   => $naw['status'],
            'PART_LEID_INTERN.BIO_nodig'    => $bio['nodig'],
            'PART_LEID_INTERN.BIO_status'   => $bio['status'],
            'PART_LEID_INTERN.REF_nodig'    => $ref['nodig'],
            'PART_LEID_INTERN.REF_status'   => $ref['status'],

            'PART_LEID_INTERN.VOG_nodig'    => $vog['nodig'],
            'PART_LEID_INTERN.VOG_status'   => $vog['status'],

            'PART_LEID_VOG.Datum_verzoek'   => $vog['verzoek'],
            'PART_LEID_VOG.Datum_aanvraag'  => $vog['aanvraag'],
            'PART_LEID_VOG.Datum_nieuwe_VOG'=> $vog['datum'],
//          'PART_LEID_VOG.Kenmerk_VOG'     => $vog['kenmerk'],
//          'PART_LEID_VOG.Scan_foto_van_je_VOG' => $vog['scan'],

        ];

        wachthond($extdebug,3, "part_values", $part_values);

        $params_get_p = [
            'checkPermissions' => FALSE,
            'select'    => array_keys($part_values),
            'where'     => [['id', '=', $part_id]],
        ];
        $current_data_p = civicrm_api4('Participant', 'get', $params_get_p)->first();

        $clean_values_p = [];
        $has_changes_p  = false;

        foreach ($part_values as $key => $new_val) {
            $old_val = $current_data_p[$key] ?? '';

            // Datum normalisatie
            if (strlen($old_val) == 10 && strlen($new_val) == 19 && strpos($new_val, $old_val) === 0) {
                 continue; 
            }
            if (empty($new_val) && empty($old_val)) {
                continue;
            }

            if ($new_val != $old_val) {
                $clean_values_p[$key]   = $new_val;
                $has_changes_p          = true;
            }
        }

        if ($has_changes_p) {
            $params_p_config = [
                'checkPermissions' => FALSE,
                'where'     => [['id', '=', $part_id]],
                'values'    => $clean_values_p
            ];
            try {
                wachthond($extdebug,3, 'params_participant_configure', $params_p_config);
                $result_p_config = civicrm_api4('Participant','update',$params_p_config);
                wachthond($extdebug,9, 'result_participant_configure', $result_p_config);
                wachthond($extdebug,1, 'result_participant_configure', "EXECUTED (Wijzigingen gevonden)");
            } catch (\Exception $e) {
                wachthond($extdebug, 1, "Fout configure participant: ".$e->getMessage());
            }
        } else {
            wachthond($extdebug, 3, 'result_participant_configure', "SKIPPED (Geen wijzigingen)");
        }
    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE INTAKE WAARDEN VOOR $displayname",       "[EINDE]");
    wachthond($extdebug,3, "########################################################################");

    return ['fot' => $fot, 'naw' => $naw, 'bio' => $bio, 'ref' => $ref];
}