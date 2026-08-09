<?php

/**
 * Deutsch — Standardsprache von Family Castel.
 * Schlüssel: bereich.name — alle UI-Texte laufen über t().
 */

declare(strict_types=1);

return [
    // Allgemein
    'app.name' => 'Family Castel',

    // Wartung
    'maintenance.title' => 'Das Schloss macht eine kurze Pause',
    'maintenance.body' => 'Family Castel wird gerade aktualisiert. In wenigen Minuten geht das Abenteuer weiter!',

    // Fehler
    'error.404_title' => 'Diese Seite liegt ausserhalb der Karte',
    'error.404_body' => 'Hier gibt es nichts zu entdecken. Kehre zum Schloss zurück!',
    'error.500_title' => 'Oh nein!',
    'error.500_body' => 'Im Schloss ist etwas schiefgelaufen. Bitte versuche es gleich noch einmal.',

    // Installer
    'install.title' => 'Installation',
    'install.progress_label' => 'Installationsfortschritt',
    'install.continue' => 'Weiter',
    'install.optional' => 'optional',

    'install.step_welcome' => 'Willkommen',
    'install.step_check' => 'Systemprüfung',
    'install.step_ownership' => 'Besitznachweis',
    'install.step_database' => 'Datenbank',
    'install.step_family' => 'Familie',
    'install.step_parent' => 'Elternkonto',
    'install.step_run' => 'Installation',

    'install.welcome_title' => 'Willkommen bei Family Castel',
    'install.welcome_lead' => 'Verwandle den Familienalltag in ein Abenteuer: Eltern vergeben Coins und erstellen Sidequests — Kinder sammeln XP, steigen auf und bauen ihre eigene Welt.',
    'install.welcome_feature_coins' => 'Coins sammeln und für Belohnungen einlösen',
    'install.welcome_feature_sidequests' => 'Sidequests annehmen und abschliessen',
    'install.welcome_feature_milestones' => 'Auf grosse Meilensteine hinsparen',
    'install.welcome_feature_private' => '100% privat auf deinem eigenen Webspace',
    'install.welcome_start' => 'Installation starten',

    'install.check_lead' => 'Family Castel prüft, ob dein Hosting alles mitbringt.',
    'install.check_recheck' => 'Erneut prüfen',
    'install.error_checks_failed' => 'Es gibt noch rote Punkte — bitte behebe sie und prüfe erneut.',

    'install.ownership_lead' => 'Als Sicherheitsnachweis bestätigst du kurz, dass dir dieser Webspace gehört.',
    'install.ownership_how_1' => 'Öffne den Dateimanager oder FTP-Zugang deines Hostings.',
    'install.ownership_how_2' => 'Öffne die Datei {path}.',
    'install.ownership_how_3' => 'Tippe den 8-stelligen Code hier ein.',
    'install.ownership_code_label' => 'Setup-Code',
    'install.error_setup_code' => 'Der Code stimmt nicht. Öffne storage/setup-token.txt und versuche es erneut.',

    'install.database_lead' => 'Verbinde Family Castel mit deiner MySQL/MariaDB-Datenbank.',
    'install.db_host' => 'Datenbank-Host',
    'install.db_port' => 'Port',
    'install.db_name' => 'Datenbank-Name',
    'install.db_user' => 'Benutzername',
    'install.db_password' => 'Passwort',
    'install.db_prefix' => 'Tabellen-Präfix',
    'install.database_test' => 'Verbindung testen & weiter',
    'install.error_db_fields' => 'Host, Datenbank-Name und Benutzername sind erforderlich.',
    'install.error_db_prefix' => 'Das Präfix darf nur Buchstaben, Zahlen und _ enthalten (max. 16 Zeichen).',
    'install.error_db_connect' => 'Verbindung fehlgeschlagen: {message}',

    'install.family_lead' => 'Erzähl uns etwas über eure Familie.',
    'install.family_name' => 'Familienname',
    'install.family_name_placeholder' => 'z. B. Familie Barlocci',
    'install.family_language' => 'Sprache',
    'install.family_timezone' => 'Zeitzone',
    'install.error_family_name' => 'Bitte gib einen Familiennamen ein.',
    'install.error_family_fields' => 'Bitte wähle eine gültige Sprache und Zeitzone.',

    'install.parent_lead' => 'Das erste Elternkonto verwaltet Family Castel.',
    'install.parent_name' => 'Name',
    'install.parent_username' => 'Benutzername oder E-Mail',
    'install.parent_password' => 'Passwort',
    'install.parent_password_hint' => 'Mindestens 10 Zeichen.',
    'install.parent_password_confirm' => 'Passwort bestätigen',
    'install.error_parent_fields' => 'Name und Benutzername sind erforderlich.',
    'install.error_parent_password' => 'Das Passwort muss mindestens 10 Zeichen lang sein.',
    'install.error_parent_confirm' => 'Die Passwörter stimmen nicht überein.',

    'install.run_lead' => 'Alles bereit! Prüfe die Angaben und starte die Installation.',
    'install.run_button' => 'Family Castel installieren',
    'install.error_failed' => 'Installation fehlgeschlagen: {message}',
    'install.error_state_expired' => 'Die Installationssitzung ist abgelaufen — bitte beginne von vorn.',
    'install.error_csrf' => 'Sicherheitsprüfung fehlgeschlagen — bitte versuche es erneut.',
    'install.error_session_binding' => 'Diese Installation wurde in einem anderen Browser begonnen. Bitte bestätige den Setup-Code erneut.',
    'install.warn_leftovers' => 'Bitte lösche diese Dateien manuell über den Dateimanager: {files}',

    'install.done_title' => 'Family Castel ist bereit!',
    'install.done_lead' => 'Viel Spass beim Abenteuer, {family}!',
    'install.done_enter' => 'Family Castel betreten',

    'install.locked_title' => 'Bereits installiert',
    'install.locked_lead' => 'Family Castel ist auf diesem Webspace bereits eingerichtet. Der Installer ist gesperrt.',
    'install.locked_home' => 'Zur Startseite',

    'install.no_marker_title' => 'Installer gesperrt',
    'install.no_marker_lead' => 'Zum (erneuten) Installieren erstelle zuerst diese leere Datei über den Dateimanager deines Hostings:',
];
