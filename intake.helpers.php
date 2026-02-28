<?php

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
 * Injecteert berekende waarden in de CiviCRM API params op basis van een mapping.
 * * @param array $params  De CiviCRM pre-hook params (passed by reference)
 * @param array $keys    De verzamelde veld-indices uit de initiële scan
 * @param array $data    De berekende waarden (bijv. ['ref_status' => 'noggoed'])
 * @param string $label  Label voor de wachthond (bijv. "REF" of "VOG")
 * @param int $extdebug  Debug niveau
 */
function intake_inject_params(&$params, $keys, $data, $label = "DATA",) {

    $extdebug = 0;
    
    foreach ($data as $varKey => $newValue) {
        
        // Controleer of de mapping-key bestaat in de gescande $keys
        if (isset($keys[$varKey])) {
            $index   = $keys[$varKey];
            $colName = $params[$index]['column_name'] ?? 'Onbekend';

            // Alleen injecteren als er een waarde is (voorkomt overschrijven met NULL)
            if ($newValue !== NULL) {
                
                // Formatteer de waarde (datums, multi-select etc.)
                $smartValue = format_civicrm_smart($newValue, $colName);
                
                // De eigenlijke injectie in de bron-array
                $params[$index]['value'] = $smartValue;

                wachthond($extdebug, 2, "Intake Inject [$label] Success", "Field: $colName | Value: $smartValue");
            }
        } else {
            wachthond($extdebug, 3, "Intake Inject [$label] Skip", "Key '$varKey' niet aanwezig in params.");
        }
    }
}

/**
 * Helper functie om API updates veilig uit te voeren en fouten te loggen.
 * * @param string $entity   De entiteit naam (bijv. 'Contact' of 'Participant')
 * @param int    $id       Het ID van het record om te updaten
 * @param array  $values   Array met velden en waarden (API formaat, dus 'Custom_123' => 'Waarde')
 * @param int    $extdebug Debug niveau voor de wachthond
 */
function intake_api_update_wrapper($entity, $id, $values) {

    $extdebug = 0;
    
    // 1. Veiligheidscheck: Als ID ontbreekt of values leeg zijn, doen we niks.
    if (empty($id) || empty($values)) {
        wachthond($extdebug, 3, "API UPDATE SKIP", "Geen ID ($id) of geen values om te updaten voor $entity.");
        return;
    }

    try {
        // 2. De daadwerkelijke CiviCRM API4 aanroep
        civicrm_api4($entity, 'update', [
            'checkPermissions'  => FALSE, // Sla permissie checks over (systeem actie)
            'where'             => [['id', '=', (int)$id]],
            'values'            => $values
        ]);

        // 3. Log succes
        wachthond($extdebug, 2, "API UPDATE SUCCESS", "$entity ID $id succesvol bijgewerkt.");
        
    } catch (\Exception $e) {
        // 4. Foutafhandeling: Log de error, maar stop het script niet.
        // Dit voorkomt dat een API fout de hele pagina van de gebruiker breekt.
        wachthond($extdebug, 1, "API UPDATE ERROR", "Fout bij updaten $entity ID $id: " . $e->getMessage());
    }
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
 * Centrale functie om de VOG/REF status te bepalen.
 * SCHEIDING: Deze functie bepaalt de beleidsmatige status (eerstex, opnieuw, noggoed, elkjaar).
 * De logica of een actie dit jaar al is uitgevoerd, vindt plaats in de aanroepende logic.
 *
 * @param array  $part_array    Huidige deelname data (met contact_id, displayname, etc).
 * @param array  $allpart_array Historie data (met pos_leid_count).
 * @param array  $intake_array  Input array (met 'type' en 'laatste').
 * @return string
 */
function intake_check_nodig($part_array, $allpart_array, $intake_array) {
    
    $extdebug       = 3; // Zet op 1 of 2 voor uitgebreide debugging
    $today_datetime = date("Y-m-d H:i:s");

    $type           = $intake_array['type']             ?? 'ONBEKEND'; 
    $laatste_datum  = $intake_array['cont_vog_laatste'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE HELPER - CHECK NODIG",                               "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // =========================================================================
    // STAP 0: CONFIGURATIE OPHALEN
    // =========================================================================
    $intake_config = find_fiscalyear(); 
    $grens_3jr     = $intake_config['noggoed3']         ?? NULL;
    $grens_1jr     = $intake_config['noggoed1']         ?? NULL;

    // =========================================================================
    // STAP 1: DATA UITPAKKEN & START LOGGING
    // =========================================================================
    $displayname     = $part_array['displayname']       ?? 'Onbekend';
    $part_functie    = $part_array['part_functie']      ?? $part_array['result_allpart_pos_leid_kampfunctie'] ?? '';
    $curcv_keer_leid = $part_array['curcv_keer_leid']   ?? 0;
    $contact_id      = $part_array['contact_id']        ?? 0;

    wachthond($extdebug, 1, "### CHECK NODIG [$type] START", "[$displayname]");

    // -------------------------------------------------------------------------
    // SAFEGUARD: INTEGRITEIT CHECK
    // -------------------------------------------------------------------------
    // We controleren of de minimale vereisten voor een betrouwbare check aanwezig zijn.
    // Zonder Contact ID kunnen we geen groepschecks (Bestuur) doen.
    // Zonder Part ID of displayname is de logging onbruikbaar.
    
    if (empty($contact_id) || $contact_id <= 0) {
        wachthond($extdebug, 1, "CHECK NODIG [$type] [CRITICAL FAIL]", "Geen geldig Contact ID! Check afgebroken.");
        return ''; 
    }

    // =========================================================================
    // STAP 2: GATEKEEPER (ACTIEVE ROL?)
    // =========================================================================
    // Alleen leidinggevende rollen (pos_leid_count > 0) vallen onder het VOG-protocol.
    $pos_leid_count = $allpart_array['result_allpart_pos_leid_count'] ?? 0;

    if ($pos_leid_count == 0) {
        wachthond($extdebug, 1, "CHECK NODIG [$type] [OBV ACTIEVE DEELNAME]", "Geen actieve leidingrol gevonden. Status leegmaken.");
        return ''; 
    }

    // =========================================================================
    // STAP 3: BASIS STATUS (ERVARING)
    // =========================================================================
    // Eerste keer mee is 'eerstex', anders is de basis 'opnieuw'.
    $status = ($curcv_keer_leid <= 1) ? 'eerstex' : 'opnieuw';

    wachthond($extdebug, 2, "CHECK NODIG [$type] [OBV ERVARING]", "Basisstatus: $status (keer_leid: $curcv_keer_leid)");

    // =========================================================================
    // STAP 4: TERMIJN CHECK (STANDAARD 3 JAAR)
    // =========================================================================
    // Indien er een datum bekend is, valideren we deze tegen de 3-jaars grens.
    if (!empty($laatste_datum)) {
        if (date_biggerequal($laatste_datum, $grens_3jr, 'laatste', 'grens_3jr')) {
            $status = 'noggoed';
            wachthond($extdebug, 2, "CHECK NODIG [$type] [OBV DATUM]", "Datum $laatste_datum is geldig (>= $grens_3jr). Status: $status");
        } else {
            $status = 'opnieuw';
            wachthond($extdebug, 2, "CHECK NODIG [$type] [OBV DATUM]", "Datum $laatste_datum is verlopen (< $grens_3jr). Status: $status");
        }
    } else {
        wachthond($extdebug, 2, "CHECK NODIG [$type] [OBV DATUM]", "Geen datum bekend. Status blijft: $status");
    }

    // =========================================================================
    // STAP 5: KADER OVERRIDE (HOOFDLEIDING / BESTUUR)
    // =========================================================================
    // Kaderleden vallen bij VOG onder een strenger regime (jaarlijks).
    // De status 'elkjaar' overrulet hier zowel 'noggoed' als 'opnieuw'.
    
    $group_data = (function_exists('acl_group_get')) ? acl_group_get($contact_id, 455, 'bestuur') : [];
    $is_kader   = (in_array($part_functie, ['hoofdleiding', 'bestuurslid']) || ($group_data['group_member'] ?? 0) == 1);

    if ($is_kader && $type == 'VOG') {
        $status = 'elkjaar';
        wachthond($extdebug, 2, "CHECK NODIG [$type] [OBV KADER]", "Persoon is Kader. Status geforceerd naar: $status");
    }

    // =========================================================================
    // STAP 6: EINDRESULTAAT
    // =========================================================================
    wachthond($extdebug, 1, "### CHECK NODIG [$type] RESULTAAT", ">> $status <<");

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE HELPER - CHECK NODIG",                               "[START]");
    wachthond($extdebug, 2, "########################################################################");

    return $status;
}