<?php

/**
 * INTAKE VOG CONFIGURATIE & SYNCHRONISATIE
 *
 * @param int   $contact_id    Contact ID van de vrijwilliger.
 * @param int   $part_id       Participant ID van de huidige inschrijving.
 * @param array $params        (Reference) Formulier waarden (hook_pre).
 * @param array $allpart_array (Optioneel) Cache objecten.
 * @param array $part_array   (Optioneel) Cache objecten.
 * @param int   $groupID       (Optioneel) Profiel ID.
 */

function intake_vog_configure($contact_id, $part_id, &$params = [], $allpart_array = [], $part_array = [], $context = 'direct') {

    $extdebug           = 3; 
    $apidebug           = FALSE;
    $today              = date('Y-m-d');
    $intake_start_tijd  = microtime(TRUE);

    $val_vognieuw       = $val_vognieuw ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG - START VERWERKING", "[Contact: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 1.0 HISTORIE & CONTEXT OPHALEN");
    wachthond($extdebug, 2, "########################################################################");

    // Technisch: Ophalen van alle deelnames om historie te kunnen wegen (VOG geldigheid).
    if (empty($allpart_array)) {
        $allpart_array = base_find_allpart($contact_id, $today);
        wachthond($extdebug, 4, "DATA: allpart_array opgehaald", "(was leeg)");
    }

    // Check: Hebben we de participatie details al? Zo niet, haal op.
    if (empty($part_array) && $part_id > 0) {
        $part_array = base_pid2part($part_id);
        wachthond($extdebug, 4, "DATA: part_array opgehaald",   "(was leeg)");
    }

	wachthond($extdebug,3, 'part_array',    					$part_array);

    $displayname            = $part_array['displayname']        ?? NULL;
    $part_kampkort          = $part_array['part_kampkort']      ?? NULL;
    $part_kampstart         = $part_array['part_kampstart']     ?? NULL;
    $part_rol               = $part_array['part_rol']           ?? NULL;
    $part_functie           = $part_array['part_functie']       ?? NULL;
    $event_fiscalyear       = $part_array['event_fiscalyear']   ?? NULL;

    // DEBUG: Context variabelen loggen
    wachthond($extdebug, 3, "displayname",          $displayname);
    wachthond($extdebug, 3, "part_kampkort",        $part_kampkort);
    wachthond($extdebug, 3, "part_kampstart",       $part_kampstart);
    wachthond($extdebug, 3, "part_rol",             $part_rol);
    wachthond($extdebug, 3, "part_functie",         $part_functie);
    wachthond($extdebug, 3, "event_fiscalyear",  	$event_fiscalyear);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 2.0 CONSOLIDATE DATA",             "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

	// =========================================================================
    // STAP 2: INITIALISEER DE MASTER ARRAY MET "RAW" INPUT
    // =========================================================================
    // Hier stop je de waarden in die direct uit het formulier ($params) komen.
    // Dit noemen we even de 'proposed' values.
    
    $intake_array_vog = [
        'type' => 'VOG',
        // Haal waarden uit $params (indien aanwezig)
        'val_vogverzoek' 		=> $val_vogverzoek,
        'val_vogaanvraag'  		=> $val_vogaanvraag,
        'val_vogdatum'     		=> $val_vognieuw,       
        
        // Voeg ook de oude participant data toe voor referentie
     	'part_vog_verzoek'      => $part_array['part_vogverzoek']         	?? NULL,
        'part_vog_aanvraag'     => $part_array['part_vogaanvraag']        	?? NULL,
        'part_vog_datum'        => $part_array['part_vogdatum']           	?? NULL,
    ];

    // =========================================================================
    // STAP 3: CONSOLIDEREN (DE VERGELIJKING)
    // =========================================================================
    // Nu roep je consolidate aan. Die krijgt:
    // 1. De bestaande data ($existing_vog_data)
    // 2. De nieuwe input ($intake_array_vog)
    // En geeft terug wat de DEFINITIEVE waarden zijn.

    $consolidated_values = intake_consolidate_vogdata(
      	$part_array, 
        $allpart_array, 
        $intake_array_vog
    );

    // =========================================================================
    // STAP 4: UPDATE DE MASTER ARRAY MET DE UITKOMST
    // =========================================================================
    // We overschrijven/vullen de array aan met de 'waarheid' uit de consolidatie.
    // Zorg dat consolidate in ieder geval 'new_cont_vogdatum' (of iets dergelijks) teruggeeft.
    
    // Voeg consolidatie toe aan onze master array
    $intake_array_vog 		= array_merge($intake_array_vog, $consolidated_values);
    wachthond($extdebug, 2, "VOG CONSOLIDATED DATA", $intake_array_vog);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 3.0 BEPAAL WAARDE VOGNODIG", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");
   
    $new_cont_vognodig 		= intake_check_nodig(
        $part_array,     	// Bevat functie, startdatum, etc.
        $allpart_array,    	// Bevat de historie (count check)
       	$intake_array_vog	// Bevat geconsolideerde intake data
    );
	wachthond($extdebug, 3, "new_cont_vognodig",	$new_cont_vognodig);   
	$intake_array_vog['nodig'] = $new_cont_vognodig;

	wachthond($extdebug, 2, "VOG CONSOLIDATED DATA", $intake_array_vog);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.1 BEPAAL STATUS ACTIVITEIT VOG VERZOEK");
    wachthond($extdebug, 2, "########################################################################");

    $res_verzoek 			  = intake_status_vogverzoek($part_array, $allpart_array, $intake_array_vog);
    $new_vogverzoek_actstatus = $res_verzoek['vogverzoek_actstatus'] ?? NULL;
    wachthond($extdebug, 3, "res_verzoek",     $res_verzoek);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.2 BEPAAL STATUS ACTIVITEIT VOG AANVRAAG");
    wachthond($extdebug, 2, "########################################################################");

    $res_aanvraag              = intake_status_vogaanvraag($contact_id, $part_array, $intake_array_vog);
    $new_vogaanvraag_actstatus = $res_aanvraag['new_vogaanvraag_actstatus'] ?? NULL;
    wachthond($extdebug, 3, "res_aanvraag",     $res_aanvraag);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.3 BEPAAL STATUS ACTIVITEIT VOG ONTVANGST");
    wachthond($extdebug, 2, "########################################################################");

    $res_ontvangst              = intake_status_vogontvangst($contact_id, $part_array, $intake_array_vog);
    $new_vogontvangst_actstatus = $res_ontvangst['new_vogontvangst_actstatus'] ?? NULL;
    wachthond($extdebug, 3, "res_ontvangst",     $res_ontvangst);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 4.4 BEPAAL ALGEHELE STATUS VOG");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Variabelen ophalen (exact zoals gevraagd)
    $part_vog_verzoek  = $intake_array_vog['part_vog_verzoek']  ?? NULL;
    $part_vog_aanvraag = $intake_array_vog['part_vog_aanvraag'] ?? NULL;
    $part_vog_datum    = $intake_array_vog['part_vog_datum']    ?? NULL; 
    $vog_nodig         = $intake_array_vog['nodig']             ?? 'onbekend'; 
    $cont_vog_laatste  = $intake_array_vog['cont_vog_laatste']  ?? NULL; 
    
    // Check op actieve rol (Gatekeeper)
    $pos_leid_count    = $allpart_array['result_allpart_pos_leid_count'] ?? 0;

    $final_status 		= 'nietnodig'; // Default startpunt

    // 2. De Waterval Logica (De hoogste status wint)

    // A. Geen actieve rol? -> Dan is er sowieso niets nodig
    if ($pos_leid_count == 0) {
        $final_status 	= 'nietnodig';
    }
    // B. Is de VOG fysiek binnen (Datum bekend)? -> Ontvangen
    elseif (!empty($part_vog_datum)) {
        $final_status 	= 'ontvangen';
    }
    // C. Is de aanvraag ingediend bij Justis? -> Ingediend
    elseif (!empty($part_vog_aanvraag)) {
        $final_status 	= 'ingediend';
    }
    // D. Is het verzoek verstuurd naar de vrijwilliger? -> Verzocht
    elseif (!empty($part_vog_verzoek)) {
        $final_status 	= 'verzocht';
    }
    // E. Geen actie dit jaar, maar oude VOG is nog geldig? -> Nog goed
    elseif ($vog_nodig == 'noggoed') {
        $final_status 	= 'noggoed';
    }
    // F. Nog geen stappen ondernomen, maar hij is wel nodig? -> Klaarzetten
    elseif (in_array($vog_nodig, ['opnieuw', 'eerstex', 'elkjaar'])) {
        $final_status 	= 'klaarzetten';
    }
    // G. Vangnet (Bijv: vog_nodig = 'Nee')
    else {
        $final_status 	= 'nietnodig';
    }

    // 3. Resultaten toewijzen en opslaan
    $new_cont_vogstatus = $final_status;
    $new_part_vogstatus = $final_status;

    $intake_array_vog['status'] = $new_cont_vogstatus;

    // 4. Debugging
    wachthond($extdebug, 2, "VOG DEFINITIEVE STATUS", [
        'Status'     => $final_status,
        'Nodig'      => $vog_nodig,
        'Rol Count'  => $pos_leid_count,
        'Verzoek'    => $part_vog_verzoek,
        'Aanvraag'   => $part_vog_aanvraag,
        'Datum'      => $part_vog_datum
    ]);

	// =========================================================================
    // STAP 4.9: VARIABELEN KLAARZETTEN VOOR SYNC & UPDATE
    // =========================================================================
    // We trekken de definitieve datums uit de array naar losse variabelen
    // zodat we ze in stap 5 en 9 makkelijk kunnen gebruiken.
    
    $new_cont_vogverzoek  = $intake_array_vog['part_vog_verzoek']   ?? NULL;
    $new_cont_vogaanvraag = $intake_array_vog['part_vog_aanvraag']  ?? NULL;
    $new_cont_vogdatum    = $intake_array_vog['part_vog_datum']     ?? NULL;
    $new_cont_voglaatste  = $intake_array_vog['cont_vog_laatste']   ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 5.0 SYNC ACTIVITIES (118, 119, 120)");
    wachthond($extdebug, 2, "########################################################################");

    // Definitie van de 3 activiteiten
    // Let op: 'date' en 'prio' keys zijn hier uniek gemaakt (geen dubbele 'date' meer)
    $act_types = [
        118 => [ 
            'name'    => 'vog_verzoek',
            'subject' => 'VOG aanvraag verzocht',
            'status'  => $new_vogverzoek_actstatus,
            'date'    => $res_verzoek['vogverzoek_actdatum'] ?? $today,
            'prio'    => $res_verzoek['vogverzoek_actprio']  ?? 'Normaal',
        ],
        119 => [ 
            'name'    => 'vog_aanvraag',
            'subject' => 'VOG aanvraag ingediend',
            'status'  => $new_vogaanvraag_actstatus,
            'date'    => $res_aanvraag['vogaanvraag_actdatum'] ?? NULL,
            'prio'    => $res_aanvraag['vogaanvraag_actprio']  ?? 'Normaal',
        ],
        120 => [ 
            'name'    => 'vog_ontvangst',
            'subject' => 'VOG ontvangst bevestigd',
            'status'  => $new_vogontvangst_actstatus,
            'date'    => $res_ontvangst['vogontvangst_actdatum'] ?? NULL,
            'prio'    => $res_ontvangst['vogontvangst_actprio']  ?? 'Normaal',
        ],
    ];    

    wachthond($extdebug, 3, "event_fiscalyear",  	$event_fiscalyear);
   	wachthond($extdebug, 3, "pos_leid_count",  		$pos_leid_count);

	// Bepaal of er een positieve registratie is voor dit jaar
    $pos_leid_count = $allpart_array['result_allpart_pos_leid_count'] ?? 0;

    foreach ($act_types as $tid => $activity) {

        // =========================================================================
        // 1. PAYLOAD VOORBEREIDEN
        // =========================================================================
        $act_payload = [
            'displayname'          => $displayname,
            'contact_id'           => $contact_id,
            'source_contact_id'    => 1, // Systeem/Admin
            'target_contact_id'    => $contact_id,
            'assignee_contact_id'  => NULL, 
            
            'activity_type_id'     => $tid,
            'activity_type_naam'   => $activity['name'],
            'activity_subject'     => $activity['subject'],
            'activity_date_time'   => $activity['date'],
            'activity_status_name' => $activity['status'],
            'activity_prioriteit'  => $activity['prio'],
            'activity_details'     => "Automatisch gegenereerd door Intake VOG Config.\nStatus: " . $activity['status'],
        ];

        // 2. Zoek bestaande activiteit voor dit fiscale jaar
        $existing  = intake_activity_get($contact_id, $act_payload, $event_fiscalyear);
        
        $act_id    = $existing['activity_id']    ?? NULL;
        $act_count = $existing['activity_count'] ?? 0;

        // =========================================================================
        // 3. SCHONEN OF HEALING (Gecombineerd)
        // We verwijderen alles als:
        // - Er duplicaten zijn ($act_count > 1) -> Healing
        // - De status 'Cancelled'/'Not Required' is -> Schonen
        // - Er geen positieve deelnames zijn ($pos_leid_count == 0) -> Schonen
        // =========================================================================

        $is_annulering    = in_array($activity['status'], ['Cancelled', 'Not Required']);
        $geen_positief    = ($pos_leid_count == 0);
        $moet_verwijderen = ($act_count > 1 || $is_annulering || $geen_positief);

        if ($moet_verwijderen && $act_count > 0) {
            
            wachthond($extdebug, 2, "########################################################################");
            wachthond($extdebug, 1, "### INTAKE ACTIVITY CLEANUP/HEALING: " . $activity['name'], "[START]");
            wachthond($extdebug, 2, "Reden: Count=$act_count, PosCount=$pos_leid_count, Status=" . $activity['status']);
            
            // Verwijder via type_delete direct alle vervuiling voor dit type/jaar
            intake_activitytype_delete($contact_id, $act_payload, $event_fiscalyear);

            // Reset de variabelen voor de volgende stap
            $act_id                     = NULL;
            $act_payload['activity_id'] = NULL;
            
            wachthond($extdebug, 2, "### INTAKE ACTIVITY CLEANUP/HEALING", "[EINDE]");
            wachthond($extdebug, 2, "########################################################################");
        }

        // =========================================================================
        // 4. ACTIE BEPALEN (CREATE / UPDATE)
        // Alleen aanmaken/bijwerken als er een status is EN een positieve deelname
        // =========================================================================

        if (!empty($activity['status']) && !$is_annulering && !$geen_positief) {
                                                    
            if (empty($act_id)) {
                // AANMAKEN
                wachthond($extdebug, 2, "ACT CREATE: Nieuwe activiteit voor " . $activity['name']);
                intake_activity_create($contact_id, $act_payload, $part_array, $intake_array_vog);
            } else {
                // UPDATEN
                wachthond($extdebug, 2, "ACT UPDATE: Status bijwerken naar " . $activity['status'], $act_id);
                
                $act_payload['activity_id'] = $act_id;
                intake_activity_update($contact_id, $act_payload, $part_array, $intake_array_vog);
            }
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 6.0 UPDATE CONTACT & PART", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

	// A. API DATA
    $api_data_cont = [
        'INTAKE.VOG_nodig'               => $intake_array_vog['nodig']             	?? NULL,
        'INTAKE.VOG_status'              => $intake_array_vog['status']            	?? NULL,
        'INTAKE.VOG_laatste'             => $intake_array_vog['cont_vog_laatste'] 	?? NULL,
    ];

    $api_data_part = [
        'PART_LEID_INTERN.VOG_nodig'     => $intake_array_vog['nodig']             	?? NULL,
        'PART_LEID_INTERN.VOG_status'    => $intake_array_vog['status']            	?? NULL,
        'PART_LEID_VOG.Datum_verzoek'    => $intake_array_vog['part_vog_verzoek']  	?? NULL,
        'PART_LEID_VOG.Datum_aanvraag'   => $intake_array_vog['part_vog_aanvraag'] 	?? NULL,
        'PART_LEID_VOG.Datum_nieuwe_VOG' => $intake_array_vog['part_vog_datum']    	?? NULL,
    ];

    // B. INJECTIE MAPPING
    $inject_map_cont = [
        'val_vognodig'                   => $intake_array_vog['nodig']             	?? NULL,
        'val_vogstatus'                  => $intake_array_vog['status']            	?? NULL,
        'val_vognieuw'                   => $intake_array_vog['cont_vog_laatste'] 	?? NULL,
    ];

    $inject_map_part = [
        'val_vogverzoek'                 => $intake_array_vog['part_vog_verzoek']  	?? NULL,
        'val_vogaanvraag'                => $intake_array_vog['part_vog_aanvraag'] 	?? NULL,
    ];

    // C. DE UPDATE SWITCH
    // Bepaal de beschikbare keys in $params voor veilige injectie
    $keys = !empty($params) ? array_keys($params) : [];

    switch ($context) {

        // ------------------------------------------------------------------
        // SCENARIO A: We zitten in de PRE hook van een CONTACT ('hook_cont')
        // ------------------------------------------------------------------
        case 'hook_cont':
            // 1. Update de ANDERE (Participant) via API (want die zit niet in dit formulier)
            if ($part_id) {
                intake_api_update_wrapper('Participant', $part_id, $api_data_part);
            }
            // 2. Injecteer de EIGEN data (Contact) direct in $params
            intake_inject_params($params, $keys, $inject_map_cont, "CONTACT");
            break;

        // ------------------------------------------------------------------
        // SCENARIO B: We zitten in de PRE hook van een PARTICIPANT ('hook_part')
        // ------------------------------------------------------------------
        case 'hook_part':
            // 1. Update de ANDERE (Contact) via API
            if ($contact_id) {
                intake_api_update_wrapper('Contact', $contact_id, $api_data_cont);
            }
            // 2. Injecteer de EIGEN data (Participant) direct in $params
            intake_inject_params($params, $keys, $inject_map_part, "PARTICIPANT");
            break;

        // ------------------------------------------------------------------
        // SCENARIO C: DIRECT / STANDALONE ('direct')
        // ------------------------------------------------------------------
        case 'direct':
        default:
            // Geen hook context, dus ALLES via harde API updates.
            if ($contact_id) {
                intake_api_update_wrapper('Contact', $contact_id, $api_data_cont);
            }
            if ($part_id) {
                intake_api_update_wrapper('Participant', $part_id, $api_data_part);
            }
            break;
    }

    // =========================================================================
    // STAP 11: RETURN DATA
    // =========================================================================
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE VOG CONFIG 7.0 RETURN DATA", "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    $updates = [
        'nodig'    => $new_cont_vognodig,
        'status'   => $new_cont_vogstatus,
        'verzoek'  => $new_cont_vogverzoek,
        'aanvraag' => $new_cont_vogaanvraag,
        'datum'    => $new_cont_vogdatum,
        'laatste'  => $new_cont_voglaatste,
    ];

    $duur = number_format(microtime(TRUE) - $intake_start_tijd, 3);
    wachthond($extdebug, 1, "### VOG CONFIG VOLTOOID IN $duur SEC", "#########################");

    return $updates;
}

function intake_consolidate_vogdata($part_array, $allpart_array, $intake_array, $params = []) {

    $extdebug       = 0;  // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 0. EXTRACTIE FORMULIER",      "[PARAMS]");
    wachthond($extdebug, 2, "########################################################################");

    $mapping = [
        'datum_verzoek'  => 'val_vog_verzoek',
        'datum_aanvraag' => 'val_vog_aanvraag',
        'datum_vognieuw' => 'val_vog_datum',
        'vog_datum'      => 'val_vog_datum', 
    ];

    $extracted = [
        'val_vog_verzoek'  => NULL,
        'val_vog_aanvraag' => NULL,
        'val_vog_datum'    => NULL,
    ];

    foreach ($params as $item) {
        $col = $item['column_name'] ?? '';
        $val = $item['value']       ?? NULL;
        foreach ($mapping as $search => $key) {
            if (str_contains($col, $search)) {
                $extracted[$key] = format_civicrm_smart($val, $col);
            }
        }
    }

    // Filter de params zodat alleen velden met 'vog' in de kolomnaam overblijven
    $vog_params_only = array_filter($params, function($p) {
        return str_contains(strtolower($p['column_name'] ?? ''), 'vog');
    });
    wachthond($extdebug,4, "FILTERED params (VOG ONLY)", 		$vog_params_only);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 1.1 DATA INLADEN UIT ARRAYS", "[LOAD_BASE]");
    wachthond($extdebug, 2, "########################################################################");

    $displayname    	= $part_array['displayname']    		?? NULL;
    $part_kampkort  	= $part_array['part_kampkort']  		?? NULL;
    $part_kampstart 	= $part_array['part_kampstart'] 		?? NULL;
    $contact_id     	= $part_array['contact_id']     		?? 0;

    // Globale contact status uit cid2cont
    $cont_vog_verzoek  	= $intake_array['cont_vog_verzoek']  	?? NULL;
    $cont_vog_aanvraag 	= $intake_array['cont_vog_aanvraag'] 	?? NULL;
    $cont_vog_datum    	= $intake_array['cont_vog_datum']    	?? NULL;
    $cont_vog_laatste  	= $intake_array['cont_vog_laatste']  	?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 1.2 HUIDIGE DEELNEMER STATUS", "[LOAD_PART]");
    wachthond($extdebug, 2, "########################################################################");

    $part_vog_verzoek  	= $part_array['part_vogverzoek']  		?? NULL;
    $part_vog_aanvraag 	= $part_array['part_vogaanvraag'] 		?? NULL;
    $part_vog_datum    	= $part_array['part_vogdatum']    		?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 1.3 OPHALEN HISTORIE",        "[LOAD_HIST]");
    wachthond($extdebug, 2, "########################################################################");

    $found_recent_vog = NULL;
    if ($contact_id > 0) {
        $params_hist = [
            'checkPermissions' => FALSE,
            'select'           => ['PART_LEID_VOG.Datum_nieuwe_VOG'],
            'where'            => [['contact_id', '=', $contact_id], ['PART_LEID_VOG.Datum_nieuwe_VOG', 'IS NOT NULL']],
            'orderBy'          => ['PART_LEID_VOG.Datum_nieuwe_VOG' => 'DESC'],
            'limit'            => 1,
        ];
        $result_hist      = civicrm_api4('Participant', 'get', $params_hist);
        $found_recent_vog = $result_hist->first()['PART_LEID_VOG.Datum_nieuwe_VOG'] ?? NULL;
        wachthond($extdebug, 2, "Historische VOG gevonden", $found_recent_vog);
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 2.1 BEPAAL WINNAAR",          "[VOG_WINNER]");
    wachthond($extdebug, 2, "########################################################################");

    // Bepaal de meest recente datum uit formulier vs historie
    $tmp_winner_datum  = (date_bigger($extracted['val_vog_datum'], $found_recent_vog)) 
                         ? $extracted['val_vog_datum'] : $found_recent_vog;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 2.2 DEELNEMER SYNC",          "[VOG_PART_SYNC]");
    wachthond($extdebug, 2, "########################################################################");

    // Verzoek & Aanvraag (Nieuwste voor dit event wint)
    $part_vog_verzoek  = (date_bigger($extracted['val_vog_verzoek'],  $part_vog_verzoek))  ? $extracted['val_vog_verzoek']  : $part_vog_verzoek;
    $part_vog_aanvraag = (date_bigger($extracted['val_vog_aanvraag'], $part_vog_aanvraag)) ? $extracted['val_vog_aanvraag'] : $part_vog_aanvraag;

    // VOG Datum: Alleen als winnaar fiscaal bij dit event hoort
    if (infiscalyear($tmp_winner_datum, $part_kampstart) == 1) {
        if (date_biggerequal($tmp_winner_datum, $part_vog_datum) || empty($part_vog_datum)) {
            $part_vog_datum = $tmp_winner_datum;
            wachthond($extdebug, 2, "PART VOG DATUM geüpdatet", $part_vog_datum);
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [VOG] 2.3 CONTACT SYNC",            "[VOG_CONT_SYNC]");
    wachthond($extdebug, 2, "########################################################################");

    // Sync naar globale contact data (indien fiscaal relevant)
    if (infiscalyear($tmp_winner_datum, $part_kampstart) == 1) {
        if (date_bigger($tmp_winner_datum, $cont_vog_datum)) {
            $cont_vog_datum = $tmp_winner_datum;
            wachthond($extdebug, 2, "CONT VOG DATUM geüpdatet", $cont_vog_datum);
        }
    }

    // VOG Laatste: Absolute winnaar ooit
    if (date_bigger($tmp_winner_datum, $cont_vog_laatste)) {
        $cont_vog_laatste = $tmp_winner_datum;
        wachthond($extdebug, 2, "CONT VOG LAATSTE geüpdatet", $cont_vog_laatste);
    }

    $result_array = [
        'displayname'       => $displayname,
        'part_kampkort'     => $part_kampkort,

        'val_vog_verzoek'   => $extracted['val_vog_verzoek'],
        'val_vog_aanvraag'  => $extracted['val_vog_aanvraag'],
        'val_vog_datum'     => $extracted['val_vog_datum'],

        'cont_vog_verzoek'  => $cont_vog_verzoek,
        'cont_vog_aanvraag' => $cont_vog_aanvraag,
        'cont_vog_datum'    => $cont_vog_datum,
        'cont_vog_laatste'  => $cont_vog_laatste,

        'part_vog_verzoek'  => $part_vog_verzoek,
        'part_vog_aanvraag' => $part_vog_aanvraag,
        'part_vog_datum'    => $part_vog_datum,
    ];

    wachthond($extdebug, 4, "Resultaat VOG Consolidatie", $result_array);

    return $result_array;
}