<?php

/**
 * BEPAAL STATUS VOG VERZOEK (ACTIVITY)
 * Bepaalt of er een VOG-verzoek activiteit moet zijn en wat de status is.
 * Kijkt naar de geconsolideerde cont_ waarden uit $intake_array.
 */
function intake_status_vogverzoek($part_array, $allpart_array, $intake_array) {

    $extdebug       = 3;  // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    // Initialisatie variabelen
    $vogverzoek_status = 'Pending'; 
    $vogverzoek_prio   = 'Normaal';
    $vogverzoek_datum  = NULL;

    // Data uitpakken
    $contact_id             = $part_array['contact_id']             ?? 0;
    $displayname            = $part_array['displayname']            ?? 'Onbekend';
    $ditevent_part_kampkort = $part_array['ditevent_part_kampkort'] ?? $part_array['part_kampkort'] ?? NULL;

    // Veiligheidscheck
    if (empty($contact_id)) {
        return NULL;
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS - VOGVERZOEK - BEPAAL STATUS VOOR $displayname [$ditevent_part_kampkort]", "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    // --- 1.1 INTAKE DATA EXTRACTIE (Geconsolideerde CONT waarden) ---
    $cont_vog_verzoek  = $intake_array['cont_vog_verzoek']      ?? NULL;
    $cont_vog_aanvraag = $intake_array['cont_vog_aanvraag']     ?? NULL;
    $cont_vog_datum    = $intake_array['cont_vog_datum']        ?? NULL;
    
    // --- 1.2 EVENT DATA EXTRACTIE ---
    $ditevent_part_id        = $part_array['id']                ?? $part_array['part_id']    ?? NULL;
    $ditevent_part_eventid   = $part_array['part_eventid']      ?? $part_array['event_id']   ?? NULL;
    $ditevent_part_kampnaam  = $part_array['part_kampnaam']     ?? NULL;
    $ditevent_part_kampstart = $part_array['part_kampstart']    ?? NULL;
    
    // Rol en Functie toevoegen
    $ditevent_part_rol       = $part_array['part_rol']          ?? NULL;
    $ditevent_part_functie   = $part_array['part_functie']      ?? NULL;

    // --- 2.0 BEPAAL DATUM ACTIVITY ---
    // Als er al een datum is, pakken we die. Anders vandaag.
    if (!empty($cont_vog_verzoek)) {
        $vogverzoek_datum = $cont_vog_verzoek;
    } else {
        $vogverzoek_datum = $today_datetime;
    }

    // --- 3.0 BEPAAL STANDAARD STATUS ---
    $vogverzoek_status = 'Pending';

    // Fiscale checks: Is er dit jaar al actie ondernomen?
    $check_verzoek  = infiscalyear($cont_vog_verzoek,  $ditevent_part_kampstart);
    $check_aanvraag = infiscalyear($cont_vog_aanvraag, $ditevent_part_kampstart);
    $check_datum    = infiscalyear($cont_vog_datum,    $ditevent_part_kampstart);

    // Als actie al gedaan is -> Completed
    if ($check_verzoek == 1 || $check_aanvraag == 1 || $check_datum == 1) {
        $vogverzoek_status = 'Completed';
    }

    wachthond($extdebug, 2, "VOG VERZOEK STATUS CHECK", [
        'Huidige Status' => $vogverzoek_status,
        'Verzoek'        => "$cont_vog_verzoek ($check_verzoek)",
        'Aanvraag'       => "$cont_vog_aanvraag ($check_aanvraag)",
        'Datum'          => "$cont_vog_datum ($check_datum)"
    ]);

    // --- 3.1 LEIDING ROL CHECK (GATEKEEPER) ---
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CHECK LEIDINGROL (ACTIVE PARTICIPATION)");
    wachthond($extdebug, 2, "########################################################################");

    // We gebruiken de count uit allpart_array om te zien of iemand 'actief' is.
    $pos_leid_count = $allpart_array['result_allpart_pos_leid_count'] ?? 0;

    if ($pos_leid_count == 0) {
        // Geen actieve leidingrol? Dan is deze hele taak overbodig/geannuleerd.
        $vogverzoek_status = 'Cancelled';
        $vogverzoek_prio   = 'Laag';
        
        wachthond($extdebug, 1, "CHECK NODIG [VOG_VERZOEK] [OBV ACTIEVE DEELNAME]", "Geen actieve leidingrol (count=0). Status -> Cancelled");
    }

    // --- 4.0 RETURN RESULTAAT ---
    $status_vogverzoek_array = [
        'displayname'           => $displayname,
        'contact_id'            => $contact_id,
        'part_id'               => $ditevent_part_id,
        'event_id'              => $ditevent_part_eventid,
        'kamp_naam'             => $ditevent_part_kampnaam,
        'kamp_start'            => $ditevent_part_kampstart,
        'kamp_rol'              => $ditevent_part_rol,
        'kamp_functie'          => $ditevent_part_functie,
        
        // Activity Data
        'activity_type_naam'    => 'VOG_verzoek',
        'vogverzoek_actdatum'   => $vogverzoek_datum,
        'vogverzoek_actstatus'  => $vogverzoek_status,
        'vogverzoek_actprio'    => $vogverzoek_prio,

        'actdatum'              => $vogverzoek_datum,
        'actstatus'             => $vogverzoek_status,
        'actprio'               => $vogverzoek_prio,        
        
        'debug_cont_values'  => [
            'verzoek'           => $cont_vog_verzoek,
            'aanvraag'          => $cont_vog_aanvraag,
            'datum'             => $cont_vog_datum
        ]
    ];

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS - VOGVERZOEK - EINDE VOOR $displayname", "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    return $status_vogverzoek_array;
}

/**
 * BEPAAL STATUS VOG AANVRAAG
 */
function intake_status_vogaanvraag($contact_id, $part_array, $array_intake) {

    $extdebug               = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug               = FALSE;
    $today_datetime         = date("Y-m-d H:i:s");    

    // Initialisatie variabelen
    $vogaanvraag_status     = 'Scheduled'; 
    $vogaanvraag_prio       = 'Normaal';
    $new_cont_vogstatus     = 'klaarzetten';
    $new_part_vogstatus     = '';

    $displayname            = $part_array['displayname']            ?? NULL;
    $ditevent_part_kampkort = $part_array['ditevent_part_kampkort'] ?? NULL;

    if (empty($contact_id)) {
        return NULL;
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS - VOGAANVRAAG - BEPAAL STATUS VOOR $displayname [$ditevent_part_kampkort]", "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    // --- 1.1 INTAKE DATA EXTRACTIE ---
    $vog_nodig                  = $array_intake['vog_nodig']        ?? NULL;
    $vog_verzoek                = $array_intake['vog_verzoek']      ?? NULL;
    $vog_aanvraag               = $array_intake['vog_aanvraag']     ?? NULL;
    $vog_datum                  = $array_intake['vog_datum']        ?? NULL;
    $vog_laatste                = $array_intake['vog_laatste']      ?? NULL;
    $grensvognoggoed            = $array_intake['grensvognoggoed']  ?? NULL;

    // --- 1.2 EVENT DATA EXTRACTIE ---
    $ditevent_part_id           = $part_array['id']                 ?? $part_array['part_id']   ?? NULL;
    $ditevent_part_eventid      = $part_array['part_eventid']       ?? $part_array['event_id']  ?? NULL;
    $ditevent_part_kampnaam     = $part_array['part_kampnaam']      ?? NULL;
    $ditevent_part_kampstart    = $part_array['part_kampstart']     ?? NULL;
    $ditevent_part_kampeinde    = $part_array['part_kampeinde']     ?? NULL;
    $ditevent_part_rol          = $part_array['part_rol']           ?? NULL;
    $ditevent_part_functie      = $part_array['part_functie']       ?? NULL;
    $ditevent_part_status_id    = $part_array['part_status_id']     ?? $part_array['status_id'] ?? NULL;

    // --- 2.0 BEPAAL DATUM ACTIVITY ---
    $vogaanvraag_datum = NULL;

    if ($vog_aanvraag && infiscalyear($vog_aanvraag, $ditevent_part_kampstart) == 1) {
        $vogaanvraag_datum = $vog_aanvraag;
    } elseif ($vog_verzoek && infiscalyear($vog_verzoek, $ditevent_part_kampstart) == 1) {
        $vogaanvraag_datum = date('Y-m-d H:i:s', strtotime($vog_verzoek . ' + 30 days'));
    } else {
        $vogaanvraag_datum = $ditevent_part_kampstart;
    }

    // Correcties op datum
    if ($vog_datum && infiscalyear($vog_datum, $ditevent_part_kampstart) == 1 && empty($vog_aanvraag)) {
        $vogaanvraag_datum = $vog_datum;
    }
    if ($vog_aanvraag && date_bigger($vog_aanvraag, $ditevent_part_kampstart) == 1) {
        $vogaanvraag_datum = $ditevent_part_kampeinde;
    }

    // --- 3.0 BEPAAL STATUS EN PRIO ---
    
    // CHECK 1: Document ontvangen dit fiscale jaar?
    if ($vog_datum && infiscalyear($vog_datum, $today_datetime) == 1) {
        $new_cont_vogstatus = 'ontvangen';
        $vogaanvraag_status = 'Completed';
    } 
    // CHECK 2: Verzoek uitgestuurd dit fiscale jaar?
    elseif ($vog_verzoek && infiscalyear($vog_verzoek, $today_datetime) == 1) {
        $new_cont_vogstatus = 'verzocht';
        
        $days = (int)date_diff(date_create($vog_verzoek), date_create($today_datetime))->format('%a');

        if ($days < 7)         { $vogaanvraag_status = "Pending"; }
        elseif ($days < 21)    { $vogaanvraag_status = "Left Message"; }
        elseif ($days < 30)    { $vogaanvraag_status = "Unreachable"; }
        elseif ($days < 270)   { $vogaanvraag_status = "No_show"; }
        else                   { $vogaanvraag_status = "Bounced"; }
    }
    // CHECK 3: Historie en houdbaarheid
    else {
        if ($vog_nodig === 'elkjaar') {
            $new_cont_vogstatus = 'klaarzetten';
            $vogaanvraag_status = 'Scheduled';
        } elseif ($vog_laatste && $grensvognoggoed && date_biggerequal($vog_laatste, $grensvognoggoed)) {
            $new_cont_vogstatus = 'noggoed';
            $vogaanvraag_status = 'Not Required';
        } else {
            $new_cont_vogstatus = 'klaarzetten';
            $vogaanvraag_status = 'Scheduled';
        }
    }

    // Finale prioriteit mapping
    $prio_map = [
        'Pending'      => 'Laag',
        'Left Message' => 'Normaal',
        'Unreachable'  => 'Urgent',
        'No_show'      => 'Urgent',
        'Bounced'      => 'Urgent',
        'Scheduled'    => 'Normaal'
    ];
    $vogaanvraag_prio = $prio_map[$vogaanvraag_status] ?? 'Normaal';

    // Finale check: Is het kamp al voorbij?
    if ($ditevent_part_kampeinde && $today_datetime > $ditevent_part_kampeinde && $vogaanvraag_status !== 'Completed') {
        $vogaanvraag_status = 'Failed';
        $new_cont_vogstatus = 'verlopen';
    }

    // Synchroniseer naar deelnemer niveau
    if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
        $new_part_vogstatus = $new_cont_vogstatus;
    }

    // --- 3.1 ANNULERING CHECK (RESET) ---
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### MAAK DE WAARDEN LEEG INDIEN DEELNAME GEANNULEERD (VOG AANVRAAG)");
    wachthond($extdebug, 2, "########################################################################");

    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    if (!empty($ditevent_part_status_id) && in_array($ditevent_part_status_id, $status_negative)) {
        $new_cont_vogstatus = "onbekend";
        $new_part_vogstatus = "onbekend";
        $vogaanvraag_status = 'Not Required';
        $vogaanvraag_prio   = 'Laag';
        wachthond($extdebug, 1, "DEELNAME GEANNULEERD: Reset VOG status voor $displayname");
    }

    // --- 4.0 RETURN RESULTAAT ---
    $status_vogaanvraag_array = [
        'displayname'        => $displayname,
        'contact_id'         => $contact_id,
        'part_id'            => $ditevent_part_id,
        'event_id'           => $ditevent_part_eventid,
        'kamp_naam'          => $ditevent_part_kampnaam,
        'kamp_start'         => $ditevent_part_kampstart,
        'kamp_rol'           => $ditevent_part_rol,
        'kamp_functie'       => $ditevent_part_functie,
        'new_cont_vogstatus' => $new_cont_vogstatus,
        'new_part_vogstatus' => $new_part_vogstatus,
        'activity_type_naam' => 'VOG_aanvraag',
        'vogaanvraag_datum'  => $vogaanvraag_datum,
        'vogaanvraag_status' => $vogaanvraag_status,
        'vogaanvraag_prio'   => $vogaanvraag_prio,
    ];

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS - VOGAANVRAAG - EINDE VOOR $displayname", "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    return $status_vogaanvraag_array;
}

/**
 * BEPAAL STATUS VOG ONTVANGST
 */
function intake_status_vogontvangst($contact_id, $part_array, $array_intake) {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    // Initialisatie variabelen
    $vogontvangst_status = 'Scheduled';
    $vogontvangst_prio   = 'Normaal';
    $new_cont_vogstatus  = 'verzocht';
    $new_part_vogstatus  = ''; 

    $displayname            = $part_array['displayname']   ?? NULL;
    $ditevent_part_kampkort = $part_array['part_kampkort']  ?? NULL;

    if (empty($contact_id)) {
        return NULL;
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS VOGONTVANGST - BEPAAL STATUS VOOR $displayname [$ditevent_part_kampkort]", "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    // --- 1.1 INTAKE DATA EXTRACTIE ---
    $vog_nodig        = $array_intake['vog_nodig']        ?? NULL;
    $vog_verzoek      = $array_intake['vog_verzoek']      ?? NULL;
    $vog_aanvraag     = $array_intake['vog_aanvraag']     ?? NULL;
    $vog_datum        = $array_intake['vog_datum']        ?? NULL;
    $vog_laatste      = $array_intake['vog_laatste']      ?? NULL;
    $grensvognoggoed  = $array_intake['grensvognoggoed']  ?? NULL;

    // --- 1.2 EVENT DATA EXTRACTIE ---
    $ditevent_part_id        = $part_array['id']               ?? $part_array['part_id']   ?? NULL;
    $ditevent_part_eventid   = $part_array['part_eventid']     ?? $part_array['event_id']  ?? NULL;
    $ditevent_part_kampnaam  = $part_array['part_kampnaam']    ?? NULL;
    $ditevent_part_kampstart = $part_array['part_kampstart']   ?? NULL;
    $ditevent_part_kampeinde = $part_array['part_kampeinde']   ?? NULL;
    $ditevent_part_rol       = $part_array['part_rol']         ?? NULL;
    $ditevent_part_functie   = $part_array['part_functie']     ?? NULL;
    $ditevent_part_status_id = $part_array['part_status_id']   ?? $part_array['status_id'] ?? NULL;

    // --- 2.0 BEPAAL DATUM ACTIVITY ---
    $vogontvangst_datum = NULL;

    if ($vog_datum && infiscalyear($vog_datum, $ditevent_part_kampstart) == 1) {
        $vogontvangst_datum = $vog_datum;
    } elseif ($vog_datum && date_bigger($vog_datum, $ditevent_part_kampstart) == 1) {
        $vogontvangst_datum = $ditevent_part_kampeinde;
    } elseif ($vog_aanvraag && infiscalyear($vog_aanvraag, $ditevent_part_kampstart) == 1) {
        $vogontvangst_datum = date('Y-m-d H:i:s', strtotime($vog_aanvraag . ' + 49 days'));
    } else {
        $vogontvangst_datum = $ditevent_part_kampeinde;
    }

    wachthond($extdebug, 1, "Activity datum bepaald op:", $vogontvangst_datum);

    // --- 3.0 BEPAAL STATUS EN PRIO ---

    // CHECK 1: Document dit jaar ontvangen?
    if ($vog_datum && infiscalyear($vog_datum, $today_datetime) == 1) {
        $new_cont_vogstatus  = 'ontvangen';
        $vogontvangst_status = 'Completed';
    } 
    // CHECK 2: Document dit jaar aangevraagd? (Escalatie logica)
    elseif ($vog_aanvraag && infiscalyear($vog_aanvraag, $today_datetime) == 1) {
        $new_cont_vogstatus  = 'ingediend';
        
        $days = (int)date_diff(date_create($vog_aanvraag), date_create($today_datetime))->format('%a');
        
        if ($days < 14)        { $vogontvangst_status = "Pending"; }
        elseif ($days < 28)    { $vogontvangst_status = "Left Message"; }
        elseif ($days < 42)    { $vogontvangst_status = "Unreachable"; }
        elseif ($days < 270)   { $vogontvangst_status = "No_show"; }
        else                   { $vogontvangst_status = "Bounced"; }
    }
    // CHECK 3: Historie en houdbaarheid
    else {
        if ($vog_nodig === 'elkjaar') {
            $new_cont_vogstatus  = 'klaarzetten';
            $vogontvangst_status = 'Scheduled';
        } elseif ($vog_laatste && $grensvognoggoed && date_biggerequal($vog_laatste, $grensvognoggoed)) {
            $new_cont_vogstatus  = 'noggoed';
            $vogontvangst_status = 'Not Required';
        } else {
            $new_cont_vogstatus  = 'klaarzetten';
            $vogontvangst_status = 'Scheduled';
        }
    }

    // Finale prioriteit mapping
    $prio_map = [
        'Pending'      => 'Laag',
        'Left Message' => 'Normaal',
        'Unreachable'  => 'Urgent',
        'No_show'      => 'Urgent',
        'Bounced'      => 'Urgent',
        'Scheduled'    => 'Normaal'
    ];
    $vogontvangst_prio = $prio_map[$vogontvangst_status] ?? 'Normaal';

    // Finale deadline check
    if ($ditevent_part_kampeinde && $today_datetime > $ditevent_part_kampeinde && $vogontvangst_status !== 'Completed') {
        $vogontvangst_status = 'Failed';
        $new_cont_vogstatus  = 'verlopen';
    }

    // Synchroniseer naar deelnemer niveau
    if (infiscalyear($ditevent_part_kampstart, $today_datetime) == 1) {
        $new_part_vogstatus = $new_cont_vogstatus;
    }
    
    // --- 3.1 ANNULERING CHECK (RESET) ---
    $status_data     = find_partstatus();
    $status_negative = $status_data['ids']['Negative'] ?? [];

    if (!empty($ditevent_part_status_id) && in_array($ditevent_part_status_id, $status_negative)) {
        $new_cont_vogstatus  = "onbekend";
        $new_part_vogstatus  = "onbekend";
        $vogontvangst_status = 'Not Required';
        $vogontvangst_prio   = 'Laag';
        wachthond($extdebug, 1, "DEELNAME GEANNULEERD: Reset VOG ontvangst voor $displayname");
    }

    // --- 4.0 RETURN RESULTAAT ---
    $status_vogontvangst_array = [
        'displayname'         => $displayname,
        'contact_id'          => $contact_id,
        'part_id'             => $ditevent_part_id,
        'event_id'            => $ditevent_part_eventid,
        'kamp_naam'           => $ditevent_part_kampnaam,
        'kamp_start'          => $ditevent_part_kampstart,
        'kamp_functie'        => $ditevent_part_functie,
        'kamp_rol'            => $ditevent_part_rol,
        'new_cont_vogstatus'  => $new_cont_vogstatus,
        'new_part_vogstatus'  => $new_part_vogstatus,
        'activity_type_naam'  => 'VOG_ontvangst',
        'vogontvangst_datum'  => $vogontvangst_datum,
        'vogontvangst_status' => $vogontvangst_status,
        'vogontvangst_prio'   => $vogontvangst_prio,
    ];

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE STATUS VOGONTVANGST - EINDE VOOR $displayname",     "[STATUS]");
    wachthond($extdebug, 1, "########################################################################");

    return $status_vogontvangst_array;
}
