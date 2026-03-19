 <?php

require_once 'intake.civix.php';

use CRM_Intake_ExtensionUtil as E;

function intake_civicrm_pre($op, $objectName, $id, &$params) {

    // 1. CHECK HETZELFDE SLOT
    // Als customPre bezig is, stoppen we hier ook.
    if (intake_recursion_lock()) {
        return; 
    }

    $extdebug   = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
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

    // 1. CHECK HET GEDEELDE SLOT
    // Als 'intake_civicrm_pre' (of deze functie zelf) bezig is met updaten, 
    // geeft deze functie TRUE terug. We stoppen dan direct.
    if (intake_recursion_lock()) {
        return; 
    }

    // 2. SLOT DICHTDOEN
    // We vertellen het hele script: "Ik ben nu bezig, negeer inkomende hooks"
    intake_recursion_lock(true);

    // --- STOP ONEINDIGE LOOPS (STATIC FLAG) ---
    static $processing_intake_custompre = false;

    if ($processing_intake_custompre) {
        // We zijn al bezig met een update in deze functie. 
        // Stop nu om te voorkomen dat we onszelf in de staart bijten.
        return;
    }

    // Zet de vlag AAN: "Niet storen, ik ben bezig"
    $processing_intake_custompre = true;

    $profilecont            = array(225); // JAAROVERZICHT
    $profilecontintake      = array(181);

    $profilepartdeel        = array(139);
    $profilepartleid        = array(190);
    $profilepart_leidintern = array(300);

    $profilepartref         = array(213);
    $profilepartvog         = array(140);
    $profilepart            = array_merge($profilepartdeel,     $profilepartleid);

    $profilepartintake      = array_merge($profilepartref,      $profilepartvog);
    $profileintakeall       = array_merge($profilecontintake,   $profilepartintake);

    if (!in_array($groupID, $profileintakeall) AND !in_array($groupID, $profilepartdeel) ) {
        $processing_intake_custompre = false;
        return;
    }

    // --- NIEUW: START STOPWATCH ---
    $intake_start_tijd      = microtime(TRUE);

    $extdebug_general       = 3;    // ALGEMENE DELEN DIE ALTIJD PLAATSVINDEN

    $extdebug_intake_cont   = 3;    //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $extdebug_intake_part   = 3;    //  1 = basic // 2 = verbose // 3 = params / 4 = results

    $extdebug_cont_ref      = 3;    //  DEBUG profiel contact_intake
    $extdebug_cont_vog      = 3;    //  DEBUG profiel contact_intake

    $extdebug_part_ref      = 3;    //  DEBUG participant profielen VOG & REF
    $extdebug_part_vog      = 3;    //  DEBUG participant profielen VOG & REF

    $extdebug               = $extdebug_general;

    $apidebug               = FALSE;
    $extwrite               = 1;
    $extintake              = 1;

    $today_datetime         = date("Y-m-d H:i:s");
    $today_datetime_past    = date('Y-m-d H:i:s', strtotime('-99 year', strtotime($today_datetime)) );
//  wachthond($extdebug,4, 'today_datetime_past',       $today_datetime_past);

    wachthond($extdebug,4, "params",        $params);

    $arraysize = is_array($params) ? count($params) : 0;

    // GA DOOR INDIEN MAAR 1 WAARDE & PROFIEL = PROFILECONTINTAKE
    // DIT GEBEURT INDIEN VIA EEN WEBFORM NAW OF BIO WORDT BIJGEWERKT
    if ($arraysize === 1 && in_array($groupID, $profilecontintake)) {
        $column_name = $params[0]['column_name'] ?? ''; // Voorkom undefined offset 0
        if ($column_name != 'fot_update_2253'   && 
            $column_name != 'fot_status_1798'   && 
            $column_name != 'naw_gecheckt_1505' && 
            $column_name != 'bio_ingevuld_1496' && 
            $column_name != 'bio_gecheckt_1497' && 
            $column_name != 'intake_trigger_2250') {
            $processing_intake_custompre = false;
            return;
        }
    }    

    // GA NIET DOOR BIJ MEERDERE WAARDEN & PROFILECONTINTAKE (M61: WAAROM NIET?)
    // DIT GEBEURT INDIEN PART INTAKE WAARDEN SCHRIJFT NAAR CONT (ZODAT ER GEEN LOOP ONTSTAAT)
    if ($arraysize > 6 AND $arraysize < 10 AND in_array($groupID, $profilecontintake)) {
        $processing_intake_custompre = false;
        return;
    }

    ##########################################################################################
    // --- Stap 1: Identificeer de aanwezige intake-prefixes ---
    ##########################################################################################

    $foundIntakePrefixes = []; // Array om gevonden intake-prefixes op te slaan
    $intakePrefixes      = ['fot_', 'naw_', 'bio_', 'ref_', 'vog_']; // Lijst met prefixes om op te controleren

    foreach ($params as $item) {
        if (isset($item['column_name']) && is_string($item['column_name'])) {
            foreach ($intakePrefixes as $prefix) {
                if (strpos($item['column_name'], $prefix) === 0) {
                    // Voeg de gevonden prefix toe aan de lijst, voorkom duplicaten
                    if (!in_array($prefix, $foundIntakePrefixes)) {
                        $foundIntakePrefixes[] = $prefix;
                    }
                }
            }
        }
    }

    ##########################################################################################
    // --- Stap 2: Gebruik de gevonden prefixes om de beslissing te maken ---
    ##########################################################################################

    $foundIntakePrefixes_FOT = 0;
    $foundIntakePrefixes_NAW = 0;
    $foundIntakePrefixes_BIO = 0;
    $foundIntakePrefixes_REF = 0;
    $foundIntakePrefixes_VOG = 0;

    if (!empty($foundIntakePrefixes)) {

        if (in_array('fot_', $foundIntakePrefixes)) {
            $foundIntakePrefixes_FOT = 1;
        }
        if (in_array('naw_', $foundIntakePrefixes)) {
            $foundIntakePrefixes_NAW = 1;
        }
        if (in_array('bio_', $foundIntakePrefixes)) {
            $foundIntakePrefixes_BIO = 1;
        }
        if (in_array('ref_', $foundIntakePrefixes)) {
            $foundIntakePrefixes_REF = 1;
        }
        if (in_array('vog_', $foundIntakePrefixes)) {
            $foundIntakePrefixes_VOG = 1;
        }
    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, 'foundIntakePrefixes_FOT',   $foundIntakePrefixes_FOT);
    wachthond($extdebug,3, 'foundIntakePrefixes_NAW',   $foundIntakePrefixes_NAW);
    wachthond($extdebug,3, 'foundIntakePrefixes_BIO',   $foundIntakePrefixes_BIO);
    wachthond($extdebug,3, 'foundIntakePrefixes_REF',   $foundIntakePrefixes_REF);
    wachthond($extdebug,3, 'foundIntakePrefixes_VOG',   $foundIntakePrefixes_VOG);
    wachthond($extdebug,3, "########################################################################");

    ##########################################################################################
    // --- Stap 3: Als er geen NAW of BIO in de params zit ga dan niet verder ---
    ##########################################################################################

    if ($foundIntakePrefixes_NAW == 0 AND $foundIntakePrefixes_BIO == 0) {

        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,3, "### INTAKE [PRE] EXIT WANT GEEN WAARDEN NAW OF BIO","[groupID: $groupID]");
        wachthond($extdebug,3, "########################################################################");

// M61 SKIP RETURN FOR NOW WANT OOK REF EN VOG PRE WERD GESKIPT
//      $is_running = false;
//      return;
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE [PRE] 0.X VERWERK DATA IN PROFILE INTAKE","[groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    if (in_array($groupID, $profilecontintake)) {

        $extdebug = $extdebug_intake_cont;  //  1 = basic // 2 = verbose // 3 = params / 4 = resultsl

        $contact_id = $entityID;

        $params_contact = [
            'checkPermissions' => FALSE,
            'debug'  => $apidebug,        
            'select' => [
                'display_name',
            ],
            'where' => [
                ['id',        'IN', [$contact_id]],
            ],
        ];
        wachthond($extdebug,7, 'params_contact',            $params_contact);
        $result_contact  = civicrm_api4('Contact','get',    $params_contact);
        wachthond($extdebug,9, 'result_contact',            $result_contact);

        $displayname = $result_contact->first()['display_name'] ?? NULL;

    }

    if (in_array($groupID, $profilepartintake)) {

        $extdebug = $extdebug_intake_part;  //  1 = basic // 2 = verbose // 3 = params / 4 = results

        $params_part = [
            'select' => [
                'id',
                'contact_id',
                'contact_id.display_name',
            ],
            'where' => [
                ['is_test',    'IN', [TRUE, FALSE]], 
                ['id',         '=',  $entityID],
            ],
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
        ];
        wachthond($extdebug,7, 'params_part',           $params_part);
        $result_part = civicrm_api4('Participant','get',$params_part);
        wachthond($extdebug,9, 'result_part',           $result_part);

        $part_id            = $result_part[0]['id']                             ?? NULL;
        $contact_id         = $result_part[0]['contact_id']                     ?? NULL;
        $displayname        = $result_part[0]['contact_id.display_name']        ?? NULL;
    }

    if (in_array($groupID, $profileintakeall)) {

        $extdebug = $extdebug_general;  //  1 = basic // 2 = verbose // 3 = params / 4 = resultsl

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 0.1 RETREIVE RELEVANT CONTACT VALUES",  "[$displayname - groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

        $array_contditjaar = base_cid2cont($contact_id);
        wachthond($extdebug,4, "array_contditjaar", $array_contditjaar);

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
        wachthond($extdebug,2, 'leeftijd_decimaal',     $leeftijd_decimaal);
        wachthond($extdebug,2, 'curcv_keer_leid',       $curcv_keer_leid);

        wachthond($extdebug,3, 'contact_foto',          $contact_foto);
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

//      $new_cont_nawnodig          = $cont_nawnodig    ?? 'elkjaar';
//      $new_cont_bionodig          = $cont_bionodig    ?? 'elkjaar';

        $new_cont_nawnodig          = 'elkjaar';

        if ($curcv_keer_leid > 0) {
            $new_cont_bionodig      = 'elkjaar';
        }

        $new_cont_refnodig          = $cont_refnodig;
        $new_cont_vognodig          = $cont_vognodig;
        $new_cont_nawstatus         = $cont_nawstatus;
        $new_cont_biostatus         = $cont_biostatus;
        $new_cont_refstatus         = $cont_refstatus;
        $new_cont_vogstatus         = $cont_vogstatus;

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 0.2 CV DEEP DIVE CHECK DIT JAAR DEEL/LEID POS/ONE", "[ALLPART]");
        wachthond($extdebug,2, "########################################################################");

        $array_allpart_ditjaar = base_find_allpart($contact_id, $today_datetime);
        wachthond($extdebug,4, 'array_allpart_ditjaar',         $array_allpart_ditjaar);

        $ditjaar_one_leid_count         = (int)($array_allpart_ditjaar['result_allpart_one_leid_count'] ?? 0);
        $ditjaar_one_leid_part_id       = (int)($array_allpart_ditjaar['result_allpart_one_leid_part_id'] ?? 0);
        $ditjaar_one_leid_event_id      = (int)($array_allpart_ditjaar['result_allpart_one_leid_event_id'] ?? 0);
        $ditjaar_one_leid_status_id     = (int)($array_allpart_ditjaar['result_allpart_one_leid_status_id'] ?? 0);
        $ditjaar_one_leid_kampfunctie   = (string)($array_allpart_ditjaar['result_allpart_one_leid_kampfunctie'] ?? '');
        $ditjaar_one_leid_kampkort      = (string)($array_allpart_ditjaar['result_allpart_one_leid_kampkort'] ?? '');

        wachthond($extdebug,3, 'ditjaar_one_leid_count',        $ditjaar_one_leid_count);
        wachthond($extdebug,3, 'ditjaar_one_leid_part_id',      $ditjaar_one_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_event_id',     $ditjaar_one_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_status_id',    $ditjaar_one_leid_status_id);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampfunctie',  $ditjaar_one_leid_kampfunctie);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampkort',     $ditjaar_one_leid_kampkort);

        $ditjaar_pos_leid_count         = (int)($array_allpart_ditjaar['result_allpart_pos_leid_count'] ?? 0);
        $ditjaar_pos_leid_part_id       = (int)($array_allpart_ditjaar['result_allpart_pos_leid_part_id'] ?? 0);
        $ditjaar_pos_leid_event_id      = (int)($array_allpart_ditjaar['result_allpart_pos_leid_event_id'] ?? 0);
        $ditjaar_pos_leid_status_id     = (int)($array_allpart_ditjaar['result_allpart_pos_leid_status_id'] ?? 0);
        $ditjaar_pos_leid_kampfunctie   = (string)($array_allpart_ditjaar['result_allpart_pos_leid_kampfunctie'] ?? '');
        $ditjaar_pos_leid_kampkort      = (string)($array_allpart_ditjaar['result_allpart_pos_leid_kampkort'] ?? '');

        wachthond($extdebug,3, 'ditjaar_pos_leid_count',        $ditjaar_pos_leid_count);
        wachthond($extdebug,3, 'ditjaar_pos_leid_part_id',      $ditjaar_pos_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_event_id',     $ditjaar_pos_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_status_id',    $ditjaar_pos_leid_status_id);
        wachthond($extdebug,2, 'ditjaar_pos_leid_kampfunctie',  $ditjaar_pos_leid_kampfunctie);
        wachthond($extdebug,2, 'ditjaar_pos_leid_kampkort',     $ditjaar_pos_leid_kampkort);

        $ditjaar_one_deel_part_id       = (int)($array_allpart_ditjaar['result_allpart_one_deel_part_id'] ?? 0);
        wachthond($extdebug,3, 'ditjaar_one_deel_part_id',      $ditjaar_one_deel_part_id);

        $ditjaar_pos_deel_count         = (int)($array_allpart_ditjaar['result_allpart_pos_deel_count'] ?? 0);
        $ditjaar_pos_deel_part_id       = (int)($array_allpart_ditjaar['result_allpart_pos_deel_part_id'] ?? 0);
        $ditjaar_pos_deel_event_id      = (int)($array_allpart_ditjaar['result_allpart_pos_deel_event_id'] ?? 0);
        $ditjaar_pos_deel_status_id     = (int)($array_allpart_ditjaar['result_allpart_pos_deel_status_id'] ?? 0);
        $ditjaar_pos_deel_kampfunctie   = (string)($array_allpart_ditjaar['result_allpart_pos_deel_kampfunctie'] ?? '');
        $ditjaar_pos_deel_kampkort      = (string)($array_allpart_ditjaar['result_allpart_pos_deel_kampkort'] ?? '');

        wachthond($extdebug,3, 'ditjaar_pos_deel_count',        $ditjaar_pos_deel_count);
        wachthond($extdebug,3, 'ditjaar_pos_deel_part_id',      $ditjaar_pos_deel_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_deel_event_id',     $ditjaar_pos_deel_event_id);
        wachthond($extdebug,3, 'ditjaar_pos_deel_status_id',    $ditjaar_pos_deel_status_id);
        wachthond($extdebug,2, 'ditjaar_pos_deel_kampfunctie',  $ditjaar_pos_deel_kampfunctie);
        wachthond($extdebug,2, 'ditjaar_pos_deel_kampkort',     $ditjaar_pos_deel_kampkort);

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### INTAKE [PRE] 0.3 BEPAAL OF ENTITYID GEBRUIKT WORDT OF POS_LEID_PARTID");
        wachthond($extdebug,3, "########################################################################");

        // volgorde eerst deel dan leid igv dubbele deelname in 1 jaar (bv leid + deel topkamp)

        if ($ditjaar_one_deel_part_id > 0) {
            $part_id    = $ditjaar_one_deel_part_id;
        } else {
            $part_id    = $part_id;
        }

        if ($ditjaar_pos_deel_part_id > 0) {
            $part_id    = $ditjaar_pos_deel_part_id;
        } else {
            $part_id    = $part_id;
        }

        if ($ditjaar_one_leid_part_id > 0) {
            $part_id    = $ditjaar_one_leid_part_id;
        } else {
            $part_id    = $part_id;
        }

        if ($ditjaar_pos_leid_part_id > 0) {
            $part_id    = $ditjaar_pos_leid_part_id;
        } else {
            $part_id    = $part_id;
        }

        wachthond($extdebug,3, "contact_id",                    $contact_id);
        wachthond($extdebug,3, "entityID",                      $entityID);
        wachthond($extdebug,3, 'part_id',                       $part_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_part_id',      $ditjaar_one_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_part_id',      $ditjaar_pos_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_deel_part_id',      $ditjaar_pos_deel_part_id);
        wachthond($extdebug,3, 'ditjaar_one_deel_part_id',      $ditjaar_one_deel_part_id);

/*
        // M61: EXIT INDIEN GEEN LEID OF DEEL DIT JAAR
        // M61: HOUDT MSS GEEN REKENING MET BELANGSTELLENDE LEID OF WACHTLIJST
        if (empty($ditjaar_pos_leid_count) && empty($ditjaar_one_deel_count) && empty($ditjaar_pos_deel_count))  {

            wachthond($extdebug,1, "RETURN WANT GEEN POSITIEVE DEELNAME DEEL OF LEID");
            $processing_intake_custompre = false;
            return;
        }        
*/
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### INTAKE [PRE] 0.4 GET PARTICIPANT DATA FROM PRIMARY EVENT");
        wachthond($extdebug,3, "########################################################################");

        if ($part_id > 0) {
            $event_array    = base_pid2part($part_id);
            wachthond($extdebug,4, "event_array",       $event_array);

            $event_fiscalyear           = $event_array['event_fiscalyear']      ?? NULL;
            $part_regdate               = $event_array['register_date']         ?? NULL;

            $part_kampstart             = $event_array['part_kampstart']        ?? NULL;
            $part_kampeinde             = $event_array['part_kampeinde']        ?? NULL;
            $part_kampkort              = $event_array['part_kampkort']         ?? NULL;
            $part_functie               = $event_array['part_functie']          ?? NULL;
            $part_rol                   = $event_array['part_rol']              ?? NULL;

            if ($part_rol == 'leiding') {

                $part_refnodig          = $event_array['part_refnodig']         ?? NULL;
                $part_refstatus         = $event_array['part_refstatus']        ?? NULL;
                $part_refpersoon        = $event_array['part_refpersoon']       ?? NULL;
                $part_refgevraagd       = $event_array['part_refgevraagd']      ?? NULL;
                $part_reffeedback       = $event_array['part_reffeedback']      ?? NULL;

                $part_vognodig          = $event_array['part_vognodig']         ?? NULL;
                $part_vogstatus         = $event_array['part_vogstatus']        ?? NULL;
                $part_vogverzoek        = $event_array['part_vogverzoek']       ?? NULL;
                $part_vogaanvraag       = $event_array['part_vogaanvraag']      ?? NULL;
                $part_vogdatum          = $event_array['part_vogdatum']         ?? NULL;

                $new_part_vogverzoek    = $part_vogverzoek;         
                $new_part_vogaanvraag   = $part_vogaanvraag;         
                $new_part_vogdatum      = $part_vogdatum;
            }

            wachthond($extdebug,3, 'event_fiscalyear',  $event_fiscalyear);
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
                wachthond($extdebug,3, 'part_refpersoon',   $part_refpersoon);
                wachthond($extdebug,3, 'part_refgevraagd',  $part_refgevraagd);
                wachthond($extdebug,3, 'part_reffeedback',  $part_reffeedback);

                wachthond($extdebug,3, 'cont_vogdatum',     $cont_vogdatum);
                wachthond($extdebug,3, 'part_vognodig',     $part_vognodig);
                wachthond($extdebug,3, 'part_vogstatus',    $part_vogstatus);
                wachthond($extdebug,3, 'part_vogverzoek',   $part_vogverzoek);
                wachthond($extdebug,3, 'part_vogaanvraag',  $part_vogaanvraag);
                wachthond($extdebug,3, 'part_vogdatum',     $part_vogdatum);
            }           

        } else {
            $event_array    = [];
            wachthond($extdebug,3, "NOG GEEN EVENT REGISTRATIE");
        }

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### INTAKE [PRE] 0.5 RETREIVE TODAY FISCAL YEAR START VALUE FROM CACHE");
        wachthond($extdebug,3, "########################################################################");

        if (!Civi::cache()->get('cache_today_fiscalyear_start'))    {
            find_fiscalyear();
        }

        $today_fiscalyear_start = Civi::cache()->get('cache_today_fiscalyear_start')    ?? NULL;
        $today_fiscalyear_einde = Civi::cache()->get('cache_today_fiscalyear_einde')    ?? NULL;
        $today_kampjaar         = Civi::cache()->get('cache_today_kampjaar')            ?? NULL;
        wachthond($extdebug,4, 'today_kampjaar',            $today_kampjaar);
        wachthond($extdebug,3, 'today_fiscalyear_start',    $today_fiscalyear_start);
        wachthond($extdebug,3, 'today_fiscalyear_einde',    $today_fiscalyear_einde);

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### INTAKE [PRE] 0.6 BEPAAL GRENSNOGGOED REF & VOG VOOR HOOFDLEIDING & STAF");
        wachthond($extdebug,3, "########################################################################");

        ################################################################################################
        # RETREIVE GRENS VOGNOGGOED VALUE FROM CACHE
        ################################################################################################

        if ($part_rol == 'leiding') {

            $grensvognoggoed1       = Civi::cache()->get('cache_grensvognoggoed1')      ?? NULL;
            $grensvognoggoed3       = Civi::cache()->get('cache_grensvognoggoed3')      ?? NULL;
            $grensrefnoggoed3       = Civi::cache()->get('cache_grensrefnoggoed3')      ?? NULL;
            wachthond($extdebug,3, 'grensvognoggoed1',          $grensvognoggoed1);
            wachthond($extdebug,3, 'grensvognoggoed3',          $grensvognoggoed3);
            wachthond($extdebug,3, 'grensrefnoggoed3',          $grensrefnoggoed3);

            wachthond($extdebug,3, "part_functie",           $part_functie);

            if (in_array($part_functie, array('hoofdleiding', 'bestuurslid'))) {

                $grensrefnoggoed    = $grensvognoggoed1;
                $grensvognoggoed    = $grensvognoggoed1;
                $grensnoggoedjaar   = 1;
                wachthond($extdebug,3, "grensvognoggoed voor groepsleiding is $grensnoggoedjaar jaar",    $grensvognoggoed);

            } else {

                $grensrefnoggoed    = $grensrefnoggoed3;
                $grensvognoggoed    = $grensvognoggoed3;
                $grensnoggoedjaar   = 3;
                wachthond($extdebug,3, "grensvognoggoed voor groepsleiding is $grensnoggoedjaar jaar",    $grensvognoggoed);
            }

            wachthond($extdebug,3, 'grensvognoggoed',           $grensvognoggoed);
            wachthond($extdebug,3, 'grensnoggoedjaar',          $grensnoggoedjaar);

        }
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
        wachthond($extdebug,3, 'arraysize',             $arraysize);

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
            'int_nodig_1494'    => 'val_int_nodig',
            'int_status_1492'   => 'val_int_status',
            'fot_status_1798'   => 'val_fot_status',
            'fot_update_2253'   => 'val_fot_update',
            'naw_nodig_1664'    => 'val_naw_nodig',
            'naw_gecheckt_1505' => 'val_naw_gecheckt',
            'naw_status_1508'   => 'val_naw_status',
            'bio_nodig_1495'    => 'val_bio_nodig',
            'bio_ingevuld_1496' => 'val_bio_ingevuld',
            'bio_gecheckt_1497' => 'val_bio_gecheckt',
            'bio_status_1498'   => 'val_bio_status',
            'ref_nodig_1019'    => 'val_ref_nodig',
            'ref_persoon_1782'  => 'val_ref_persoon',
            'ref_laatste_1004'  => 'val_ref_laatste',
            'ref_status_1490'   => 'val_ref_status',
            'vog_nodig_998'     => 'val_vog_nodig',
            'vog_laatste_56'    => 'val_vog_laatste',
            'vog_status_1489'   => 'val_vog_status',
        ];

        // 2. Initialiseer hulp-arrays
        $keys    = [];
        $indexed = []; 

        // 3. De Loop: Verwerk ALLEEN de velden die de gebruiker nu opslaat
        foreach ($params as $index => $item) {
            $col = $item['column_name'] ?? '';

            // Altijd opslaan in indexed voor algemene doeleinden
            $indexed[$index] = [
                'key' => $index,
                'column_name' => $col,
                'value' => $item['value'] ?? NULL
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
                
                wachthond($extdebug, 3, "Smart Processing [$col]", 
                    "Var: $$varName | Index: $index | Raw: $displayRaw | Clean: $displayClean"
                );
            }
        }

        // 4. Toon samenvatting van alle gevonden wijzigingen in deze call
        wachthond($extdebug, 2, "Intake 1.1 Summary: Found " . count($keys) . " fields in params", array_keys($keys));

        wachthond($extdebug,2, 'params',    $params);
        wachthond($extdebug,2, 'keys',      $keys);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 2.0 CONFIGURE NAW STATUS",              "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        if (isset($keys['val_naw_gecheckt'])) {
            $naw_res = intake_status_naw(
                $contact_id, 
                ($val_naw_gecheckt ?? NULL), 
                $curcv_keer_leid, 
                ($event_array['register_date'] ?? NULL), 
                $today_datetime
            );
            
            wachthond($extdebug, 2, 'naw_res', $naw_res);
            wachthond($extdebug, 2, "keys val_naw_gecheckt", ($keys['val_naw_gecheckt'] ?? 'MISSING'));

            if (isset($keys['val_naw_gecheckt'])) {
                $params[$keys['val_naw_gecheckt']]['value'] = format_civicrm_smart($naw_res['gecheckt'], 'naw_gecheckt_1505');
            } else {
                wachthond($extdebug, 2, 'geen keys naw_gecheckt', $keys);
            }

            if (isset($keys['val_naw_status'])) {
                $params[$keys['val_naw_status']]['value'] = format_civicrm_smart($naw_res['status'], 'naw_status_1508');
            } else {
                wachthond($extdebug, 2, 'geen keys naw_status', $keys);
            }

            $new_part_nawgecheckt = $naw_res['part_gecheckt'];
            $new_cont_nawstatus = $naw_res['status'];
        } else {
            wachthond($extdebug, 2, 'geen keys naw_gecheckt', $keys);
        }

        if (isset($new_part_nawgecheckt)) { wachthond($extdebug, 2, 'new_part_nawgecheckt', $new_part_nawgecheckt); }
        if (isset($new_cont_nawstatus))   { wachthond($extdebug, 2, 'new_cont_nawstatus',   $new_cont_nawstatus); }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 3.0 CONFIGURE BIO STATUS",                "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        if (isset($keys['val_bio_gecheckt']) || isset($keys['val_bio_ingevuld'])) {
            
            $bio_res = intake_status_bio(
                $contact_id, 
                ($val_bio_ingevuld ?? NULL), 
                ($val_bio_gecheckt ?? NULL), 
                $today_datetime
            );
            
            wachthond($extdebug, 2, 'bio_res', $bio_res);

            // --- 1. STATUS INJECTIE (VEILIG) ---
            if (isset($keys['val_bio_status'])) {
                
                $statusFieldID = $keys['val_bio_status'];

                // Check: Heeft bio_res wel een waarde? Zo nee, doe NIETS.
                // Dit voorkomt dat je een bestaande status overschrijft met leeg/null.
                if (!empty($bio_res['status'])) {

                    if (!isset($params[$statusFieldID])) {
                        $params[$statusFieldID] = [];
                    }

                    $params[$statusFieldID]['value'] = format_civicrm_smart($bio_res['status'], 'bio_status_1498');
                    wachthond($extdebug, 2, 'INJECTED bio_status', $params[$statusFieldID]);

                } else {
                    wachthond($extdebug, 2, 'SKIP bio_status', 'Resultaat was leeg, bestaande waarde behouden.');
                }

            } else {
                wachthond($extdebug, 2, 'ERROR: geen mapping gevonden voor bio_status', $keys);
            }

            // --- 2. DATUM INJECTIE (VEILIG) ---
            if (isset($keys['val_bio_gecheckt'])) {
                 $checkFieldID = $keys['val_bio_gecheckt'];
                 
                 // Check: Heeft bio_res wel een datum?
                 if (!empty($bio_res['gecheckt'])) {

                     if (!isset($params[$checkFieldID])) {
                         $params[$checkFieldID] = [];
                     }
                     
                     $params[$checkFieldID]['value'] = format_civicrm_smart($bio_res['gecheckt'], 'bio_gecheckt_1497');
                     wachthond($extdebug, 2, 'INJECTED bio_gecheckt', $params[$checkFieldID]);

                 } else {
                     // Optioneel: Wil je de datum wissen als hij leeg terugkomt? 
                     // Meestal niet bij een PRE-hook, tenzij je zeker weet dat het een reset is.
                     wachthond($extdebug, 2, 'SKIP bio_gecheckt', 'Resultaat was leeg.');
                 }
            }

            $new_part_biogecheckt   = $bio_res['part_gecheckt'];
            $new_cont_biostatus     = $bio_res['status'];

        } else {
            wachthond($extdebug, 2, 'geen keys bio_gecheckt of bio_ingevuld', $keys);
        }

        if (isset($new_part_biogecheckt)) { wachthond($extdebug, 2, 'new_part_biogecheckt', $new_part_biogecheckt); }
        if (isset($new_cont_biostatus))   { wachthond($extdebug, 2, 'new_cont_biostatus',   $new_cont_biostatus); }
        
        wachthond($extdebug, 2, 'params', $params);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 4.0 CONFIGURE FOT STATUS",              "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $calc_fot_update = $val_fot_update ?? ($array_contditjaar['cont_fotupdate'] ?? '');
        $foto_res = intake_check_fotostatus(($array_contditjaar['contact_foto'] ?? ''), $calc_fot_update, $geslacht, $today_fiscalyear_start);
        
        wachthond($extdebug, 2, 'foto_res', $foto_res); 

        if (isset($keys['val_fot_status'])) {
            $params[$keys['val_fot_status']]['value'] = format_civicrm_smart($foto_res['status'], 'fot_status_1798');
        }

        // --- API UPDATE LOGICA (MET LOCK) ---
        $contact_update_values = [];

        // 1. Placeholder nodig?
        if ($foto_res['status'] == 0 && ($foto_res['current_url'] !== $foto_res['placeholder_url'])) {
            $contact_update_values['image_URL'] = $foto_res['placeholder_url'];
        }

        // 2. Status update nodig (als veld NIET in huidige params zit)?
        if (!isset($keys['val_fot_status'])) {
            $contact_update_values['Intake.fot_status_1798'] = $foto_res['status'];
        }

        // Voer update uit als er iets te updaten valt
        if (!empty($contact_update_values)) {
            
            $p_photo = [
                'checkPermissions' => FALSE, 
                'where' => [['id', '=', $contact_id]], 
                'values' => $contact_update_values
            ];
            
            wachthond($extdebug, 7, 'params_photo_update_customPre', $p_photo);
            
            try { 
                $res_photo = civicrm_api4('Contact', 'update', $p_photo);
                wachthond($extdebug, 9, 'result_photo_update_customPre', $res_photo);
            } catch (\Exception $e) { 
                wachthond($extdebug, 1, "Fout foto/status update: ".$e->getMessage()); 
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 6.1 INT - BEPAAL WAARDE NODIG",                  "[INT]");
        wachthond($extdebug,2, "########################################################################");

        $extdebug = $extdebug_intake_cont;

        // Bepaal INT NODIG
        $part_functie       = $event_array['part_functie'] ?? '';
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

        // 1. Map de berekende INT-waarden aan de variabelen uit de mapping van Sectie 1.1
        $int_injection = [
            'val_int_nodig'    => $new_cont_intnodig,
            'val_int_status'   => $new_cont_intstatus,
        ];

        // 2. Loop door de INT-velden en injecteer alleen als ze aanwezig zijn in de huidige form-submit
        foreach ($int_injection as $varKey => $newValue) {
            
            // Controleer of dit veld aanwezig was in de inkomende params (via de opgeslagen keys)
            if (isset($keys[$varKey])) {
                $index      = $keys[$varKey];
                $colName    = $params[$index]['column_name'];

                // Alleen injecteren als we een nieuwe waarde hebben (voorkom overschrijven met NULL)
                if ($newValue !== NULL) {
                    
                    wachthond($extdebug, 3, "INT Injectie Bezig", "Veld: $colName | Waarde: $newValue");

                    // De SmartHelper handelt het formaat af (bijv. ^A voor multi-select status)
                    $params[$index]['value'] = format_civicrm_smart($newValue, $colName);

                    wachthond($extdebug, 2, "Intake 6.3 INT Inject Success", 
                        "Field: $colName | Final Value: " . $params[$index]['value']
                    );
                }
            } else {
                // Het veld zat niet in de params, dus we slaan het over (strikt uitsluitend uit $params)
                wachthond($extdebug, 4, "Intake 6.3 INT Inject Skip", "Field $varKey not present in form submission.");
            }
        }

        wachthond($extdebug,2, 'new_cont_intnodig',         $new_cont_intnodig);
        wachthond($extdebug,2, 'new_cont_intstatus',        $new_cont_intstatus);

        wachthond($extdebug,4, "NEW params",                $params);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE [PRE] 7.0 UPDATE INTAKE CONTACT (MET DIRTY CHECK)",   "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $params_cont_update = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,          
            'reload'=> TRUE,
            'where' => [
                ['id',  '=', $contact_id],
            ],
            'values' => [
                'id'                        => $contact_id, 
                'INTAKE.intake_modified'    => $today_datetime,
            ],
        ];

        $params_cont_update['values']['INTAKE.INT_nodig']   = $new_cont_intnodig    ?? NULL;
        $params_cont_update['values']['INTAKE.INT_status']  = $new_cont_intstatus   ?? NULL;

        $params_cont_update['values']['INTAKE.REF_cid']     = $new_cont_refcid      ?? NULL;
        $params_cont_update['values']['INTAKE.REF_naam']    = $new_cont_refnaam     ?? NULL;
        $params_cont_update['values']['INTAKE.REF_datum']   = $new_cont_refdatum    ?? NULL;
        $params_cont_update['values']['INTAKE.REF_persoon'] = $new_cont_refpersoon  ?? NULL;
        $params_cont_update['values']['INTAKE.REF_status']  = $new_cont_refstatus   ?? NULL;

        if ($contact_id && count($params_cont_update['values']) > 1) {
            
            // --- DIRTY CHECK CONTACT ---
            // 1. Haal huidige waarden op
            $get_c = [
                'checkPermissions' => FALSE,
                'select' => array_keys($params_cont_update['values']),
                'where'  => [['id', '=', $contact_id]],
            ];
            $curr_c = civicrm_api4('Contact', 'get', $get_c)->first();

            $clean_vals_c = [];
            $has_change_c = false;

            foreach ($params_cont_update['values'] as $key => $new_val) {
                if ($key === 'id') continue;
                // Modified datum negeren we voor de check (anders update hij altijd)
                if ($key === 'INTAKE.intake_modified') continue; 

                $old_val = $curr_c[$key] ?? '';

                // Datum normalisatie (negeer tijdverschil)
                if (strlen($old_val) == 10 && strlen($new_val) == 19 && strpos($new_val, $old_val) === 0) {
                     continue; 
                }
                
                // Vergelijk
                if ($new_val != $old_val) {
                    $clean_vals_c[$key] = $new_val;
                    $has_change_c = true;
                }
            }

            if ($has_change_c) {
                // Als er échte wijzigingen zijn, neem dan ook de modified datum mee
                $clean_vals_c['INTAKE.intake_modified'] = $today_datetime;
                $clean_vals_c['id'] = $contact_id;
                
                $params_cont_update['values'] = $clean_vals_c;
                
                wachthond($extdebug,2, 'params_cont_update', $params_cont_update);
                $result_cont_update = civicrm_api4('Contact','update',  $params_cont_update);
                wachthond($extdebug,1, 'result_cont_update', "EXECUTED (Wijzigingen gevonden)");
            } else {
                wachthond($extdebug,1, 'result_cont_update', "SKIPPED (Geen inhoudelijke wijzigingen)");
            }
        }

    }

    #########################################################################
    ### INTAKE CONT [PRE]                                             [START]
    #########################################################################

    #########################################################################
    ### INTAKE PART REF [PRE]                                         [START]
    #########################################################################

//  if (in_array($groupID, $profilepartref))  {
    if (in_array(12345, $profilepartref))  {

        $extdebug = $extdebug_part_ref;

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 1.1 GET VALUES FROM PARAMS", "[$displayname | groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

        // 1. Definieer de mapping voor Referentie datums
        $ref_date_mapping = [
            'ref_persoon_1301'  => 'val_datum_refpersoon',
            'ref_gevraagd_1295' => 'val_datum_refgevraagd',
            'ref_feedback_1296' => 'val_datum_reffeedback',
        ];

        // 2. Loop door de params
        foreach ($params as $i => $item) {
            $col = $item['column_name'] ?? '';

            // Vul indexed array voor algemeen gebruik (bestaande logica behouden)
            $indexed[$i] = [
                'key'         => $i,
                'column_name' => $col,
                'value'       => $item['value'] ?? NULL
            ];

            // 3. Automatische datum extractie via de Smart Helper
            if (isset($ref_date_mapping[$col])) {
                $varName = $ref_date_mapping[$col];
                
                // Sla de index op in de globale $all_... arrays (voor compatibiliteit met de rest van je script)
                ${"all_" . $varName}[] = $i; 

                // De Smart Helper handelt de ISO-conversie en 00:00:00 fix af
                $$varName = format_civicrm_smart($item['value'] ?? NULL, $col);
                
                wachthond($extdebug, 3, "REF Date Extracted: $varName", $$varName);
            }
        }

        // 4. Zet de primaire keys voor de rest van het script (behoud van je variabelenamen)
        $key_datum_refpersoon  = $all_val_datum_refpersoon[0]  ?? -1;
        $key_datum_refgevraagd = $all_val_datum_refgevraagd[0] ?? -1;
        $key_datum_reffeedback = $all_val_datum_reffeedback[0] ?? -1;

        ##########################################################################################
        ### GET DISPLAYNAME
        ##########################################################################################        

        if ($entityID > 0) {

            $params_part = [
                'select' => [
                    'id',
                    'contact_id',
                    'contact_id.display_name',
                    'contact_id.INTAKE.REF_laatste',     
                    'PART.PART_kampstart',
                    'PART.PART_kampkort',
                    'PART.PART_kampfunctie',
                    'PART_LEID_INTERN.REF_nodig',
                    'PART_LEID_INTERN.REF_status',
                    'PART_LEID_REF.REF_persoon',
                    'PART_LEID_REF.REF_gevraagd',
                    'PART_LEID_REF.REF_feedback',
                    'PART_LEID_REFERENTIE.referentie_cid',
                    'PART_LEID_REFERENTIE.referentie_naam',
                ],
                'where' => [
                    ['is_test',         'IN', [TRUE, FALSE]], 
                    ['id',              '=',  $entityID],
                ],
                'checkPermissions' => FALSE,
                'debug' => $apidebug,
            ];
        }
        wachthond($extdebug,7, 'params_part',           $params_part);
        $result_part = civicrm_api4('Participant','get',$params_part);
        wachthond($extdebug,9, 'result_part',           $result_part);

        if (isset($result_part))    {
            $part_id            = $result_part[0]['id']                                     ?? NULL;
            $contact_id         = $result_part[0]['contact_id']                             ?? NULL;
            $displayname        = $result_part[0]['contact_id.display_name']                ?? NULL;
            $kampstart          = $result_part[0]['PART.PART_kampstart']                    ?? NULL;
            $kampkort           = $result_part[0]['PART.PART_kampkort']                     ?? NULL;
            $kampfunctie        = $result_part[0]['PART.PART_kampfunctie']                  ?? NULL;

            $part_refdatum      = $result_part[0]['contact_id.INTAKE.REF_datum']            ?? NULL;
            $part_refnodig      = $result_part[0]['PART_LEID_INTERN.REF_nodig']             ?? NULL;
            $part_refstatus     = $result_part[0]['PART_LEID_INTERN.REF_status']            ?? NULL;
            $part_refpersoon    = $result_part[0]['PART_LEID_REF.REF_persoon']              ?? NULL;
            $part_refgevraagd   = $result_part[0]['PART_LEID_REF.REF_gevraagd']             ?? NULL;
            $part_reffeedback   = $result_part[0]['PART_LEID_REF.REF_feedback']             ?? NULL;

            $part_refcid        = $result_part[0]['PART_LEID_REFERENTIE.referentie_cid']    ?? NULL;
            $part_refnaam       = $result_part[0]['PART_LEID_REFERENTIE.referentie_naam']   ?? NULL;

            wachthond($extdebug,1, 'contact_id',        $contact_id);
            wachthond($extdebug,1, 'displayname',       $displayname);
            wachthond($extdebug,1, 'kampstart',         $kampstart);
            wachthond($extdebug,1, 'kampkort',          $kampkort);
            wachthond($extdebug,1, 'kampfunctie',       $kampfunctie);

            wachthond($extdebug,1, 'part_refdatum',     $part_refdatum);
            wachthond($extdebug,1, 'part_refnodig',     $part_refnodig);
            wachthond($extdebug,1, 'part_refstatus',    $part_refstatus);
            wachthond($extdebug,1, 'part_refpersoon',   $part_refpersoon);
            wachthond($extdebug,1, 'part_refgevraagd',  $part_refgevraagd);
            wachthond($extdebug,1, 'part_reffeedback',  $part_reffeedback);

            wachthond($extdebug,1, 'part_refcid',       $part_refcid);
            wachthond($extdebug,1, 'part_refnaam',      $part_refnaam);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 1.2 CONSOLIDATE REF DATUMS",   "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,4, "val_datum_refpersoon",  $val_datum_refpersoon);
        wachthond($extdebug,4, "val_datum_refgevraagd", $val_datum_refgevraagd);
        wachthond($extdebug,4, "val_datum_reffeedback", $val_datum_reffeedback);

        $val_cont_refnodig      =   NULL;
        $val_cont_refstatus     =   NULL;
        $val_cont_reflaatste    =   NULL;

        $val_part_refpersoon    =   $val_datum_refpersoon;
        $val_part_refgevraagd   =   $val_datum_refgevraagd;
        $val_part_reffeedback   =   $val_datum_reffeedback;

        $array_intake_refall    = array(
            'grensrefnoggoed'               =>  $grensrefnoggoed,

            'val_cont_refnodig'             =>  $val_cont_refnodig,
            'val_cont_refstatus'            =>  $val_cont_refstatus,
            'val_cont_reflaatste'           =>  $val_cont_reflaatste,

            'val_part_refpersoon'           =>  $val_part_refpersoon,
            'val_part_refgevraagd'          =>  $val_part_refgevraagd,
            'val_part_reffeedback'          =>  $val_part_reffeedback,

            'cont_ref_nodig'                =>  $cont_refnodig,
            'cont_ref_status'               =>  $cont_refstatus,
            'cont_ref_laatste'              =>  $cont_reflaatste,

            'part_ref_persoon'              =>  $part_refpersoon,
            'part_ref_gevraagd'             =>  $part_refgevraagd,
            'part_ref_feedback'             =>  $part_reffeedback,
        );

        wachthond($extdebug,3, "event_array",           $event_array);
        wachthond($extdebug,4, "array_allpart_ditjaar", $array_allpart_ditjaar);
        wachthond($extdebug,3, 'array_intake_refall',   $array_intake_refall);

        $consolidate_refdata_array = intake_consolidate_refdata($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_refall, $groupID);
        wachthond($extdebug,2, 'consolidate_refdata_array', $consolidate_refdata_array);

        $new_cont_refnodig      = $consolidate_refdata_array['new_cont_refnodig'];
        $new_cont_refstatus     = $consolidate_refdata_array['new_cont_refstatus'];
        $new_cont_reflaatste    = $consolidate_refdata_array['new_cont_reflaatste'];
        $new_cont_refpersoon    = $consolidate_refdata_array['new_cont_refpersoon'];
        $new_cont_refverzoek    = $consolidate_refdata_array['new_cont_refverzoek'];
        $new_cont_refdatum      = $consolidate_refdata_array['new_cont_refdatum'];

        $new_part_refnodig      = $consolidate_refdata_array['new_part_refnodig'];
        $new_part_refstatus     = $consolidate_refdata_array['new_part_refstatus'];
        $new_part_reflaatste    = $consolidate_refdata_array['new_part_reflaatste'];
        $new_part_refpersoon    = $consolidate_refdata_array['new_part_refpersoon'];
        $new_part_refverzoek    = $consolidate_refdata_array['new_part_refverzoek'];
        $new_part_refdatum      = $consolidate_refdata_array['new_part_refdatum'];

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 2.0 BEPAAL WAARDE REFNODIG",   "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,1, 'new_cont_refnodig (orgineel)',  $new_cont_refnodig);
        $new_cont_refnodig = intake_refnodig($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_refall, $consolidate_refdata_array, $groupID);
        wachthond($extdebug,1, 'new_cont_refnodig (nieuw)',     $new_cont_refnodig);

        // M61: TODO hier meot eigenlijk een IF om te checken of event wel dit jaar is
        $new_part_refnodig = $new_cont_refnodig;

        // WRITE NEW REFNODIG VALUES TO CONSOLIDATE ARRAY
        $consolidate_refdata_array['new_cont_refnodig']         = $new_cont_refnodig;
        $consolidate_refdata_array['new_part_refnodig']         = $new_part_refnodig;

        wachthond($extdebug,2, 'consolidate_refdata_array', $consolidate_refdata_array);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 3.0 GET REFERENTIE RELATION INFO", "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $referentie_array = intake_referentie_get($contact_id, $groupID);
        wachthond($extdebug,3, "referentie_array",    $referentie_array);

        $referentie_relid       = $referentie_array['ref_rel_id'];
        $referentie_start       = $referentie_array['ref_start'];
        $referentie_einde       = $referentie_array['ref_einde'];
        $referentie_cid         = $referentie_array['ref_referentie_cid'];
        $referentie_naam        = $referentie_array['ref_referentie_naam'];
        $referentie_voornaam    = $referentie_array['ref_referentie_voornaam'];
        $referentie_geslacht    = $referentie_array['ref_referentie_geslacht'];
        $aanvrager_toestemming  = $referentie_array['ref_aanvrager_toestemming'];
        $aanvrager_relatie      = $referentie_array['ref_aanvrager_relatie'];
        $referentie_relatie     = $referentie_array['ref_referentie_relatie'];
        $referentie_motivatie   = $referentie_array['ref_referentie_motivatie'];
        $referentie_gevraagd    = $referentie_array['ref_gevraagd'];
        $referentie_feedback    = $referentie_array['ref_feedback'];
        $referentie_bezwaar     = $referentie_array['ref_bezwaar'];
        $referentie_telefoon    = $referentie_array['ref_referentie_telefoon'];

        wachthond($extdebug,3, "referentie_relid",      $referentie_relid);
        wachthond($extdebug,3, "referentie_start",      $referentie_start);
        wachthond($extdebug,3, "referentie_einde",      $referentie_einde);
        wachthond($extdebug,3, "referentie_cid",        $referentie_cid);
        wachthond($extdebug,3, "referentie_naam",       $referentie_naam);
        wachthond($extdebug,3, "referentie_voornaam",   $referentie_voornaam);
        wachthond($extdebug,3, "referentie_geslacht",   $referentie_geslacht);
        wachthond($extdebug,3, "aanvrager_toestemming", $aanvrager_toestemming);
        wachthond($extdebug,3, "aanvrager_relatie",     $aanvrager_relatie);
        wachthond($extdebug,3, "referentie_relatie",    $referentie_relatie);
        wachthond($extdebug,3, "referentie_motivatie",  $referentie_motivatie);
        wachthond($extdebug,3, "referentie_gevraagd",   $referentie_gevraagd);
        wachthond($extdebug,3, "referentie_feedback",   $referentie_feedback);
        wachthond($extdebug,3, "referentie_bezwaar",    $referentie_bezwaar);
        wachthond($extdebug,3, "referentie_telefoon",   $referentie_telefoon);

        if (infiscalyear($val_datum_refpersoon, $kampstart) == 1) {    // INFISCALYEAR DITJAAR
            $new_cont_refcid        = $part_refcid;
            $new_cont_refnaam       = $part_refnaam;
            $new_cont_refpersoon    = $part_refpersoon;
            wachthond($extdebug,1, 'new_cont_refcid',       $new_cont_refcid);
            wachthond($extdebug,1, 'new_cont_refnaam',      $new_cont_refnaam);
            wachthond($extdebug,1, 'new_cont_refpersoon',   $new_cont_refpersoon);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.1 BEPAAL NEW STATUS REF PERSOON");
        wachthond($extdebug,2, "########################################################################");

        $intake_status_refpersoon_array = intake_status_refpersoon($contact_id, $event_array, $consolidate_refdata_array, $new_cont_refnodig, $groupID);
        wachthond($extdebug,2, "intake_status_refpersoon_array",   $intake_status_refpersoon_array);

        $new_cont_refstatus         = $intake_status_refpersoon_array['new_cont_refstatus'];
        $new_part_refstatus         = $intake_status_refpersoon_array['new_part_refstatus'];
        $new_refpersoon_actdatum    = $intake_status_refpersoon_array['new_refpersoon_actdatum'];
        $new_refpersoon_actstatus   = $intake_status_refpersoon_array['new_refpersoon_actstatus'];
        $new_refpersoon_actprio     = $intake_status_refpersoon_array['new_refpersoon_actprio'];

        wachthond($extdebug,3, "new_cont_vogstatus",        $new_cont_refstatus);
        wachthond($extdebug,3, "new_part_vogstatus",        $new_part_refstatus);
        wachthond($extdebug,3, "new_refpersoon_actdatum",   $new_refpersoon_actdatum);
        wachthond($extdebug,3, "new_refpersoon_actstatus",  $new_refpersoon_actstatus);
        wachthond($extdebug,3, "new_refpersoon_actprio",    $new_refpersoon_actprio);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.2 BEPAAL NEW STATUS REF FEEDBACK");
        wachthond($extdebug,2, "########################################################################");

        $intake_status_reffeedback_array = intake_status_reffeedback($contact_id, $event_array, $consolidate_refdata_array, $new_cont_refnodig, $groupID);
        wachthond($extdebug,2, "intake_status_reffeedback_array",   $intake_status_reffeedback_array);

        $new_cont_refstatus         = $intake_status_reffeedback_array['new_cont_refstatus'];
        $new_part_refstatus         = $intake_status_reffeedback_array['new_part_refstatus'];
        $new_reffeedback_actdatum   = $intake_status_reffeedback_array['new_reffeedback_actdatum'];
        $new_reffeedback_actstatus  = $intake_status_reffeedback_array['new_reffeedback_actstatus'];
        $new_reffeedback_actprio    = $intake_status_reffeedback_array['new_reffeedback_actprio'];

        wachthond($extdebug,3, "new_cont_vogstatus",        $new_cont_refstatus);
        wachthond($extdebug,3, "new_part_vogstatus",        $new_part_refstatus);
        wachthond($extdebug,3, "new_reffeedback_actdatum",  $new_reffeedback_actdatum);
        wachthond($extdebug,3, "new_reffeedback_actstatus", $new_reffeedback_actstatus);
        wachthond($extdebug,3, "new_reffeedback_actprio",   $new_reffeedback_actprio);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.3 UPDATE INTAKE ACTIVITIES", "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $activity_array = array(
            'displayname'               => $displayname,
            'contact_id'                => $contact_id,
            'activity_source'           => 1, 
            'activity_target'           => $contact_id,
        );

        $intake_array = array(
            'ref_nodig'                 => $new_cont_refnodig,
            'ref_datum_persoon'         => $new_cont_refpersoon,
            'ref_datum_gevraagd'        => $new_cont_refverzoek,
            'ref_datum_feedback'        => $new_cont_refdatum,
            'ref_bezwaar'               => $referentie_bezwaar,
        );

        wachthond($extdebug,4, 'activity_array',        $activity_array);
        wachthond($extdebug,4, 'intake_array',          $intake_array);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.4 GET & UPDATE ACTIVITY",      "[REFPERSOON]");
        wachthond($extdebug,2, "########################################################################");

        if ($new_refpersoon_actstatus) {

            $activity_array['activity_type_id']         = 139;              // ref_persoon
            $activity_array['activity_type_naam']       = 'ref_persoon';    // 139
            $activity_array['activity_subject']         = 'REF persoon doorgeven';
            $activity_array['activity_date_time']       = $new_refpersoon_actdatum;
            $activity_array['activity_status_name']     = $new_refpersoon_actstatus;
            $activity_array['activity_prioriteit']      = $new_refpersoon_actprio;

            wachthond($extdebug,3, 'intake_array',              $intake_array);
            wachthond($extdebug,3, 'activity_array',            $activity_array);
            wachthond($extdebug,3, 'new_refpersoon_actstatus',  $new_refpersoon_actstatus);

            // GET ACTIVITY
            $intake_activity_get  = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
            wachthond($extdebug,2, "activity_intake_array", $activity_intake_array);
            $refpersoon_activity_id = $intake_activity_get['activity_id'] ?? NULL;

            // CREATE ACTIVITY
            if (empty($refpersoon_activity_id)) {
                $refpersoon_activity_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_create - refpersoon", "[NEW ACTID: $refpersoon_activity_id]");
            }

            // UPDATE ACTIVITY
            if ($refpersoon_activity_id AND $new_refpersoon_actstatus) {
                $activity_array['activity_id']          = $refpersoon_activity_id;
                $intake_activity_update = intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $referentie_array, $groupID);
                wachthond($extdebug,3, "intake_activity_update", "[ACTID: $intake_activity_update]");
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.5 GET & UPDATE ACTIVITY",     "[REFFEEDBACK]");
        wachthond($extdebug,2, "########################################################################");

        if ($new_reffeedback_actstatus) {

            $activity_array['activity_type_id']         = 117;              // ref_feedback
            $activity_array['activity_type_naam']       = 'ref_feedback';   // 117
            $activity_array['activity_subject']         = 'REF feedback ontvangen';
            $activity_array['activity_date_time']       = $new_reffeedback_actdatum;
            $activity_array['activity_status_name']     = $new_reffeedback_actstatus;
            $activity_array['activity_prioriteit']      = $new_reffeedback_actprio;

            if ($referentie_cid) {
                $activity_array['activity_assignee']    = $referentie_cid;
            }

            wachthond($extdebug,3, 'intake_array',              $intake_array);
            wachthond($extdebug,3, 'activity_array',            $activity_array);
            wachthond($extdebug,3, 'new_reffeedback_actstatus', $new_reffeedback_actstatus);

            // GET ACTIVITY
            $intake_activity_get  = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
            wachthond($extdebug,2, "intake_activity_get", $intake_activity_get);
            $reffeedback_activity_id = $intake_activity_get['activity_id'] ?? NULL;

            // CREATE ACTIVITY
            if (empty($reffeedback_activity_id)) {
                $reffeedback_activity_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_create - reffeedback", "[NEW ACTID: $reffeedback_activity_id]");
            }

            // UPDATE ACTIVITY
            if ($reffeedback_activity_id AND $new_reffeedback_actstatus) {
                $activity_array['activity_id']          = $reffeedback_activity_id;
                $intake_activity_update = intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $referentie_array, $groupID);
                wachthond($extdebug,3, "intake_activity_update - reffeedback", "[ACTID: $intake_activity_update]");
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 4.6 UPDATE INTAKE CONTACT",    "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $params_cont_update = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,         
            'reload'=> TRUE,
            'where' => [
                ['id',  '=', $contact_id],
            ],
            'values' => [
                'id' => $contact_id, 
            ],
        ];

        if ($new_cont_refcid)       { $params_cont_update['values']['INTAKE.REF_cid']       = $new_cont_refcid;     }
        if ($new_cont_refnaam)      { $params_cont_update['values']['INTAKE.REF_naam']      = $new_cont_refnaam;    }
        if ($new_cont_refdatum)     { $params_cont_update['values']['INTAKE.REF_datum']     = $new_cont_refdatum;   }
        if ($new_cont_refpersoon)   { $params_cont_update['values']['INTAKE.REF_persoon']   = $new_cont_refpersoon; }
        if ($new_cont_refnodig)     { $params_cont_update['values']['INTAKE.REF_nodig']     = $new_cont_refnodig;   }
        if ($new_cont_refstatus)    { $params_cont_update['values']['INTAKE.REF_status']    = $new_cont_refstatus;  }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD");
        wachthond($extdebug, 2, "########################################################################");

        // 1. Haal statussen op via de nieuwe centrale functie
        $status_data     = find_partstatus();
        $status_negative = $status_data['ids']['Negative'] ?? [];

        // 2. Check: Is de huidige status negatief?
        if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       

            wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - CLEARING INTAKE FIELDS", $ditjaar_one_leid_status_id);

            $params_contact['values']['INTAKE.INT_nodig']     = "";
            $params_contact['values']['INTAKE.INT_status']    = "";
            $params_contact['values']['INTAKE.REF_nodig']     = "";
            $params_contact['values']['INTAKE.REF_status']    = "";
            $params_contact['values']['INTAKE.VOG_nodig']     = "";
            $params_contact['values']['INTAKE.VOG_status']    = "";
        }

        if ($contact_id) {
            wachthond($extdebug,2, 'params_cont_update',            $params_cont_update);
            $result_cont_update = civicrm_api4('Contact','update',  $params_cont_update);
            wachthond($extdebug,9, 'result_cont_update',            $result_cont_update);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] 5.0 UPDATE INTAKE PART",       "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $params_part_update = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,         
            'reload'=> TRUE,
            'where' => [
                ['id',  '=', $part_id],
            ],
            'values' => [
                'id'                     => $part_id, 
            ],
        ];

        if ($new_part_refnodig)     { $params_part_update['values']['PART_LEID_INTERN.REF_nodig']   = $new_part_refnodig;    }
        if ($new_part_refstatus)    { $params_part_update['values']['PART_LEID_INTERN.REF_status']  = $new_part_refstatus;   }

        if ($part_id) {
            wachthond($extdebug,2, 'params_part_update',                $params_part_update);
            $result_part_update = civicrm_api4('Participant','update',  $params_part_update);
            wachthond($extdebug,9, 'result_part_update',                $result_part_update);
        }

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART REF [PRE] EINDE",    "[$displayname | groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");
    }

    #########################################################################
    ### INTAKE PART REF [PRE]                                         [EINDE]
    #########################################################################

    #########################################################################
    ### INTAKE PART VOG [PRE]                                         [START]
    #########################################################################

//  if (in_array($groupID, $profilepartvog))  {
    if (in_array(12345, $profilepartvog))  {
        // 1. Definieer de mapping: 'CiviCRM_Column' => 'Variabele_Naam'

        $vog_date_mapping = [
            'datum_verzoek_599'  => 'val_datum_vogverzoek',
            'datum_aanvraag_600' => 'val_datum_vogaanvraag',
            'datum_vognieuw_603' => 'val_datum_vognieuw',
        ];

        // 2. Loop één keer door de params om alle indexen en waarden te vangen
        $keys = [];
        foreach ($params as $i => $item) {
            $col = $item['column_name'] ?? '';
            
            // Vul indexed array voor algemeen gebruik
            $indexed[$i] = [
                'key'           => $i,
                'column_name'   => $col,
                'value'         => $item['value'] ?? NULL
            ];

            // Als de kolom in onze mapping staat, verwerk de datum direct
            if (isset($vog_date_mapping[$col])) {
                $varName        = $vog_date_mapping[$col];
                $keys[$varName] = $i; // Sla de index op (bijv. $keys['val_datum_vogverzoek'] = 5)
                
                // Haal de datum door de Smart Helper (vervangt handmatige strtotime/format)
                // Deze helper zet 'today 00:00' automatisch om naar 'today H:i:s'
                $$varName = format_civicrm_smart($item['value'] ?? NULL, $col);
                
                wachthond($extdebug, 3, "VOG Date Extracted: $varName", $$varName);
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 2.0 CONSOLIDATE VOG DATUMS",   "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $val_cont_vognodig      =   NULL;
        $val_cont_vogstatus     =   NULL;
        $val_cont_voglaatste    =   NULL;

        $val_part_vogverzoek    =   $val_datum_vogverzoek;
        $val_part_vogaanvraag   =   $val_datum_vogaanvraag;
        $val_part_vogdatum      =   $val_datum_vognieuw;

        $array_intake_vogall    = array(
            'grensvognoggoed'               =>  $grensvognoggoed,

            'val_cont_vognodig'             =>  $val_cont_vognodig,
            'val_cont_vogstatus'            =>  $val_cont_vogstatus,
            'val_cont_voglaatste'           =>  $val_cont_voglaatste,

            'val_part_vogverzoek'           =>  $val_part_vogverzoek,
            'val_part_vogaanvraag'          =>  $val_part_vogaanvraag,
            'val_part_vogdatum'             =>  $val_part_vogdatum,

            'cont_vog_nodig'                =>  $cont_vognodig,
            'cont_vog_status'               =>  $cont_vogstatus,
            'cont_vog_laatste'              =>  $cont_voglaatste,

            'part_vog_verzoek'              =>  $part_vogverzoek,
            'part_vog_reminder'             =>  $part_vogreminder,
            'part_vog_aanvraag'             =>  $part_vogaanvraag,
            'part_vog_datum'                =>  $part_vogdatum,
        );

        wachthond($extdebug,4, "event_array",           $event_array);
        wachthond($extdebug,4, "array_allpart_ditjaar", $array_allpart_ditjaar);
        wachthond($extdebug,4, 'array_intake_vogall',   $array_intake_vogall);

        $consolidate_vogdata_array = intake_consolidate_vogdata($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_vogall, $groupID);
        wachthond($extdebug,2, 'consolidate_vogdata_array', $consolidate_vogdata_array);

        $new_cont_vognodig      = $consolidate_vogdata_array['new_cont_vognodig'];
        $new_cont_vogstatus     = $consolidate_vogdata_array['new_cont_vogstatus'];
        $new_cont_voglaatste    = $consolidate_vogdata_array['new_cont_voglaatste'];
        $new_cont_vogverzoek    = $consolidate_vogdata_array['new_cont_vogverzoek'];
        $new_cont_vogaanvraag   = $consolidate_vogdata_array['new_cont_vogaanvraag'];
        $new_cont_vogdatum      = $consolidate_vogdata_array['new_cont_vogdatum'];

        $new_part_vognodig      = $consolidate_vogdata_array['new_part_vognodig'];
        $new_part_vogstatus     = $consolidate_vogdata_array['new_part_vogstatus'];
        $new_part_voglaatste    = $consolidate_vogdata_array['new_part_voglaatste'];
        $new_part_vogverzoek    = $consolidate_vogdata_array['new_part_vogverzoek'];
        $new_part_vogaanvraag   = $consolidate_vogdata_array['new_part_vogaanvraag'];
        $new_part_vogdatum      = $consolidate_vogdata_array['new_part_vogdatum'];

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 3.0 BEPAAL WAARDE VOGNODIG",   "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,1, 'new_cont_vognodig',         $new_cont_vognodig);
        $new_cont_vognodig = intake_vognodig($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_vogall, $consolidate_vogdata_array, $groupID);
        wachthond($extdebug,1, 'new_cont_vognodig',         $new_cont_vognodig);

        // M61: TODO hier moet eigenlijk een IF om te checken of event wel dit jaar is
        $new_part_vognodig = $new_cont_vognodig;

        // WRITE NEW VOGNODIG VALUES TO CONSOLIDATE ARRAY
        $consolidate_vogdata_array['new_cont_vognodig']         = $new_cont_vognodig;
        $consolidate_vogdata_array['new_part_vognodig']         = $new_part_vognodig;

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 4.1 BEPAAL NEW STATUS ACTIVITEIT VOG VERZOEK");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,3, 'new_part_vogverzoek',       $new_part_vogverzoek);
        wachthond($extdebug,3, 'part_kampstart',            $part_kampstart);

        $new_vogverzoek_actstatus       = 'Pending';                        // M61: eerst default waarde

        if (infiscalyear($new_part_vogverzoek, $part_kampstart) == 1) {     // INFISCALYEAR DITJAAR
            $new_vogverzoek_actstatus   = 'Completed';
            wachthond($extdebug,3, "new_vogverzoek_actstatus",  $new_vogverzoek_actstatus);
        }
        if (infiscalyear($new_part_vogaanvraag, $part_kampstart) == 1) {    // INFISCALYEAR DITJAAR
            $new_vogverzoek_actstatus   = 'Completed';
            wachthond($extdebug,3, "new_vogverzoek_actstatus",  $new_vogverzoek_actstatus);
        }
        if (infiscalyear($new_part_vogdatum,    $part_kampstart) == 1) {    // INFISCALYEAR DITJAAR
            $new_vogverzoek_actstatus   = 'Completed';
            wachthond($extdebug,3, "new_vogverzoek_actstatus",  $new_vogverzoek_actstatus);
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD");
        wachthond($extdebug, 2, "########################################################################");

        // 1. Haal statussen op via de nieuwe centrale functie
        $status_data     = find_partstatus();
        $status_negative = $status_data['ids']['Negative'] ?? [];

        // 2. Check: Is de huidige status negatief?
        if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       

            wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - RESET REF & VOG", $ditjaar_one_leid_status_id);

            $new_cont_refstatus        = "onbekend";
            $new_part_refstatus        = "onbekend";
            $new_vogverzoek_actstatus  = 'Not Required';
        }

        $new_vogverzoek_actdatum    = $new_cont_vogverzoek;

        wachthond($extdebug,3, 'new_vogverzoek_actstatus',      $new_vogverzoek_actstatus);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 4.2 BEPAAL NEW STATUS ACTIVITEIT VOG AANVRAAG");
        wachthond($extdebug,2, "########################################################################");

        $intake_array = array(
            'grensvognoggoed'   => $grensvognoggoed,
            'vog_status'        => $new_cont_vogstatus,
            'vog_laatste'       => $new_cont_voglaatste,
            'vog_nodig'         => $new_cont_vognodig,
            'vog_verzoek'       => $new_cont_vogverzoek,
            'vog_aanvraag'      => $new_cont_vogaanvraag,
            'vog_datum'         => $new_cont_vogdatum,
        );
        wachthond($extdebug,3, "intake_array",                      $intake_array);

        $intake_status_vogaanvraag_array  = intake_status_vogaanvraag($contact_id, $event_array, $intake_array, $groupID);
        wachthond($extdebug,2, "intake_status_vogaanvraag_array",   $intake_status_vogaanvraag_array);

        $new_cont_vogstatus         = $intake_status_vogaanvraag_array['new_cont_vogstatus'];
        $new_part_vogstatus         = $intake_status_vogaanvraag_array['new_part_vogstatus'];
        $new_vogaanvraag_actdatum   = $intake_status_vogaanvraag_array['new_vogaanvraag_actdatum'];
        $new_vogaanvraag_actstatus  = $intake_status_vogaanvraag_array['new_vogaanvraag_actstatus'];
        $new_vogaanvraag_actprio    = $intake_status_vogaanvraag_array['new_vogaanvraag_actprio'];

        wachthond($extdebug,3, "new_cont_vogstatus",        $new_cont_vogstatus);
        wachthond($extdebug,3, "new_part_vogstatus",        $new_part_vogstatus);
        wachthond($extdebug,3, "new_vogaanvraag_actdatum",  $new_vogaanvraag_actdatum);
        wachthond($extdebug,3, "new_vogaanvraag_actstatus", $new_vogaanvraag_actstatus);
        wachthond($extdebug,3, "new_vogaanvraag_actprio",   $new_vogaanvraag_actprio);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 4.3 BEPAAL NEW STATUS ACTIVITEIT VOG ONTVANGST");
        wachthond($extdebug,2, "########################################################################");

        $intake_status_vogontvangst_array  = intake_status_vogontvangst($contact_id, $event_array, $intake_array, $groupID);
        wachthond($extdebug,2, "intake_status_vogontvangst_array",   $intake_status_vogontvangst_array);

        $new_cont_vogstatus         = $intake_status_vogontvangst_array['new_cont_vogstatus'];
        $new_part_vogstatus         = $intake_status_vogontvangst_array['new_part_vogstatus'];
        $new_vogontvangst_actdatum  = $intake_status_vogontvangst_array['new_vogontvangst_actdatum'];        
        $new_vogontvangst_actstatus = $intake_status_vogontvangst_array['new_vogontvangst_actstatus'];
        $new_vogontvangst_actprio   = $intake_status_vogontvangst_array['new_vogontvangst_actprio'];

        wachthond($extdebug,4, "new_cont_vogstatus",        $new_cont_vogstatus);
        wachthond($extdebug,4, "new_part_vogstatus",        $new_part_vogstatus);
        wachthond($extdebug,4, "new_vogontvangst_actdatum", $new_vogontvangst_actdatum);
        wachthond($extdebug,4, "new_vogontvangst_actstatus",$new_vogontvangst_actstatus);
        wachthond($extdebug,4, "new_vogontvangst_actprio",  $new_vogontvangst_actprio);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 5.0 UPDATE  ACTIVITIES", "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,3, 'event_fiscalyear', $ditevent_fiscalyear);

        $activity_array = array(
            'displayname'       => $displayname,
            'contact_id'        => $contact_id,
            'activity_source'   => 1, 
            'activity_target'   => $contact_id,
        );

        $intake_array = array(
            'grensvognoggoed'   => $grensvognoggoed,
            'vog_status'        => $new_cont_vogstatus,
            'vog_laatste'       => $new_cont_voglaatste,
            'vog_nodig'         => $new_cont_vognodig,
            'vog_verzoek'       => $new_cont_vogverzoek,
            'vog_aanvraag'      => $new_cont_vogaanvraag,
            'vog_datum'         => $new_cont_vogdatum,
        );

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 5.1 GET & UPDATE ACTIVITY",      "[VOGVERZOEK]");
        wachthond($extdebug,2, "########################################################################");

        if ($new_vogverzoek_actstatus) {

            $activity_array['activity_type_id']         = 118;              // vog_verzoek
            $activity_array['activity_type_naam']       = 'vog_verzoek';    // 118  [required]
            $activity_array['activity_subject']         = 'VOG aanvraag verzocht';            
            $activity_array['activity_date_time']       = $new_vogverzoek_actdatum;
            $activity_array['activity_status_name']     = $new_vogverzoek_actstatus;
            $activity_array['activity_prioriteit']      = $new_vogverzoek_actprio;

            wachthond($extdebug,3, 'intake_array',                      $intake_array);
            wachthond($extdebug,3, 'activity_array',                    $activity_array);
            wachthond($extdebug,3, 'new_vogverzoek_actstatus',          $new_vogverzoek_actstatus);

            // GET ACTIVITY
            $intake_activity_get  = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
            wachthond($extdebug,2, "intake_activity_get", $intake_activity_get);
            $vogverzoek_activity_id = $intake_activity_get['activity_id'] ?? NULL;

            // CREATE ACTIVITY
            if (empty($vogverzoek_activity_id)) {
                $vogverzoek_activity_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_create - vogverzoek", "[NEW ACTID: $vogverzoek_activity_id]");
            }

            // UPDATE ACTIVITY
            if ($vogverzoek_activity_id AND $new_vogverzoek_actstatus) {
                $activity_array['activity_id']          = $vogverzoek_activity_id;
                $intake_activity_update = intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_update - vogverzoek", "[NEW ACTID: $intake_activity_update]");
            }

            // DELETE ACTIVITY
            if ($vogverzoek_activity_id AND $new_cont_vognodig == 'noggoed') {
                $intake_activity_update = intake_activity_delete($contact_id, $vogverzoek_activity_id);
                wachthond($extdebug,3, "intake_activity_delete - vogverzoek", "[ACTID: $vogverzoek_activity_id]");
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 5.2 GET & UPDATE ACTIVITY",     "[VOGAANVRAAG]");
        wachthond($extdebug,2, "########################################################################");

        if ($new_vogaanvraag_actstatus) {

            $activity_array['activity_type_id']         = 119;              // vog_aanvraag
            $activity_array['activity_type_naam']       = 'vog_aanvraag';   // 119  [required]
            $activity_array['activity_subject']         = 'VOG aanvraag ingediend';
            $activity_array['activity_date_time']       = $new_vogaanvraag_actdatum;
            $activity_array['activity_status_name']     = $new_vogaanvraag_actstatus;
            $activity_array['activity_prioriteit']      = $new_vogaanvraag_actprio;

            wachthond($extdebug,3, 'intake_array',                      $intake_array);
            wachthond($extdebug,3, 'activity_array',                    $activity_array);
            wachthond($extdebug,3, 'new_vogaanvraag_actstatus',         $new_vogaanvraag_actstatus);

            // GET ACTIVITY
            $intake_activity_get  = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
            wachthond($extdebug,2, "intake_activity_get", $intake_activity_get);
            $vogaanvraag_activity_id = $intake_activity_get['activity_id'] ?? NULL;

            // CREATE ACTIVITY
            if (empty($vogaanvraag_activity_id)) {
                $vogaanvraag_activity_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_create - vogaanvraag", "[NEW ACTID: $vogaanvraag_activity_id]");
            }

            // UPDATE ACTIVITY
            if ($vogaanvraag_activity_id AND $new_vogaanvraag_actstatus) {
                $activity_array['activity_id']          = $vogaanvraag_activity_id;
                $intake_activity_update = intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_update - vogaanvraag", "[NEW ACTID: $intake_activity_update]");
            }

            // DELETE ACTIVITY
            if ($vogaanvraag_activity_id AND $new_cont_vognodig == 'noggoed') {
                $intake_activity_update = intake_activity_delete($contact_id, $vogaanvraag_activity_id);
                wachthond($extdebug,3, "intake_activity_delete - vogaanvraag", "[ACTID: $vogaanvraag_activity_id]");
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 5.3 GET & UPDATE ACTIVITY",    "[VOGONTVANGST]");
        wachthond($extdebug,2, "########################################################################");

        if ($new_vogontvangst_actstatus) {

            $activity_array['activity_type_id']         = 120;              // vog_ontvangst
            $activity_array['activity_type_naam']       = 'vog_ontvangst';  // 120  [required]
            $activity_array['activity_subject']         = 'VOG ontvangst bevestigd';
            $activity_array['activity_date_time']       = $new_vogontvangst_actdatum;
            $activity_array['activity_status_name']     = $new_vogontvangst_actstatus;
            $activity_array['activity_prioriteit']      = $new_vogontvangst_actprio;

            wachthond($extdebug,3, 'intake_array',                      $intake_array);
            wachthond($extdebug,3, 'activity_array',                    $activity_array);
            wachthond($extdebug,3, 'new_vogontvangst_actstatus',        $new_vogontvangst_actstatus);

            // GET ACTIVITY
            $intake_activity_get  = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
            wachthond($extdebug,2, "intake_activity_get", $intake_activity_get);
            $vogontvangst_activity_id = $intake_activity_get['activity_id'] ?? NULL;

            // CREATE ACTIVITY
            if (empty($vogontvangst_activity_id)) {
                $vogontvangst_activity_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_create - vogontvangst", "[NEW ACTID: $vogontvangst_activity_id]");
            }

            // UPDATE ACTIVITY
            if ($vogontvangst_activity_id AND $new_vogontvangst_actstatus) {
                $activity_array['activity_id']          = $vogontvangst_activity_id;
                $intake_activity_update = intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $groupID);
                wachthond($extdebug,3, "intake_activity_update - vogontvangst", "[ACTID: $intake_activity_update]");
            }

            // DELETE ACTIVITY
            if ($vogontvangst_activity_id AND $new_cont_vognodig == 'noggoed') {
                $intake_activity_update = intake_activity_delete($contact_id, $vogontvangst_activity_id);
                wachthond($extdebug,3, "intake_activity_delete - vogontvangst", "[ACTID: $vogontvangst_activity_id]");
            }

        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 6.0 UPDATE INTAKE PART",       "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        // Integers (moeten echte getallen zijn voor APIv4)
        $contact_id = (int)$contact_id;
        $part_id    = (int)$part_id;

        $params_part_update = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,         
            'reload'=> TRUE,
            'where' => [
                ['id',  '=', $part_id],
            ],
            'values' => [
                'id'     =>  $part_id, 
            ],
        ];

        if ($new_part_vognodig)     { $params_part_update['values']['PART_LEID_INTERN.VOG_nodig']   = $new_part_vognodig;   }
        if ($new_part_vogstatus)    { $params_part_update['values']['PART_LEID_INTERN.VOG_status']  = $new_part_vogstatus;  }

        if ($part_id) {
            wachthond($extdebug,2, 'params_part_update',                $params_part_update);
            $result_part_update = civicrm_api4('Participant','update',  $params_part_update);
            wachthond($extdebug,9, 'result_part_update',                $result_part_update);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 7.0 UPDATE INTAKE CONTACT",        "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        $params_cont_update = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,         
            'reload'=> TRUE,
            'where' => [
                ['id',  '=', $contact_id],
            ],
            'values' => [
                'id' => $contact_id, 
            ],
        ];

        if ($new_cont_vognodig)     { $params_cont_update['values']['INTAKE.VOG_nodig']     = $new_cont_vognodig;   }
        if ($new_cont_vogstatus)    { $params_cont_update['values']['INTAKE.VOG_status']    = $new_cont_vogstatus;  }
        if ($new_cont_vogdatum)     { $params_cont_update['values']['INTAKE.VOG_laatste']   = $new_cont_vogdatum;   }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (INTAKE)");
        wachthond($extdebug, 2, "########################################################################");

        // 1. Haal statussen op via de nieuwe centrale functie
        $status_data     = find_partstatus();
        $status_negative = $status_data['ids']['Negative'] ?? [];

        // 2. Check: Is de huidige status negatief? (En bestaat het ID wel?)
        if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       

            wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - CLEARING INTAKE FIELDS", $ditjaar_one_leid_status_id);

            $params_contact['values']['INTAKE.INT_nodig']     = "";
            $params_contact['values']['INTAKE.INT_status']    = "";
            $params_contact['values']['INTAKE.REF_nodig']     = "";
            $params_contact['values']['INTAKE.REF_status']    = "";
            $params_contact['values']['INTAKE.VOG_nodig']     = "";
            $params_contact['values']['INTAKE.VOG_status']    = "";
        }

        if ($contact_id) {
            wachthond($extdebug,2, 'params_cont_update',            $params_cont_update);
            $result_part_update = civicrm_api4('Contact','update',  $params_cont_update);
            wachthond($extdebug,9, 'result_cont_update',            $result_cont_update);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] 8.0 INJECTEER IN PART PARAMS", "[$displayname]");
        wachthond($extdebug,2, "########################################################################");

        // --- INTAKE PART VOG [PRE] 8.0 INJECTEER IN PART PARAMS ---
        $vog_updates = [
            $key_datum_vogverzoek  => $new_cont_vogverzoek,
            $key_datum_vogaanvraag => $new_cont_vogaanvraag,
            $key_datum_vognieuw    => $new_cont_vogdatum,
        ];

        foreach ($vog_updates as $key => $value) {
            if (is_numeric($key) && !empty($value)) {
                // De Smart Helper herkent 'Date' in de metadata en maakt de juiste string
                $params[$key]['value'] = format_civicrm_smart($value, $params[$key]['column_name']);
                wachthond($extdebug, 2, "VOG Param Inject", "Field: " . $params[$key]['column_name'] . " | Value: " . $params[$key]['value']);
            }
        }

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE PART VOG [PRE] EINDE",    "[$displayname | groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

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

    // --- NIEUW: STOP STOPWATCH EN LOG ---
    $intake_duur = number_format(microtime(TRUE) - $intake_start_tijd, 3);
    wachthond($extdebug, 1, "### TOTALE VERWERKINGSTIJD INTAKE MODULE: " . $intake_duur . " sec");
    wachthond($extdebug,1, "########################################################################");

    // --- VLAG WEER UITZETTEN ---
    $is_intake_bezig = false;

    // 3. SLOT WEER OPENEN (ALTIJD!)
    intake_recursion_lock(false);

}

function intake_activity_get($contact_id, $array_activity, $array_period) {

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    $activity_type_id   = $array_activity['activity_type_id']       ?? NULL;
    $activity_type_naam = $array_activity['activity_type_naam']     ?? NULL;

    $period_start       = $array_period['fiscalyear_start'];
    $period_einde       = $array_period['fiscalyear_einde'];

    wachthond($extdebug,3, "array_period",                                              $array_period);
    wachthond($extdebug,3, "period_start",                                              $period_start);
    wachthond($extdebug,3, "period_einde",                                              $period_einde);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE GET ACTIVITY $activity_type_naam",                   "[START]");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,2, "contact_id",                                                  $contact_id);
    wachthond($extdebug,2, "activity_type_id",                                      $activity_type_id);

    if (empty($contact_id) OR empty($activity_type_id)) {
        return;
    }

    $params_activity_get = [
        'checkPermissions' => FALSE,
        'debug' => $apidebug,       
        'select' => [
            'row_count',
            'id',
            'activity_date_time',
            'status_id',
            'status_id:name',
            'subject',
            'activity_contact.contact_id',

            'ACT_ALG.actcontact_naam',
            'ACT_ALG.actcontact_cid',
            'ACT_ALG.actcontact_pid',
            'ACT_ALG.actcontact_eid',

            'ACT_ALG.kampnaam',
            'ACT_ALG.kampkort',
            'ACT_ALG.kampfunctie',
            'ACT_ALG.kamprol',
            'ACT_ALG.kampstart',
            'ACT_ALG.kampeinde',
            'ACT_ALG.kampjaar',

            'ACT_REF.ref_nodig',
            'ACT_REF.aanvrager_naam',
            'ACT_REF.referentie_naam',
            'ACT_REF.relid',

            'ACT_VOG.vog_nodig',
            'ACT_VOG.datum_verzoek',
            'ACT_VOG.datum_reminder',
            'ACT_VOG.datum_aanvraag',
            'ACT_VOG.datum_ontvangst',

            'ACT_ALG.modified',
            'ACT_ALG.prioriteit:label',
            'priority_id:name',
        ],
        'join' => [
            ['ActivityContact AS activity_contact', 'INNER'],
        ],
        'where' => [
            ['activity_contact.contact_id',     '=',  $contact_id],
            ['activity_contact.record_type_id', '=',  3],
            ['activity_type_id',                '=',  $activity_type_id],
#           ['activity_type_id:name',           '=',  $activity_type_naam],
            ['activity_date_time',              '>=', $period_start],
            ['activity_date_time',              '<=', $period_einde],
            ['is_test',                         'IN', [true, false]]
        ],
    ];

//  $params_activity_get['select']['ACT_REF.aanvrager_naam']     = $vog_datum_verzocht;
//  $params_activity_get['select']['ACT_REF.referentie_naam']    = $vog_datum_ingediend;
//  $params_activity_get['select']['ACT_REF.relid']              = $vog_datum;

    wachthond($extdebug,7,'params_activity_get',            $params_activity_get);
    $result_activity_get = civicrm_api4('Activity', 'get',  $params_activity_get);
    $result_activity_count = $result_activity_get->countMatched();
    wachthond($extdebug,3,'result_activity_count',          $result_activity_count);
    wachthond($extdebug,9,'result_activity_get',            $result_activity_get);

    if ($result_activity_count == 1) {

        $activity_id            = $result_activity_get[0]['id']                         ?? NULL;
        $activity_status        = $result_activity_get[0]['status_id']                  ?? NULL;
        $activity_status_name   = $result_activity_get[0]['status_id:name']             ?? NULL;
        $activity_datum         = $result_activity_get[0]['activity_date_time']         ?? NULL;
        // Gebruik de first() safe check en forceer strings
        $activity_datum         = (string)($result_activity_get[0]['activity_date_time'] ?? '');

        $activity_kampkort      = $result_activity_get[0]['ACT_ALG.kampkort']           ?? NULL;
        $activity_kampfunctie   = $result_activity_get[0]['ACT_ALG.kampfunctie']        ?? NULL;
        $activity_kampstart     = $result_activity_get[0]['ACT_ALG.kampstart']          ?? NULL;
        $activity_kampjaar      = $result_activity_get[0]['ACT_ALG.kampjaar']           ?? NULL;

        $referentie_aanvrager   = $result_activity_get[0]['ACT_REF.aanvrager_naam']     ?? NULL;
        $referentie_relid       = $result_activity_get[0]['ACT_REF.relid']              ?? NULL;
        $referentie_refid       = $result_activity_get[0]['ACT_REF.referentie_cid']     ?? NULL;
        $referentie_naam        = $result_activity_get[0]['ACT_REF.referentie_naam']    ?? NULL;

        $vog_nodig              = $result_activity_get[0]['ACT_VOG.vog_nodig']          ?? NULL;
        $vog_verzoek            = $result_activity_get[0]['ACT_VOG.datum_verzoek']      ?? NULL;
        $vog_reminder           = $result_activity_get[0]['ACT_VOG.datum_reminder']     ?? NULL;
        $vog_aanvraag           = $result_activity_get[0]['ACT_VOG.datum_aanvraag']     ?? NULL;
        $vog_datum              = $result_activity_get[0]['ACT_VOG.datum_ontvangst']    ?? NULL;

        // Voor de custom velden (VOG/REF) ook forceren naar string
        $vog_verzoek            = (string)($result_activity_get[0]['ACT_VOG.datum_verzoek']     ?? '');
        $vog_datum              = (string)($result_activity_get[0]['ACT_VOG.datum_ontvangst']   ?? '');

        $activity_intake_array = array(

            'activity_type_naam'        => $activity_type_naam,
            'activity_type_id'          => $activity_type_id,
            'activity_count'            => $result_activity_count,

            'activity_id'               => $activity_id,
            'activity_status'           => $activity_status,
            'activity_status_name'      => $activity_status_name,
            'activity_datum'            => $activity_datum,

            'activity_kampkort'         => $activity_kampkort,
            'activity_kampfunctie'      => $activity_kampfunctie,
            'activity_kampstart'        => $activity_kampstart,
            'activity_kampjaar'         => $activity_kampjaar,
        );

        if (in_array($activity_type_naam, array('ref_persoon','ref_feedback'))) {

            $activity_intake_array['referentie_aanvrager']  = $referentie_aanvrager;
            $activity_intake_array['referentie_relid']      = $referentie_relid;
            $activity_intake_array['referentie_refid']      = $referentie_refid;
            $activity_intake_array['referentie_naam']       = $referentie_naam;
        }

        if (in_array($activity_type_naam, array('vog_verzoek','vog_aanvraag','vog_ontvangst'))) {

            $activity_intake_array['vog_nodig']             = $vog_nodig;
            $activity_intake_array['vog_verzoek']           = $vog_verzoek;
            $activity_intake_array['vog_reminder']          = $vog_reminder;
            $activity_intake_array['vog_aanvraag']          = $vog_aanvraag;
            $activity_intake_array['vog_datum']             = $vog_datum;
        }

        return $activity_intake_array;

    } else {
 
        $refpersoon_activity_id     = NULL;
        $refpersoon_activity_status = NULL;
        $refpersoon_activity_datum  = NULL;
        wachthond($extdebug,1, "result_activity_count voor $activity_type_naam", $result_activity_count);

        $activity_intake_array = array(
            'activity_type_name'        => $activity_type_naam,
            'activity_type_id'          => $activity_type_id,
            'activity_count'            => $result_activity_count,
        );

        return $activity_intake_array;

    }

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE GET ACTIVITY $activity_type_naam",                   "[EINDE]");
    wachthond($extdebug,3, "########################################################################");

    $running = false; // Aan het einde weer vrijgeven
}

function intake_activity_create($contact_id, $array_activity, $array_event, $array_intake, $groupID = NULL) {

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    $activity_type_naam  = $array_activity['activity_type_naam']                    ?? NULL;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE CREATE ACTIVITY $activity_type_naam", "[START] / groupID: $groupID");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,2, "contact_id",                                                  $contact_id);
    wachthond($extdebug,2, "array_activity",                                          $array_activity);
    wachthond($extdebug,2, "array_event",                                                $array_event);
    wachthond($extdebug,2, "array_intake",                                              $array_intake);

    if (empty($contact_id) OR empty($array_activity)) {
        return;
    }

    $today_datetime                 = date("Y-m-d H:i:s");
    $displayname                    = $array_activity['displayname']                ?? NULL;
    $contact_id                     = $array_activity['contact_id']                 ?? NULL;

    $activity_source                = $array_activity['activity_source']            ?? NULL; 
    $activity_target                = $array_activity['activity_target']            ?? NULL; 
    $activity_assignee              = $array_activity['activity_assignee']          ?? NULL; 

    $activity_type_id               = $array_activity['activity_type_id']           ?? NULL;
    $activity_type_naam             = $array_activity['activity_type_naam']         ?? NULL;
    $activity_datetime              = $array_activity['activity_date_time']         ?? NULL;
    $activity_subject               = $array_activity['activity_subject']           ?? NULL;
    $activity_status_name           = $array_activity['activity_status_name']       ?? NULL;
    $activity_prioriteit            = $array_activity['activity_prioriteit']        ?? NULL;

    $ditevent_part_id               = $array_event['ditevent_part_id']              ?? NULL;
    $ditevent_part_eventid          = $array_event['ditevent_part_eventid']         ?? NULL;
    $ditevent_leid_functie          = $array_event['ditevent_leid_functie']         ?? NULL;
    $ditevent_part_functie          = $array_event['ditevent_part_functie']         ?? NULL;
    $ditevent_part_rol              = $array_event['ditevent_part_rol']             ?? NULL;

    $ditevent_part_kampnaam         = $array_event['ditevent_part_kampnaam']        ?? NULL;
    $ditevent_part_kampkort         = $array_event['ditevent_part_kampkort']        ?? NULL;

    $ditevent_part_kampkort_low     = $array_event['ditevent_part_kampkort_low']    ?? NULL;
    $ditevent_part_kampkort_cap     = $array_event['ditevent_part_kampkort_cap']    ?? NULL;

    $ditevent_part_kampstart        = $array_event['ditevent_part_kampstart']       ?? NULL;
    $ditevent_part_kampeinde        = $array_event['ditevent_part_kampeinde']       ?? NULL;
    $ditevent_part_kampjaar         = $array_event['ditevent_part_kampjaar']        ?? NULL;

    $params_activity_create = [
        'checkPermissions' => FALSE,
        'debug'  => $apidebug,
        'values' => [
            'source_contact_id'         => $activity_source, 
            'target_contact_id'         => $activity_target,
            'assignee_contact_id'       => $activity_assignee,
            'activity_type_id'          => $activity_type_id,
#           'activity_type_id:name'     => $activity_type_naam,
            'activity_date_time'        => $activity_datetime,
            'subject'                   => $activity_subject, 
            'status_id:name'            => $activity_status_name,

            'ACT_ALG.actcontact_naam'   => $displayname,
            'ACT_ALG.actcontact_cid'    => $contact_id,
            'ACT_ALG.actcontact_pid'    => $ditevent_part_id,
            'ACT_ALG.actcontact_eid'    => $ditevent_part_eventid,
            'ACT_ALG.kampfunctie'       => $ditevent_part_functie,
            'ACT_ALG.kamprol'           => $ditevent_part_rol,

            'ACT_ALG.kampnaam'          => $ditevent_part_kampkort_cap,
            'ACT_ALG.kampkort'          => $ditevent_part_kampkort_low,
            'ACT_ALG.kampstart'         => $ditevent_part_kampstart,
            'ACT_ALG.kampeinde'         => $ditevent_part_kampeinde,
            'ACT_ALG.kampjaar'          => $ditevent_part_kampjaar,

            'ACT_ALG.modified'          => $today_datetime,
            'ACT_ALG.prioriteit:label'  => $activity_prioriteit,
        ],
    ];

    if (in_array($activity_type_naam, array('ref_persoon','ref_feedback'))) {

        $ref_relid                      = $array_referentie['ref_rel_id']                   ?? NULL;
        $ref_nodig                      = $array_referentie['ref_nodig']                    ?? NULL;

        $ref_aanvrager_cid              = $array_referentie['ref_aanvrager_cid']            ?? NULL;
        $ref_aanvrager_naam             = $array_referentie['ref_aanvrager_naam']           ?? NULL;
        $ref_aanvrager_voornaam         = $array_referentie['ref_aanvrager_voornaam']       ?? NULL;
        $ref_aanvrager_functie          = $array_referentie['ref_aanvrager_functie']        ?? NULL;

        $ref_referentie_cid             = $array_referentie['ref_referentie_cid']           ?? NULL;
        $ref_referentie_naam            = $array_referentie['ref_referentie_naam']          ?? NULL;
        $ref_referentie_voornaam        = $array_referentie['ref_referentie_voornaam']      ?? NULL;
        $ref_referentie_telefoon        = $array_referentie['ref_referentie_telefoon']      ?? NULL;

        $ref_referentie_relatie         = $array_referentie['ref_referentie_relatie']       ?? NULL;
        $ref_referentie_motivatie       = $array_referentie['ref_referentie_motivatie']     ?? NULL;

        $ref_datum_gevraagd             = $array_intake['ref_datum_gevraagd']               ?? NULL;
        $ref_datum_feedback             = $array_intake['ref_datum_feedback']               ?? NULL;
        $ref_bezwaar                    = $array_intake['ref_bezwaar']                      ?? NULL;

        if ($ref_relid)                 { $params_activity_create['values']['ACT_REF.rel_id']               = $ref_relid;                   }
        if ($ref_nodig)                 { $params_activity_create['values']['ACT_REF.ref_nodig']            = $ref_nodig;                   }
        if ($ref_aanvrager_cid)         { $params_activity_create['values']['ACT_REF.aanvrager_cid']        = $ref_aanvrager_cid;           }
        if ($ref_aanvrager_naam)        { $params_activity_create['values']['ACT_REF.aanvrager_naam']       = $ref_aanvrager_naam;          }
        if ($ref_aanvrager_voornaam)    { $params_activity_create['values']['ACT_REF.aanvrager_voornaam']   = $ref_aanvrager_voornaam;      }
        if ($ref_aanvrager_functie)     { $params_activity_create['values']['ACT_REF.aanvrager_functie']    = $ref_aanvrager_functie;       }
        if ($ref_referentie_cid)        { $params_activity_create['values']['ACT_REF.referentie_cid']       = $ref_referentie_cid;          }
        if ($ref_referentie_naam)       { $params_activity_create['values']['ACT_REF.referentie_naam']      = $ref_referentie_naam;         }
        if ($ref_referentie_voornaam)   { $params_activity_create['values']['ACT_REF.referentie_voornaam']  = $ref_referentie_voornaam;     }
        if ($ref_referentie_telefoon)   { $params_activity_create['values']['ACT_REF.referentie_telefoon']  = $ref_referentie_telefoon;     }
        if ($ref_referentie_relatie)    { $params_activity_create['values']['ACT_REF.referentie_relatie']   = $ref_referentie_relatie;      }
        if ($ref_referentie_motivatie)  { $params_activity_create['values']['ACT_REF.referentie_motivatie'] = $ref_referentie_motivatie;    }
        if ($ref_datum_gevraagd)        { $params_activity_create['values']['ACT_REF.referentie_verzoek']   = $ref_datum_gevraagd;          }
        if ($ref_datum_feedback)        { $params_activity_create['values']['ACT_REF.referentie_feedback']  = $ref_datum_feedback;          }
        if ($ref_bezwaar)               { $params_activity_create['values']['ACT_REF.referentie_bezwaar']   = $ref_bezwaar;                 }

        $params_activity_create['values']['ACT_REF.referentie_verzoek']   = $ref_datum_gevraagd;

    }

    if (in_array($activity_type_naam, array('vog_verzoek','vog_aanvraag','vog_ontvangst'))) {

        $params_activity_create['values']['assignee_contact_id']    = NULL;

        $vog_nodig              = $array_intake['vog_nodig']        ?? NULL;
        $vog_verzoek            = $array_intake['vog_verzoek']      ?? '';
        $vog_reminder           = $array_intake['vog_reminder']     ?? '';
        $vog_aanvraag           = $array_intake['vog_aanvraag']     ?? '';
        $vog_datum              = $array_intake['vog_datum']        ?? '';

        if ($vog_nodig)         { $params_activity_create['values']['ACT_VOG.vog_nodig']            = $vog_nodig;       }
        if ($vog_verzoek)       { $params_activity_create['values']['ACT_VOG.datum_verzoek']        = $vog_verzoek;     }
        if ($vog_aanvraag)      { $params_activity_create['values']['ACT_VOG.datum_aanvraag']       = $vog_aanvraag;    }
        if ($vog_reminder)      { $params_activity_create['values']['ACT_VOG.datum_reminder']       = $vog_reminder;    }
        if ($vog_datum)         { $params_activity_create['values']['ACT_VOG.datum_ontvangst']      = $vog_datum;       }
    }

    wachthond($extdebug,3, "params_activity_create",                                    $params_activity_create);
    $result_activity_create = civicrm_api4("Activity", "create",                        $params_activity_create);
    wachthond($extdebug,3, "params_activity_create $activity_type_naam",                "EXECUTED");
    wachthond($extdebug,7, "params_activity_create $activity_type_naam RESULT",         $result_activity_create);
    wachthond($extdebug,7, "params_activity_create $activity_type_naam RESULTS 0",      $result_activity_create[0]);
    wachthond($extdebug,7, "params_activity_create $activity_type_naam RESULTS 0 ID",   $result_activity_create[0]['id']);
    if (empty($activity_id))         { $activity_id       = $result_activity_create[0]['id']        ?? NULL; }
    if (empty($activity_status))     { $activity_status   = $result_activity_create[0]['status_id'] ?? NULL; }
    wachthond($extdebug,2, "(nieuwe) activity_id",       $activity_id);
    wachthond($extdebug,2, "(nieuwe) activity_status",   $activity_status);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE CREATE ACTIVITY $activity_type_naam",                "[EINDE]");
    wachthond($extdebug,1, "########################################################################");

    return $activity_id;
}

function intake_activity_update($contact_id, $array_activity, $array_event, $array_intake, $array_referentie, $groupID = NULL) {

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    $activity_type_naam             = $array_activity['activity_type_naam']         ?? NULL;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE UPDATE ACTIVITY $activity_type_naam", "[START] / groupID: $groupID");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,2, "contact_id",                                                  $contact_id);
    wachthond($extdebug,2, "array_activity",                                          $array_activity);
    wachthond($extdebug,2, "array_event",                                                $array_event);
    wachthond($extdebug,2, "array_intake",                                              $array_intake);

    if (empty($contact_id) OR empty($array_activity)) {
        wachthond($extdebug,2, "contact_id",                                               $contact_id);
        wachthond($extdebug,2, "array_activity",                                       $array_activity);
        wachthond($extdebug,2, "array_event",                                             $array_event);
        wachthond($extdebug,2, "array_intake",                                           $array_intake);
        return;
    }

    $today_datetime                 = date("Y-m-d H:i:s");
    $displayname                    = $array_activity['displayname']                ?? NULL;
    $contact_id                     = $array_activity['contact_id']                 ?? NULL;

    $activity_id                    = $array_activity['activity_id']                ?? NULL;

    $activity_source                = $array_activity['activity_source']            ?? NULL;
    $activity_target                = $array_activity['activity_target']            ?? NULL;
    $activity_assignee              = $array_activity['activity_assignee']          ?? NULL;

    $activity_type_id               = $array_activity['activity_type_id']           ?? NULL;
    $activity_type_naam             = $array_activity['activity_type_naam']         ?? NULL;
    $activity_datetime              = $array_activity['activity_date_time']         ?? NULL;
    $activity_subject               = $array_activity['activity_subject']           ?? NULL;
    $activity_status_name           = $array_activity['activity_status_name']       ?? NULL;
    $activity_prioriteit            = $array_activity['activity_prioriteit']        ?? NULL;

    $ditevent_part_id               = $array_event['id']                            ?? NULL;
    $ditevent_part_eventid          = $array_event['part_event_id']                 ?? NULL;
    $ditevent_part_functie          = $array_event['part_functie']                  ?? NULL;
    $ditevent_part_rol              = $array_event['part_rol']                      ?? NULL;

    $ditevent_part_kamptype_id      = $array_event['part_kamptype_id']              ?? NULL;

    $ditevent_event_type_id         = $array_event['event_type_id']                 ?? NULL;
    $ditevent_part_kampnaam         = $array_event['part_kampnaam']                 ?? NULL;
    $ditevent_part_kampkort         = $array_event['part_kampkort']                 ?? NULL;
    $ditevent_part_kampkort_low     = $array_event['part_kampkort_low']             ?? NULL;
    $ditevent_part_kampkort_cap     = $array_event['part_kampkort_cap']             ?? NULL;

    $ditevent_part_kampstart        = $array_event['part_kampstart']                ?? NULL;
    $ditevent_part_kampeinde        = $array_event['part_kampeinde']                ?? NULL;
    $ditevent_part_kampjaar         = $array_event['part_kampjaar']                 ?? NULL;

    $params_activity_update = [
        'checkPermissions' => FALSE,
        'debug' => $apidebug,
        'where' => [
            ['id',       '=',$activity_id],
        ],
        'values' => [
            'id'          => $activity_id,
        ],
    ];

    if ($activity_type_id)              { $params_activity_update['values']['activity_type_id']             = $activity_type_id;            }
    if ($activity_type_naam)            { $params_activity_update['values']['activity_type_naam']           = $activity_type_naam;          }

    if ($activity_source)               { $params_activity_update['values']['source_contact_id']            = $activity_source;             }
    if ($activity_target)               { $params_activity_update['values']['target_contact_id']            = $activity_target;             }
    if ($activity_assignee)             { $params_activity_update['values']['assignee_contact_id']          = $activity_assignee;           }

    if ($activity_datetime)             { $params_activity_update['values']['activity_date_time']           = $activity_datetime;           }
    if ($activity_subject)              { $params_activity_update['values']['subject']                      = $activity_subject;            }
    if ($activity_status_name)          { $params_activity_update['values']['status_id:name']               = $activity_status_name;        }

    if ($activity_prioriteit)           { $params_activity_update['values']['priority_id:name']             = $activity_prioriteit;         }
    if ($activity_prioriteit)           { $params_activity_update['values']['ACT_ALG.prioriteit:label']     = $activity_prioriteit;         }

    if ($activity_id)                   { $params_activity_update['values']['ACT_ALG.activity_id']          = $activity_id;                 }
    if ($contact_id)                    { $params_activity_update['values']['ACT_ALG.actcontact_cid']       = $contact_id;                  }
    if ($displayname)                   { $params_activity_update['values']['ACT_ALG.actcontact_naam']      = $displayname;                 }
    if ($ditevent_part_id)              { $params_activity_update['values']['ACT_ALG.actcontact_pid']       = $ditevent_part_id;            }
    if ($ditevent_part_eventid)         { $params_activity_update['values']['ACT_ALG.actcontact_eid']       = $ditevent_part_eventid;       }
    if ($ditevent_part_functie)         { $params_activity_update['values']['ACT_ALG.kampfunctie']          = $ditevent_part_functie;       }
    if ($ditevent_part_rol)             { $params_activity_update['values']['ACT_ALG.kamprol']              = $ditevent_part_rol;           }

    if ($ditevent_part_kamptype_id)     { $params_activity_update['values']['ACT_ALG.kamptype_nr']          = $ditevent_part_kamptype_id;   }
    if ($ditevent_part_kampkort_cap)    { $params_activity_update['values']['ACT_ALG.kampnaam']             = $ditevent_part_kampkort_cap;  }
    if ($ditevent_part_kampkort_low)    { $params_activity_update['values']['ACT_ALG.kampkort']             = $ditevent_part_kampkort_low;  }
    if ($ditevent_part_kampstart)       { $params_activity_update['values']['ACT_ALG.kampstart']            = $ditevent_part_kampstart;     }
    if ($ditevent_part_kampeinde)       { $params_activity_update['values']['ACT_ALG.kampeinde']            = $ditevent_part_kampeinde;     }
    if ($ditevent_part_kampjaar)        { $params_activity_update['values']['ACT_ALG.kampjaar']             = $ditevent_part_kampjaar;      }

    wachthond($extdebug,2, "activity_type_naam",    $activity_type_naam);

    if (in_array($activity_type_naam, array('ref_persoon','ref_feedback'))) {

        $ref_relid                      = $array_referentie['ref_rel_id']                   ?? NULL;
        $ref_nodig                      = $array_referentie['ref_nodig']                    ?? NULL;

        $ref_aanvrager_cid              = $array_referentie['ref_aanvrager_cid']            ?? NULL;
        $ref_aanvrager_naam             = $array_referentie['ref_aanvrager_naam']           ?? NULL;
        $ref_aanvrager_voornaam         = $array_referentie['ref_aanvrager_voornaam']       ?? NULL;
        $ref_aanvrager_functie          = $array_referentie['ref_aanvrager_functie']        ?? NULL;

        $ref_referentie_cid             = $array_referentie['ref_referentie_cid']           ?? NULL;
        $ref_referentie_naam            = $array_referentie['ref_referentie_naam']          ?? NULL;
        $ref_referentie_voornaam        = $array_referentie['ref_referentie_voornaam']      ?? NULL;
        $ref_referentie_telefoon        = $array_referentie['ref_referentie_telefoon']      ?? NULL;

        $ref_referentie_relatie         = $array_referentie['ref_referentie_relatie']       ?? NULL;
        $ref_referentie_motivatie       = $array_referentie['ref_referentie_motivatie']     ?? NULL;

        $ref_datum_gevraagd             = $array_intake['ref_datum_gevraagd']               ?? NULL;
        $ref_datum_feedback             = $array_intake['ref_datum_feedback']               ?? NULL;
        $ref_bezwaar                    = $array_intake['ref_bezwaar']                      ?? NULL;

        if ($ref_relid)                 { $params_activity_update['values']['ACT_REF.rel_id']               = $ref_relid;                   }
        if ($ref_nodig)                 { $params_activity_update['values']['ACT_REF.ref_nodig']            = $ref_nodig;                   }
        if ($ref_aanvrager_cid)         { $params_activity_update['values']['ACT_REF.aanvrager_cid']        = $ref_aanvrager_cid;           }
        if ($ref_aanvrager_naam)        { $params_activity_update['values']['ACT_REF.aanvrager_naam']       = $ref_aanvrager_naam;          }
        if ($ref_aanvrager_voornaam)    { $params_activity_update['values']['ACT_REF.aanvrager_voornaam']   = $ref_aanvrager_voornaam;      }
        if ($ref_aanvrager_functie)     { $params_activity_update['values']['ACT_REF.aanvrager_functie']    = $ref_aanvrager_functie;       }
        if ($ref_referentie_cid)        { $params_activity_update['values']['ACT_REF.referentie_cid']       = $ref_referentie_cid;          }
        if ($ref_referentie_naam)       { $params_activity_update['values']['ACT_REF.referentie_naam']      = $ref_referentie_naam;         }
        if ($ref_referentie_voornaam)   { $params_activity_update['values']['ACT_REF.referentie_voornaam']  = $ref_referentie_voornaam;     }
        if ($ref_referentie_telefoon)   { $params_activity_update['values']['ACT_REF.referentie_telefoon']  = $ref_referentie_telefoon;     }
        if ($ref_referentie_relatie)    { $params_activity_update['values']['ACT_REF.referentie_relatie']   = $ref_referentie_relatie;      }
        if ($ref_referentie_motivatie)  { $params_activity_update['values']['ACT_REF.referentie_motivatie'] = $ref_referentie_motivatie;    }
        if ($ref_datum_gevraagd)        { $params_activity_update['values']['ACT_REF.referentie_verzoek']   = $ref_datum_gevraagd;          }
        if ($ref_datum_feedback)        { $params_activity_update['values']['ACT_REF.referentie_feedback']  = $ref_datum_feedback;          }
        if ($ref_bezwaar)               { $params_activity_update['values']['ACT_REF.referentie_bezwaar']   = $ref_bezwaar;                 }

        $params_activity_update['values']['ACT_REF.referentie_verzoek']   = $ref_datum_gevraagd;

    }

    if (in_array($activity_type_naam, array('vog_verzoek','vog_aanvraag','vog_ontvangst'))) {

        $params_activity_update['values']['assignee_contact_id']    = NULL;

        $vog_nodig              = $array_intake['vog_nodig']        ?? NULL;
        $vog_verzoek            = $array_intake['vog_verzoek']      ?? '';
        $vog_reminder           = $array_intake['vog_reminder']     ?? '';
        $vog_aanvraag           = $array_intake['vog_aanvraag']     ?? '';
        $vog_datum              = $array_intake['vog_datum']        ?? '';

        if ($vog_nodig)         { $params_activity_update['values']['ACT_VOG.vog_nodig']            = $vog_nodig;       }
        if ($vog_verzoek)       { $params_activity_update['values']['ACT_VOG.datum_verzoek']        = $vog_verzoek;     }
        if ($vog_aanvraag)      { $params_activity_update['values']['ACT_VOG.datum_aanvraag']       = $vog_aanvraag;    }
        if ($vog_reminder)      { $params_activity_update['values']['ACT_VOG.datum_reminder']       = $vog_reminder;    }
        if ($vog_datum)         { $params_activity_update['values']['ACT_VOG.datum_ontvangst']      = $vog_datum;       }
    }

    wachthond($extdebug,3, "params_activity_update",                                    $params_activity_update);
    $result_activity_update = civicrm_api4("Activity", "update",                        $params_activity_update);
    wachthond($extdebug,3, "params_activity_update $activity_type_naam",                "EXECUTED");
    wachthond($extdebug,7, "params_activity_update $activity_type_naam RESULT",         $result_activity_update);
    wachthond($extdebug,7, "params_activity_update $activity_type_naam RESULTS 0",      $result_activity_update[0]);
    wachthond($extdebug,7, "params_activity_update $activity_type_naam RESULTS 0 ID",   $result_activity_update[0]['id']);
    if (empty($activity_id))         { $activity_id       = $result_activity_update[0]['id']        ?? NULL; }
    if (empty($activity_status))     { $activity_status   = $result_activity_update[0]['status_id'] ?? NULL; }
    wachthond($extdebug,2, "(nieuwe) activity_id",       $activity_id);
    wachthond($extdebug,2, "(nieuwe) activity_status",   $activity_status);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### INTAKE UPDATE ACTIVITY $activity_type_naam",                "[EINDE]");
    wachthond($extdebug,1, "########################################################################");

    return $activity_id;
}

function intake_activity_delete($contact_id, $activity_id) {

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### INTAKE DELETE ACTIVITY $activity_id",                       "[START]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, "contact_id",                                                  $contact_id);
    wachthond($extdebug,3, "activity_id",                                                $activity_id);

    if (empty($contact_id) OR empty($activity_id)) {
        return;
    }

    $params_activity_delete = [
        'checkPermissions' => FALSE,
        'debug' => $apidebug,
        'where' => [
            ['id', '=', $activity_id],
        ],
    ];
    $result_activity_delete = civicrm_api4('Activity', 'delete', $params_activity_delete);
    wachthond($extdebug,1, "ACTIVITY VERWIJDERD", "activity_id: $activity_id");

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### INTAKE DELETE ACTIVITY $activity_id",                       "[EINDE]");
    wachthond($extdebug,3, "########################################################################");

    return $result_activity_delete;
}

function intake_activitytype_delete($contact_id, $activity_type_name, $periodstart = NULL, $periodeinde = NULL) {

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE DELETE ACTIVITY $activity_type_name",                "[START]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,2, "contact_id",                                                  $contact_id);
    wachthond($extdebug,2, "activity_type_name",                                  $activity_type_name);

    if (empty($contact_id) OR empty($activity_type_id)) {
        return;
    }

    $params_activity_delete = [
        'checkPermissions' => FALSE,
        'debug' => $apidebug,
        'where' => [
            ['activity_contact.contact_id', '=',  $contact_id],
            ['activity_type_id:name',       '=',  $activity_type_name],
            ['activity_date_time',          '>=', $periodstart],
            ['activity_date_time',          '<=', $periodeinde],
        ],
    ];
    $result_activity_delete = civicrm_api4('Activity', 'delete', $params_activity_delete);
    wachthond($extdebug,1, "ACTIVITY_TYPE VERWIJDERD", "activity_type: $activity_type_name");

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE DELETE ACTIVITY_TYPE $activity_type_name",           "[EINDE]");
    wachthond($extdebug,1, "########################################################################");

    return $result_activity_delete;
}

function intake_status_refpersoon($contact_id, $array_event, $consolidate_refdata_array, $refnodig, $groupID) {

    $extdebug       = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    $displayname                = $array_event['displayname']           ?? NULL;
    $ditevent_part_kampkort     = $array_event['part_kampkort']         ?? NULL;

    $ditjaar_one_leid_kampkort  = $array_allpart_ditjaar['result_allpart_one_leid_kampkort']    ?? NULL;
    $ditjaar_pos_leid_kampkort  = $array_allpart_ditjaar['result_allpart_pos_leid_kampkort']    ?? NULL;

    if (empty($contact_id)) {
        return;
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - BEPAAL STATUS REF PERSOON VOOR $displayname", "[$ditevent_part_kampkort]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,1, "groupID",                                                        $groupID);
    wachthond($extdebug,4, "contact_id",                                                  $contact_id);
    wachthond($extdebug,3, "array_event",                                                $array_event);
    wachthond($extdebug,3, "consolidate_refdata_array",                    $consolidate_refdata_array);
    wachthond($extdebug,3, "refnodig",                                                      $refnodig);

    if ($array_allpart_ditjaar) {

        $ditjaar_one_leid_count         = $array_allpart_ditjaar['result_allpart_one_leid_count'];
        $ditjaar_one_leid_part_id       = $array_allpart_ditjaar['result_allpart_one_leid_part_id'];
        $ditjaar_one_leid_event_id      = $array_allpart_ditjaar['result_allpart_one_leid_event_id'];
        $ditjaar_one_leid_status_id     = $array_allpart_ditjaar['result_allpart_one_leid_status_id'];
        $ditjaar_one_leid_kampfunctie   = $array_allpart_ditjaar['result_allpart_one_leid_kampfunctie'];
        $ditjaar_one_leid_kampkort      = $array_allpart_ditjaar['result_allpart_one_leid_kampkort'];

        wachthond($extdebug,3, 'ditjaar_one_leid_count',        $ditjaar_one_leid_count);
        wachthond($extdebug,3, 'ditjaar_one_leid_part_id',      $ditjaar_one_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_event_id',     $ditjaar_one_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_status_id',    $ditjaar_one_leid_status_id);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampfunctie',  $ditjaar_one_leid_kampfunctie);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampkort',     $ditjaar_one_leid_kampkort);

        $ditjaar_pos_leid_count         = $array_allpart_ditjaar['result_allpart_pos_leid_count'];
        $ditjaar_pos_leid_part_id       = $array_allpart_ditjaar['result_allpart_pos_leid_part_id'];
        $ditjaar_pos_leid_event_id      = $array_allpart_ditjaar['result_allpart_pos_leid_event_id'];
        $ditjaar_pos_leid_status_id     = $array_allpart_ditjaar['result_allpart_pos_leid_status_id'];
        $ditjaar_pos_leid_kampfunctie   = $array_allpart_ditjaar['result_allpart_pos_leid_kampfunctie'];
        $ditjaar_pos_leid_kampkort      = $array_allpart_ditjaar['result_allpart_pos_leid_kampkort'];

        wachthond($extdebug,3, 'ditjaar_pos_leid_count',        $ditjaar_pos_leid_count);
        wachthond($extdebug,3, 'ditjaar_pos_leid_part_id',      $ditjaar_pos_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_event_id',     $ditjaar_pos_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_status_id',    $ditjaar_pos_leid_status_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_kampfunctie',  $ditjaar_pos_leid_kampfunctie);
        wachthond($extdebug,3, 'ditjaar_pos_leid_kampkort',     $ditjaar_pos_leid_kampkort);
    }

    if ($array_event) {

        $ditevent_part_id               = $array_event['id']                    ?? NULL;
        $ditevent_part_eventid          = $array_event['part_event_id']         ?? NULL;
        $ditevent_part_status_id        = $array_event['status_id']             ?? NULL;
        $ditevent_part_functie          = $array_event['part_functie']          ?? NULL;
        $ditevent_part_rol              = $array_event['part_rol']              ?? NULL;

        $ditevent_part_kampnaam         = $array_event['part_kampnaam']         ?? NULL;
        $ditevent_part_kampkort         = $array_event['part_kampkort']         ?? NULL;
        $ditevent_part_kampstart        = $array_event['part_kampstart']        ?? NULL;
        $ditevent_part_kampeinde        = $array_event['part_kampeinde']        ?? NULL;

        $ditevent_part_vogverzoek       = $array_event['part_vogverzoek']       ?? NULL;
    }

    if ($consolidate_refdata_array) {

        $ref_status             = $consolidate_refdata_array['new_cont_refstatus'];
        $ref_laatste            = $consolidate_refdata_array['new_cont_reflaatste'];
        $ref_persoon            = $consolidate_refdata_array['new_cont_refpersoon'];
        $ref_verzoek            = $consolidate_refdata_array['new_cont_refverzoek'];
        $ref_datum              = $consolidate_refdata_array['new_cont_refdatum'];
    }

    $ref_nodig                  = $refnodig;
    $vog_verzoek                = $ditevent_part_vogverzoek;

    wachthond($extdebug,3, 'vog_verzoek',       $vog_verzoek);
    wachthond($extdebug,3, 'ref_nodig',         $ref_nodig);
    wachthond($extdebug,3, 'ref_status',        $ref_status);
    wachthond($extdebug,3, 'ref_laatste',       $ref_laatste);
    wachthond($extdebug,3, 'ref_persoon',       $ref_persoon);
    wachthond($extdebug,3, 'ref_verzoek',       $ref_verzoek);
    wachthond($extdebug,3, 'ref_datum',         $ref_datum);

    $new_refpersoon_actstatus           = 'Scheduled';    // DEFAULT TO AVAILABLE (CONCEPT)
    $new_refpersoon_actprio             = 'Normaal';      // DEFAULT TO NORMAL

    $reflaatste_binnengrens              = date_biggerequal($ref_laatste,        $grensrefnoggoed,   'reflaatste',    'grensref');
    $reflaatste_buitengrens              = date_biggerequal($grensrefnoggoed,    $ref_laatste,       'grensref',     'reflaatste');
    wachthond($extdebug,3, "reflaatste_binnengrens",             "$reflaatste_binnengrens\t[reflaatste: $ref_laatste]");
    wachthond($extdebug,3, "reflaatste_buitengrens",             "$reflaatste_buitengrens\t[reflaatste: $ref_laatste]");

    $refpersoon_binnengrens             = date_biggerequal($ref_persoon,        $grensrefnoggoed,   'refpersoon',   'grensref');
    $refpersoon_buitengrens             = date_biggerequal($grensrefnoggoed,    $ref_persoon,       'grensref',     'refpersoon');
    wachthond($extdebug,3, "refpersoon_binnengrens",             "$refpersoon_binnengrens\t[refpersoon: $ref_persoon]");
    wachthond($extdebug,3, "refpersoon_buitengrens",             "$refpersoon_buitengrens\t[refpersoon: $ref_persoon]");    

    $refpersoon_infiscalyear_ditevent   = infiscalyear($ref_persoon,    $ditevent_part_kampstart,   'refpersoon',   'ditevent') ?? 0;
    $refverzoek_infiscalyear_ditevent   = infiscalyear($ref_verzoek,    $ditevent_part_kampstart,   'refverzoek',   'ditevent') ?? 0;
    $refdatum_infiscalyear_ditevent     = infiscalyear($ref_datum,      $ditevent_part_kampstart,   'refdatum',     'ditevent') ?? 0;
    wachthond($extdebug,3, "refpersoon_infiscalyear_ditevent",  $refpersoon_infiscalyear_ditevent);
    wachthond($extdebug,3, "refpersoon_infiscalyear_ditevent",  $refpersoon_infiscalyear_ditevent);
    wachthond($extdebug,3, "refdatum_infiscalyear_ditevent",    $refdatum_infiscalyear_ditevent);

    $reflaatste_infiscalyear_ditjaar    = infiscalyear($ref_laatste,    $today_datetime,            'reflaatste',   'ditjaar')  ?? 0;
    $refpersoon_infiscalyear_ditjaar    = infiscalyear($ref_persoon,    $today_datetime,            'refpersoon',   'ditjaar')  ?? 0;
    $refverzoek_infiscalyear_ditjaar    = infiscalyear($ref_verzoek,    $today_datetime,            'refpersoon',   'ditjaar')  ?? 0;
    $refdatum_infiscalyear_ditjaar      = infiscalyear($ref_datum,      $today_datetime,            'refdatum',     'ditjaar')  ?? 0;

    wachthond($extdebug,3, "reflaatste_infiscalyear_ditjaar",   $reflaatste_infiscalyear_ditjaar);
    wachthond($extdebug,3, "refpersoon_infiscalyear_ditjaar",   $refpersoon_infiscalyear_ditjaar);
    wachthond($extdebug,3, "refverzoek_infiscalyear_ditjaar",   $refverzoek_infiscalyear_ditjaar);
    wachthond($extdebug,3, "refdatum_infiscalyear_ditjaar",     $refdatum_infiscalyear_ditjaar);

    wachthond($extdebug,2, "0 new_refpersoon_actstatus",        $new_refpersoon_actstatus);
    wachthond($extdebug,2, "0 new_refpersoon_actprio",          $new_refpersoon_actprio);

    // REFRECENT NIET IN DIT JAAR, WEL BINNEN GRENSNOGGOED
    if ($reflaatste_binnengrens == 1 AND $reflaatste_infiscalyear_ditjaar == 0) {
        $new_cont_refstatus  = 'noggoed';
    }
    // REFRECENT VALT BUITEN GRENS NOGGOED
    if ($reflaatste_buitengrens == 1 OR empty($ref_laatste)) {
        $new_cont_refstatus  = 'onbekend';
    }
    // REFRECENT DIT JAAR AANGEVRAAGD EN ONTVANGEN
    if ($reflaatste_infiscalyear_ditjaar == 1) {
        $new_cont_refstatus  = 'ontvangen';
    }

    wachthond($extdebug,3, "new_cont_refstatus",         $new_cont_refstatus);
    wachthond($extdebug,3, "new_part_refstatus",         $new_part_refstatus);

    if (in_array($ref_nodig, array("noggoed"))) {

        $new_cont_refstatus         = 'noggoed';
        $new_refpersoon_actstatus   = 'Not Required';

    } else {

        $diffsince_vogverzoek       = NULL;
        $dayssince_vogverzoek       = NULL;
        $diffsince_vogverzoek       = date_diff(date_create($vog_verzoek),date_create($today_datetime));
        $dayssince_vogverzoek       = $diffsince_vogverzoek->format('%a');  
        wachthond($extdebug,2, "- dayssince_vogverzoek", $dayssince_vogverzoek);

        // IF REF FEEDBACK IS ONTVANGEN
        if ($refdatum_infiscalyear_ditjaar == 1) {
            $new_cont_refstatus     = 'ontvangen';
                                                                              $new_refpersoon_actstatus = 'Completed';
        // IF PERSOON IS DOORGEGEVEN
        } elseif ($refpersoon_infiscalyear_ditjaar == 1) {
            $new_cont_refstatus     = 'vragen';
                                                                              $new_refpersoon_actstatus = 'Completed';
        // IF EVENT INMIDDELS VOORBIJ IS
        } elseif (date_bigger($today_datetime, $ditevent_part_kampeinde)) {
                                                                              $new_refpersoon_actstatus = "Failed";          // NA EVENT
                                                                              $new_cont_refstatus       = 'verlopen';
        // IF VOG IS VERZOCHT (VANAF DAN GAAN DE DEADLINES LOPEN)
        } elseif ($refpersoon_infiscalyear_ditevent == 1) {

            if ($dayssince_vogverzoek >= 0  AND $dayssince_vogverzoek < 7)  { $new_refpersoon_actstatus = "Pending";       } // AFWACHTING
            if ($dayssince_vogverzoek >= 7  AND $dayssince_vogverzoek < 21) { $new_refpersoon_actstatus = "Left Message";  } // HERINNERD
            if ($dayssince_vogverzoek >= 21 AND $dayssince_vogverzoek < 30) { $new_refpersoon_actstatus = "Unreachable";   } // ONBEREIKBAAR
            if ($dayssince_vogverzoek >= 30)                                { $new_refpersoon_actstatus = "No_show";       } // VERLOPEN
            if ($dayssince_vogverzoek >= 270)                               { $new_refpersoon_actstatus = "Bounced";       } // GEFAALD

            $new_cont_refstatus     = 'onbekend';

        // M61: ER ZOU GEEN ELSE MOETEN ZIJN
        } else {
                                                                              $new_refpersoon_actstatus = "Scheduled";       // INGEPLAND
        }

        // GEBRUIK CONT_VOGSTATUS ALS PART_VOGSTATUS INDIEN EVENT IN DIT FISCALYEAR
        if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
            $new_part_refstatus = $new_cont_refstatus;
        }

        if ($new_refpersoon_actstatus == "Pending")       { $new_refpersoon_actprio = 'Laag';   }   // AFWACHTING
        if ($new_refpersoon_actstatus == "Left Message")  { $new_refpersoon_actprio = 'Normaal';}   // HERINNERD
        if ($new_refpersoon_actstatus == "Unreachable")   { $new_refpersoon_actprio = 'Urgent'; }   // ONBEREIKBAAR
        if ($new_refpersoon_actstatus == "No_show")       { $new_refpersoon_actprio = 'Urgent'; }   // VERLOPEN     
        if ($new_refpersoon_actstatus == "Bounced")       { $new_refpersoon_actprio = 'Urgent'; }   // GEFAALD/GEBOUNCED
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (REF STATUS)");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal statussen op via de nieuwe centrale functie
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    // 2. Check: Is de huidige status negatief?
    if (!empty($ditevent_part_status_id) && in_array($ditevent_part_status_id, $status_negative)) {       

        wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - RESET REF STATUS", $ditevent_part_status_id);

        $new_cont_refstatus        = "onbekend";
        $new_part_refstatus        = "onbekend";
        $new_refpersoon_actstatus  = 'Not Required';
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - BEPAAL DATUM ACTIVITY REF PERSOON $displayname", "[$ditevent_part_kampkort]");
    wachthond($extdebug,2, "########################################################################");

    $new_refpersoon_actdatum       = NULL;

    // REF ACTIVITIES STARTEN NAV VOG_VERZOEK DUS DATUM MOET TOV VAN VOG VERZOEK WORDEN GEREKEND

    // INDIEN REF PERSOON IS DOORGEGEVEN
    if (infiscalyear($ref_persoon, $ditevent_part_kampstart,'ref_verzoek','ditevent') == 1) {
        $new_refpersoon_actdatum       = $ref_persoon;
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = ref persoon doorgegeven", $ref_persoon);
    // INDIEN NOG IN AFWACHTING REF PERSOON
    } elseif (infiscalyear($vog_verzoek, $ditevent_part_kampstart,'ref_persoon','ditevent') == 1) {
        $deadline                       = strtotime ( '+30 day' , strtotime ($vog_verzoek) ) ;
        $datum_deadline                 = date ( 'Y-m-d H:i:s' , $deadline );
        $new_refpersoon_actdatum        = $datum_deadline;
        wachthond($extdebug,1, "activity_datum ref_verzoek - DEADLINE = vog_verzoek +30",$datum_deadline);
    // VOG IS NOG NIET VERZOCHT
    } else {
        $new_refpersoon_actdatum        = $ditevent_part_kampstart;
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = kampstart",    $ref_verzoek);
    }
    // REF PERSOON NA START VAN KAMP
    if (date_bigger($ref_verzoek, $ditevent_part_kampstart) == 1) {
        wachthond($extdebug,1, "### DATUM ACTIVITY ONTVANGST > EVENT START DATE ###");
        $new_refpersoon_actdatum        = $ditevent_part_kampeinde;       // M61: TODO WAAROM EIGENLIJK?
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = kampeinde",    $ref_verzoek);
    }

    $status_refpersoon_array = array(
        'displayname'               => $displayname,
        'contact_id'                => $contact_id,
        'part_id'                   => $ditevent_part_id,
        'event_id'                  => $ditevent_part_eventid,
        'kamp_naam'                 => $ditevent_part_kampnaam,
        'kamp_start'                => $ditevent_part_kampstart,
        'kamp_rol'                  => $ditevent_part_rol,
        'kamp_functie'              => $ditevent_part_functie,
        'new_cont_refstatus'        => $new_cont_refstatus,
        'new_part_refstatus'        => $new_part_refstatus,
        'activity_type_naam'        => 'REF_persoon',
        'new_refpersoon_actdatum'   => $new_refpersoon_actdatum,
        'new_refpersoon_actstatus'  => $new_refpersoon_actstatus,
        'new_refpersoon_actprio'    => $new_refpersoon_actprio,
    );

    return $status_refpersoon_array;
}

function intake_status_reffeedback($contact_id, $array_event, $consolidate_refdata_array, $refnodig, $groupID) {

    $extdebug       = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");

    $displayname                = $array_event['displayname']           ?? NULL;
    $ditevent_part_kampkort     = $array_event['part_kampkort']         ?? NULL;

    $ditjaar_one_leid_kampkort  = $array_allpart_ditjaar['result_allpart_one_leid_kampkort']    ?? NULL;
    $ditjaar_pos_leid_kampkort  = $array_allpart_ditjaar['result_allpart_pos_leid_kampkort']    ?? NULL;

    if (empty($contact_id)) {
        return;
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - BEPAAL STATUS REF FEEDBACK VOOR $displayname [$ditevent_part_kampkort]", "[START]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,1, "groupID",                                                        $groupID);
    wachthond($extdebug,4, "contact_id",                                                  $contact_id);
    wachthond($extdebug,3, "array_event",                                                $array_event);
    wachthond($extdebug,3, "consolidate_refdata_array",                    $consolidate_refdata_array);
    wachthond($extdebug,3, "refnodig",                                                      $refnodig);

    if ($array_allpart_ditjaar) {

        $ditjaar_one_leid_count         = $array_allpart_ditjaar['result_allpart_one_leid_count'];
        $ditjaar_one_leid_part_id       = $array_allpart_ditjaar['result_allpart_one_leid_part_id'];
        $ditjaar_one_leid_event_id      = $array_allpart_ditjaar['result_allpart_one_leid_event_id'];
        $ditjaar_one_leid_status_id     = $array_allpart_ditjaar['result_allpart_one_leid_status_id'];
        $ditjaar_one_leid_kampfunctie   = $array_allpart_ditjaar['result_allpart_one_leid_kampfunctie'];
        $ditjaar_one_leid_kampkort      = $array_allpart_ditjaar['result_allpart_one_leid_kampkort'];

        wachthond($extdebug,3, 'ditjaar_one_leid_count',        $ditjaar_one_leid_count);
        wachthond($extdebug,3, 'ditjaar_one_leid_part_id',      $ditjaar_one_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_event_id',     $ditjaar_one_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_one_leid_status_id',    $ditjaar_one_leid_status_id);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampfunctie',  $ditjaar_one_leid_kampfunctie);
        wachthond($extdebug,2, 'ditjaar_one_leid_kampkort',     $ditjaar_one_leid_kampkort);

        $ditjaar_pos_leid_count         = $array_allpart_ditjaar['result_allpart_pos_leid_count'];
        $ditjaar_pos_leid_part_id       = $array_allpart_ditjaar['result_allpart_pos_leid_part_id'];
        $ditjaar_pos_leid_event_id      = $array_allpart_ditjaar['result_allpart_pos_leid_event_id'];
        $ditjaar_pos_leid_status_id     = $array_allpart_ditjaar['result_allpart_pos_leid_status_id'];
        $ditjaar_pos_leid_kampfunctie   = $array_allpart_ditjaar['result_allpart_pos_leid_kampfunctie'];
        $ditjaar_pos_leid_kampkort      = $array_allpart_ditjaar['result_allpart_pos_leid_kampkort'];

        wachthond($extdebug,3, 'ditjaar_pos_leid_count',        $ditjaar_pos_leid_count);
        wachthond($extdebug,3, 'ditjaar_pos_leid_part_id',      $ditjaar_pos_leid_part_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_event_id',     $ditjaar_pos_leid_event_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_status_id',    $ditjaar_pos_leid_status_id);
        wachthond($extdebug,3, 'ditjaar_pos_leid_kampfunctie',  $ditjaar_pos_leid_kampfunctie);
        wachthond($extdebug,3, 'ditjaar_pos_leid_kampkort',     $ditjaar_pos_leid_kampkort);
    }

    if ($array_event) {

        $ditevent_part_regdate          = $array_event['register_date']         ?? NULL;

        $ditevent_part_id               = $array_event['id']                    ?? NULL;
        $ditevent_part_eventid          = $array_event['part_event_id']         ?? NULL;
        $ditevent_part_functie          = $array_event['part_functie']          ?? NULL;
        $ditevent_part_rol              = $array_event['part_rol']              ?? NULL;

        $ditevent_part_kampnaam         = $array_event['part_kampnaam']         ?? NULL;
        $ditevent_part_kampkort         = $array_event['part_kampkort']         ?? NULL;
        $ditevent_part_kampstart        = $array_event['part_kampstart']        ?? NULL;
        $ditevent_part_kampeinde        = $array_event['part_kampeinde']        ?? NULL;
    }

    if ($consolidate_refdata_array) {

        $ref_status                     = $consolidate_refdata_array['new_cont_refstatus'];
        $ref_laatste                    = $consolidate_refdata_array['new_cont_reflaatste'];
        $ref_persoon                    = $consolidate_refdata_array['new_cont_refpersoon'];
        $ref_verzoek                    = $consolidate_refdata_array['new_cont_refverzoek'];
        $ref_datum                      = $consolidate_refdata_array['new_cont_refdatum'];
    }

    $ref_nodig                          = $refnodig;

    wachthond($extdebug,3, 'vog_verzoek',       $vog_verzoek);
    wachthond($extdebug,3, 'ref_nodig',         $ref_nodig);
    wachthond($extdebug,3, 'ref_status',        $ref_status);
    wachthond($extdebug,3, 'ref_laatste',       $ref_laatste);
    wachthond($extdebug,3, 'ref_persoon',       $ref_persoon);
    wachthond($extdebug,3, 'ref_verzoek',       $ref_verzoek);
    wachthond($extdebug,3, 'ref_datum',         $ref_datum);

    $new_reffeedback_actstatus          = 'Scheduled';    // DEFAULT TO AVAILABLE (CONCEPT)
    $new_reffeedback_actprio            = 'Normaal';      // DEFAULT TO NORMAL

    $reflaatste_binnengrens             = date_biggerequal($ref_laatste,        $grensrefnoggoed,   'reflaatste',    'grensref');
    $reflaatste_buitengrens             = date_biggerequal($grensrefnoggoed,    $ref_laatste,       'grensref',     'reflaatste');
    wachthond($extdebug,3, "reflaatste_binnengrens",             "$reflaatste_binnengrens\t[reflaatste: $ref_laatste]");
    wachthond($extdebug,3, "reflaatste_buitengrens",             "$reflaatste_buitengrens\t[reflaatste: $ref_laatste]");

    $refpersoon_infiscalyear_ditevent   = infiscalyear($ref_persoon,    $ditevent_part_kampstart,   'refpersoon',   'ditevent') ?? 0;
    wachthond($extdebug,3, "refpersoon_infiscalyear_ditevent",  $refpersoon_infiscalyear_ditevent);
    $refpersoon_infiscalyear_ditjaar    = infiscalyear($ref_persoon,    $today_datetime,            'refpersoon',   'ditjaar')  ?? 0;
    wachthond($extdebug,3, "refpersoon_infiscalyear_ditjaar",   $refpersoon_infiscalyear_ditjaar);

    $refverzoek_binnengrens             = date_biggerequal($ref_verzoek,        $grensrefnoggoed,   'refverzoek',   'grensref');
    $refverzoek_buitengrens             = date_biggerequal($grensrefnoggoed,    $ref_verzoek,       'grensref',     'refverzoek');
    wachthond($extdebug,3, "refverzoek_binnengrens",             "$refverzoek_binnengrens\t[refverzoek: $ref_verzoek]");
    wachthond($extdebug,3, "refverzoek_buitengrens",             "$refverzoek_buitengrens\t[refverzoek: $ref_verzoek]");    

    $refverzoek_infiscalyear_ditevent   = infiscalyear($ref_verzoek,    $ditevent_part_kampstart,   'refverzoek',   'ditevent') ?? 0;
    $refdatum_infiscalyear_ditevent     = infiscalyear($ref_datum,      $ditevent_part_kampstart,   'refdatum',     'ditevent') ?? 0;
    wachthond($extdebug,3, "refverzoek_infiscalyear_ditevent",  $refverzoek_infiscalyear_ditevent);
    wachthond($extdebug,3, "refverzoek_infiscalyear_ditevent",  $refverzoek_infiscalyear_ditevent);
    wachthond($extdebug,3, "refdatum_infiscalyear_ditevent",    $refdatum_infiscalyear_ditevent);

    $reflaatste_infiscalyear_ditjaar    = infiscalyear($ref_laatste,    $today_datetime,            'reflaatste',   'ditjaar')  ?? 0;
    $refverzoek_infiscalyear_ditjaar    = infiscalyear($ref_verzoek,    $today_datetime,            'refverzoek',   'ditjaar')  ?? 0;
    $refdatum_infiscalyear_ditjaar      = infiscalyear($ref_datum,      $today_datetime,            'refdatum',     'ditjaar')  ?? 0;

    wachthond($extdebug,3, "reflaatste_infiscalyear_ditjaar",   $reflaatste_infiscalyear_ditjaar);
    wachthond($extdebug,3, "refverzoek_infiscalyear_ditjaar",   $refverzoek_infiscalyear_ditjaar);
    wachthond($extdebug,3, "refdatum_infiscalyear_ditjaar",     $refdatum_infiscalyear_ditjaar);

    wachthond($extdebug,2, "0 new_reffeedback_actstatus",        $new_reffeedback_actstatus);
    wachthond($extdebug,2, "0 new_reffeedback_actprio",          $new_reffeedback_actprio);

    // REFRECENT NIET IN DIT JAAR, WEL BINNEN GRENSNOGGOED
    if ($reflaatste_binnengrens == 1 AND $reflaatste_infiscalyear_ditjaar == 0) {
        $new_cont_refstatus  = 'noggoed';
    }
    // REFRECENT VALT BUITEN GRENS NOGGOED
    if ($reflaatste_buitengrens == 1 OR empty($ref_laatste)) {
        $new_cont_refstatus  = 'onbekend';
    }
    // REFRECENT DIT JAAR AANGEVRAAGD EN ONTVANGEN
    if ($reflaatste_infiscalyear_ditjaar == 1) {
        $new_cont_refstatus  = 'ontvangen';
    }

    wachthond($extdebug,3, "0 new_cont_refstatus",         $new_cont_refstatus);
    wachthond($extdebug,3, "0 new_part_refstatus",         $new_part_refstatus);

    if (in_array($ref_nodig, array("noggoed"))) {

        $new_cont_refstatus         = 'noggoed';
        $new_reffeedback_actstatus  = 'Not Required';

    } else {

        $diffsince_refverzoek       = NULL;
        $dayssince_refverzoek       = NULL;
        $diffsince_refverzoek       = date_diff(date_create($ref_verzoek),date_create($today_datetime));
        $dayssince_refverzoek       = $diffsince_refverzoek->format('%a');  
        wachthond($extdebug,2, "- dayssince_refverzoek", $dayssince_refverzoek);

        // IF REF FEEDBACK IS ONTVANGEN
        if ($refdatum_infiscalyear_ditjaar == 1) {
            $new_cont_refstatus     = 'ontvangen';
                                                                              $new_reffeedback_actstatus = 'Completed';
        // IF REF PERSOON NOG NIET IS DOORGEGEVEN
        } elseif ($refpersoon_infiscalyear_ditjaar != 1) {
            $new_cont_refstatus     = 'onbekend';
                                                                              $new_reffeedback_actstatus = "Scheduled";       // INGEPLAND
        // IF EVENT INMIDDELS VOORBIJ IS
        } elseif (date_bigger($today_datetime, $ditevent_part_kampeinde)) {
            $new_cont_refstatus     = 'verlopen';
                                                                              $new_reffeedback_actstatus = "Failed";          // NA EVENT
        // IF VOG IS VERZOCHT (VANAF DAN GAAN DE DEADLINES LOPEN)
        } elseif ($refverzoek_infiscalyear_ditevent == 1) {

            if ($dayssince_refverzoek >= 0  AND $dayssince_refverzoek < 7)  { $new_reffeedback_actstatus = "Pending";       } // AFWACHTING
            if ($dayssince_refverzoek >= 7  AND $dayssince_refverzoek < 21) { $new_reffeedback_actstatus = "Left Message";  } // HERINNERD
            if ($dayssince_refverzoek >= 21 AND $dayssince_refverzoek < 30) { $new_reffeedback_actstatus = "Unreachable";   } // ONBEREIKBAAR
            if ($dayssince_refverzoek >= 30)                                { $new_reffeedback_actstatus = "No_show";       } // VERLOPEN
            if ($dayssince_refverzoek >= 270)                               { $new_reffeedback_actstatus = "Bounced";       } // GEFAALD

            $new_cont_refstatus     = 'gevraagd';

        } elseif ($refpersoon_infiscalyear_ditjaar == 1) {
            $new_cont_refstatus     = 'vragen';
                                                                              $new_reffeedback_actstatus = 'Scheduled';
        // M61: ER ZOU GEEN ELSE MOETEN ZIJN
        } else {
                                                                              $new_reffeedback_actstatus = "Scheduled";       // INGEPLAND
        }

        // GEBRUIK CONT_VOGSTATUS ALS PART_VOGSTATUS INDIEN EVENT IN DIT FISCALYEAR
        if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
            $new_part_refstatus     = $new_cont_refstatus;
        }

        if ($new_reffeedback_actstatus == "Pending")       { $new_reffeedback_actprio = 'Laag';   }   // AFWACHTING
        if ($new_reffeedback_actstatus == "Left Message")  { $new_reffeedback_actprio = 'Normaal';}   // HERINNERD
        if ($new_reffeedback_actstatus == "Unreachable")   { $new_reffeedback_actprio = 'Urgent'; }   // ONBEREIKBAAR
        if ($new_reffeedback_actstatus == "No_show")       { $new_reffeedback_actprio = 'Urgent'; }   // VERLOPEN     
        if ($new_reffeedback_actstatus == "Bounced")       { $new_reffeedback_actprio = 'Urgent'; }   // GEFAALD/GEBOUNCED
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (REF FEEDBACK)");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal statussen op via de nieuwe centrale functie
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    // 2. Check: Is de huidige status negatief? (En bestaat het ID wel?)
    if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       

        wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - RESET REF FEEDBACK", $ditjaar_one_leid_status_id);

        $new_cont_refstatus         = "onbekend";
        $new_part_refstatus         = "onbekend";
        $new_reffeedback_actstatus  = 'Not Required';
    }

    wachthond($extdebug,3, "F new_cont_refstatus",          $new_cont_refstatus);
    wachthond($extdebug,3, "F new_part_refstatus",          $new_part_refstatus);

    wachthond($extdebug,2, "F new_reffeedback_actstatus",   $new_reffeedback_actstatus);
    wachthond($extdebug,2, "F new_reffeedback_actprio",     $new_reffeedback_actprio);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - BEPAAL DATUM ACTIVITY REF FEEDBACK $displayname", "[$ditevent_part_kampkort]");
    wachthond($extdebug,2, "########################################################################");

    $new_reffeedback_actdatum           = NULL;

    // REF FEEDBACK IS DOORGEGEVEN      - GEBRUIK DATUM FEEDBACK
    if (infiscalyear($ref_datum, $ditevent_part_kampstart,'ref_datum','ditevent') == 1) {
        $new_reffeedback_actdatum       = $ref_datum;
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = ref feedback ontvangen", $ref_datum);
    // NOG IN AFWACHTING REF FEEDBACK   - GEBRUIK 30 DAGEN NA REFVERZOEK
    } elseif (infiscalyear($ref_verzoek, $ditevent_part_kampstart,'ref_verzoek','ditevent') == 1) {
        $deadline                       = strtotime ( '+30 day' , strtotime ($ref_verzoek) ) ;
        $datum_deadline                 = date ( 'Y-m-d H:i:s' , $deadline );
        $new_reffeedback_actdatum       = $datum_deadline;
        wachthond($extdebug,1, "activity_datum ref_verzoek - DEADLINE = ref_verzoek +30",$datum_deadline);
    // FEEDBACK IS NOG NIET VERZOCHT    - GEBRUIK 30 DAGEN NA REGDATE
    } else {
        $deadline                       = strtotime ( '+30 day' , strtotime ($ditevent_part_regdate) ) ;
        $datum_deadline                 = date ( 'Y-m-d H:i:s' , $deadline );
        $new_reffeedback_actdatum       = $datum_deadline;
        $new_reffeedback_actdatum       = $ditevent_part_kampstart;
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = kampstart",    $ref_verzoek);
    }
    // REF FEEDBACK NA START VAN KAMP   - GEBRUIK DATUM KAMPEINDE
    if (date_bigger($ref_verzoek, $ditevent_part_kampstart) == 1) {
        wachthond($extdebug,1, "### DATUM ACTIVITY ONTVANGST > EVENT START DATE ###");
        $new_reffeedback_actdatum        = $ditevent_part_kampeinde;       // M61: TODO WAAROM EIGENLIJK?
        wachthond($extdebug,1, "activity_datum ref_verzoek - LOGDATUM = kampeinde",    $ref_verzoek);
    }

    $status_reffeedback_array = array(
        'displayname'               => $displayname,
        'contact_id'                => $contact_id,
        'part_id'                   => $ditevent_part_id,
        'event_id'                  => $ditevent_part_eventid,
        'kamp_naam'                 => $ditevent_part_kampnaam,
        'kamp_start'                => $ditevent_part_kampstart,
        'kamp_rol'                  => $ditevent_part_rol,
        'kamp_functie'              => $ditevent_part_functie,
        'new_cont_refstatus'        => $new_cont_refstatus,
        'new_part_refstatus'        => $new_part_refstatus,
        'activity_type_naam'        => 'REF_feedback',
        'new_reffeedback_actdatum'  => $new_reffeedback_actdatum,
        'new_reffeedback_actstatus' => $new_reffeedback_actstatus,
        'new_reffeedback_actprio'   => $new_reffeedback_actprio,
    );

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - BEPAAL STATUS REF FEEDBACK VOOR $displayname [$ditevent_part_kampkort]", "[EINDE]");
    wachthond($extdebug,1, "########################################################################");

    return $status_reffeedback_array;
}

function intake_status_vogaanvraag($contact_id, $array_event, $array_intake, $groupID) {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    $displayname            = $array_event['displayname']               ?? NULL;
    $ditevent_part_kampkort = $array_event['ditevent_part_kampkort']    ?? NULL;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS - VOGAANVRAAG - BEPAAL STATUS VOOR $displayname [$ditevent_part_kampkort]", "[STATUS]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,2, "groupID",                                                        $groupID);
    wachthond($extdebug,4, "contact_id",                                                  $contact_id);
    wachthond($extdebug,3, "array_event",                                                $array_event);
    wachthond($extdebug,3, "array_intake",                                              $array_intake);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGAANVRAAG - 1.0 DATA EXTRACTIE");
    wachthond($extdebug,2, "########################################################################");

    // --- 1.1 INTAKE DATA ---
    $vog_nodig        = $array_intake['vog_nodig']          ?? NULL;
    $vog_verzoek      = $array_intake['vog_verzoek']        ?? NULL;
    $vog_aanvraag     = $array_intake['vog_aanvraag']       ?? NULL;
    $vog_datum        = $array_intake['vog_datum']          ?? NULL;
    $vog_laatste      = $array_intake['vog_laatste']        ?? NULL;
    $grensvognoggoed  = $array_intake['grensvognoggoed']    ?? NULL;

    wachthond($extdebug, 3, 'vog_nodig',        $vog_nodig);
    wachthond($extdebug, 3, 'vog_verzoek',      $vog_verzoek);
    wachthond($extdebug, 3, 'vog_reminder',     $vog_reminder);
    wachthond($extdebug, 3, 'vog_aanvraag',     $vog_aanvraag);
    wachthond($extdebug, 3, 'vog_datum',        $vog_datum);
    wachthond($extdebug, 3, 'vog_laatste',      $vog_laatste);
    wachthond($extdebug, 3, 'grensvognoggoed',  $grensvognoggoed);

    // --- 1.2 EVENT DATA ---
    $ditevent_part_id        = $array_event['id']                ?? $array_event['part_id']  ?? NULL;
    $ditevent_part_eventid   = $array_event['part_eventid']      ?? $array_event['event_id'] ?? NULL;
    $ditevent_part_kampnaam  = $array_event['part_kampnaam']     ?? NULL;
    $ditevent_part_kampstart = $array_event['part_kampstart']    ?? NULL;
    $ditevent_part_kampeinde = $array_event['part_kampeinde']    ?? NULL;
    $ditevent_part_rol       = $array_event['part_rol']          ?? NULL;
    $ditevent_part_functie   = $array_event['part_functie']      ?? NULL;
    $ditevent_part_status_id = $array_event['part_status_id']    ?? $array_event['status_id'] ?? NULL;

    wachthond($extdebug, 3, 'ditevent_part_id',           $ditevent_part_id);
    wachthond($extdebug, 3, 'ditevent_part_eventid',      $ditevent_part_eventid);
    wachthond($extdebug, 3, 'ditevent_part_kampnaam',     $ditevent_part_kampnaam);
    wachthond($extdebug, 3, 'ditevent_part_kampstart',    $ditevent_part_kampstart);
    wachthond($extdebug, 3, 'ditevent_part_kampeinde',    $ditevent_part_kampeinde);
    wachthond($extdebug, 3, 'ditevent_part_rol',          $ditevent_part_rol);
    wachthond($extdebug, 3, 'ditevent_part_functie',      $ditevent_part_functie);
    wachthond($extdebug, 3, 'ditevent_part_status_id',    $ditevent_part_status_id);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGAANVRAAG - 2.0 BEPAAL DATUM ACTIVITY");
    wachthond($extdebug,2, "########################################################################");

    $vogaanvraag_datum = NULL;

    // Prioriteit 1: Aanvraag is gedaan (binnen fiscale jaar van event).
    if ($vog_aanvraag && infiscalyear($vog_aanvraag, $ditevent_part_kampstart, 'vog_aanvraag', 'ditevent') == 1) {
        $vogaanvraag_datum = $vog_aanvraag;
    } 
    // Prioriteit 2: Alleen verzoek gedaan: gebruik deadline (verzoek + 30 dagen).
    elseif ($vog_verzoek && infiscalyear($vog_verzoek, $ditevent_part_kampstart, 'vog_verzoek', 'ditevent') == 1) {
        $vogaanvraag_datum = date('Y-m-d H:i:s', strtotime($vog_verzoek . ' + 30 days'));
    } 
    // Prioriteit 3: Geen actie bekend: default naar start van het kamp.
    else {
        $vogaanvraag_datum = $ditevent_part_kampstart;
    }

    // Correctie: Als document er al is maar aanvraagdatum ontbreekt, gebruik documentdatum.
    if ($vog_datum && infiscalyear($vog_datum, $ditevent_part_kampstart, 'vog_datum', 'ditevent') == 1 && empty($vog_aanvraag)) {
        $vogaanvraag_datum = $vog_datum;
    }

    // Override: Als actie pas NA start van kamp plaatsvond, loggen op kampeinde.
    if ($vog_aanvraag && date_bigger($vog_aanvraag, $ditevent_part_kampstart) == 1) {
        $vogaanvraag_datum = $ditevent_part_kampeinde;
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGAANVRAAG - 3.0 BEPAAL STATUS EN PRIO");
    wachthond($extdebug,2, "########################################################################");

    $vogaanvraag_status     = 'Scheduled'; 
    $vogaanvraag_prio       = 'Normaal';
    $new_cont_vogstatus     = 'klaarzetten';
    $new_part_vogstatus     = ''; // Default leeg voor de part-status

    // CHECK 1: Is er DIT fiscale jaar al een document ontvangen?
    if ($vog_datum && infiscalyear($vog_datum, $today_datetime, 'vog', 'ditjaar')) {
        $new_cont_vogstatus = 'ontvangen';
        $vogaanvraag_status = 'Completed';
    } 
    // CHECK 2: Is er DIT fiscale jaar al een verzoek uitgestuurd? (Escalatie logica)
    elseif ($vog_verzoek && infiscalyear($vog_verzoek, $today_datetime, 'verzoek', 'ditjaar')) {
        $new_cont_vogstatus = 'verzocht';
        
        $days = (int)date_diff(date_create($vog_verzoek), date_create($today_datetime))->format('%a');
        wachthond($extdebug, 3, 'Dagen sinds verzoek', $days);

        if ($days >= 0   AND $days < 7)   { $vogaanvraag_status = "Pending";      $vogaanvraag_prio = "Laag";    } 
        if ($days >= 7   AND $days < 21)  { $vogaanvraag_status = "Left Message"; $vogaanvraag_prio = "Normaal"; } 
        if ($days >= 21  AND $days < 30)  { $vogaanvraag_status = "Unreachable";  $vogaanvraag_prio = "Urgent";  } 
        if ($days >= 30  AND $days < 270) { $vogaanvraag_status = "No_show";      $vogaanvraag_prio = "Urgent";  } 
        if ($days >= 270)                 { $vogaanvraag_status = "Bounced";      $vogaanvraag_prio = "Urgent";  }
    }
    // CHECK 3: Geen actie dit jaar? Controleer plicht op basis van 'elkjaar' of houdbaarheid
    else {
        if ($vog_nodig === 'elkjaar') {
            // Directe check op elkjaar: indien geen actie dit jaar -> altijd klaarzetten
            $new_cont_vogstatus = 'klaarzetten';
            $vogaanvraag_status = 'Scheduled';
        } elseif ($vog_laatste && $grensvognoggoed && date_biggerequal($vog_laatste, $grensvognoggoed)) {
            // Normale 3-jaar check
            $new_cont_vogstatus = 'noggoed';
            $vogaanvraag_status = 'Not Required';
        } else {
            // Verlopen of onbekend
            $new_cont_vogstatus = 'klaarzetten';
            $vogaanvraag_status = 'Scheduled';
        }
    }

    // Finale check: Is het kamp al voorbij?
    if ($ditevent_part_kampeinde && $today_datetime > $ditevent_part_kampeinde && $vogaanvraag_status !== 'Completed') {
        $vogaanvraag_status = 'Failed';
        $new_cont_vogstatus = 'verlopen';
    }

    // --- SPECIFIEKE CHECK: SYNCHRONISEER CONTACT NAAR DEELNEMER ---
    if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
        $new_part_vogstatus = $new_cont_vogstatus;
    }

    // --- 3.1 ANNULERING CHECK ---
    // Als de deelname-status negatief is (geannuleerd/afgewezen), trekken we de verzoeken in.

    // Zoek dit op in je status-functies:
    $ditevent_part_status_id = $array_event['status_id'] ?? 0;
    // En controleer de cache check:
    $status_negative = Civi::cache()->get('cache_status_negative') ?? [];
    if (!empty($status_negative) && in_array($ditevent_part_status_id, $status_negative)) {
        // Alleen dán resetten naar onbekend
        $new_cont_vogstatus  = "onbekend";
        $new_part_vogstatus  = "onbekend";
        $vogaanvraag_status  = 'Not Required'; // Haalt de taak uit de actieve lijst
        $vogaanvraag_prio    = 'Laag';
        wachthond($extdebug, 1, "DEELNAME GEANNULEERD: VOG aanvraag status gereset naar onbekend/Not Required");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGAANVRAAG - 4.0 RETURN RESULTAAT");
    wachthond($extdebug,2, "########################################################################");

    $status_vogaanvraag_array = array(
        'displayname'               => $displayname,
        'contact_id'                => $contact_id,
        'part_id'                   => $ditevent_part_id,
        'event_id'                  => $ditevent_part_eventid,
        'kamp_naam'                 => $ditevent_part_kampnaam,
        'kamp_start'                => $ditevent_part_kampstart,
        'kamp_rol'                  => $ditevent_part_rol,
        'kamp_functie'              => $ditevent_part_functie,
        'new_cont_vogstatus'        => $new_cont_vogstatus,
        'new_part_vogstatus'        => $new_part_vogstatus,
        'activity_type_naam'        => 'VOG_aanvraag',
        'vogaanvraag_datum'         => $vogaanvraag_datum,
        'vogaanvraag_status'        => $vogaanvraag_status,
        'vogaanvraag_prio'          => $vogaanvraag_prio,
    );

    wachthond($extdebug, 3, "FINAL DATA_ARRAY: status_vogaanvraag_array", $status_vogaanvraag_array);

    return $status_vogaanvraag_array;

}

function intake_status_vogontvangst($contact_id, $array_event, $array_intake, $groupID) {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    $displayname            = $array_event['displayname']               ?? NULL;
    $ditevent_part_kampkort = $array_event['part_kampkort']             ?? NULL;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGONTVANGST - BEPAAL STATUS VOOR $displayname [$ditevent_part_kampkort]", "[STATUS]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,2, "groupID",                                                         $groupID);
    wachthond($extdebug,4, "contact_id",                                                   $contact_id);
    wachthond($extdebug,3, "array_event",                                                 $array_event);
    wachthond($extdebug,3, "array_intake",                                               $array_intake);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGONTVANGST - 1.0 DATA EXTRACTIE");
    wachthond($extdebug,2, "########################################################################");

    // --- 1.1 INTAKE DATA ---
    $vog_nodig        = $array_intake['vog_nodig']          ?? NULL;
    $vog_verzoek      = $array_intake['vog_verzoek']        ?? NULL;
    $vog_aanvraag     = $array_intake['vog_aanvraag']       ?? NULL;
    $vog_datum        = $array_intake['vog_datum']          ?? NULL;
    $vog_laatste      = $array_intake['vog_laatste']        ?? NULL;
    $grensvognoggoed  = $array_intake['grensvognoggoed']    ?? NULL;

    wachthond($extdebug, 3, 'vog_nodig',        $vog_nodig);
    wachthond($extdebug, 3, 'vog_verzoek',      $vog_verzoek);
    wachthond($extdebug, 3, 'vog_reminder',     $vog_reminder);
    wachthond($extdebug, 3, 'vog_aanvraag',     $vog_aanvraag);
    wachthond($extdebug, 3, 'vog_datum',        $vog_datum);
    wachthond($extdebug, 3, 'vog_laatste',      $vog_laatste);
    wachthond($extdebug, 3, 'grensvognoggoed',  $grensvognoggoed);

    // --- 1.2 EVENT DATA ---
    $ditevent_part_id        = $array_event['id']                ?? $array_event['part_id']  ?? NULL;
    $ditevent_part_eventid   = $array_event['part_eventid']      ?? $array_event['event_id'] ?? NULL;
    $ditevent_part_kampnaam  = $array_event['part_kampnaam']     ?? NULL;
    $ditevent_part_kampstart = $array_event['part_kampstart']    ?? NULL;
    $ditevent_part_kampeinde = $array_event['part_kampeinde']    ?? NULL;
    $ditevent_part_rol       = $array_event['part_rol']          ?? NULL;
    $ditevent_part_functie   = $array_event['part_functie']      ?? NULL;
    $ditevent_part_status_id = $array_event['part_status_id']    ?? $array_event['status_id'] ?? NULL;

    wachthond($extdebug, 3, 'ditevent_part_id',           $ditevent_part_id);
    wachthond($extdebug, 3, 'ditevent_part_eventid',      $ditevent_part_eventid);
    wachthond($extdebug, 3, 'ditevent_part_kampnaam',     $ditevent_part_kampnaam);
    wachthond($extdebug, 3, 'ditevent_part_kampstart',    $ditevent_part_kampstart);
    wachthond($extdebug, 3, 'ditevent_part_kampeinde',    $ditevent_part_kampeinde);
    wachthond($extdebug, 3, 'ditevent_part_rol',          $ditevent_part_rol);
    wachthond($extdebug, 3, 'ditevent_part_functie',      $ditevent_part_functie);
    wachthond($extdebug, 3, 'ditevent_part_status_id',    $ditevent_part_status_id);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGONTVANGST- 2.0 BEPAAL DATUM ACTIVITY");
    wachthond($extdebug,2, "########################################################################");

    $vogontvangst_datum = NULL;

    // Prioriteit 1: VOG is binnen: gebruik documentdatum (mits in fiscale jaar event).
    if ($vog_datum && infiscalyear($vog_datum, $ditevent_part_kampstart, 'vog_datum', 'ditevent') == 1) {
        $vogontvangst_datum = $vog_datum;
    } 
    // Prioriteit 2: VOG is binnen maar na start kamp: loggen op kampeinde.
    elseif ($vog_datum && date_bigger($vog_datum, $ditevent_part_kampstart) == 1) {
        $vogontvangst_datum = $ditevent_part_kampeinde;
    } 
    // Prioriteit 3: Nog aan het wachten: deadline op aanvraag + 49 dagen (7 weken).
    elseif ($vog_aanvraag && infiscalyear($vog_aanvraag, $ditevent_part_kampstart, 'vog_aanvraag', 'ditevent') == 1) {
        $vogontvangst_datum = date('Y-m-d H:i:s', strtotime($vog_aanvraag . ' + 49 days'));
    } 
    // Prioriteit 4: Geen actie bekend: default naar kampeinde.
    else {
        $vogontvangst_datum = $ditevent_part_kampeinde;
    }

    wachthond($extdebug,1, "activity_datum vog_ontvangst bepaald op:", $vogontvangst_datum);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGONTVANGST- 3.0 BEPAAL STATUS EN PRIO");
    wachthond($extdebug,2, "########################################################################");

    $vogontvangst_status = 'Scheduled';
    $vogontvangst_prio   = 'Normaal';
    $new_cont_vogstatus  = 'verzocht';
    $new_part_vogstatus  = ''; // Default leeg voor de part-status

    // CHECK 1: Is het document DIT fiscale jaar al ontvangen?
    if ($vog_datum && infiscalyear($vog_datum, $today_datetime, 'vog', 'ditjaar')) {
        $new_cont_vogstatus  = 'ontvangen';
        $vogontvangst_status = 'Completed';
    } 
    // CHECK 2: Is het document DIT fiscale jaar al aangevraagd door de vrijwilliger?
    elseif ($vog_aanvraag && infiscalyear($vog_aanvraag, $today_datetime, 'aanvraag', 'ditjaar')) {
        $new_cont_vogstatus  = 'ingediend';
        
        // BEREKEN DAGEN SINDS AANVRAAG VOOR ONTVANGST-ESCALATIE
        $days = (int)date_diff(date_create($vog_aanvraag), date_create($today_datetime))->format('%a');
        wachthond($extdebug, 3, 'Dagen sinds aanvraag', $days);

        // Inline escalatie-logica voor de ontvangst-fase
        if ($days >= 0  AND $days < 14)  { $vogontvangst_status = "Pending";      $vogontvangst_prio = "Laag";    } 
        if ($days >= 14 AND $days < 28)  { $vogontvangst_status = "Left Message"; $vogontvangst_prio = "Normaal"; } 
        if ($days >= 28 AND $days < 42)  { $vogontvangst_status = "Unreachable";  $vogontvangst_prio = "Urgent";  } 
        if ($days >= 42 AND $days < 270) { $vogontvangst_status = "No_show";      $vogontvangst_prio = "Urgent";  } 
        if ($days >= 270)                { $vogontvangst_status = "Bounced";      $vogontvangst_prio = "Urgent";  } 
    }
    // CHECK 3: Geen actie dit jaar? Controleer plicht
    else {
        if ($vog_nodig === 'elkjaar') {
            // Bestuur/Staf: Indien geen actie dit jaar -> status 'klaarzetten'
            $new_cont_vogstatus  = 'klaarzetten';
            $vogontvangst_status = 'Scheduled';
        } elseif ($vog_laatste && $grensvognoggoed && date_biggerequal($vog_laatste, $grensvognoggoed)) {
            // Reguliere leiding met geldige oude VOG
            $new_cont_vogstatus  = 'noggoed';
            $vogontvangst_status = 'Not Required';
        } else {
            // Verlopen of nooit gehad
            $new_cont_vogstatus  = 'klaarzetten';
            $vogontvangst_status = 'Scheduled';
        }
    }

    // Finale deadline check: Als het kamp voorbij is en de VOG is niet binnen.
    if ($ditevent_part_kampeinde && $today_datetime > $ditevent_part_kampeinde && $vogontvangst_status !== 'Completed') {
        $vogontvangst_status    = 'Failed';
        $new_cont_vogstatus     = 'verlopen';
    }

    // --- SPECIFIEKE CHECK: SYNCHRONISEER CONTACT NAAR DEELNEMER ---
    if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
        $new_part_vogstatus = $new_cont_vogstatus;
    }
    
    // --- 3.1 ANNULERING CHECK ---
    $status_negative = Civi::cache()->get('cache_status_negative') ?? [];
    if (!empty($status_negative) && in_array($ditevent_part_status_id, $status_negative)) {
        $new_cont_vogstatus  = "onbekend";
        $new_part_vogstatus  = "onbekend";
        $vogontvangst_status = 'Not Required';
        $vogontvangst_prio   = 'Laag';
        wachthond($extdebug, 1, "DEELNAME GEANNULEERD: VOG ontvangst gereset.");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE STATUS VOGONTVANGST- 4.0 RETURN RESULTAAT");
    wachthond($extdebug,2, "########################################################################");

    $status_vogontvangst_array = array(
        'displayname'                => $displayname,
        'contact_id'                 => $contact_id,
        'part_id'                    => $ditevent_part_id,
        'event_id'                   => $ditevent_part_eventid,
        'kamp_naam'                  => $ditevent_part_kampnaam,
        'kamp_start'                 => $ditevent_part_kampstart,
        'kamp_functie'               => $ditevent_part_functie,
        'kamp_rol'                   => $ditevent_part_rol,
        'new_cont_vogstatus'         => $new_cont_vogstatus,
        'new_part_vogstatus'         => $new_part_vogstatus,
        'activity_type_naam'         => 'VOG_ontvangst',
        'vogontvangst_datum'         => $vogontvangst_datum,
        'vogontvangst_status'        => $vogontvangst_status,
        'vogontvangst_prio'          => $vogontvangst_prio,
    );

    wachthond($extdebug, 3, "FINAL DATA_ARRAY: status_vogontvangst_array", $status_vogontvangst_array);

    return $status_vogontvangst_array;
}

function intake_consolidate_refdata($contact_id, $array_allpart_ditjaar, $array_event, $array_intake_refall, $groupID) {

    $extdebug       = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE CONSOLIDATE [PRE] CONSOLIDATE REF DATA", "[groupID: $groupID]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, "array_event",           $array_event);
    wachthond($extdebug,4, "array_allpart_ditjaar", $array_allpart_ditjaar);
    wachthond($extdebug,3, "array_intake_refall",   $array_intake_refall);

    if ($array_intake_refall) {

        $cont_val_refnodig              = $array_intake_refall['val_cont_refnodig']     ?? NULL;
        $cont_val_refstatus             = $array_intake_refall['val_cont_refstatus']    ?? NULL;
        $cont_val_reflaatste            = $array_intake_refall['val_cont_reflaatste']   ?? NULL;

        $part_val_refpersoon            = $array_intake_refall['val_part_refpersoon']   ?? NULL;
        $part_val_refgevraagd           = $array_intake_refall['val_part_refgevraagd']  ?? NULL;
        $part_val_reffeedback           = $array_intake_refall['val_part_reffeedback']  ?? NULL;

        $cont_ref_nodig                 = $array_intake_refall['cont_ref_nodig']        ?? NULL;
        $cont_ref_status                = $array_intake_refall['cont_ref_status']       ?? NULL;
        $cont_ref_laatste               = $array_intake_refall['cont_ref_laatste']      ?? NULL;

        $part_ref_persoon               = $array_intake_refall['part_ref_persoon']      ?? NULL;
        $part_ref_verzoek               = $array_intake_refall['part_ref_gevraagd']     ?? NULL;
        $part_ref_feedback              = $array_intake_refall['part_ref_feedback']     ?? NULL;

    }

    if ($array_event) {

        $part_kampkort                  = $array_event['part_kampkort']                 ?? NULL;
        $part_kampstart                 = $array_event['part_kampstart']                ?? NULL;
        $part_kampeinde                 = $array_event['part_kampeinde']                ?? NULL;
        $part_functie                   = $array_event['part_functie']                  ?? NULL;

        $part_ref_nodig                 = $array_event['part_refnodig']                 ?? NULL;
        $part_ref_status                = $array_event['part_refstatus']                ?? NULL;
    }

    wachthond($extdebug,1, 'part_kampkort',         $part_kampkort);
    wachthond($extdebug,1, 'part_kampstart',        $part_kampstart);
    wachthond($extdebug,1, 'part_kampeinde',        $part_kampeinde);
    wachthond($extdebug,1, 'part_functie',          $part_functie);

    wachthond($extdebug,3, 'cont_val_refnodig',     $cont_val_refnodig);
    wachthond($extdebug,3, 'cont_val_refstatus',    $cont_val_refstatus);
    wachthond($extdebug,3, 'cont_val_reflaatste',   $cont_val_reflaatste);

    wachthond($extdebug,3, 'part_val_refpersoon',   $part_val_refpersoon);
    wachthond($extdebug,3, 'part_val_refgevraagd',  $part_val_refgevraagd);
    wachthond($extdebug,3, 'part_val_reffeedback',  $part_val_reffeedback);

    wachthond($extdebug,3, 'cont_ref_nodig',        $cont_ref_nodig);
    wachthond($extdebug,3, 'cont_ref_status',       $cont_ref_status);
    wachthond($extdebug,3, 'cont_ref_laatste',      $cont_ref_laatste);

    wachthond($extdebug,3, 'part_ref_nodig',        $part_ref_nodig);
    wachthond($extdebug,3, 'part_ref_status',       $part_ref_status);
    wachthond($extdebug,3, 'part_ref_persoon',      $part_ref_persoon);
    wachthond($extdebug,3, 'part_ref_verzoek',      $part_ref_verzoek);
    wachthond($extdebug,3, 'part_ref_feedback',     $part_ref_feedback);

    if ($part_ref_nodig) {
        $new_part_refnodig      = $part_ref_nodig; 
        $new_cont_refnodig      = $new_part_refnodig;
    }
    if ($part_ref_status) {
        $new_part_refstatus     = $part_ref_status;   
        $new_cont_refstatus     = $new_part_refstatus;
    }

    if (date_bigger($part_val_refpersoon,  $part_ref_persoon)   OR empty($part_ref_persoon))    {
        $new_part_refpersoon    = $part_val_refpersoon;
    } else {
        $new_part_refpersoon    = $part_ref_persoon;
    }
    if (date_bigger($part_val_refgevraagd, $part_ref_verzoek)   OR empty($part_ref_verzoek))    {
        $new_part_refverzoek    = $part_val_refgevraagd;
    } else {
        $new_part_refverzoek    = $part_ref_verzoek;
    }
    if (date_bigger($part_val_reffeedback, $part_ref_feedback)  OR empty($part_ref_feedback))   {
        $new_part_reffeedback   = $part_val_reffeedback;
    } else {
        $new_part_reffeedback   = $part_ref_feedback;
    }

    if (date_bigger($part_val_reffeedback, $cont_ref_laatste)   OR empty($cont_ref_laatste))    {
        $new_cont_reflaatste    = $part_val_reffeedback;
    } else {
        $new_cont_reflaatste    = $cont_ref_laatste;
    }

    $part_refpersoon_infiscalyear_ditjaar       = infiscalyear($new_part_refpersoon,    $today_datetime,   'part_refpersoon',   'ditjaar');
    wachthond($extdebug,3, "part_refpersoon_infiscalyear_ditjaar",      $part_refpersoon_infiscalyear_ditjaar);
    if ($part_refpersoon_infiscalyear_ditjaar == 1) {
        $new_cont_refpersoon    = $new_part_refpersoon;
    }
    $part_refverzoek_infiscalyear_ditjaar       = infiscalyear($new_part_refverzoek,    $today_datetime,    'part_refverzoek',  'ditjaar');
    wachthond($extdebug,3, "part_refverzoek_infiscalyear_ditjaar",      $part_refverzoek_infiscalyear_ditjaar);
    if ($part_refverzoek_infiscalyear_ditjaar == 1) {
        $new_cont_refverzoek   = $new_part_refverzoek;
    }
    $part_reffeedback_infiscalyear_ditjaar     = infiscalyear($new_part_reffeedback,    $today_datetime,    'part_reffeedback', 'ditjaar');
    wachthond($extdebug,3, "part_reffeedback_infiscalyear_ditjaar",     $part_reffeedback_infiscalyear_ditjaar);
    if ($part_reffeedback_infiscalyear_ditjaar == 1) {
        $new_cont_reffeedback   = $new_part_reffeedback;
    }

    wachthond($extdebug,3, "new_cont_refnodig",         $new_cont_refnodig);
    wachthond($extdebug,3, "new_cont_refstatus",        $new_cont_refstatus);
    wachthond($extdebug,3, "new_cont_reflaatste",       $new_cont_reflaatste);
    wachthond($extdebug,3, "new_cont_refpersoon",       $new_cont_refpersoon);
    wachthond($extdebug,3, "new_cont_refverzoek",       $new_cont_refverzoek);
    wachthond($extdebug,3, "new_cont_reffeedback",      $new_cont_reffeedback);

    wachthond($extdebug,3, "new_part_refnodig",         $new_part_refnodig);
    wachthond($extdebug,3, "new_part_refstatus",        $new_part_refstatus);
    wachthond($extdebug,3, "new_part_reflaatste",       $new_part_reflaatste);
    wachthond($extdebug,3, "new_part_refpersoon",       $new_part_refpersoon);
    wachthond($extdebug,3, "new_part_refverzoek",       $new_part_refverzoek);
    wachthond($extdebug,3, "new_part_reffeedback",      $new_part_reffeedback);

    $consolidate_refdata_array = array(
        'new_cont_refnodig'         => $new_cont_refnodig,
        'new_cont_refstatus'        => $new_cont_refstatus,
        'new_cont_reflaatste'       => $new_cont_reflaatste,
        'new_cont_refpersoon'       => $new_cont_refpersoon,
        'new_cont_refverzoek'       => $new_cont_refverzoek,
        'new_cont_refdatum'         => $new_cont_reffeedback,

        'new_part_refnodig'         => $new_part_refnodig,
        'new_part_refstatus'        => $new_part_refstatus,
        'new_part_reflaatste'       => $new_part_reflaatste,
        'new_part_refpersoon'       => $new_part_refpersoon,
        'new_part_refverzoek'       => $new_part_refverzoek,
        'new_part_refdatum'         => $new_part_reffeedback,
    );

    return $consolidate_refdata_array;

}

function intake_consolidate_vogdata($contact_id, $array_allpart_ditjaar, $array_event, $array_intake_vogall, $groupID) {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE CONSOLIDATE [PRE] CONSOLIDATE VOG DATA!", "[groupID: $groupID]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, "array_event",           $array_event);
    wachthond($extdebug,4, "array_allpart_ditjaar", $array_allpart_ditjaar);
    wachthond($extdebug,3, "array_intake_vogall",   $array_intake_vogall);

    if ($array_intake_vogall) {

        $cont_val_vognodig              = $array_intake_vogall['val_cont_vognodig']    ?? NULL;
        $cont_val_vogstatus             = $array_intake_vogall['val_cont_vogstatus']   ?? NULL;
        $cont_val_voglaatste            = $array_intake_vogall['val_cont_voglaatste']  ?? NULL;

        $part_val_vogverzoek            = $array_intake_vogall['val_part_vogverzoek']  ?? NULL;
        $part_val_vogreminder           = $array_intake_vogall['val_part_vogreminder'] ?? NULL;
        $part_val_vogaanvraag           = $array_intake_vogall['val_part_vogaanvraag'] ?? NULL;
        $part_val_vogdatum              = $array_intake_vogall['val_part_vogdatum']    ?? NULL;

        $cont_vog_nodig                 = $array_intake_vogall['cont_vog_nodig']       ?? NULL;
        $cont_vog_status                = $array_intake_vogall['cont_vog_status']      ?? NULL;
        $cont_vog_laatste               = $array_intake_vogall['cont_vog_laatste']     ?? NULL;

        $part_vog_verzoek               = $array_intake_vogall['part_vog_verzoek']     ?? NULL;
        $part_vog_reminder              = $array_intake_vogall['part_vog_reminder']    ?? NULL;
        $part_vog_aanvraag              = $array_intake_vogall['part_vog_aanvraag']    ?? NULL;
        $part_vog_datum                 = $array_intake_vogall['part_vog_datum']       ?? NULL;
    }

    $part_kampkort          = $array_event['part_kampkort']             ?? NULL;
    $part_kampstart         = $array_event['part_kampstart']            ?? NULL;
    $part_kampeinde         = $array_event['part_kampeinde']            ?? NULL;
    $part_functie           = $array_event['part_functie']              ?? NULL;

    $part_vog_nodig         = $array_event['part_vognodig']             ?? NULL;
    $part_vog_status        = $array_event['part_vogstatus']            ?? NULL;

    wachthond($extdebug,1, 'part_kampkort',         $part_kampkort);
    wachthond($extdebug,1, 'part_functie',          $part_functie);
    wachthond($extdebug,1, 'part_kampstart',        $part_kampstart);
    wachthond($extdebug,1, 'part_kampeinde',        $part_kampeinde);

    wachthond($extdebug,3, 'cont_val_vognodig',     $cont_val_vognodig);
    wachthond($extdebug,3, 'cont_val_vogstatus',    $cont_val_vogstatus);
    wachthond($extdebug,3, 'cont_val_voglaatste',   $cont_val_voglaatste);

    wachthond($extdebug,3, 'part_val_vogverzoek',   $part_val_vogverzoek);
    wachthond($extdebug,3, 'part_val_vogreminder',  $part_val_vogreminder);
    wachthond($extdebug,3, 'part_val_vogaanvraag',  $part_val_vogaanvraag);
    wachthond($extdebug,3, 'part_val_vogdatum',     $part_val_vogdatum);

    wachthond($extdebug,3, 'cont_vog_nodig',        $cont_vog_nodig);
    wachthond($extdebug,3, 'cont_vog_status',       $cont_vog_status);
    wachthond($extdebug,3, 'cont_vog_laatste',      $cont_vog_laatste);

    wachthond($extdebug,3, 'part_vog_nodig',        $part_vog_nodig);
    wachthond($extdebug,3, 'part_vog_status',       $part_vog_status);
    wachthond($extdebug,3, 'part_vog_verzoek',      $part_vog_verzoek);
    wachthond($extdebug,3, 'part_vog_reminder',     $part_vog_reminder);
    wachthond($extdebug,3, 'part_vog_aanvraag',     $part_vog_aanvraag);
    wachthond($extdebug,3, 'part_vog_datum',        $part_vog_datum);

    if ($part_vog_nodig) {
        $new_part_vognodig      = $part_vog_nodig; 
        $new_cont_vognodig      = $new_part_vognodig;
    }
    if ($part_vog_status) {
        $new_part_vogstatus     = $part_vog_status;   
        $new_cont_vogstatus     = $new_part_vogstatus;
    }

    if (date_bigger($part_val_vogverzoek,  $part_vog_verzoek)   OR empty($part_vog_verzoek))    {
        $new_part_vogverzoek    = $part_val_vogverzoek;
    } else {
        $new_part_vogverzoek    = $part_vog_verzoek;
    }
    if (date_bigger($part_val_vogaanvraag, $part_vog_aanvraag)  OR empty($part_vog_aanvraag))   {
        $new_part_vogaanvraag   = $part_val_vogaanvraag;
    } else {
        $new_part_vogaanvraag   = $part_vog_aanvraag;
    }
    if (date_biggerequal($part_val_vogdatum, $part_vog_datum)        OR empty($part_vog_datum))      {
        $new_part_vogdatum      = $part_val_vogdatum;
        $new_part_voglaatste    = $part_val_vogdatum;
    } else {
        $new_part_vogdatum      = $part_vog_datum;
    }

    if (date_bigger($part_val_vogdatum, $cont_vog_laatste)      OR empty($cont_vog_laatste))    {
        $new_cont_voglaatste    = $part_val_vogdatum;
    } else {
        $new_cont_voglaatste    = $cont_vog_laatste;
    }

    $part_vogverzoek_infiscalyear_ditjaar   = infiscalyear($new_part_vogverzoek,    $today_datetime,   'part_vogverzoek',   'ditjaar');
    wachthond($extdebug,3, "part_vogverzoek_infiscalyear_ditjaar",      $part_vogverzoek_infiscalyear_ditjaar);
    if ($part_vogverzoek_infiscalyear_ditjaar == 1) {
        $new_cont_vogverzoek    = $new_part_vogverzoek;
    }
    $part_vogaanvraag_infiscalyear_ditjaar  = infiscalyear($new_part_vogaanvraag,   $today_datetime,    'part_vogaanvraag', 'ditjaar');
    wachthond($extdebug,3, "part_vogaanvraag_infiscalyear_ditjaar",     $part_vogaanvraag_infiscalyear_ditjaar);
    if ($part_vogaanvraag_infiscalyear_ditjaar == 1) {
        $new_cont_vogaanvraag   = $new_part_vogaanvraag;
    }
    $part_vogdatum_infiscalyear_ditjaar     = infiscalyear($new_part_vogdatum,      $today_datetime,    'part_vogdatum',    'ditjaar');
    wachthond($extdebug,3, "part_vogdatum_infiscalyear_ditjaar",        $part_vogdatum_infiscalyear_ditjaar);
    if ($part_vogdatum_infiscalyear_ditjaar == 1) {
        $new_cont_vogdatum      = $new_part_vogdatum;
    }

    wachthond($extdebug,3, "new_cont_vognodig",         $new_cont_vognodig);
    wachthond($extdebug,3, "new_cont_vogstatus",        $new_cont_vogstatus);
    wachthond($extdebug,3, "new_cont_voglaatste",       $new_cont_voglaatste);
    wachthond($extdebug,3, "new_cont_vogverzoek",       $new_cont_vogverzoek);
    wachthond($extdebug,3, "new_cont_vogaanvraag",      $new_cont_vogaanvraag);
    wachthond($extdebug,3, "new_cont_vogdatum",         $new_cont_vogdatum);

    wachthond($extdebug,3, "new_part_vognodig",         $new_part_vognodig);
    wachthond($extdebug,3, "new_part_vogstatus",        $new_part_vogstatus);
    wachthond($extdebug,3, "new_part_voglaatste",       $new_part_voglaatste);
    wachthond($extdebug,3, "new_part_vogverzoek",       $new_part_vogverzoek);
    wachthond($extdebug,3, "new_part_vogaanvraag",      $new_part_vogaanvraag);
    wachthond($extdebug,3, "new_part_vogdatum",         $new_part_vogdatum);

    $consolidate_vogdata_array = array(

        'vault'                     => 'vogdata',
        'new_cont_vognodig'         => $new_cont_vognodig,
        'new_cont_vogstatus'        => $new_cont_vogstatus,
        'new_cont_voglaatste'       => $new_cont_voglaatste,
        'new_cont_vogverzoek'       => $new_cont_vogverzoek,
        'new_cont_vogaanvraag'      => $new_cont_vogaanvraag,
        'new_cont_vogdatum'         => $new_cont_vogdatum,

        'new_part_vognodig'         => $new_part_vognodig,
        'new_part_vogstatus'        => $new_part_vogstatus,
        'new_part_voglaatste'       => $new_part_voglaatste,
        'new_part_vogverzoek'       => $new_part_vogverzoek,
        'new_part_vogaanvraag'      => $new_part_vogaanvraag,
        'new_part_vogdatum'         => $new_part_vogdatum,
    );

    return $consolidate_vogdata_array;

}

function intake_refnodig($contact_id, $array_event, $array_intake_refall, $consolidate_refdata_array, $groupID) {

    $extdebug       = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE DETERMINE REFNODIG",                     "[groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,3, "array_event",               $array_event);
    wachthond($extdebug,3, "array_intake_refall",       $array_intake_refall);
    wachthond($extdebug,3, "consolidate_refdata_array", $consolidate_refdata_array);

    if ($consolidate_refdata_array) {

        $new_cont_refnodig          = $consolidate_refdata_array['cont_ref_nodig']      ?? NULL;
        $new_cont_refstatus         = $consolidate_refdata_array['cont_ref_status']     ?? NULL;
        $new_cont_reflaatste        = $consolidate_refdata_array['cont_ref_laatste']    ?? NULL;
        $new_part_refpersoon        = $consolidate_refdata_array['part_ref_persoon']    ?? NULL;
        $new_part_refverzoek        = $consolidate_refdata_array['part_ref_verzoek']    ?? NULL;
        $new_part_reffeedback       = $consolidate_refdata_array['part_ref_feedback']   ?? NULL;
    }

    wachthond($extdebug,1, 'new_cont_refnodig',     $new_cont_refnodig);
    wachthond($extdebug,1, 'new_cont_refstatus',    $new_cont_refstatus);
    wachthond($extdebug,1, 'new_cont_reflaatste',   $new_cont_reflaatste);

    $part_functie       = $array_event['result_allpart_pos_leid_kampfunctie']           ?? NULL;
    $part_kampkort      = $array_event['result_allpart_pos_leid_kampkort']              ?? NULL;
    $curcv_keer_leid    = $array_intake_refall['curcv_keer_leid']                       ?? NULL;
    $grensrefnoggoed    = $consolidate_refdata_array['grensrefnoggoed']                 ?? NULL;

    wachthond($extdebug,1, 'part_functie',          $part_functie);
    wachthond($extdebug,1, 'part_kampkort',         $part_kampkort);
    wachthond($extdebug,1, 'curcv_keer_leid',       $curcv_keer_leid);
    wachthond($extdebug,1, 'grensrefnoggoed',       $grensrefnoggoed);

    ##########################################################################################
    ### BEPAAL REFNODIG OBV KEREN MEE ALS LEIDING
    ##########################################################################################
    if ($curcv_keer_leid == 1) {
        $new_cont_refnodig   = 'eerstex';
    } elseif ($curcv_keer_leid >= 2) { 
        $new_cont_refnodig   = 'opnieuw';
    }
    wachthond($extdebug,3,  "new_cont_refnodig [OBV KEREN MEE]",        $new_cont_refnodig);

    $new_cont_reflaatste_binnengrens              = date_biggerequal($new_cont_reflaatste, $grensrefnoggoed, 'new_cont_reflaatste', 'grensref');
    $new_cont_reflaatste_buitengrens              = date_biggerequal($grensrefnoggoed, $new_cont_reflaatste, 'grensref',  'new_cont_reflaatste');
    wachthond($extdebug,3, "new_cont_reflaatste_binnengrens",           "$new_cont_reflaatste_binnengrens\t[new_cont_reflaatste: $new_cont_reflaatste]");
    wachthond($extdebug,3, "new_cont_reflaatste_buitengrens",           "$new_cont_reflaatste_buitengrens\t[new_cont_reflaatste: $new_cont_reflaatste]");

    $new_cont_reflaatste_infiscalyear_ditjaar     = infiscalyear($new_cont_reflaatste,$today_datetime,      'new_cont_reflaatste','ditjaar');
    $new_cont_reflaatste_infiscalyear_ditevent    = infiscalyear($new_cont_reflaatste,$ditevent_event_start,'new_cont_reflaatste','ditevent');
    wachthond($extdebug,3, "new_cont_reflaatste_infiscalyear_ditjaar",   $new_cont_reflaatste_infiscalyear_ditjaar);
    wachthond($extdebug,3, "new_cont_reflaatste_infiscalyear_ditevent",  $new_cont_reflaatste_infiscalyear_ditevent);

    ##########################################################################################
    ### BEPAAL REFNODIG INDIEN ER NOG GEEN REFDATUM IS
    ##########################################################################################
    if (empty($new_cont_reflaatste)) {
        $new_cont_refnodig          = 'eerstex';
        $new_part_refnodig          = 'eerstex';
        wachthond($extdebug,3,  "new_cont_refnodig [OBV GRENSNOGGOED]",     $new_cont_refnodig);
    }
    ##########################################################################################        
    ### BEPAAL REFNODIG INDIEN LAATSTE REF BUITEN GRENS REF NOGGOED
    ##########################################################################################
    if ($new_cont_reflaatste AND $new_cont_reflaatste_buitengrens == 1) {
        $new_cont_refnodig          = 'opnieuw';
        $new_part_refnodig          = 'opnieuw';
        wachthond($extdebug,3,  "new_cont_refnodig [OBV GRENSNOGGOED]",     $new_cont_refnodig);
    }
    ##########################################################################################        
    ### BEPAAL REFNODIG INDIEN LAATSTE REF NOGGOED (BINNEN GRENSNOGGOED MAAR NIET IN HUIDIG JAAR)
    ##########################################################################################        
    if ($new_cont_reflaatste_binnengrens == 1 AND $new_cont_reflaatste_infiscalyear_ditjaar != 1) {
        $new_cont_refnodig          = 'noggoed';
        $new_part_refnodig          = 'noggoed';
        wachthond($extdebug,3,  "new_cont_refnodig [OBV GRENSNOGGOED]",     $new_cont_refnodig);
    }
    ##########################################################################################
    ### CHECK OF KAMPLEIDER OOK DEEL IS VAN HET BESTUUR
    ##########################################################################################
    $aclgroup_bestuur       = 455;  // M61: hardcoded id of (manual) ACL group ditjaar_bestuur
    $group_bestuur          = acl_group_get($contact_id, $aclgroup_bestuur, 'bestuur');
    $group_bestuur_member   = $group_bestuur['group_member'];

    ##########################################################################################
    ### BEPAAL REFNODIG OBV KAMPFUNCTIE (HOOFDLEIDING OF BESTUUR)
    ##########################################################################################
    if (in_array($part_functie, array('hoofdleiding', 'bestuurslid')) OR $group_bestuur_member == 1) {
        $new_cont_refnodig   = 'elkjaar';
        $new_part_refnodig   = 'elkjaar';        
        wachthond($extdebug,3,  "new_cont_refnodig [OBV KAMPFUNCTIE]",      $new_cont_refnodig);
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (REF NODIG)");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal statussen op via de nieuwe centrale functie
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    // 2. Check: Is de huidige status negatief?
    if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       
        
        wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - RESET REF NODIG", $ditjaar_one_leid_status_id);

        $new_cont_refnodig = '';      
    }    

    wachthond($extdebug,3,  "new_cont_refnodig [UITEINDELIJK]",         $new_cont_refnodig);

    return $new_cont_refnodig;

}


function intake_vognodig($contact_id, $array_event, $array_intake_vogall, $consolidate_vogdata_array, $groupID) {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE DETERMINE VOGNODIG",                     "[groupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,3, "array_event",               $array_event);
    wachthond($extdebug,3, "array_intake_vogall",       $array_intake_vogall);
    wachthond($extdebug,3, "consolidate_vogdata_array", $consolidate_vogdata_array);

    if ($consolidate_vogdata_array) {

        $grensvognoggoed         = $consolidate_vogdata_array['grensvognoggoed']     ?? NULL;

        $cont_vog_nodig          = $consolidate_vogdata_array['cont_vog_nodig']      ?? NULL;
        $cont_vog_status         = $consolidate_vogdata_array['cont_vog_status']     ?? NULL;
        $cont_vog_laatste        = $consolidate_vogdata_array['cont_vog_laatste']    ?? NULL;

        $part_vog_verzoek        = $consolidate_vogdata_array['part_vog_verzoek']    ?? NULL;
        $part_vog_reminder       = $consolidate_vogdata_array['part_vog_reminder']   ?? NULL;
        $part_vog_aanvraag       = $consolidate_vogdata_array['part_vog_aanvraag']   ?? NULL;
        $part_vog_datum          = $consolidate_vogdata_array['part_vog_datum']      ?? NULL;
    }

    wachthond($extdebug,1, 'cont_vog_nodig',     $cont_vog_nodig);
    wachthond($extdebug,1, 'cont_vog_status',    $cont_vog_status);
    wachthond($extdebug,1, 'cont_vog_laatste',   $cont_vog_laatste);

    $part_functie       = $array_intake_vogall['part_functie']      ?? NULL;
    $curcv_keer_leid    = $array_intake_vogall['curcv_keer_leid']   ?? NULL;

    wachthond($extdebug,1, 'part_functie',          $part_functie);
    wachthond($extdebug,1, 'curcv_keer_leid',       $curcv_keer_leid);
    wachthond($extdebug,1, 'grensvognoggoed',       $grensvognoggoed);

    ##########################################################################################
    ### BEPAAL VOGNODIG OBV KEREN MEE ALS LEIDING
    ##########################################################################################
    if ($curcv_keer_leid == 1) {
        $cont_vog_nodig   = 'eerstex';
    } elseif ($curcv_keer_leid >= 2) { 
        $cont_vog_nodig   = 'opnieuw';
    }
    wachthond($extdebug,2,  "cont_vog_nodig [OBV KEREN MEE]",               $cont_vog_nodig);

    $cont_vog_laatste_binnengrens              = date_biggerequal($cont_vog_laatste,  $grensvognoggoed, 'cont_vog_laatste',  'grensvog');
    $cont_vog_laatste_buitengrens              = date_biggerequal($grensvognoggoed,   $cont_vog_laatste,'grensvog',          'cont_vog_laatste');
    wachthond($extdebug,2, "cont_vog_laatste_binnengrens",           "$cont_vog_laatste_binnengrens\t[cont_vog_laatste: $cont_vog_laatste]");
    wachthond($extdebug,2, "cont_vog_laatste_buitengrens",           "$cont_vog_laatste_buitengrens\t[cont_vog_laatste: $cont_vog_laatste]");

    $cont_vog_laatste_infiscalyear_ditjaar     = infiscalyear($cont_vog_laatste,      $today_datetime,      'cont_vog_laatste','ditjaar');
    wachthond($extdebug,2, "cont_vog_laatste_infiscalyear_ditjaar",   $cont_vog_laatste_infiscalyear_ditjaar);

    ##########################################################################################
    ### BEPAAL VOGNODIG INDIEN ER NOG GEEN VOGDATUM IS
    ##########################################################################################
    if (empty($cont_vog_laatste)) {
        $cont_vog_nodig          = 'eerstex';
        $part_vog_nodig          = 'eerstex';
        wachthond($extdebug,2,  "cont_vog_nodig [OBV GRENSNOGGOED]",        $cont_vog_nodig);
    }
    ##########################################################################################        
    ### BEPAAL VOGNODIG INDIEN LAATSTE VOG BUITEN GRENS VOG NOGGOED
    ##########################################################################################
    if ($cont_vog_laatste_buitengrens == 1) {
        $cont_vog_nodig          = 'opnieuw';
        $part_vog_nodig          = 'opnieuw';
        wachthond($extdebug,2,  "cont_vog_nodig [OBV GRENSNOGGOED]",        $cont_vog_nodig);
    }
    ##########################################################################################        
    ### BEPAAL VOGNODIG INDIEN LAATSTE VOG NOGGOED (BINNEN GRENSNOGGOED MAAR NIET IN HUIDIG JAAR)
    ##########################################################################################        
    if ($cont_vog_laatste_binnengrens == 1 AND $cont_vog_laatste_infiscalyear_ditjaar != 1) {
        $cont_vog_nodig          = 'noggoed';
        $part_vog_nodig          = 'noggoed';
        wachthond($extdebug,2,  "cont_vog_nodig [OBV GRENSNOGGOED]",        $cont_vog_nodig);
    }
    ##########################################################################################
    ### CHECK OF KAMPLEIDER OOK DEEL IS VAN HET BESTUUR
    ##########################################################################################
    $aclgroup_bestuur       = 455;  // M61: hardcoded id of (manual) ACL group ditjaar_bestuur
    $group_bestuur          = acl_group_get($contact_id, $aclgroup_bestuur, 'bestuur');
    $group_bestuur_member   = $group_bestuur['group_member'];

    ##########################################################################################
    ### BEPAAL VOGNODIG OBV KAMPFUNCTIE (HOOFDLEIDING OF BESTUUR)
    ##########################################################################################
    if (in_array($part_functie, array('hoofdleiding', 'bestuurslid')) OR $group_bestuur_member == 1) {
        $cont_vog_nodig   = 'elkjaar';
    }
    wachthond($extdebug,2,  "cont_vog_nodig [OBV KAMPFUNCTIE OF BESTUUR]",   $cont_vog_nodig);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (VOG NODIG)");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal statussen op via de nieuwe centrale functie
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    // 2. Check: Is de huidige status negatief?
    if (!empty($ditjaar_one_leid_status_id) && in_array($ditjaar_one_leid_status_id, $status_negative)) {       

        wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - RESET VOG NODIG", $ditjaar_one_leid_status_id);

        $cont_vog_nodig = '';      
    }

    wachthond($extdebug, 2, "cont_vog_nodig [UITEINDELIJK]", $cont_vog_nodig);

    return $cont_vog_nodig;

}

/**
 * Haalt referentie-informatie op.
 * * @param int    $contact_id
 * @param int    $groupID
 * @param string $mode       'current' = Actief in dit boekjaar (standaard)
 * 'recent'  = Nieuwste datum met feedback (historie)
 */
function intake_referentie_get($contact_id, $groupID = NULL, $mode = 'current') {

    $extdebug       = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");

    $referentie_array = [];

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### INTAKE GETREF - GET REFERENTIE ($mode)",       "[groupID: $groupID]");
    wachthond($extdebug,2, "########################################################################");

    // Basis parameters die voor beide modes gelden
    $params_ref_get = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'select' => [
            'row_count',           
            'id',
            'contact_id_a.display_name',
            'contact_id_a.first_name',
            'contact_id_b',
            'contact_id_b.display_name',
            'contact_id_b.first_name',
            'contact_id_b.middle_name',
            'contact_id_b.last_name',
            'contact_id_b.gender_id',
            'start_date', 
            'end_date',
 
            'ref_aanvrager.aanvrager_naam',
            'ref_aanvrager.aanvrager_functie',
            'ref_aanvrager.kamp_partid',
            'ref_aanvrager.kamp_cid',

            'ref_aanvrager.kamp_naam',
            'ref_aanvrager.kamp_eid',
            'ref_aanvrager.kamp_datum',

            'ref_aanvrager.referentie_relatie', 
            'ref_aanvrager.referentie_motivatie',
            'ref_aanvrager.referentie_toestemming',
            'ref_aanvrager.referentie_verzoek',
            'ref_aanvrager.datum_verzoek',
            'ref_feedback.datum_feedback',
            'ref_feedback.Bezwaar',
        ],    
        'where' => [
            ['relationship_type_id','=', 16],
            ['contact_id_a',        '=', $contact_id],
        ],
        'limit' => 1,
    ];

    // --- LOGICA SPLITSING OP BASIS VAN MODE ---
    if ($mode === 'recent') {
        // MODE: RECENT
        // Zoek de nieuwste feedbackdatum, ongeacht of de relatie nog actief is
        $params_ref_get['where'][] = ['ref_feedback.datum_feedback', 'IS NOT NULL'];
        $params_ref_get['orderBy'] = ['ref_feedback.datum_feedback' => 'DESC'];
        
        wachthond($extdebug, 2, "Mode Selection", "RECENT (Sorting by feedback date)");

    } else {
        // MODE: CURRENT (Default)
        // Zoek de actieve relatie voor dit boekjaar
        $params_ref_get['where'][] = ['end_date', '=', 'this.fiscal_year'];
        // $params_ref_get['where'][] = ['is_active', '=', TRUE]; // Optioneel
        
        wachthond($extdebug, 2, "Mode Selection", "CURRENT (Filtering by fiscal year)");
    }

    wachthond($extdebug,3, 'params_ref_get',            $params_ref_get);
    
    $result_ref_get = civicrm_api4('Relationship','get',$params_ref_get);
    
    wachthond($extdebug,9, 'result_ref_get',            $result_ref_get);
    $result_ref_get_refcount =                          $result_ref_get->countMatched();
    wachthond($extdebug,9, 'result_ref_get_refcount',   $result_ref_get_refcount);

    if ($result_ref_get_refcount >= 1) { // >= 1 want limit is 1

        // Gebruik de first() methode; dit is veiliger in PHP 8 dan $result_ref_get[0]
        $ref_row                = $result_ref_get->first();

        $referentie_relid       = (int)($ref_row['id'] ?? 0);
        $referentie_start       = (string)($ref_row['start_date'] ?? '');
        $referentie_einde       = (string)($ref_row['end_date'] ?? '');    

        $aanvrager_cid          = (int)$contact_id;
        $aanvrager_name         = (string)($ref_row['contact_id_a.display_name'] ?? '');
        $aanvrager_voornaam     = (string)($ref_row['contact_id_a.first_name'] ?? '');
        $aanvrager_functie      = (string)($ref_row['ref_aanvrager.aanvrager_functie'] ?? '');
        $aanvrager_kampnaam     = (string)($ref_row['ref_aanvrager.kamp_naam'] ?? '');
        $aanvrager_kamp_eid     = (int)($ref_row['ref_aanvrager.kamp_eid'] ?? 0);
        $aanvrager_kampstart    = (string)($ref_row['ref_aanvrager.kamp_datum'] ?? '');
        
        // Forceer tekstvelden expliciet naar string om mysqli_real_escape_string TypeError te voorkomen
        $aanvrager_relatie      = (string)($ref_row['ref_aanvrager.referentie_relatie'] ?? '');
        $aanvrager_motivatie    = (string)($ref_row['ref_aanvrager.referentie_motivatie'] ?? '');
        $aanvrager_relatie      = trim($aanvrager_relatie);
        $aanvrager_motivatie    = trim($aanvrager_motivatie);

        $referentie_cid         = (int)($ref_row['contact_id_b'] ?? 0);
        $referentie_name        = (string)($ref_row['contact_id_b.display_name'] ?? '');
        $referentie_fn          = (string)($ref_row['contact_id_b.first_name'] ?? '');
        $referentie_mn          = (string)($ref_row['contact_id_b.middle_name'] ?? '');
        $referentie_ln          = (string)($ref_row['contact_id_b.last_name'] ?? '');
        $referentie_geslacht    = trim((string)($ref_row['contact_id_b.gender_id'] ?? ''));

        $referentie_gevraagd    = (string)($ref_row['ref_aanvrager.datum_verzoek'] ?? '');
        $referentie_feedback    = (string)($ref_row['ref_feedback.datum_feedback'] ?? '');
        $referentie_bezwaar     = (string)($ref_row['ref_feedback.Bezwaar'] ?? '');

        // Multi-select veld via de helper
        $ref_toestemming_raw    = $ref_row['ref_aanvrager.referentie_toestemming'] ?? '';
        $referentie_toestemming = format_civicrm_string($ref_toestemming_raw);

        wachthond($extdebug,3, 'referentie_relid',      $referentie_relid);
        wachthond($extdebug,3, 'referentie_cid',        $referentie_cid);
        wachthond($extdebug,3, 'referentie_name',       $referentie_name);

        wachthond($extdebug,3, 'referentie_fn',         $referentie_fn);
        wachthond($extdebug,3, 'referentie_mn',         $referentie_mn);
        wachthond($extdebug,3, 'referentie_ln',         $referentie_ln);

        wachthond($extdebug,3, 'referentie_geslacht',   $referentie_geslacht);
        wachthond($extdebug,3, 'referentie_relatie',    $referentie_relatie);
        wachthond($extdebug,3, 'aanvrager_motivatie',   $aanvrager_motivatie);

        // 1. Zorg dat het ALTIJD een array is (cast naar string en explode indien nodig)
        // Let op: $ref_toestemming_array werd hierboven nog niet gedefinieerd in jouw snippet, 
        // ik neem aan dat je $ref_toestemming_raw bedoelde te exploden.
        // Voor de zekerheid:
        $ref_toestemming_array = [];
        if (!empty($ref_toestemming_raw)) {
             $ref_toestemming_array = array_filter(explode('', trim((string)$ref_toestemming_raw, '')));
        }
        
        // 2. De helper doet uniek maken, sorteren en separators (^A) plakken
        $ref_toestemming = format_civicrm_string($ref_toestemming_array);
        
        // 3. Voor de count filteren we de array (veilig voor PHP 8)
        $ref_toestemming_nr = count(array_filter((array)$ref_toestemming_array));

        wachthond($extdebug,4, 'ref_toestemming_nr',        $ref_toestemming_nr);
        wachthond($extdebug,4, 'ref_toestemming',           $ref_toestemming);
        wachthond($extdebug,4, 'referentie_toestemming',    $referentie_toestemming);
        
        wachthond($extdebug,3, 'referentie_start',          $referentie_start);
        wachthond($extdebug,3, 'referentie_einde',          $referentie_einde);
        wachthond($extdebug,3, 'referentie_gevraagd',       $referentie_gevraagd);
        wachthond($extdebug,3, 'referentie_feedback',       $referentie_feedback);
        wachthond($extdebug,3, 'referentie_bezwaar',        $referentie_bezwaar);

        $referentie_phone = ""; // Init
        if ($referentie_cid > 0) {

            ##########################################################################################
            // GET PHONE VAN REFERENTIE
            ##########################################################################################
            $params_refphone = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,        
                'select' => [
                    'phone',
                ],
                'where' => [
                    ['contact_id',        'IN', [$referentie_cid]],
                    ['location_type_id',  '=', 1],
                    ['phone_type_id',     '=', 2],
                ],
            ];
            $result_refphone    = civicrm_api4('Phone',  'get',      $params_refphone);
            wachthond($extdebug,9, 'result_refphone',               $result_refphone);

            if (isset($result_refphone[0])) {
                $referentie_phone       = $result_refphone[0]['phone'] ?? NULL;
                $referentie_phone       = str_replace(' ', '', $referentie_phone);
                wachthond($extdebug,3, 'referentie_phone', $referentie_phone);
            }
        }

        $referentie_array = array(
            'ref_rel_id'                => $referentie_relid,
            'ref_start'                 => $referentie_start,
            'ref_einde'                 => $referentie_einde,

            'ref_aanvrager_cid'         => $contact_id,
            'ref_aanvrager_naam'        => $aanvrager_name,
            'ref_aanvrager_voornaam'    => $aanvrager_voornaam,

            'ref_aanvrager_kampnaam'    => $aanvrager_kampnaam,
            'ref_aanvrager_kamp_eid'    => $aanvrager_kamp_eid,
            'ref_aanvrager_kampstart'   => $aanvrager_kampstart,

            'ref_aanvrager_functie'     => $aanvrager_functie,
            'ref_aanvrager_relatie'     => $aanvrager_relatie,
            'ref_aanvrager_motivatie'   => $aanvrager_motivatie,
            'ref_aanvrager_toestemming' => $ref_toestemming,

            'ref_gevraagd'              => $referentie_gevraagd,
            'ref_feedback'              => $referentie_feedback,
            'ref_bezwaar'               => $referentie_bezwaar,

            'ref_referentie_cid'        => $referentie_cid,
            'ref_referentie_naam'       => $referentie_name,
            'ref_referentie_voornaam'   => $referentie_fn,
            'ref_referentie_geslacht'   => $referentie_geslacht,
            'ref_referentie_relatie'    => $referentie_relatie,
            'ref_referentie_telefoon'   => $referentie_phone,
        );
    }

    wachthond($extdebug,2, 'referentie_array',  $referentie_array);
    
    return $referentie_array;
}

/**
 * Bepaalt de status van een contactfoto op basis van datum, URL-kenmerken, fysiek bestaan EN afmetingen.
 *
 * @param string $contact_foto      De URL van de foto (uit database/CRM).
 * @param string $fot_update        De datum van laatste update (Y-m-d).
 * @param string $geslacht          'jongen', 'meisje', of leeg.
 * @param string $fiscalyear_start  Startdatum huidig boekjaar (voor verloop-check).
 * @return array                    ['status', 'current_url', 'placeholder_url', 'is_placeholder']
 */
function intake_check_fotostatus($contact_foto, $fot_update, $geslacht, $fiscalyear_start) {

    $extdebug = 3; // Zet op 3 voor volledige logging

    // --- CONFIGURATIE PADEN (Server specifiek) ---
    $web_root = '/var/www/vhosts/ozkprod/web';
    $prv_root = '/var/www/vhosts/ozkprod/private';
    $civicrm_upload_path = $prv_root . '/civicrm/custom/';

    wachthond($extdebug, 3, "FOTO CHECK START", "Input URL: [$contact_foto] | Datum: [$fot_update]");

    // --- STAP 1: Datum Logic (Basis Status) ---
    // -1 = Verlopen, 0 = Leeg/Fout, 1 = Geldig, 2 = Perfect
    $status = 0;

    if (!empty($fot_update)) {
        if (date_biggerequal($fot_update, $fiscalyear_start)) {
            $status = 1; // Recent geupload in dit boekjaar
        } elseif (date_bigger($fiscalyear_start, $fot_update)) {
            $status = -1; // Foto is ouder dan het huidige boekjaar
        }
    } else {
        // NIEUW: Geen datum aanwezig? Dan beschouwen we hem ook als verlopen.
        // Tenzij het straks een placeholder blijkt te zijn (dat wordt in Stap 2 afgevangen).
        $status = -1; 
        wachthond($extdebug, 3, "FOTO CHECK DATUM", "Geen updatedatum gevonden -> Status op -1 (Verlopen) gezet.");
    }

    // --- STAP 2: String & Map Logic (Snelle URL Analyse) ---
    $url_lower = strtolower($contact_foto);

    // Is het een placeholder of leeg? -> Altijd status 0 (Dit overruled de -1 van hierboven)
    if (empty($contact_foto) || str_contains($url_lower, 'placeholder')) {
        $status = 0;
    } else {
        // Geldige mappen checks (voorlopige status upgrade als we nog op 0 staan)
        $is_valid_folder = (
            str_contains($url_lower, 'uploads') || 
            str_contains($url_lower, 'imagefile') || 
            (str_contains($url_lower, 'profielfotos') && str_contains($url_lower, 'instagram'))
        );

        // Als map geldig is en status was onbekend (0) -> zet op 1
        // We overschrijven -1 (te oud) hier NIET.
        if ($is_valid_folder && $status === 0) {
            $status = 1;
        }
    }

    // --- STAP 3: Fysieke File & Dimensie Check ---
    // Alleen uitvoeren als we denken dat er een foto is (status != 0)
    if ($status !== 0) {
        $local_path = '';

        // A. Pad bepalen: CiviCRM URL
        if (str_contains($url_lower, 'civicrm/contact/imagefile')) {
            $query_str = parse_url($contact_foto, PHP_URL_QUERY);
            parse_str($query_str, $params);

            if (isset($params['photo'])) {
                $local_path = $civicrm_upload_path . basename($params['photo']);
                wachthond($extdebug, 3, "FOTO PAD RESOLVE", "Type: CiviCRM | Bestand: " . basename($params['photo']));
            }
        } 
        // B. Pad bepalen: Drupal URL
        else {
            $url_path = parse_url($contact_foto, PHP_URL_PATH); 
            $local_path = $web_root . urldecode($url_path);     
        }

        // C. Checken of bestand bestaat en afmetingen ophalen
        if (!empty($local_path)) {
            $file_check = intake_check_fotofile($local_path, $extdebug);

            // 1. Is het bestand weg of corrupt?
            if ($file_check['isfile'] === 0 || $file_check['getsize'] === 0) {
                wachthond($extdebug, 1, "FOTO CHECK FAIL", "Bestand niet gevonden/corrupt: $local_path");
                $status = 0; // Harde reset naar placeholder
            } 
            // 2. Bestand is in orde -> Check vierkant
            else {
                $w = $file_check['width'];
                $h = $file_check['height'];

                // Is het vierkant? (met marge van 1 pixel)
                $is_square = (abs($w - $h) <= 1);
                
                // Is het groot genoeg? (> 50px)
                $is_big_enough = ($w > 50);

                if ($is_square && $is_big_enough) {
                    // Alleen upgraden naar status 2 als hij NIET verlopen is (-1)
                    if ($status !== -1) {
                        $status = 2;
                        wachthond($extdebug, 3, "FOTO STATUS UPGRADE", "Vierkante foto ($w x $h). Status is nu 2.");
                    } else {
                        wachthond($extdebug, 3, "FOTO STATUS INFO", "Vierkante foto ($w x $h), maar datum is verlopen of leeg (-1).");
                    }
                } else {
                    wachthond($extdebug, 3, "FOTO DIMENSIES", "Foto is rechthoekig ($w x $h). Status blijft $status.");
                }
            }
        } else {
            // Kon URL niet vertalen naar pad
            $status = 0;
        }
    }

    wachthond($extdebug, 3, "FOTO STATUS RESULTAAT", "Berekende Status: $status");

    // --- STAP 4: Placeholder bepalen ---
    $placeholder_base = "https://www.onvergetelijk.nl/sites/default/files/ozkimages/";
    
    if ($geslacht == 'jongen') {
        $placeholder_url = $placeholder_base . "placeholder_boy.png";
    } elseif ($geslacht == 'meisje') {
        $placeholder_url = $placeholder_base . "placeholder_girl.png";
    } else {
        $placeholder_url = $placeholder_base . "placeholder.png";
    }

    return [
        'status'          => $status,
        'current_url'     => $contact_foto,
        'placeholder_url' => $placeholder_url,
        'is_placeholder'  => ($status === 0) 
    ];
}

/**
 * Helper: Valideer of een lokaal pad bestaat, een plaatje is én geef afmetingen terug.
 * * @return array ['isfile' => 0/1, 'getsize' => 0/1, 'width' => int, 'height' => int]
 */
function intake_check_fotofile($path, $extdebug) {

    $extdebug = 3; // Hardcoded debug

    $result = array(
        'isfile'  => 0,
        'getsize' => 0,
        'width'   => 0, 
        'height'  => 0  
    );

    if (empty($path)) {
        return $result;
    }

    // 1. Check fysiek bestaan
    if (is_file($path)) {
        $result['isfile'] = 1;
        
        // 2. Check header en afmetingen
        $image_info = @getimagesize($path);
        
        if ($image_info !== false) {
            $result['getsize'] = 1;
            $result['width']   = $image_info[0]; // Breedte
            $result['height']  = $image_info[1]; // Hoogte
            
            wachthond($extdebug, 3, 'VALIDFOTO INFO', "Bestand OK. Afmetingen: " . $image_info[0] . "x" . $image_info[1]);
        } else {
            wachthond($extdebug, 1, 'VALIDFOTO CORRUPT', 'Bestand is geen geldig image: ' . basename($path));
        }
    } 

    return $result;
}

/**
 * Configureert de Intake status voor een contact (Foto, NAW, BIO).
 * Accepteert NULL voor part_id (?int).
 */
function intake_civicrm_configure(int $contact_id, ?int $part_id = 0): array {

    $extdebug           = 3;
    $today              = date("Y-m-d H:i:s");

    if (!Civi::cache()->get('cache_today_fiscalyear_start')) { find_fiscalyear(); }

    $contact_values     = [];

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE",                                        "[START]");
    wachthond($extdebug,4, "########################################################################");

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - GET CID2CONT",                               "[$contact_id]");
    wachthond($extdebug,3, "########################################################################");

    $allcont_array      = base_cid2cont($contact_id);
    $displayname        = $allcont_array['displayname']                             ?? NULL;
    $fiscalyear         = Civi::cache()->get('cache_today_fiscalyear_start');

    wachthond($extdebug,4, 'allcont_array',     $allcont_array);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - GET ALLPART",                               "[$displayname]");
    wachthond($extdebug,3, "########################################################################");

    $allpart_array      = base_find_allpart($contact_id, $today);
    $part_id            = (int)($allpart_array['result_allpart_pos_part_id']        ?? 0);
    $pos_part_id_deel   = (int)($allpart_array['result_allpart_pos_deel_part_id']   ?? 0);
    $pos_part_id_leid   = (int)($allpart_array['result_allpart_pos_leid_part_id']   ?? 0);
    wachthond($extdebug,4, 'allpart_array',     $allpart_array);

    wachthond($extdebug,3, 'pos_part_id',       $part_id);
    wachthond($extdebug,3, 'pos_part_id_deel',  $pos_part_id_deel);
    wachthond($extdebug,3, 'pos_part_id_leid',  $pos_part_id_leid);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - GET PID2PART",                                  "[$part_id]");
    wachthond($extdebug,3, "########################################################################");

    if ($part_id) {
        $event_array    = $part_id > 0 ? base_pid2part($part_id) : [];
        wachthond($extdebug,4, 'result_pid2part', $event_array);
    }

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE - FOT",                                    "[FOT]");
    wachthond($extdebug,4, "########################################################################");

    $foto_url           = $allcont_array['contact_foto']                            ?? '';
    $foto_datum         = $allcont_array['cont_fotupdate']                          ?? '';
    $gender             = $allcont_array['gender']                                  ?? '';

    $fot                = intake_check_fotostatus($foto_url, $foto_datum, $gender, $fiscalyear);
    $contact_values['INTAKE.FOT_status']   = $fot['status']   ?? '';

    if ($fot['status'] == 0 && ($fot['current_url'] !== $fot['placeholder_url'])) {
        $contact_values['image_URL'] = $fot['placeholder_url'];
    }

    wachthond($extdebug,3, 'result_fot', $fot);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE - NAW",                                    "[NAW]");
    wachthond($extdebug,4, "########################################################################");

    $naw_gecheckt   = $allcont_array['cont_nawgecheckt'] ?? NULL;
    $keer_leid      = $allcont_array['curcv_keer_leid']  ?? 0;
    $register_date  = $event_array['register_date']      ?? NULL;

    $naw            = intake_status_naw($contact_id, $naw_gecheckt, $keer_leid, $register_date, $today);
    wachthond($extdebug,3, 'result_naw', $naw);

    $contact_values['INTAKE.NAW_nodig']     = $naw['nodig']     ?? '';
    $contact_values['INTAKE.NAW_gecheckt']  = $naw['gecheckt']  ?? '';
    $contact_values['INTAKE.NAW_status']    = $naw['status']    ?? '';

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE - BIO",                                    "[BIO]");
    wachthond($extdebug,4, "########################################################################");

    $bio_ingevuld = $allcont_array['cont_bioingevuld'] ?? NULL;
    $bio_gecheckt = $allcont_array['cont_biogecheckt'] ?? NULL;

    $bio = intake_status_bio($contact_id, $bio_ingevuld, $bio_gecheckt, $today);
    wachthond($extdebug,3, 'result_bio', $bio);

    $contact_values['INTAKE.BIO_nodig']     = $bio['nodig']     ?? '';
    $contact_values['INTAKE.BIO_gecheckt']  = $bio['gecheckt']  ?? '';
    $contact_values['INTAKE.BIO_status']    = $bio['status']    ?? '';

    // Voer alleen uit als er een actieve deelname is gevonden ($pos_part_id_leid > 0)
    if ($pos_part_id_leid > 0) {
        
        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,3, "### INTAKE - CONFIGURE - REF",                   "[VOOR PARTID $part_id]");
        wachthond($extdebug,4, "########################################################################");

        $ref = intake_referentie_configure($contact_id, $pos_part_id_leid, $allpart_array, $event_array);
        wachthond($extdebug,3, 'result_ref', $ref);

        $contact_values['INTAKE.REF_nodig']     = $ref['nodig']     ?? '';
        $contact_values['INTAKE.REF_status']    = $ref['status']    ?? '';
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
        $vog = intake_vog_configure($contact_id, $pos_part_id_leid, $params, $allpart_array, $event_array, $groupID);
        wachthond($extdebug, 3, 'result_vog', $vog);

        // De centrale $contact_values array bijwerken met de resultaten uit de VOG module
        $contact_values['INTAKE.VOG_nodig']     = $vog['nodig']     ?? '';
        $contact_values['INTAKE.VOG_status']    = $vog['status']    ?? '';
        $contact_values['INTAKE.VOG_laatste']   = $vog['laatste']   ?? '';
    }

    wachthond($extdebug, 4, "########################################################################");
    wachthond($extdebug, 3, "### INTAKE - CONFIGURE - INT",                                    "[INT]");
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

    $contact_values['INTAKE.INT_status'] = $new_int_status;

    wachthond($extdebug, 3, "contact_values", $contact_values);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE - UPDATE CONTACT",                 "[UPDATE CONT]");
    wachthond($extdebug,4, "########################################################################");

    // --- DIRTY CHECK CONTACT ---
    $params_get_c = [
        'checkPermissions' => FALSE,
        'select'    => array_keys($contact_values),
        'where'     => [['id', '=', $contact_id]],
    ];
    $current_data_c = civicrm_api4('Contact', 'get', $params_get_c)->first();

    $clean_values_c = [];
    $has_changes_c  = false;

    foreach ($contact_values as $key => $new_val) {
        $old_val = $current_data_c[$key] ?? '';
        
        // --- FIX: OOK HIER DATUM NORMALISATIE ---
        if (strlen($old_val) == 10 && strlen($new_val) == 19 && strpos($new_val, $old_val) === 0) {
             continue; 
        }
        if (empty($new_val) && empty($old_val)) {
            continue;
        }

        if ($new_val != $old_val) {
            $clean_values_c[$key]   = $new_val;
            $has_changes_c          = true;
            // Zet aan voor debug:
            // wachthond($extdebug, 1, "INTAKE CHANGE CONTACT [$key]", "Oud: '$old_val' -> Nieuw: '$new_val'");
        }
    }

    if ($has_changes_c) {
        $params_c_config = [
            'checkPermissions' => FALSE, 
            'where'     => [['id', '=', $contact_id]], 
            'values'    => $clean_values_c
        ];
        try { 
            wachthond($extdebug,3,'params_contact_configure',   $params_c_config);
            $result_c_config = civicrm_api4('Contact','update', $params_c_config);
            wachthond($extdebug,9,'result_contact_configure',   $result_c_config); 
            wachthond($extdebug,3,'result_contact_configure', "EXECUTED (Wijzigingen gevonden)");
        } catch (\Exception $e) { 
            wachthond($extdebug, 1, "Fout configure contact: ".$e->getMessage()); 
        }
    } else {
        wachthond($extdebug, 3, 'result_contact_configure', "SKIPPED (Geen wijzigingen)");
    }

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE - UPDATE PARTICIPANT",             "[UPDATE PART]");
    wachthond($extdebug,4, "########################################################################");

    if ($part_id > 0) {
        $part_values = [
            'PART.NAW_gecheckt'             => $naw['gecheckt'], 
            'PART.BIO_gecheckt'             => $bio['gecheckt'],

//          'PART_LEID_INTERN.INT_nodig'    => $int['nodig'],
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

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, "### INTAKE - CONFIGURE INTAKE WAARDEN VOOR $displayname",       "[EINDE]");
    wachthond($extdebug,4, "########################################################################");

    return ['fot' => $fot, 'naw' => $naw, 'bio' => $bio, 'ref' => $ref];
}

/**
 * Configureert de volledige logica voor Referenties (Check, Status, Activities, Updates).
 *
 * DOEL:
 * Deze functie is de centrale 'motor' voor het referentieproces. Hij bepaalt:
 * 1. Heeft deze vrijwilliger een referentie nodig? (Nieuw of verlopen?)
 * 2. Wat is de status van de referentie (opgegeven, gevraagd, feedback ontvangen)?
 * 3. Maakt of update de bijbehorende CiviCRM taken (Activities).
 * 4. Schrijft de berekende statussen weg naar Contact en Participant records.
 *
 * @param int   $contact_id     Het Contact ID van de vrijwilliger.
 * @param int   $part_id        Het Participant ID van de huidige inschrijving.
 * @param array $allpart_array  (Optioneel) Cache: Resultaat van base_allpart() om DB-calls te besparen.
 * @param array $event_array    (Optioneel) Cache: Resultaat van base_pid2part() voor event-dates.
 * @param array $params         (Optioneel) Formulier input (alleen beschikbaar bij hook_pre edit).
 * @param int   $groupID        (Optioneel) ID van de profielgroep die de trigger gaf (0 = Systeem/Core).
 */
function intake_referentie_configure($contact_id, $part_id, $allpart_array = [], $event_array = [], $params = [], $groupID = 0) {

    $extdebug   = 0; // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE; 
    $today      = date('Y-m-d');
    
    // Zorg dat groupID altijd een waarde heeft voor logging (0 betekent: aangeroepen door systeem/backend)
    $groupID    = $groupID ?? 0;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 1.0 DATA VOORBEREIDING", "[PartID: $part_id | GroupID: $groupID]");
    wachthond($extdebug,1, "########################################################################");

    // -------------------------------------------------------------------------
    // Stap 1a: Deelnemer Historie (AllPart)
    // -------------------------------------------------------------------------
    // We moeten weten of iemand al eerder leiding was. 
    // OPTIMALISATIE: Als $allpart_array is meegegeven (vanuit intake_configure), gebruiken we die.
    // Anders halen we hem hier vers op.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 1.1 Stap 1a: Haal alle deelnemer-historie op (AllPart)");
    wachthond($extdebug,1, "########################################################################");

    if (!empty($allpart_array)) {
        $array_allpart_ditjaar = $allpart_array;
        wachthond($extdebug,2, "AllPart data", "Opgehaald uit input argument (Cache)");
    } else {
        $array_allpart_ditjaar = base_find_allpart($contact_id, $today);
        wachthond($extdebug,2, "AllPart data", "Vers opgehaald via base_allpart");
    }
    
    // Debug de belangrijkste beslis-variabelen
    wachthond($extdebug,4, "Allpart: Pos Leid Part ID", $array_allpart_ditjaar['ditjaar_pos_leid_part_id'] ?? 'NULL');
    wachthond($extdebug,4, "Allpart: Status ID",        $array_allpart_ditjaar['ditjaar_one_leid_status_id'] ?? 'NULL');

    // -------------------------------------------------------------------------
    // Stap 1b: Event Informatie (Kamp data)
    // -------------------------------------------------------------------------
    // We moeten weten wanneer het kamp start om het boekjaar te bepalen.
    // Ook hier: gebruik cache indien beschikbaar.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 1.2 Stap 1b: Haal Event informatie op");
    wachthond($extdebug,1, "########################################################################");

    if (!empty($event_array)) {
        $event_array = $event_array; // Expliciet toewijzen voor duidelijkheid
        wachthond($extdebug,2, "Event data", "Opgehaald uit input argument (Cache)");
    } else {
        // Fallback: Zoek Event ID via de API als we alleen een Part ID hebben
        $check_p         = civicrm_api4('Participant', 'get', ['select' => ['event_id'], 'where' => [['id', '=', $part_id]]])->first();
        $target_event_id = $check_p['event_id'] ?? NULL;

        if ($target_event_id) {
            $params_ev = [
                'where'            => [['id', '=', $target_event_id]],
                'checkPermissions' => FALSE,
            ];
            $event_array = civicrm_api4('Event', 'get', $params_ev)->first();
            wachthond($extdebug,2, "Event data", "Vers opgehaald voor Event ID: $target_event_id");
        } else {
            $event_array = [];
            wachthond($extdebug,1, "Event data", "FOUT: Geen Event ID gevonden bij Participant $part_id");
        }
    }
    
    // -------------------------------------------------------------------------
    // Stap 1c: Config & Fiscal Year
    // -------------------------------------------------------------------------
    // Bepaal in welk boekjaar dit kamp valt (eerste 4 cijfers van startdatum).
    // Bepaal de 'grensdatum': referenties ouder dan deze datum zijn niet meer geldig.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 1.3 Stap 1c: Bereken boekjaar en grenzen");
    wachthond($extdebug,1, "########################################################################");

    $event_start        = $event_array['start_date'] ?? date('Y-m-d');
//  $event_fiscalyear   = substr($event_start, 0, 4);
    $event_fiscalyear   = $event_array['event_fiscalyear']      ?? NULL;
//  $grensrefnoggoed    = '2022-01-01'; // TODO: Dit eventueel later uit een systeem-setting halen
    // Haal op uit cache zoals in de rest van intake.php
    $grensrefnoggoed    = Civi::cache()->get('cache_grensrefnoggoed3');

    wachthond($extdebug,2, "Berekend Boekjaar",       $event_fiscalyear);
    wachthond($extdebug,2, "Grensdatum (Ref Geldig)", $grensrefnoggoed);

    // -------------------------------------------------------------------------
    // Stap 2: Verwerk Formulier Input ($params)
    // -------------------------------------------------------------------------
    // Als de gebruiker het profiel opslaat, zitten de nieuwe waardes in $params.
    // Deze veldnamen zijn vaak cryptisch (bv 'ref_persoon_1301'). We mappen ze hier naar leesbare variabelen.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 2.0 GET VALUES FROM PARAMS",   "[INPUT VERWERKING]");
    wachthond($extdebug,1, "########################################################################");

    $val_datum_refpersoon  = NULL;
    $val_datum_refgevraagd = NULL;
    $val_datum_reffeedback = NULL;

    $ref_date_mapping = [
        'ref_persoon_1301'  => 'val_datum_refpersoon',
        'ref_gevraagd_1295' => 'val_datum_refgevraagd',
        'ref_feedback_1296' => 'val_datum_reffeedback',
    ];

    if (!empty($params)) {
        wachthond($extdebug,3, "Params ontvangen", count($params) . " items");
        foreach ($params as $i => $item) {
            $col = $item['column_name'] ?? '';
            
            if (isset($ref_date_mapping[$col])) {
                $varName  = $ref_date_mapping[$col];
                // Smart helper zorgt dat dd-mm-yyyy correct yyyy-mm-dd wordt
                $$varName = format_civicrm_smart($item['value'] ?? NULL, $col);
                
                wachthond($extdebug, 2, ">> DATUM GEVONDEN IN PARAMS ($col)", "$varName = " . $$varName);
            }
        }
    } else {
        wachthond($extdebug,2, "Geen params ontvangen", "Aangeroepen vanuit Core update (geen formulier input)");
    }

    // -------------------------------------------------------------------------
    // Stap 3: Huidige Database Waarden Ophalen
    // -------------------------------------------------------------------------
    // We moeten weten wat er NU in de database staat. Dit is nodig om te vergelijken
    // of om gaten op te vullen als $params leeg is (bij een core update).
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 3.0 GET CURRENT DATA",          "[PARTICIPANT API]");
    wachthond($extdebug,1, "########################################################################");

    $params_part = [
        'select' => [
            'id',
            'contact_id',
            'contact_id.display_name',
            'contact_id.INTAKE.REF_laatste',      
            'PART.PART_kampstart',
            'PART.PART_kampkort',
            'PART.PART_kampfunctie',
            'PART_LEID_INTERN.REF_nodig',
            'PART_LEID_INTERN.REF_status',
            'PART_LEID_REF.REF_persoon',
            'PART_LEID_REF.REF_gevraagd',
            'PART_LEID_REF.REF_feedback',
            'PART_LEID_REFERENTIE.referentie_cid',
            'PART_LEID_REFERENTIE.referentie_naam',
        ],
        'where' => [
            ['is_test', 'IN', [TRUE, FALSE]], 
            ['id',      '=',  $part_id],
        ],
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
    ];

    wachthond($extdebug,7, 'params_part', $params_part);
    $result_part = civicrm_api4('Participant','get',$params_part);
    wachthond($extdebug,9, 'result_part', $result_part);

    if (isset($result_part) && count($result_part) > 0) {
        $r = $result_part[0]; // Korte alias

        $displayname      = $r['contact_id.display_name']              ?? 'Onbekend';
        $kampstart        = $r['PART.PART_kampstart']                  ?? NULL;
        $kampkort         = $r['PART.PART_kampkort']                   ?? NULL;
        $kampfunctie      = $r['PART.PART_kampfunctie']                ?? NULL;

        $cont_reflaatste  = $r['contact_id.INTAKE.REF_laatste']        ?? NULL;
        
        $part_refpersoon  = $r['PART_LEID_REF.REF_persoon']            ?? NULL;
        $part_refgevraagd = $r['PART_LEID_REF.REF_gevraagd']           ?? NULL;
        $part_reffeedback = $r['PART_LEID_REF.REF_feedback']           ?? NULL;
        
        $part_refcid      = $r['PART_LEID_REFERENTIE.referentie_cid']  ?? NULL;
        $part_refnaam     = $r['PART_LEID_REFERENTIE.referentie_naam'] ?? NULL;

        wachthond($extdebug,1, 'Huidige Data Displayname', $displayname);
        wachthond($extdebug,2, 'Huidige DB Ref Persoon',   $part_refpersoon);
        wachthond($extdebug,2, 'Huidige DB Ref Feedback',  $part_reffeedback);
    } else {
        wachthond($extdebug,1, "FOUT: Geen participant data gevonden voor ID $part_id (Script stopt)");
        return; 
    }

    // -------------------------------------------------------------------------
    // Stap 3.1: DEEP DIVE - Check Relaties voor recentste feedback (HISTORIE)
    // -------------------------------------------------------------------------
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 3.1 CHECK RELATIES (MODE: RECENT)");
    wachthond($extdebug,1, "########################################################################");

    // We halen de RECENTSTE referentie MET feedback op (ongeacht het jaar)
    $rel_data_laatste   = intake_referentie_get($contact_id, 0, 'recent');

    $rel_laatste_datum  = $rel_data_laatste['ref_feedback']        ?? NULL; 
    $rel_cid            = $rel_data_laatste['ref_referentie_cid']  ?? NULL; 
    $rel_naam           = $rel_data_laatste['ref_referentie_naam'] ?? NULL; 
    $rel_bezwaar        = $rel_data_laatste['ref_bezwaar']         ?? NULL; 

    if ($rel_laatste_datum) {
        
        // LOGICA: Als de relatie-datum nieuwer is dan wat we in de DB vonden,
        // OF als we nog helemaal geen datum hadden -> Gebruik deze data voor het Contactprofiel.
        if (empty($cont_reflaatste) || $rel_laatste_datum > $cont_reflaatste) {
            
            $cont_reflaatste = $rel_laatste_datum;
            
            if ($rel_cid)               { $part_refcid  = $rel_cid;  }
            if ($rel_naam)              { $part_refnaam = $rel_naam; }
            if (!empty($rel_bezwaar))   { $referentie_array['ref_bezwaar'] = $rel_bezwaar; }
            
            wachthond($extdebug, 2, "UPDATE REF_DATA (HISTORIE)", "Contactgegevens bijgewerkt met recentste relatie ($cont_reflaatste).");
        }
    }
    
    // Variabele definiëren voor de log en consolidatie
    $cont_refbezwaar = $referentie_array['ref_bezwaar'] ?? 'Geen';

    wachthond($extdebug,3, 'Naam deelnemer',    $displayname);
    wachthond($extdebug,3, 'rel_laatste_datum', $rel_laatste_datum);
    wachthond($extdebug,3, 'rel_cid',           $rel_cid);
    wachthond($extdebug,3, 'rel_naam',          $rel_naam);
    wachthond($extdebug,3, 'rel_bezwaar',       $rel_bezwaar);

    // -------------------------------------------------------------------------
    // Stap 4: Consolideren (Samenvoegen)
    // -------------------------------------------------------------------------
    // Hier bepalen we de "waarheid". 
    // REGEL: Als er input is ($params/val_datum...), gebruiken we die.
    // REGEL: Als er GEEN input is, gebruiken we de bestaande DB waarde.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 4.0 CONSOLIDATE DATA",             "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $val_part_refpersoon    = $val_datum_refpersoon;
    $val_part_refgevraagd   = $val_datum_refgevraagd;
    $val_part_reffeedback   = $val_datum_reffeedback;

    $array_intake_refall    = [
        'grensrefnoggoed'      => $grensrefnoggoed,
        'val_part_refpersoon'  => $val_part_refpersoon,
        'val_part_refgevraagd' => $val_part_refgevraagd,
        'val_part_reffeedback' => $val_part_reffeedback,
        'cont_ref_laatste'     => $cont_reflaatste,
        'part_ref_persoon'     => $part_refpersoon,
        'part_ref_gevraagd'    => $part_refgevraagd,
        'part_ref_feedback'    => $part_reffeedback,
    ];

    wachthond($extdebug,4, "Input voor Consolidate", $array_intake_refall);
    
    // De consolidate helper functie doet het zware werk van vergelijken
    $consolidate_refdata_array = intake_consolidate_refdata($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_refall, $groupID);
    
    wachthond($extdebug,2, 'UITKOMST Consolidate Array', $consolidate_refdata_array);

    // Variabelen uitpakken uit de helper resultaten
    $new_cont_refnodig   = $consolidate_refdata_array['new_cont_refnodig'];
    $new_cont_refstatus  = $consolidate_refdata_array['new_cont_refstatus'];
    $new_cont_reflaatste = $consolidate_refdata_array['new_cont_reflaatste'];
    $new_cont_refpersoon = $consolidate_refdata_array['new_cont_refpersoon'];
    $new_cont_refverzoek = $consolidate_refdata_array['new_cont_refverzoek'];
    $new_cont_refdatum   = $consolidate_refdata_array['new_cont_refdatum'];

    $new_part_refnodig   = $consolidate_refdata_array['new_part_refnodig'];
    $new_part_refstatus  = $consolidate_refdata_array['new_part_refstatus'];

    wachthond($extdebug,2, "Consolidated Datum Persoon", $new_cont_refpersoon);

    // -------------------------------------------------------------------------
    // Stap 5: Berekening Ref NODIG
    // -------------------------------------------------------------------------
    // Is dit een nieuwe vrijwilliger? Of is de laatste referentie te oud (> grensdatum)?
    // Zo ja, zet ref_nodig op 'Ja'.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 5.0 REFNODIG CALCULATION",         "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $new_cont_refnodig = intake_refnodig($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_refall, $consolidate_refdata_array, $groupID);
    $new_part_refnodig = $new_cont_refnodig; // Participant status volgt contact status

    // Update de consolidate array, want status berekening (stap 7) heeft dit nodig
    $consolidate_refdata_array['new_cont_refnodig'] = $new_cont_refnodig;
    $consolidate_refdata_array['new_part_refnodig'] = $new_part_refnodig;

    wachthond($extdebug,1, 'UITKOMST Ref Nodig?', ($new_cont_refnodig ? $new_cont_refnodig : 'NEE / Leeg'));

    // -------------------------------------------------------------------------
    // Stap 6: Sync naar Contact Profiel (Historie)
    // -------------------------------------------------------------------------
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 6.0 SYNC CONTACT INFO",       "[HISTORY FILL]");
    wachthond($extdebug,1, "########################################################################");

    $new_cont_refcid            = NULL;
    $new_cont_refnaam           = NULL;
    $new_cont_refdatum          = NULL; // Feedback datum
    $new_cont_refpersoon        = NULL; // Persoon datum

    if (!empty($cont_reflaatste)) {

        $new_cont_refcid        = $part_refcid;
        $new_cont_refnaam       = $part_refnaam;
        
        // HIER DE CORRECTIE:
        $new_cont_refdatum      = $cont_reflaatste;  // Dit is de Feedback datum -> INTAKE.REF_datum    
        wachthond($extdebug,1, "SYNC CONTACT: Referentie historie bijgewerkt. Feedback: $new_cont_refdatum / Persoon: $new_cont_refpersoon");
    }

    // -------------------------------------------------------------------------
    // Stap 6.1: Sync naar Participant Profiel (Actueel - Dit Jaar)
    // -------------------------------------------------------------------------
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 6.1 SYNC PARTICIPANT INFO",   "[MODE: CURRENT]");
    wachthond($extdebug,1, "########################################################################");

    $new_part_refcid        = NULL;
    $new_part_refnaam       = NULL;

    // We halen nu specifiek de 'CURRENT' relatie op (actief in dit fiscal year)
    $rel_data_current       = intake_referentie_get($contact_id, 0, 'current');

    if (!empty($rel_data_current['ref_rel_id'])) {
        
        // GEVONDEN: Er is een actieve referent voor dit jaar.
        // Deze gegevens schrijven we naar de Participant record.
        
        $new_part_refcid    = $rel_data_current['ref_referentie_cid']  ?? NULL;
        $new_part_refnaam   = $rel_data_current['ref_referentie_naam'] ?? NULL;

        // Als er een actieve referent is, is dat per definitie ook de 'laatste'.
        // We zorgen dat de contact-variabelen gelijk lopen met de actieve relatie.
        if ($new_part_refcid) {
            $new_cont_refcid  = $new_part_refcid;
            $new_cont_refnaam = $new_part_refnaam;
            // Datum nemen we alleen mee als die in de current array zit (bv feedback datum)
            if (!empty($rel_data_current['ref_feedback'])) {
                $new_cont_refpersoon = $rel_data_current['ref_feedback'];
            }
        }

        wachthond($extdebug, 1, "SYNC PARTICIPANT: Actieve relatie gevonden ($new_part_refnaam).");
    } else {
        wachthond($extdebug, 2, "SYNC PARTICIPANT: Geen actieve relatie voor dit jaar gevonden.");
    }

    // -------------------------------------------------------------------------
    // Stap 7: Bepaal Statussen voor Taken
    // -------------------------------------------------------------------------
    // We splitsen het proces in tweeën:
    // A. Het aanleveren van de naam (Ref Persoon).
    // B. Het binnenkomen van de feedback (Ref Feedback).
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 7.0 BEPAAL STATUSSEN", "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    // A. Ref Persoon Status
    $intake_status_refpersoon_array = intake_status_refpersoon($contact_id, $event_array, $consolidate_refdata_array, $new_cont_refnodig, $groupID);
    wachthond($extdebug,3, "Status Array (Persoon)", $intake_status_refpersoon_array);
    
    $new_refpersoon_actdatum        = $intake_status_refpersoon_array['new_refpersoon_actdatum'];
    $new_refpersoon_actstatus       = $intake_status_refpersoon_array['new_refpersoon_actstatus'];
    $new_refpersoon_actprio         = $intake_status_refpersoon_array['new_refpersoon_actprio'];

    // B. Ref Feedback Status
    $intake_status_reffeedback_array = intake_status_reffeedback($contact_id, $event_array, $consolidate_refdata_array, $new_cont_refnodig, $groupID);
    wachthond($extdebug,3, "Status Array (Feedback)", $intake_status_reffeedback_array);
    
    $new_reffeedback_actdatum        = $intake_status_reffeedback_array['new_reffeedback_actdatum'];
    $new_reffeedback_actstatus       = $intake_status_reffeedback_array['new_reffeedback_actstatus'];
    $new_reffeedback_actprio         = $intake_status_reffeedback_array['new_reffeedback_actprio'];

    // Eindstatus bepalen (samenvatting van het geheel)
    $new_cont_refstatus = $intake_status_reffeedback_array['new_cont_refstatus'];
    $new_part_refstatus = $intake_status_reffeedback_array['new_part_refstatus'];
    
    wachthond($extdebug,1, "Eindstatus Ref", $new_cont_refstatus);

    // -------------------------------------------------------------------------
    // Stap 8: CiviCRM Activiteiten (Taken)
    // -------------------------------------------------------------------------
    // Op basis van de statussen maken we taken aan of werken we ze bij.
    // Dit zorgt dat het op de takenlijst van de Backoffice komt.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 8.0 ACTIVITIES",                  "[UPDATE/CREATE]");
    wachthond($extdebug,1, "########################################################################");

    $activity_array = [
        'displayname'       => $displayname,
        'contact_id'        => $contact_id,
        'activity_source'   => 1, 
        'activity_target'   => $contact_id,
    ];

    $intake_array = [
        'ref_nodig'         => $new_cont_refnodig,
        'ref_datum_persoon' => $new_cont_refpersoon,
        'ref_datum_gevraagd'=> $new_cont_refverzoek,
        'ref_datum_feedback'=> $new_cont_refdatum,
        'ref_bezwaar'       => $referentie_array['ref_bezwaar'] ?? NULL,
    ];

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 8.1 ACTIVITIES REF PERSOON",            "[PERSOON]");
    wachthond($extdebug,1, "########################################################################");

    // --- ACTIVITY: REF PERSOON ---
    if ($new_refpersoon_actstatus) {
        $activity_array['activity_type_id']     = 139; // ref_persoon
        $activity_array['activity_naam']        = 'ref_persoon';
        $activity_array['activity_subject']     = 'REF persoon doorgeven';
        $activity_array['activity_date_time']   = $new_refpersoon_actdatum;
        $activity_array['activity_status_name'] = $new_refpersoon_actstatus;
        $activity_array['activity_prioriteit']  = $new_refpersoon_actprio;

        $existing_act = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
        $act_id       = $existing_act['activity_id'] ?? NULL;

        if (empty($act_id)) {
            $act_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
            wachthond($extdebug, 1, "ACTION: Activity Created (RefPersoon)", "ID: $act_id");
        } else {
            wachthond($extdebug, 2, "ACTION: Activity Found (RefPersoon)", "ID: $act_id (Updating...)");
            $activity_array['activity_id'] = $act_id;
            intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $referentie_array, $groupID);
        }
    } else {
        wachthond($extdebug, 3, "Activity RefPersoon SKIPPED", "Geen status bepaald");
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 8.1 ACTIVITIES REF FEEDBACK",          "[FEEDBACK]");
    wachthond($extdebug,1, "########################################################################");

    // --- ACTIVITY: REF FEEDBACK ---
    if ($new_reffeedback_actstatus) {
        $activity_array['activity_type_id']     = 117; // ref_feedback
        $activity_array['activity_naam']        = 'ref_feedback';
        $activity_array['activity_subject']     = 'REF feedback ontvangen';
        $activity_array['activity_date_time']   = $new_reffeedback_actdatum;
        $activity_array['activity_status_name'] = $new_reffeedback_actstatus;
        $activity_array['activity_prioriteit']  = $new_reffeedback_actprio;

        if ($referentie_array['ref_referentie_cid'] ?? NULL) {
            $activity_array['activity_assignee'] = $referentie_array['ref_referentie_cid'];
        }

        $existing_act = intake_activity_get($contact_id, $activity_array, $event_fiscalyear);
        $act_id       = $existing_act['activity_id'] ?? NULL;

        if (empty($act_id)) {
            $act_id = intake_activity_create($contact_id, $activity_array, $event_array, $intake_array, $groupID);
            wachthond($extdebug, 1, "ACTION: Activity Created (RefFeedback)", "ID: $act_id");
        } else {
            wachthond($extdebug, 2, "ACTION: Activity Found (RefFeedback)", "ID: $act_id (Updating...)");
            $activity_array['activity_id'] = $act_id;
            intake_activity_update($contact_id, $activity_array, $event_array, $intake_array, $referentie_array, $groupID);
        }
    } else {
        wachthond($extdebug, 3, "Activity RefFeedback SKIPPED", "Geen status bepaald");
    }

    // -------------------------------------------------------------------------
    // Stap 9: Update Contact Record
    // -------------------------------------------------------------------------
    // Schrijf de berekende waardes weg naar het algemene Contact profiel.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 9.0 UPDATE CONTACT",               "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $params_cont_update = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,          
        'reload'           => TRUE,
        'where'            => [['id',   '=',$contact_id]],
        'values'           => ['id'     =>  $contact_id],
    ];

    if ($new_cont_refcid)     { $params_cont_update['values']['INTAKE.REF_cid']     = $new_cont_refcid;     }
    if ($new_cont_refnaam)    { $params_cont_update['values']['INTAKE.REF_naam']    = $new_cont_refnaam;    }
    if ($new_cont_refdatum)   { $params_cont_update['values']['INTAKE.REF_datum']   = $new_cont_refdatum;   }
    if ($new_cont_refpersoon) { $params_cont_update['values']['INTAKE.REF_persoon'] = $new_cont_refpersoon; }
    if ($new_cont_refnodig)   { $params_cont_update['values']['INTAKE.REF_nodig']   = $new_cont_refnodig;   }
    if ($new_cont_refstatus)  { $params_cont_update['values']['INTAKE.REF_status']  = $new_cont_refstatus;  }

    // --- CHECK: NEGATIVE STATUS (Annulering) ---
    // Als de deelnemer geannuleerd is (Negative status), moeten we de intake-velden leegmaken.
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];
    $current_status  = $array_allpart_ditjaar['ditjaar_one_leid_status_id'] ?? NULL;

    if (!empty($current_status) && in_array($current_status, $status_negative)) {        
        wachthond($extdebug, 1, "STATUS NEGATIVE DETECTED - CLEARING REF FIELDS", "Status ID: $current_status");
        $params_cont_update['values']['INTAKE.REF_nodig']   = "";
        $params_cont_update['values']['INTAKE.REF_status']  = "";
    }

    // Execute Contact Update
    if (count($params_cont_update['values']) > 1) { 
        wachthond($extdebug,2, 'Params voor Contact Update',    $params_cont_update);
        $result_cont_update = civicrm_api4('Contact','update',  $params_cont_update);
        wachthond($extdebug,9, 'Result Contact Update',         $result_cont_update);
    } else {
        wachthond($extdebug,2, 'Contact Update Skipped', 'Geen wijzigingen gevonden');
    }

    // -------------------------------------------------------------------------
    // Stap 10: Update Participant Record
    // -------------------------------------------------------------------------
    // Schrijf de status weg naar de specifieke inschrijving (Participant record).
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 10.0 UPDATE PARTICIPANT", "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $params_part_update = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,          
        'reload'           => TRUE,
        'where'            => [['id',   '=',$part_id]],
        'values'           => ['id'     =>  $part_id],
    ];

    if ($new_part_refnodig)  { $params_part_update['values']['PART_LEID_INTERN.REF_nodig']  = $new_part_refnodig;  }
    if ($new_part_refstatus) { $params_part_update['values']['PART_LEID_INTERN.REF_status'] = $new_part_refstatus; }

    // Execute Participant Update
    if (count($params_part_update['values']) > 1) {
        wachthond($extdebug,2, 'Params voor Participant Update',    $params_part_update);
        $result_part_update = civicrm_api4('Participant','update',  $params_part_update);
        wachthond($extdebug,9, 'Result Participant Update',         $result_part_update);
    } else {
        wachthond($extdebug,2, 'Participant Update Skipped', 'Geen wijzigingen gevonden');
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 11.0 RETURN",                       "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $ref_values = [
        
        'nodig'             => $new_cont_refnodig,
        'status'            => $new_cont_refstatus,

        // Contact profiel (berekende updates)
        'cont_refcid'       => $new_cont_refcid,
        'cont_refnaam'      => $new_cont_refnaam,
        'cont_refdatum'     => $new_cont_refdatum,
        'cont_refpersoon'   => $new_cont_refpersoon,
        'cont_refnodig'     => $new_cont_refnodig,
        'cont_refstatus'    => $new_cont_refstatus,

        // Participant (berekende updates)
        'part_refnodig'     => $new_part_refnodig,
        'part_refstatus'    => $new_part_refstatus,

        // Huidige data (uit Step 3 / DB)
        'part_ref_persoon'  => $part_refpersoon,
        'part_ref_gevraagd' => $part_refgevraagd,
        'part_ref_feedback' => $part_reffeedback,
        
        // Relatie info (uit Step 6)
        'ref_bezwaar'       => $referentie_array['ref_bezwaar'] ?? NULL,
    ];

    return $ref_values;

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REFERENTIE CONFIGURE",                               "[EINDE]");
    wachthond($extdebug,1, "########################################################################");
}

// --- HELPER NAW ---

function intake_status_naw($contact_id, $gecheckt, $keer_leid, $reg_date, $today) {
    
    // 1. Bepaal de datum (bij 1e keer leiding, pak de registratiedatum)
    $naw_gecheckt = ($keer_leid == 1 && $reg_date) ? $reg_date : $gecheckt;

    // 2. Check of deze datum in het huidige boekjaar valt
    $in_year = infiscalyear($naw_gecheckt, $today, 'nawgecheckt', 'ditjaar');

    // 3. Status bepalen
    $naw_status = ($in_year == 1 || ($keer_leid == 1 && !empty($naw_gecheckt))) ? 'bijgewerkt' : 'ongecheckt';

    // 4. Nodig bepalen (hardcoded elkjaar)
    $naw_nodig = 'elkjaar';

    return [
        'nodig'    => $naw_nodig,
        'gecheckt' => $naw_gecheckt,
        'status'   => $naw_status,
    ];
}

// --- HELPER BIO ---

function intake_status_bio($contact_id, $ingevuld, $gecheckt, $today) {
    
    // 1. Bepaal de datum
    // - Als er iets is ingevuld EN (het veld gecheckt is nog leeg OF ingevuld is nieuwer) -> Pak Ingevuld.
    // - Anders -> Blijf bij Gecheckt.
    if (!empty($ingevuld) && (empty($gecheckt) || date_biggerequal($ingevuld, $gecheckt) == 1)) {
        $bio_gecheckt = $ingevuld;
    } else {
        $bio_gecheckt = $gecheckt;
    }

    // 2. Check of deze datum in het huidige boekjaar valt
    $in_year    = infiscalyear($bio_gecheckt, $today, 'biogecheckt', 'ditjaar');

    // 3. Status bepalen
    $bio_status = ($in_year == 1) ? 'bijgewerkt' : 'ongecheckt';

    // 4. Nodig bepalen (hardcoded elkjaar)
    $bio_nodig  = 'elkjaar';

    return [
        'nodig'    => $bio_nodig,
        'gecheckt' => $bio_gecheckt,
        'status'   => $bio_status,
    ];
}

/**
 * Centrale vergrendeling om loops tussen pre en customPre te voorkomen.
 *
 * @param bool|null $set  TRUE = Zet op slot, FALSE = Haal van slot, NULL = Check status
 * @return bool           Is het slot actief?
 */
function intake_recursion_lock($set = null) {
    static $locked = false;
    
    if ($set !== null) {
        $locked = $set;
    }
    
    return $locked;
}

/**
 * INTAKE VOG CONFIGURATIE & SYNCHRONISATIE
 *
 * @param int   $contact_id    Contact ID van de vrijwilliger.
 * @param int   $part_id       Participant ID van de huidige inschrijving.
 * @param array $params        (Reference) Formulier waarden (hook_pre).
 * @param array $allpart_array (Optioneel) Cache objecten.
 * @param array $event_array   (Optioneel) Cache objecten.
 * @param int   $groupID       (Optioneel) Profiel ID.
 */
function intake_vog_configure($contact_id, $part_id, &$params = [], $allpart_array = [], $event_array = [], $groupID = 0) {
    
    // --- INITIALISATIE & DEBUG SETTINGS ---
    $extdebug          = 1; 
    $apidebug          = FALSE;
    $today             = date('Y-m-d');
    $intake_start_tijd = microtime(TRUE);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.0 START VERWERKING", "[Contact: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.1 HISTORIE & CONTEXT OPHALEN");
    wachthond($extdebug, 2, "########################################################################");

    // Technisch: Ophalen van alle deelnames om historie te kunnen wegen (VOG geldigheid).
    if (!empty($allpart_array)) {
        $array_allpart_ditjaar = $allpart_array;
    } else {
        $array_allpart_ditjaar = base_find_allpart($contact_id, $today);
    }
    
    // Event data bevat 'kampstart' en 'fiscal_year' voor de geldigheidstermijn.
    $event_array            = !empty($event_array) ? $event_array : base_pid2part($part_id);
    $part_kampstart         = $event_array['part_kampstart']    ?? NULL;
    $part_rol               = $event_array['part_rol']          ?? NULL;
    $part_functie           = $event_array['part_functie']      ?? NULL;
    $ditevent_fiscalyear    = $event_array['fiscal_year']       ?? date('Y');
    $displayname            = $array_allpart_ditjaar['display_name'] ?? 'Onbekend';
    $event_fiscalyear       = $event_array['event_fiscalyear']  ?? NULL;

    // DEBUG: Context variabelen loggen
    wachthond($extdebug, 3, "part_kampstart",       $part_kampstart);
    wachthond($extdebug, 3, "part_rol",             $part_rol);
    wachthond($extdebug, 3, "part_functie",         $part_functie);
    wachthond($extdebug, 3, "ditevent_fiscalyear",  $ditevent_fiscalyear);
    wachthond($extdebug, 3, "displayname",          $displayname);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.2 GRENSDATUM BEPALEN (BELEID)");
    wachthond($extdebug, 2, "########################################################################");

    // Functioneel: HL/Bestuur = 1 jaar geldig, reguliere leiding = 3 jaar geldig.
    $grensvognoggoed = NULL;
    if ($part_rol == 'leiding') {
        $grensvognoggoed = in_array($part_functie, ['hoofdleiding', 'bestuurslid']) 
            ? (Civi::cache()->get('cache_grensvognoggoed1') ?? NULL) 
            : (Civi::cache()->get('cache_grensvognoggoed3') ?? NULL);
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.3 EXTRACTIE UIT PARAMS (FORMULIER INPUT)");
    wachthond($extdebug, 2, "########################################################################");

    // Technisch: Map Civi-kolommen naar lokale variabelen en bewaar indices ($keys).
    $val_vognodig = $val_vogstatus = $val_vogverzoek = $val_vogaanvraag = $val_vognieuw = NULL;
    $keys         = []; 
    
    $vog_date_mapping = [
        'vog_nodig_1724'    => 'val_vognodig',
        'vog_status_1719'   => 'val_vogstatus',
        'datum_verzoek_599' => 'val_vogverzoek',
        'datum_aanvraag_600'=> 'val_vogaanvraag',
        'datum_vognieuw_603'=> 'val_vognieuw',
    ];

    foreach ($params as $i => $item) {
        $col = $item['column_name'] ?? '';
        
        if (isset($vog_date_mapping[$col])) {
            $varName = $vog_date_mapping[$col];
            $keys[$varName] = $i; 
            
            // Raw value ophalen en opschonen/formatteren
            $raw_val = $item['value'] ?? NULL;
            $$varName = format_civicrm_smart($raw_val, $col);
            
            wachthond($extdebug, 3, "EXTRACT [Index: $i]", "Col: $col -> Var: $varName | Val: " . ($$varName ?? 'NULL'));
        }
    }

    wachthond($extdebug, 2, "### EXTRACTIE VOLTOOID", [
        'vognodig'   => $val_vognodig,
        'vogstatus'  => $val_vogstatus,
        'verzoek'    => $val_vogverzoek,
        'aanvraag'   => $val_vogaanvraag,
        'nieuw_vog'  => $val_vognieuw
    ]);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.4 CHECK HISTORISCHE VOG DATUM",  "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    /**
     * TECHNISCH:
     * We zoeken de meest recente 'Datum nieuwe VOG' in alle historische deelnames 
     * van deze persoon (Participant), gesorteerd op datum aflopend.
     */
    $params_vog_history = [
        'checkPermissions' => FALSE,
        'select'           => ['PART_LEID_VOG.Datum_nieuwe_VOG'],
        'where'            => [
            ['contact_id',                     '=', $contact_id],
            ['PART_LEID_VOG.Datum_nieuwe_VOG', 'IS NOT NULL'],
            ['PART_LEID_VOG.Datum_nieuwe_VOG', '<>', ''],
        ],
        'orderBy'          => ['PART_LEID_VOG.Datum_nieuwe_VOG' => 'DESC'],
        'limit'            => 1,
    ];

    wachthond($extdebug, 7, 'params_vog_history',           $params_vog_history);
    $result_vog_history = civicrm_api4('Participant','get', $params_vog_history);
    wachthond($extdebug, 9, 'result_vog_history',           $result_vog_history);

    // Verwerking resultaat
    $found_recent_vog   = $result_vog_history->first()['PART_LEID_VOG.Datum_nieuwe_VOG'] ?? NULL;

    if ($found_recent_vog) {
        wachthond($extdebug, 1, "HISTORISCHE VOG GEVONDEN", $found_recent_vog);

        // LOGICA: Als de historische datum recenter is dan de formulier-waarde (of als die leeg is), overschrijven.
        if (empty($val_vognieuw) || $found_recent_vog > $val_vognieuw) {
            $val_voglaatste = $found_recent_vog;
            wachthond($extdebug, 2, "-> UPDATE: Gebruik historische datum als actuele VOG datum.");
        } else {
            wachthond($extdebug, 2, "-> SKIP: Huidige formulierdatum is recenter.");
        }
    } else {
        wachthond($extdebug, 3, "Geen eerdere VOG-datum gevonden in historie.");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 2.0 CONSOLIDATE DATA", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    // Functioneel: Voeg de input van het formulier samen met de bekende data uit de DB.
    $array_intake_vogall = [
        'grensvognoggoed'      => $grensvognoggoed,
        'val_part_vogverzoek'  => $val_vogverzoek,
        'val_part_vogaanvraag' => $val_vogaanvraag,
        'val_part_vogdatum'    => $val_vognieuw,
        'part_vog_verzoek'     => $event_array['part_vogverzoek']  ?? NULL,
        'part_vog_aanvraag'    => $event_array['part_vogaanvraag'] ?? NULL,
        'part_vog_datum'       => $event_array['part_vogdatum']    ?? NULL,
    ];

    $consolidate_vogdata_array = intake_consolidate_vogdata($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_vogall, $groupID);
    $new_cont_vogverzoek       = $consolidate_vogdata_array['new_cont_vogverzoek']  ?? NULL;
    $new_cont_vogaanvraag      = $consolidate_vogdata_array['new_cont_vogaanvraag'] ?? NULL;
    $new_cont_vogdatum         = $consolidate_vogdata_array['new_cont_vogdatum']    ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 3.0 BEPAAL WAARDE VOGNODIG", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    $new_cont_vognodig = intake_vognodig($contact_id, $array_allpart_ditjaar, $event_array, $array_intake_vogall, $consolidate_vogdata_array, $groupID);
    $new_part_vognodig = $new_cont_vognodig;

    $intake_array = [
        'grensvognoggoed' => $grensvognoggoed,
        'vog_nodig'       => $new_cont_vognodig,
        'vog_verzoek'     => $new_cont_vogverzoek,
        'vog_aanvraag'    => $new_cont_vogaanvraag,
        'vog_datum'       => $new_cont_vogdatum,
    ];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.1 BEPAAL STATUS ACTIVITEIT VOG VERZOEK");
    wachthond($extdebug, 2, "########################################################################");

    $new_vogverzoek_actstatus = 'Pending';
    if (infiscalyear($new_cont_vogverzoek,  $part_kampstart) == 1)  { $new_vogverzoek_actstatus = 'Completed'; }
    if (infiscalyear($new_cont_vogaanvraag, $part_kampstart) == 1)  { $new_vogverzoek_actstatus = 'Completed'; }
    if (infiscalyear($new_cont_vogdatum,    $part_kampstart) == 1)  { $new_vogverzoek_actstatus = 'Completed'; }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.2 BEPAAL STATUS ACTIVITEIT VOG AANVRAAG");
    wachthond($extdebug, 2, "########################################################################");

    $res_aanvraag              = intake_status_vogaanvraag($contact_id, $event_array, $intake_array, $groupID);
    $new_vogaanvraag_actstatus = $res_aanvraag['new_vogaanvraag_actstatus'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.3 BEPAAL STATUS ACTIVITEIT VOG ONTVANGST");
    wachthond($extdebug, 2, "########################################################################");

    $res_ontvangst              = intake_status_vogontvangst($contact_id, $event_array, $intake_array, $groupID);
    $new_vogontvangst_actstatus = $res_ontvangst['new_vogontvangst_actstatus'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.4 BEPAAL ALGEHELE STATUS VOG");
    wachthond($extdebug, 2, "########################################################################");

    $new_cont_vogstatus = $res_ontvangst['new_cont_vogstatus'] ?? $res_aanvraag['new_cont_vogstatus'] ?? NULL;
    $new_part_vogstatus = $res_ontvangst['new_part_vogstatus'] ?? $res_aanvraag['new_part_vogstatus'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.5 RESET BIJ ANNULERING");
    wachthond($extdebug, 2, "########################################################################");

    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];
    $current_status  = $array_allpart_ditjaar['ditjaar_one_leid_status_id'] ?? NULL;

    if (!empty($current_status) && in_array($current_status, $status_negative)) {
        $new_cont_vognodig        = $new_cont_vogstatus = $new_part_vognodig = $new_part_vogstatus = "";
        $new_vogverzoek_actstatus = 'Not Required';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 5.0 SYNC ACTIVITIES (118, 119, 120)");
    wachthond($extdebug, 2, "########################################################################");

    $act_types = [
        118 => [
            'name'   => 'vog_verzoek',
            'subject'=> 'VOG aanvraag verzocht',
            'status' => $new_vogverzoek_actstatus,
            'date'   => $new_cont_vogverzoek
        ],
        119 => [
            'name'   => 'vog_aanvraag',
            'subject'=> 'VOG aanvraag ingediend',
            'status' => $new_vogaanvraag_actstatus,
            'date'   => $res_aanvraag['new_vogaanvraag_actdatum']   ?? NULL
        ],
        120 => [
            'name'   => 'vog_ontvangst',
            'subject'=> 'VOG ontvangst bevestigd',
            'status' => $new_vogontvangst_actstatus,
            'date'   => $res_ontvangst['new_vogontvangst_actdatum'] ?? NULL
        ],
    ];

    foreach ($act_types as $tid => $setup) {
        // Alleen actie ondernemen als er een status is bepaald
        if ($setup['status']) {
            
            $act_payload = [
                'displayname'          => $displayname,
                'contact_id'           => $contact_id,
                'activity_source'      => 1,
                'activity_target'      => $contact_id,
                'activity_type_id'     => $tid,
                'activity_type_naam'   => $setup['name'],
                'activity_subject'     => $setup['subject'],
                'activity_date_time'   => $setup['date'],
                'activity_status_name' => $setup['status'],
                'activity_prioriteit'  => 'Normal'
            ];

            // Check of de activiteit reeds bestaat voor dit fiscale jaar
            $act_id = intake_activity_get($contact_id, $act_payload, $event_fiscalyear)['activity_id'] ?? NULL;

            wachthond($extdebug, 3, "SYNC ACT $tid [".$setup['name']."]", "Existing ID: ".($act_id ?? 'NONE')." | Status: ".$setup['status']);

            if ($act_id && $new_cont_vognodig == 'noggoed') {
                wachthond($extdebug, 2, "ACT DELETE: VOG is historisch 'noggoed', verwijder overbodige taak $tid", $act_id);
                intake_activity_delete($contact_id, $act_id);
            } 
            elseif (empty($act_id)) {
                wachthond($extdebug, 2, "ACT CREATE: Geen activiteit gevonden voor type $tid, aanmaken...", $setup['name']);
                intake_activity_create($contact_id, $act_payload, $event_array, $intake_array, $groupID);
            } 
            else {
                wachthond($extdebug, 2, "ACT UPDATE: Bestaande activiteit $act_id bijwerken naar status: ".$setup['status']);
                $act_payload['activity_id'] = $act_id;
                intake_activity_update($contact_id, $act_payload, $event_array, $intake_array, $groupID);
            }
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 9.0 UPDATE CONTACT", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    $api_params_contact = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [['id', '=', (int)$contact_id]],
        'values'           => [
            'INTAKE.VOG_nodig'   => $new_cont_vognodig,
            'INTAKE.VOG_status'  => $new_cont_vogstatus,
            'INTAKE.VOG_laatste' => $new_cont_vogdatum,
        ],
    ];

    wachthond($extdebug, 3, "api_params_contact",       $api_params_contact);
    $result_contact = civicrm_api4('Contact', 'update', $api_params_contact);
    wachthond($extdebug, 9, "result_contact",           $result_contact);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 10.0 UPDATE PARTICIPANT", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    $api_params_participant = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [['id', '=', (int)$part_id]],
        'values'           => [
            'PART_LEID_INTERN.VOG_nodig'     => $new_part_vognodig,
            'PART_LEID_INTERN.VOG_status'    => $new_part_vogstatus,
            'PART_LEID_VOG.Datum_verzoek'    => $new_cont_vogverzoek,
            'PART_LEID_VOG.Datum_aanvraag'   => $new_cont_vogaanvraag,
            'PART_LEID_VOG.Datum_nieuwe_VOG' => $new_cont_vogdatum,
        ],
    ];

    wachthond($extdebug, 3, "api_params_participant",         $api_params_participant);
    $result_participant = civicrm_api4('Participant','update',$api_params_participant);
    wachthond($extdebug, 9, "result_participant",             $result_participant);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 11.0 RETURN DATA", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    /**
     * Omdat we de variabelen hierboven niet hebben overschreven, 
     * zijn ze hier nog steeds 'schoon'. We kunnen ze direct teruggeven.
     */
    $updates = [
        'nodig'    => $new_cont_vognodig,
        'status'   => $new_cont_vogstatus,
        'verzoek'  => $new_cont_vogverzoek,
        'aanvraag' => $new_cont_vogaanvraag,
        'datum'    => $new_cont_vogdatum,
        'laatste'  => $val_voglaatste,
    ];

    foreach ($updates as $var => $value) {
        if (isset($keys[$var])) {
            $idx                   = $keys[$var];
            $col_name              = $params[$idx]['column_name'] ?? 'Onbekend';
            
            // format_civicrm_smart regelt wel de datum-conversie, maar laat strings met rust
            $smart_val             = format_civicrm_smart($value, $col_name);
            $params[$idx]['value'] = $smart_val;
            
            wachthond($extdebug, 3, "Final Param Sync: $col_name", $smart_val);
        }
    }

    $duur = number_format(microtime(TRUE) - $intake_start_tijd, 3);
    wachthond($extdebug, 1, "### VOG CONFIG VOLTOOID IN $duur SEC", "#########################");

    return $updates;
}