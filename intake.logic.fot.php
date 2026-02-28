<?php

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
