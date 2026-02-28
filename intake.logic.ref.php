<?php

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
 * @param array $part_array    (Optioneel) Cache: Resultaat van base_pid2part() voor event-dates.
 * @param array $params         (Optioneel) Formulier input (alleen beschikbaar bij hook_pre edit).
 * @param int   $groupID        (Optioneel) ID van de profielgroep die de trigger gaf (0 = Systeem/Core).
 */

function intake_ref_configure($contact_id, $part_id, &$params = [], $allpart_array = [], $part_array = [], $grensrefnoggoed = NULL) {

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
        $allpart_array = $allpart_array;
        wachthond($extdebug,2, "AllPart data", "Opgehaald uit input argument (Cache)");
    } else {
        $allpart_array = base_find_allpart($contact_id, $today);
        wachthond($extdebug,2, "AllPart data", "Vers opgehaald via base_allpart");
    }
    
    // Debug de belangrijkste beslis-variabelen
    wachthond($extdebug,4, "Allpart: Pos Leid Part ID", $allpart_array['ditjaar_pos_leid_part_id'] ?? 'NULL');
    wachthond($extdebug,4, "Allpart: Status ID",        $allpart_array['ditjaar_one_leid_status_id'] ?? 'NULL');

    // -------------------------------------------------------------------------
    // Stap 1b: Event Informatie (Kamp data)
    // -------------------------------------------------------------------------
    // We moeten weten wanneer het kamp start om het boekjaar te bepalen.
    // Ook hier: gebruik cache indien beschikbaar.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 1.2 Stap 1b: Haal Event informatie op");
    wachthond($extdebug,1, "########################################################################");

    if (!empty($part_array)) {
        $part_array = $part_array; // Expliciet toewijzen voor duidelijkheid
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
            $part_array = civicrm_api4('Event', 'get', $params_ev)->first();
            wachthond($extdebug,2, "Event data", "Vers opgehaald voor Event ID: $target_event_id");
        } else {
            $part_array = [];
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

    $event_start        = $part_array['start_date'] ?? date('Y-m-d');
    $event_fiscalyear   = $part_array['event_fiscalyear']      ?? NULL;
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
    $refdata_array      = intake_consolidate_refdata($part_array, $allpart_array, $array_intake_refall, $params);
    
    $intake_array_ref   = $refdata_array;

    wachthond($extdebug,2, 'UITKOMST Consolidate Array', $refdata_array);

    // Variabelen uitpakken uit de helper resultaten
    $new_cont_refnodig   = $refdata_array['new_cont_refnodig'];
    $new_cont_refstatus  = $refdata_array['new_cont_refstatus'];
    $new_cont_reflaatste = $refdata_array['new_cont_reflaatste'];
    $new_cont_refpersoon = $refdata_array['new_cont_refpersoon'];
    $new_cont_refverzoek = $refdata_array['new_cont_refverzoek'];
    $new_cont_refdatum   = $refdata_array['new_cont_refdatum'];

    $new_part_refnodig   = $refdata_array['new_part_refnodig'];
    $new_part_refstatus  = $refdata_array['new_part_refstatus'];

    wachthond($extdebug,2, "Consolidated Datum Persoon", $new_cont_refpersoon);

    // -------------------------------------------------------------------------
    // Stap 5: Berekening Ref NODIG
    // -------------------------------------------------------------------------
    // Is dit een nieuwe vrijwilliger? Of is de laatste referentie te oud (> grensdatum)?
    // Zo ja, zet ref_nodig op 'Ja'.
    
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### INTAKE REF CONFIG 5.0 REFNODIG CALCULATION",         "[$displayname]");
    wachthond($extdebug,1, "########################################################################");

    $new_cont_refnodig = intake_check_nodig(
        $part_array,        // Bevat functie, startdatum, etc.
        $allpart_array,     // Bevat de historie (count check)
        $intake_array_ref   // Bevat geconsolideerde intake data
    );
    wachthond($extdebug, 3, "new_cont_refnodig",    $new_cont_refnodig);

    // Update de consolidate array, want status berekening (stap 7) heeft dit nodig
    $refdata_array['new_cont_refnodig'] = $new_cont_refnodig;
    $refdata_array['new_part_refnodig'] = $new_part_refnodig;

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
    $intake_status_refpersoon_array  = intake_status_refpersoon($contact_id, $part_array, $refdata_array, $new_cont_refnodig, $groupID);
    wachthond($extdebug,3, "Status Array (Persoon)", $intake_status_refpersoon_array);
    
    $new_refpersoon_actdatum         = $intake_status_refpersoon_array['new_refpersoon_actdatum'];
    $new_refpersoon_actstatus        = $intake_status_refpersoon_array['new_refpersoon_actstatus'];
    $new_refpersoon_actprio          = $intake_status_refpersoon_array['new_refpersoon_actprio'];

    // B. Ref Feedback Status
    $intake_status_reffeedback_array = intake_status_reffeedback($contact_id, $part_array, $refdata_array, $new_cont_refnodig, $groupID);
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
            $act_id = intake_activity_create($contact_id, $activity_array, $part_array, $intake_array);
            wachthond($extdebug, 1, "ACTION: Activity Created (RefPersoon)", "ID: $act_id");
        } else {
            wachthond($extdebug, 2, "ACTION: Activity Found (RefPersoon)", "ID: $act_id (Updating...)");
            $activity_array['activity_id'] = $act_id;
//          intake_activity_update($contact_id, $activity_array, $part_array, $intake_array, $referentie_array);
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
            $act_id = intake_activity_create($contact_id, $activity_array, $part_array, $intake_array);
            wachthond($extdebug, 1, "ACTION: Activity Created (RefFeedback)", "ID: $act_id");
        } else {
            wachthond($extdebug, 2, "ACTION: Activity Found (RefFeedback)", "ID: $act_id (Updating...)");
            $activity_array['activity_id'] = $act_id;
//          intake_activity_update($contact_id, $activity_array, $part_array, $intake_array, $referentie_array);
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
    $current_status  = $allpart_array['ditjaar_one_leid_status_id'] ?? NULL;

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
        wachthond($extdebug,3, 'Params voor Participant Update',    $params_part_update);
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

/**
 * Functie: intake_consolidate_refdata
 * Doel:    Het samenvoegen van Referentie-data uit drie bronnen:
 * 1. Het zojuist ingevulde formulier ($params)
 * 2. De huidige data van dit specifieke evenement ($part_array)
 * 3. De historie uit de database ($intake_referentie_get)
 *
 * Logica:  Formulier wint van Historie = 'Winnaar'.
 * Winnaar wordt naar Participant geschreven (mits in fiscaal jaar).
 * Winnaar wordt naar Contact geschreven (als het de nieuwste ooit is).
 */
function intake_consolidate_refdata($part_array, $allpart_array, $intake_array, $params = []) {

    $extdebug       = 0;  // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;
    $today_datetime = date("Y-m-d H:i:s");    

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 0. EXTRACTIE FORMULIER",      "[PARAMS]");
    wachthond($extdebug, 2, "########################################################################");

    // Configuratie: Welke velden in de $params (formulier) zoeken we?
    // We mappen ze naar interne 'val_' variabelen voor consistentie.
    $mapping = [
        'referentie_persoon'  => 'val_ref_persoon',   // Datum: Persoon opgegeven
        'referentie_verzoek'  => 'val_ref_verzoek',   // Datum: Wij hebben gemaild
        'referentie_feedback' => 'val_ref_feedback',  // Datum: Feedback ontvangen
    ];

    $extracted = [
        'val_ref_persoon'  => NULL,
        'val_ref_verzoek'  => NULL,
        'val_ref_feedback' => NULL,
    ];

    // Loop door alle parameters heen en kijk of we matches vinden
    foreach ($params as $item) {
        $col = $item['column_name'] ?? '';
        $val = $item['value']       ?? NULL;
        
        foreach ($mapping as $search => $key) {
            if (str_contains($col, $search)) {
                // Formatteer de datum direct naar CiviCRM formaat (Y-m-d)
                $extracted[$key] = format_civicrm_smart($val, $col);
            }
        }
    }

    // DEBUG: Wat hebben we uit het formulier kunnen vissen?
    wachthond($extdebug, 3, "Stap 0: Resultaat extractie formulier", $extracted);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 1.1 BASIS DATA EVENT",        "[LOAD_BASE]");
    wachthond($extdebug, 2, "########################################################################");

    $displayname    = $part_array['displayname']    ?? NULL;
    $part_kampkort  = $part_array['part_kampkort']  ?? NULL;
    $part_kampstart = $part_array['part_kampstart'] ?? NULL; // Cruciaal voor fiscale check
    $contact_id     = $part_array['contact_id']     ?? 0;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 1.2 HUIDIGE CONTACT DATA",   "[LOAD_CONT]");
    wachthond($extdebug, 2, "########################################################################");

    // Dit is de "Global State" van het contact (meestal uit cid2cont)
    // We focussen hier op de 'Laatste' velden, want Referenties zijn vaak jaaroverschrijdend relevant.
    $cont_ref_laatste = $intake_array['cont_reflaatste']  ?? NULL;
    $cont_ref_naam    = $intake_array['cont_refnaam']     ?? NULL;
    $cont_ref_cid     = $intake_array['cont_ref_cid']     ?? NULL;
    $cont_ref_bezwaar = $intake_array['cont_ref_bezwaar'] ?? 'Geen'; // Default op 'Geen'

    wachthond($extdebug, 3, "Huidige Global Contact Status", [
        'Laatste datum' => $cont_ref_laatste,
        'Naam referent' => $cont_ref_naam,
        'Bezwaar'       => $cont_ref_bezwaar
    ]);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 1.3 HUIDIGE DEELNEMER DATA", "[LOAD_PART]");
    wachthond($extdebug, 2, "########################################################################");

    // Dit is de status van dit specifieke evenement-record (kan leeg zijn of oud)
    $part_ref_persoon  = $part_array['part_refpersoon']  ?? NULL;
    $part_ref_verzoek  = $part_array['part_refverzoek']  ?? NULL;
    $part_ref_feedback = $part_array['part_reffeedback'] ?? NULL;

    wachthond($extdebug, 3, "Huidige Participant Status", [
        'Persoon opgegeven' => $part_ref_persoon,
        'Feedback datum'    => $part_ref_feedback
    ]);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 1.4 HISTORIE OPHALEN (DB)",  "[LOAD_HIST]");
    wachthond($extdebug, 2, "########################################################################");

    // Initialiseer variabelen voor de historie
    $rel_laatste_datum = NULL;
    $rel_cid           = NULL;
    $rel_naam          = NULL;
    $rel_bezwaar       = NULL;

    if ($contact_id > 0) {
        // We gebruiken de helper functie om diep in de CiviCRM relaties/historie te graven
        // Dit haalt de meest recente referentie op, ongeacht het jaar.
        $rel_data_laatste  = intake_referentie_get($contact_id, 0, 'recent');
        
        $rel_laatste_datum = $rel_data_laatste['ref_feedback']        ?? NULL; 
        $rel_cid           = $rel_data_laatste['ref_referentie_cid']  ?? NULL; 
        $rel_naam          = $rel_data_laatste['ref_referentie_naam'] ?? NULL; 
        $rel_bezwaar       = $rel_data_laatste['ref_bezwaar']         ?? NULL; 

        if ($rel_laatste_datum) {
            wachthond($extdebug, 2, "Historie gevonden (DB)", "$rel_laatste_datum (Referent: $rel_naam)");
        } else {
            wachthond($extdebug, 2, "Geen historie gevonden in DB.");
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 2.1 BEPAAL WINNAAR",          "[REF_WINNER]");
    wachthond($extdebug, 2, "########################################################################");

    // STAP A: Wie heeft de nieuwste data? Het Formulier ($extracted) of de Database ($rel_laatste)?
    // Als formulier leeg is, wint de database sowieso.
    
    $is_val_newer = date_bigger($extracted['val_ref_feedback'], $rel_laatste_datum);

    if ($is_val_newer) {
        // Formulier bevat een nieuwere datum (of DB was leeg)
        $tmp_winner_feedback = $extracted['val_ref_feedback'];
        
        // Metadata (Naam/CID/Bezwaar) halen we uit formulier, of fallback naar DB als formulier dat niet bevat
        $tmp_winner_naam     = $params['referentie_naam']    ?? $rel_naam;
        $tmp_winner_cid      = $params['referentie_cid']     ?? $rel_cid;
        $tmp_winner_bezwaar  = $params['referentie_bezwaar'] ?? $rel_bezwaar;
        
        wachthond($extdebug, 2, "Winnaar = FORMULIER", "Datum: $tmp_winner_feedback");
    } else {
        // Database historie is nieuwer of gelijk aan formulier
        $tmp_winner_feedback = $rel_laatste_datum;
        $tmp_winner_naam     = $rel_naam;
        $tmp_winner_cid      = $rel_cid;
        $tmp_winner_bezwaar  = $rel_bezwaar;
        
        wachthond($extdebug, 2, "Winnaar = DATABASE HISTORIE", "Datum: $tmp_winner_feedback");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 2.2 DEELNEMER SYNC",          "[REF_PART_SYNC]");
    wachthond($extdebug, 2, "########################################################################");

    // STAP B: Update de specifieke event-data (Participant)
    
    // B.1: Persoon & Verzoek datums
    // Simpele logica: Als formulier data heeft die nieuwer is dan wat er stond -> Update.
    if (date_bigger($extracted['val_ref_persoon'], $part_ref_persoon)) {
        $part_ref_persoon = $extracted['val_ref_persoon'];
        wachthond($extdebug, 2, "PART UPDATE: Datum Persoon Door gegeven", $part_ref_persoon);
    }
    
    if (date_bigger($extracted['val_ref_verzoek'], $part_ref_verzoek)) {
        $part_ref_verzoek = $extracted['val_ref_verzoek'];
        wachthond($extdebug, 2, "PART UPDATE: Datum Verzoek Verstuurd", $part_ref_verzoek);
    }

    // B.2: Feedback Datum (CRUCIAAL: Fiscale Check)
    // We mogen een oude referentie uit 2020 NIET koppelen aan een kamp in 2025.
    // De datum moet vallen binnen het fiscale jaar van de 'part_kampstart'.
    
    $check_fiscaal = infiscalyear($tmp_winner_feedback, $part_kampstart);

    if ($check_fiscaal == 1) {
        // Datum is geldig voor dit kampjaar. Is hij ook nieuwer dan wat er al stond?
        if (date_biggerequal($tmp_winner_feedback, $part_ref_feedback) || empty($part_ref_feedback)) {
            $part_ref_feedback = $tmp_winner_feedback;
            wachthond($extdebug, 2, "PART UPDATE: Feedback Datum (Fiscaal OK)", $part_ref_feedback);
        } else {
            wachthond($extdebug, 2, "PART SKIP: Bestaande datum was al nieuwer/gelijk.");
        }
    } else {
        wachthond($extdebug, 2, "PART SKIP: Datum ($tmp_winner_feedback) valt buiten fiscaal jaar kamp ($part_kampstart).");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CONSOLIDATE [REF] 2.3 CONTACT SYNC",            "[REF_CONT_SYNC]");
    wachthond($extdebug, 2, "########################################################################");

    // STAP C: Update de globale contact data (Contact)
    // Hier is de logica simpeler: We willen gewoon weten wat de aller- allerlaatste referentie is.
    // Fiscale jaren maken hier niet uit, we willen de "Last Known Good".
    
    if (date_bigger($tmp_winner_feedback, $cont_ref_laatste)) {
        // We hebben een nieuwe kampioen! Update de globale status.
        $cont_ref_laatste = $tmp_winner_feedback;
        $cont_ref_naam    = $tmp_winner_naam;
        $cont_ref_cid     = $tmp_winner_cid;
        $cont_ref_bezwaar = $tmp_winner_bezwaar;
        
        wachthond($extdebug, 2, "CONTACT UPDATE: Nieuwe 'Laatste' Referentie gevonden!", [
            'Datum'   => $cont_ref_laatste,
            'Naam'    => $cont_ref_naam,
            'Bezwaar' => $cont_ref_bezwaar
        ]);
    } else {
        wachthond($extdebug, 2, "CONTACT SKIP: Huidige profieldata is al recenter ($cont_ref_laatste).");
    }

    $result_array = [
        'vault'               => 'refdata',
        'displayname'         => $displayname,
        'part_kampkort'       => $part_kampkort,
        
        // Debug info uit historie
        'rel_bezwaar_raw'     => $rel_bezwaar, 

        // 1. Wat kwam er uit het formulier?
        'val_ref_persoon'     => $extracted['val_ref_persoon'],
        'val_ref_verzoek'     => $extracted['val_ref_verzoek'],
        'val_ref_feedback'    => $extracted['val_ref_feedback'],

        // 2. Wat is nu de Globale Contact Status?
        'cont_ref_laatste'    => $cont_ref_laatste,
        'cont_ref_naam'       => $cont_ref_naam,
        'cont_ref_cid'        => $cont_ref_cid,
        'cont_ref_bezwaar'    => $cont_ref_bezwaar ?? 'Geen',

        // 3. Wat is nu de Event Participant Status?
        'part_ref_persoon'    => $part_ref_persoon,
        'part_ref_verzoek'    => $part_ref_verzoek,
        'part_ref_feedback'   => $part_ref_feedback,
    ];

    wachthond($extdebug, 4, "### FINAL RESULT REF DATA ###", $result_array);

    return $result_array;
}