<?php

/**
 * =============================================================================
 * INTAKE ACTIVITIES LIBRARY
 * Bevat functies voor GET, CREATE, UPDATE en DELETE van CiviCRM Activities
 * via APIv4.
 * =============================================================================
 */

/**
 * -----------------------------------------------------------------------------
 * 1. ACTIVITEIT OPHALEN (GET)
 * -----------------------------------------------------------------------------
 */
function intake_activity_get($contact_id, $array_activity, $array_period) {

    $extdebug           = 3; 
    $apidebug           = FALSE;

    $activity_type_id   = $array_activity['activity_type_id']   ?? NULL;
    $activity_type_naam = $array_activity['activity_type_naam'] ?? NULL;

    $period_start       = $array_period['fiscalyear_start'];
    $period_einde       = $array_period['fiscalyear_einde'];

    $today              = date("Y-m-d H:i:s");

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### INTAKE GET ACTIVITY $activity_type_naam",                   "[START]");
    wachthond($extdebug, 3, "########################################################################");

    if (empty($contact_id) OR empty($activity_type_id)) {
        return [];
    }

    $params_activity_get = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,        
        'select'           => [
            // Standaard velden
            'row_count',
            'id',
            'activity_date_time',
            'status_id',
            'status_id:name',
            'subject',
            'activity_contact.contact_id',
            'priority_id:name',

            // Custom Fields Algemeen (ACT_ALG)
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
            'ACT_ALG.modified',
            'ACT_ALG.prioriteit:label',

            // Custom Fields Referentie (ACT_REF)
            'ACT_REF.ref_nodig',
            'ACT_REF.aanvrager_naam',
            'ACT_REF.referentie_naam',
            'ACT_REF.relid',

            // Custom Fields VOG (ACT_VOG)
            'ACT_VOG.vog_nodig',
            'ACT_VOG.datum_verzoek',
            'ACT_VOG.datum_reminder',
            'ACT_VOG.datum_aanvraag',
            'ACT_VOG.datum_ontvangst',
        ],
        'join'             => [
            ['ActivityContact AS activity_contact', 'INNER'],
        ],
        'where'            => [
            ['activity_contact.contact_id',     '=',  $contact_id],
            ['activity_contact.record_type_id', '=',  3], // 3 = Target (doelwit)
            ['activity_type_id',                '=',  $activity_type_id],
            ['activity_date_time',              '>=', $period_start],
            ['activity_date_time',              '<=', $period_einde],
            ['is_test',                         'IN', [true, false]]
        ],
    ];

    wachthond($extdebug,7, "params_activity_get",           $params_activity_get);
    $result_activity_get   = civicrm_api4('Activity','get', $params_activity_get);
    $result_activity_count = $result_activity_get->countMatched();
    wachthond($extdebug,3, "result_activity_count",         $result_activity_count);
    wachthond($extdebug,9, "result_activity_get",           $result_activity_get);

    // 1. Initialiseer return array
    $activity_intake_array = [
        'activity_type_naam' => $activity_type_naam,
        'activity_type_id'   => $activity_type_id,
        'activity_count'     => $result_activity_count,
    ];

    // 2. Stop als er geen resultaat is
    if ($result_activity_count == 0) {
        wachthond($extdebug, 3, "### INTAKE GET ACTIVITY $activity_type_naam", "[EINDE - GEEN RESULTAAT]");
        return $activity_intake_array;
    }

    // 3. Resultaat verwerken
    $res = $result_activity_get[0];

    // 4. Data toevoegen
    $activity_intake_array += [
        'activity_id'          => $res['id']                             ?? NULL,
        'activity_status'      => $res['status_id']                      ?? NULL,
        'activity_status_name' => $res['status_id:name']                 ?? NULL,
        'activity_datum'       => (string)($res['activity_date_time']    ?? ''),
        
        'activity_kampkort'    => $res['ACT_ALG.kampkort']               ?? NULL,
        'activity_kampfunctie' => $res['ACT_ALG.kampfunctie']            ?? NULL,
        'activity_kampstart'   => $res['ACT_ALG.kampstart']              ?? NULL,
        'activity_kampjaar'    => $res['ACT_ALG.kampjaar']               ?? NULL,
    ];

    // 5. Specifiek: REF
    if (in_array($activity_type_naam, ['ref_persoon', 'ref_feedback'])) {
        $activity_intake_array += [
            'referentie_aanvrager' => $res['ACT_REF.aanvrager_naam']     ?? NULL,
            'referentie_relid'     => $res['ACT_REF.relid']              ?? NULL,
            'referentie_refid'     => $res['ACT_REF.referentie_cid']     ?? NULL,
            'referentie_naam'      => $res['ACT_REF.referentie_naam']    ?? NULL,
        ];
    }

    // 6. Specifiek: VOG
    if (in_array($activity_type_naam, ['vog_verzoek', 'vog_aanvraag', 'vog_ontvangst'])) {
        $activity_intake_array += [
            'vog_nodig'            => $res['ACT_VOG.vog_nodig']          ?? NULL,
            'vog_verzoek'          => (string)($res['ACT_VOG.datum_verzoek']   ?? ''),
            'vog_reminder'         => $res['ACT_VOG.datum_reminder']     ?? NULL,
            'vog_aanvraag'         => $res['ACT_VOG.datum_aanvraag']     ?? NULL,
            'vog_datum'            => (string)($res['ACT_VOG.datum_ontvangst'] ?? ''),
        ];
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### INTAKE GET ACTIVITY $activity_type_naam",                   "[EINDE]");
    wachthond($extdebug, 3, "########################################################################");

    return $activity_intake_array;
}

/**
 * -----------------------------------------------------------------------------
 * 2. ACTIVITEIT AANMAKEN (CREATE)
 * -----------------------------------------------------------------------------
 */
function intake_activity_create($contact_id, $array_activity, $part_array, $array_intake) {

    $extdebug           = 3; 
    $apidebug           = FALSE;

    $activity_type_id   = $array_activity['activity_type_id']   ?? NULL;
    $activity_type_naam = $array_activity['activity_type_naam'] ?? NULL;

    $period_start       = $array_period['fiscalyear_start'];
    $period_einde       = $array_period['fiscalyear_einde'];

    $today              = date("Y-m-d H:i:s");

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE CREATE ACTIVITY $activity_type_naam",                         "[START]");
    wachthond($extdebug, 3, "########################################################################");

    if (empty($contact_id) || empty($array_activity)) {
        wachthond($extdebug, 1, "CREATE ABORT", "Contact ID of Activity Array leeg.");
        return NULL;
    }

    $params_activity_create = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'values'           => [
            // --- STANDAARD VELDEN ---
            // Fallback chain voor source_contact_id (voorkomt API crash: ID 1 = Admin)
            'source_contact_id'          => $array_activity['source_contact_id']        ?? $array_activity['activity_source']   ?? 1, 
            'target_contact_id'          => $array_activity['target_contact_id']        ?? $array_activity['activity_target']   ?? $contact_id,
            'assignee_contact_id'        => $array_activity['assignee_contact_id']      ?? $array_activity['activity_assignee'] ?? NULL,
            
            'activity_type_id'           => $array_activity['activity_type_id']         ?? NULL,
            'subject'                    => $array_activity['activity_subject']         ?? 'Geen onderwerp',
            'activity_date_time'         => $array_activity['activity_date_time']       ?? $today,
            'status_id:name'             => $array_activity['activity_status_name']     ?? 'Completed',
            'details'                    => $array_activity['activity_details']         ?? NULL,

            'ACT_ALG.activity_id'        => $array_activity['activity_id']              ?? NULL,
            'ACT_ALG.prioriteit:label'   => $array_activity['activity_prioriteit']      ?? 'Normal',

            // --- CUSTOM FIELDS (ALGEMEEN) ---
            'ACT_ALG.actcontact_naam'    => $part_array['displayname']                  ?? NULL,
            'ACT_ALG.actcontact_cid'     => $part_array['contact_id']                   ?? NULL,
            'ACT_ALG.actcontact_pid'     => $part_array['id']                           ?? NULL,
            'ACT_ALG.actcontact_eid'     => $part_array['part_event_id']                ?? NULL,
            'ACT_ALG.kampfunctie'        => $part_array['part_functie']                 ?? NULL,
            'ACT_ALG.kamprol'            => $part_array['part_rol']                     ?? NULL,
            'ACT_ALG.kamptype_nr'        => $part_array['part_kampweek_nr']             ?? NULL,
            'ACT_ALG.kampnaam'           => $part_array['part_kampkort_cap']            ?? NULL,
            'ACT_ALG.kampkort'           => $part_array['part_kampkort_low']            ?? NULL,
            'ACT_ALG.kampstart'          => $part_array['part_kampstart']               ?? NULL,
            'ACT_ALG.kampeinde'          => $part_array['part_kampeinde']               ?? NULL,
            'ACT_ALG.kampjaar'           => $part_array['part_kampjaar']                ?? NULL,
            'ACT_ALG.prioriteit:label'   => $array_activity['activity_prioriteit']      ?? NULL,
            'ACT_ALG.modified'           => $today                                      ?? NULL,

        ],
    ];

    // VOG SPECIFIEK
    if (in_array($activity_type_naam, ['vog_verzoek', 'vog_aanvraag', 'vog_ontvangst'])) {
        $params_activity_create['values']['assignee_contact_id'] = NULL;
        
        if (!empty($array_intake['nodig']))             { $params_activity_create['values']['ACT_VOG.vog_nodig']       = $array_intake['nodig']; }
        if (!empty($array_intake['part_vog_verzoek']))  { $params_activity_create['values']['ACT_VOG.datum_verzoek']   = $array_intake['part_vog_verzoek']; }
        if (!empty($array_intake['part_vog_aanvraag'])) { $params_activity_create['values']['ACT_VOG.datum_aanvraag']  = $array_intake['part_vog_aanvraag']; }
        if (!empty($array_intake['part_vog_datum']))    { $params_activity_create['values']['ACT_VOG.datum_ontvangst'] = $array_intake['part_vog_datum']; }
        if (!empty($array_intake['vog_reminder']))      { $params_activity_create['values']['ACT_VOG.datum_reminder']  = $array_intake['vog_reminder']; }
    }

    // REFERENTIE SPECIFIEK
    if (in_array($activity_type_naam, ['ref_persoon', 'ref_feedback'])) {
        // Indien beschikbaar uit array_intake mappen
        if (!empty($array_intake['ref_rel_id']))        { $params_activity_create['values']['ACT_REF.rel_id']          = $array_intake['ref_rel_id']; }
        // ... overige ref velden ...
    }

    try {
        wachthond($extdebug,3, "params_activity_create",            $params_activity_create);
        $result_activity_create = civicrm_api4('Activity','create', $params_activity_create);
        wachthond($extdebug,3, "result_activity_create",            $result_activity_create);
        $new_id = $result_activity_create[0]['id'] ?? NULL;
        
        wachthond($extdebug, 2, "API SUCCESS", "ID: $new_id");
        wachthond($extdebug, 1, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE CREATE ACTIVITY $activity_type_naam",                "[EINDE]");
        wachthond($extdebug, 1, "########################################################################");
        
        return $new_id;

    } catch (Exception $e) {
        wachthond($extdebug, 1, "API ERROR ($activity_type_naam)", $e->getMessage());
        return NULL;
    }
}

/**
 * -----------------------------------------------------------------------------
 * 3. ACTIVITEIT BIJWERKEN (UPDATE)
 * -----------------------------------------------------------------------------
 */
//function intake_activity_update($contact_id, $array_activity, $part_array, $array_intake, $array_referentie) {
function intake_activity_update($contact_id, $array_activity, $part_array, $array_intake) {

    $extdebug           = 3; 
    $apidebug           = FALSE;

    $activity_id        = $array_activity['activity_id']        ?? NULL;
    $activity_type_id   = $array_activity['activity_type_id']   ?? NULL;
    $activity_type_naam = $array_activity['activity_type_naam'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE UPDATE ACTIVITY $activity_type_naam",                "[START]");
    wachthond($extdebug, 2, "########################################################################");

    if (empty($contact_id) || empty($activity_id)) {
        return NULL;
    }

    // 1. Basis Waarden verzamelen
    $values = [
        'id'                         => $activity_id,
        // Alleen velden updaten die aanwezig zijn in de array
        'activity_type_id'           => $array_activity['activity_type_id']         ?? NULL,
        'source_contact_id'          => $array_activity['source_contact_id']        ?? 1,
        'target_contact_id'          => $array_activity['target_contact_id']        ?? NULL,
        'assignee_contact_id'        => $array_activity['assignee_contact_id']      ?? NULL,
        
        'activity_date_time'         => $array_activity['activity_date_time']       ?? NULL,
        'subject'                    => $array_activity['activity_subject']         ?? NULL,
        'status_id:name'             => $array_activity['activity_status_name']     ?? NULL,
        'details'                    => $array_activity['activity_details']         ?? NULL,

        'ACT_ALG.activity_id'        => $array_activity['activity_id']              ?? NULL,
        'ACT_ALG.prioriteit:label'   => $array_activity['activity_prioriteit']      ?? NULL,

        // Custom Fields
        'ACT_ALG.actcontact_naam'    => $part_array['displayname']                  ?? NULL,
        'ACT_ALG.actcontact_cid'     => $part_array['contact_id']                   ?? NULL,
        'ACT_ALG.actcontact_pid'     => $part_array['id']                           ?? NULL,
        'ACT_ALG.actcontact_eid'     => $part_array['part_event_id']                ?? NULL,
        'ACT_ALG.kampfunctie'        => $part_array['part_functie']                 ?? NULL,
        'ACT_ALG.kamprol'            => $part_array['part_rol']                     ?? NULL,
        'ACT_ALG.kamptype_nr'        => $part_array['part_kampweek_nr']             ?? NULL,
        'ACT_ALG.kampnaam'           => $part_array['part_kampkort_cap']            ?? NULL,
        'ACT_ALG.kampkort'           => $part_array['part_kampkort_low']            ?? NULL,
        'ACT_ALG.kampstart'          => $part_array['part_kampstart']               ?? NULL,
        'ACT_ALG.kampeinde'          => $part_array['part_kampeinde']               ?? NULL,
        'ACT_ALG.kampjaar'           => $part_array['part_kampjaar']                ?? NULL,
        'ACT_ALG.modified'           => $today                                      ?? NULL,
    ];

    // 2. Referentie Logica
    if (in_array($activity_type_naam, ['ref_persoon', 'ref_feedback'])) {
        $ref = $array_referentie;
        $values += [
            'ACT_REF.rel_id'                => $ref['ref_rel_id']                   ?? NULL,
            'ACT_REF.ref_nodig'             => $ref['ref_nodig']                    ?? NULL,
            'ACT_REF.aanvrager_cid'         => $ref['ref_aanvrager_cid']            ?? NULL,
            'ACT_REF.aanvrager_naam'        => $ref['ref_aanvrager_naam']           ?? NULL,
            'ACT_REF.referentie_cid'        => $ref['ref_referentie_cid']           ?? NULL,
            'ACT_REF.referentie_naam'       => $ref['ref_referentie_naam']          ?? NULL,
            'ACT_REF.referentie_telefoon'   => $ref['ref_referentie_telefoon']      ?? NULL,
            'ACT_REF.referentie_motivatie'  => $ref['ref_referentie_motivatie']     ?? NULL,
            'ACT_REF.referentie_verzoek'    => $array_intake['ref_datum_gevraagd']  ?? NULL,
            'ACT_REF.referentie_feedback'   => $array_intake['ref_datum_feedback']  ?? NULL,
        ];
    }

    // 3. VOG Logica
    if (in_array($activity_type_naam, ['vog_verzoek', 'vog_aanvraag', 'vog_ontvangst'])) {
        $values['assignee_contact_id'] = NULL;
        $values += [
            'ACT_VOG.vog_nodig'             => $array_intake['nodig']               ?? NULL,
            'ACT_VOG.datum_verzoek'         => $array_intake['part_vog_verzoek']    ?? NULL,
            'ACT_VOG.datum_aanvraag'        => $array_intake['part_vog_aanvraag']   ?? NULL,
            'ACT_VOG.datum_ontvangst'       => $array_intake['part_vog_datum']      ?? NULL,
            'ACT_VOG.datum_reminder'        => $array_intake['vog_reminder']        ?? NULL,
        ];
    }    

    wachthond($extdebug,3, "params_activity_update 0",              $params_activity_update);

    // Filter NULL waarden eruit (maar behoud 0 of lege string)
    $values = array_filter($values, function($v) { return !is_null($v); });

    $params_activity_update = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'values'           => $values, 
    ];

    try {
        wachthond($extdebug,3, "params_activity_update",            $params_activity_update);
        $result_activity_create = civicrm_api4("Activity","update", $params_activity_update);
        wachthond($extdebug,9, "result_activity_create",            $params_activity_update);
        wachthond($extdebug,2, "API UPDATE SUCCESS", $activity_id);
        
        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE UPDATE ACTIVITY $activity_type_naam",                "[EINDE]");
        wachthond($extdebug,1, "########################################################################");

        return $result_activity_create[0]['id'] ?? $activity_id;

    } catch (Exception $e) {
        wachthond($extdebug, 1, "API UPDATE ERROR", $e->getMessage());
        return NULL;
    }
}

/**
 * -----------------------------------------------------------------------------
 * 4. ACTIVITEIT VERWIJDEREN (ENKEL)
 * -----------------------------------------------------------------------------
 */
function intake_activity_delete($contact_id, $activity_id) {

    $extdebug           = 3; 
    $apidebug           = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 2, "### INTAKE DELETE ACTIVITY $activity_id",                       "[START]");
    wachthond($extdebug, 2, "########################################################################");

    if (empty($contact_id) || empty($activity_id)) {
        return;
    }

    $params_activity_delete = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [['id', '=', $activity_id]],
    ];

    try {
        wachthond($extdebug,3, "params_activity_delete",            $params_activity_delete);
        $result_activity_delete = civicrm_api4('Activity','delete', $params_activity_delete);
        wachthond($extdebug,9, "result_activity_delete",            $result_activity_delete);
        
        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### INTAKE DELETE ACTIVITY $activity_id",                       "[EINDE]");
        wachthond($extdebug,1, "########################################################################");

        return $result_activity_delete;

    } catch (Exception $e) {
        wachthond($extdebug, 1, "DELETE ERROR", $e->getMessage());
        return NULL;
    }
}

/**
 * -----------------------------------------------------------------------------
 * 5. ACTIVITEITSTYPE VERWIJDEREN (BULK OP NAAM/PERIODE)
 * -----------------------------------------------------------------------------
 */
function intake_activitytype_delete($contact_id, $array_activity, $array_period) {

    $extdebug           = 3; 
    $apidebug           = FALSE;

    $activity_type_id   = $array_activity['activity_type_id']   ?? NULL;
    $activity_type_naam = $array_activity['activity_type_naam'] ?? NULL;

    $period_start       = $array_period['fiscalyear_start'];
    $period_einde       = $array_period['fiscalyear_einde'];

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### INTAKE DELETE ACTIVITY TYPE $activity_type_naam",           "[START]");
    wachthond($extdebug, 1, "########################################################################");

    if (empty($contact_id) || empty($activity_type_naam)) {
        return;
    }

    $params_activitytype_delete = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [
//          ['activity_contact.contact_id', '=',  $contact_id],
            ['ACT_ALG.actcontact_cid',      '=',  $contact_id],
            ['activity_type_id',            '=',  $activity_type_id],
        ],
    ];

    // Voeg periode conditie alleen toe als deze bestaat
    if (!empty($period_start)) {
        $params['where'][] = ['activity_date_time', '>=', $period_start];
    }
    if (!empty($period_einde)) {
        $params['where'][] = ['activity_date_time', '<=', $period_einde];
    }    

    try {
        wachthond($extdebug,3, "params_activitytype_delete",            $params_activitytype_delete);
        $result_activitytype_delete = civicrm_api4('Activity', 'delete',$params_activitytype_delete);
        wachthond($extdebug,3, "result_activitytype_delete",            $result_activitytype_delete);
        
        wachthond($extdebug, 1, "########################################################################");
        wachthond($extdebug, 1, "### INTAKE DELETE ACTIVITY_TYPE $activity_type_naam",           "[EINDE]");
        wachthond($extdebug, 1, "########################################################################");

        return $result_activitytype_delete;

    } catch (Exception $e) {
        wachthond($extdebug, 1, "DELETE TYPE ERROR", $e->getMessage());
        return NULL;
    }
}