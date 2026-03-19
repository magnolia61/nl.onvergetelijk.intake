<?php

function intake_status_refpersoon($contact_id, $part_array, $refdata_array, $refnodig, $groupID) {

    $extdebug       = 3; 
    $today_datetime = date("Y-m-d H:i:s");

    // Initialisatie
    $displayname                = $part_array['displayname']            ?? NULL;
    $ditevent_part_kampkort     = $part_array['part_kampkort']          ?? NULL;

    if (empty($contact_id)) { return NULL; }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS - REF PERSOON VOOR $displayname",   "[$ditevent_part_kampkort]");
    wachthond($extdebug, 1, "########################################################################");

    // Mapping data
    $ditevent_part_id           = $part_array['id']                     ?? NULL;
    $ditevent_part_eventid      = $part_array['part_event_id']          ?? NULL;
    $ditevent_part_kampnaam     = $part_array['part_kampnaam']          ?? NULL;
    $ditevent_part_kampstart    = $part_array['part_kampstart']         ?? NULL;
    $ditevent_part_kampeinde    = $part_array['part_kampeinde']         ?? NULL;
    $ditevent_part_rol          = $part_array['part_rol']               ?? NULL;
    $ditevent_part_functie      = $part_array['part_functie']           ?? NULL;
    
    $part_refpersoon            = $part_array['part_refpersoon']        ?? NULL;
    $cont_refpersoon            = $refdata_array['new_cont_refpersoon'] ?? NULL;
    $vog_verzoek                = $part_array['part_vogverzoek']        ?? NULL;

    // Bepaal meest recente datum
    $meest_recente_refpersoon   = (date_bigger($part_refpersoon, $cont_refpersoon)) ? $part_refpersoon : $cont_refpersoon;
    
    // Fiscale checks
    $refpersoon_infiscal_nu     = infiscalyear($meest_recente_refpersoon, $today_datetime)          ?? 0;
    $refpersoon_infiscal_event  = infiscalyear($meest_recente_refpersoon, $ditevent_part_kampstart) ?? 0;

    // Defaults
    $new_refpersoon_actstatus   = 'Scheduled';
    $new_cont_refstatus         = 'onbekend';

    // =========================================================================
    // LOGICA VOOR ACTIVITEIT: REF PERSOON DOORGEVEN
    // =========================================================================
    if ($refpersoon_infiscal_nu == 1 || $refpersoon_infiscal_event == 1) {
        $new_cont_refstatus       = 'vragen'; 
        $new_refpersoon_actstatus = 'Completed';
    } 
    elseif ($refnodig == "noggoed") {
        $new_cont_refstatus       = 'noggoed';
        $new_refpersoon_actstatus = 'Not Required';
    }
    elseif (date_bigger($today_datetime, $ditevent_part_kampeinde)) {
        $new_cont_refstatus       = 'verlopen';
        $new_refpersoon_actstatus = 'Failed';
    }
    elseif ($vog_verzoek) {
        $diff = date_diff(date_create($vog_verzoek), date_create($today_datetime));
        $days = (int)$diff->format('%r%a'); 
        
        if ($days < 7)       { $new_refpersoon_actstatus = "Pending"; }
        elseif ($days < 21)  { $new_refpersoon_actstatus = "Left Message"; }
        elseif ($days < 30)  { $new_refpersoon_actstatus = "Unreachable"; }
        else                 { $new_refpersoon_actstatus = "No_show"; }
    }

    $prio_map = ['Pending' => 'Laag', 'Left Message' => 'Normaal', 'Unreachable' => 'Urgent', 'No_show' => 'Urgent'];
    $new_refpersoon_actprio = $prio_map[$new_refpersoon_actstatus] ?? 'Normaal';
    $new_refpersoon_actdatum = $meest_recente_refpersoon ?: $ditevent_part_kampstart;

    return [
        'displayname'               => $displayname,
        'contact_id'                => $contact_id,
        'part_id'                   => $ditevent_part_id,
        'event_id'                  => $ditevent_part_eventid,
        'kamp_naam'                 => $ditevent_part_kampnaam,
        'kamp_start'                => $ditevent_part_kampstart,
        'kamp_rol'                  => $ditevent_part_rol,
        'kamp_functie'              => $ditevent_part_functie,
        'new_cont_refstatus'        => $new_cont_refstatus,
        'activity_type_naam'        => 'REF_persoon',
        'new_refpersoon_actdatum'   => $new_refpersoon_actdatum,
        'new_refpersoon_actstatus'  => $new_refpersoon_actstatus,
        'new_refpersoon_actprio'    => $new_refpersoon_actprio,
    ];
}

function intake_status_reffeedback($contact_id, $part_array, $refdata_array, $refnodig, $groupID) {

    $extdebug       = 3;
    $today_datetime = date("Y-m-d H:i:s");

    // Initialisatie
    $displayname                    = $part_array['displayname']        ?? NULL;
    $ditevent_part_kampkort         = $part_array['part_kampkort']      ?? NULL;

    if (empty($contact_id)) { return NULL; }

    // Mapping data
    $ditevent_part_id           = $part_array['id']                     ?? NULL;
    $ditevent_part_eventid      = $part_array['part_event_id']          ?? NULL;
    $ditevent_part_kampnaam     = $part_array['part_kampnaam']          ?? NULL;
    $ditevent_part_kampstart    = $part_array['part_kampstart']         ?? NULL;
    $ditevent_part_kampeinde    = $part_array['part_kampeinde']         ?? NULL;
    $ditevent_part_rol          = $part_array['part_rol']               ?? NULL;
    $ditevent_part_functie      = $part_array['part_functie']           ?? NULL;

    $ref_datum                  = $refdata_array['new_cont_refdatum']   ?? NULL;

    wachthond($extdebug, 1, "### INTAKE STATUS - REF FEEDBACK VOOR $displayname");

    // Fiscale check
    $refdatum_infiscal_nu       = infiscalyear($ref_datum, $today_datetime) ?? 0;

    // Defaults
    $new_reffeedback_actstatus  = 'Scheduled';
    $new_cont_refstatus         = 'onbekend';

    // =========================================================================
    // LOGICA VOOR ACTIVITEIT: REF FEEDBACK ONTVANGEN
    // =========================================================================
    if ($refdatum_infiscal_nu == 1) {
        $new_cont_refstatus        = 'ontvangen';
        $new_reffeedback_actstatus = 'Completed';
    }
    elseif ($refnodig == "noggoed") {
        $new_cont_refstatus        = 'noggoed';
        $new_reffeedback_actstatus = 'Not Required';
    }
    elseif (date_bigger($today_datetime, $ditevent_part_kampeinde)) {
        $new_cont_refstatus        = 'verlopen';
        $new_reffeedback_actstatus = 'Failed';
    }

    $new_reffeedback_actprio = 'Normaal';
    $new_reffeedback_actdatum = $ref_datum ?: $ditevent_part_kampstart;

    return [
        'displayname'               => $displayname,
        'contact_id'                => $contact_id,
        'part_id'                   => $ditevent_part_id,
        'event_id'                  => $ditevent_part_eventid,
        'kamp_naam'                 => $ditevent_part_kampnaam,
        'kamp_start'                => $ditevent_part_kampstart,
        'kamp_rol'                  => $ditevent_part_rol,
        'kamp_functie'              => $ditevent_part_functie,
        'new_cont_refstatus'        => $new_cont_refstatus,
        'activity_type_naam'        => 'REF_feedback',
        'new_reffeedback_actdatum'  => $new_reffeedback_actdatum,
        'new_reffeedback_actstatus' => $new_reffeedback_actstatus,
        'new_reffeedback_actprio'   => $new_reffeedback_actprio,
    ];
}

/**
 * Bepaalt de overkoepelende REF status op basis van de refdata_array.
 * * @param int   $contact_id
 * @param array $part_array
 * @param array $refdata_array (bevat de resultaten van persoon & feedback functies)
 * @param string $refnodig
 */
function intake_status_ref_algemeen($contact_id, $part_array, $refdata_array, $refnodig) {
    
    $extdebug        = 2;
    $algemene_status = 'onbekend';
    
    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS [REF-ALGEMEEN] ANALYSE",                      "[START]");
    wachthond($extdebug, 1, "########################################################################");

    // =========================================================================
    // STAP 1: DATA VERZAMELEN (FALLBACK INDIEN NODIG)
    // =========================================================================

    // Als de specifieke status_persoon of status_feedback velden niet in de array zitten, berekenen we ze on-the-fly
    if (!isset($refdata_array['status_persoon']) || !isset($refdata_array['status_feedback'])) {
        wachthond($extdebug, 2, "[FALLBACK] Deelstatussen ontbreken in array, bereken ze nu via sub-functies...");
        
        $res_p = intake_status_refpersoon($contact_id, $part_array, $refdata_array, $refnodig, NULL);
        $res_f = intake_status_reffeedback($contact_id, $part_array, $refdata_array, $refnodig, NULL);
        
        $status_p = $res_p['new_cont_refstatus'] ?? 'onbekend';
        $status_f = $res_f['new_cont_refstatus'] ?? 'onbekend';
        
        wachthond($extdebug, 3, "[DEBUG] Fallback resultaat: Persoon=$status_p, Feedback=$status_f");
    } else {
        $status_p = $refdata_array['status_persoon'];
        $status_f = $refdata_array['status_feedback'];
        wachthond($extdebug, 2, "[DATA] Deelstatussen gevonden in refdata_array.");
    }

    wachthond($extdebug, 2, "[CHECK] Status Persoon: $status_p | Status Feedback: $status_f | Nodig: $refnodig");

    // =========================================================================
    // STAP 2: HIERARCHIE VAN DE ALGEMENE STATUS (DE WATERVAL)
    // =========================================================================

    // 1. Feedback is binnen -> Alles OK
    if ($status_f == 'ontvangen') {
        wachthond($extdebug, 2, "[MATCH] Conditie 1: Feedback is ontvangen. Proces compleet.");
        $algemene_status = 'ontvangen';
    }
    // 2. Persoon is doorgegeven/bekend, maar feedback nog niet (onbekend, vragen, etc.)
    elseif ($status_p == 'vragen' || $status_p == 'ontvangen') {
        wachthond($extdebug, 2, "[MATCH] Conditie 2: Persoon is bekend ($status_p), maar feedback nog niet binnen.");
        // Zodra we een persoon hebben, 'vragen' we om de feedback (of we wachten erop)
        $algemene_status = 'vragen';
    }
    // 3. Geen actie nodig op basis van historie (dit jaar alles nog geldig)
    elseif ($refnodig == 'noggoed') {
        wachthond($extdebug, 2, "[MATCH] Conditie 3: Geen actie nodig op basis van historie (noggoed).");
        $algemene_status = 'noggoed';
    }
    // 4. Fallback op persoon-status (bijv. 'verlopen' of 'onbekend')
    else {
        wachthond($extdebug, 2, "[MATCH] Conditie 4: Geen specifieke match, overname status_persoon ($status_p).");
        $algemene_status = $status_p;
    }

    wachthond($extdebug, 1, "### RESULTAAT ALGEMEEN: [$algemene_status]");
    wachthond($extdebug, 1, "########################################################################");

    return $algemene_status;
}
/**
 * Haalt referentie-informatie op.
 * * @param int    $contact_id
 * @param int    $groupID
 * @param string $mode       'current' = Actief in dit boekjaar (standaard)
 * 'recent'  = Nieuwste datum met feedback (historie)
 */
function intake_referentie_get($contact_id, $groupID = NULL, $mode = 'current') {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
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

    wachthond($extdebug,7, 'params_ref_get',            $params_ref_get);
    
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
