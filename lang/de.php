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

    // Auth
    'auth.login_title' => 'Eltern-Anmeldung',
    'auth.login_lead' => 'Melde dich an, um Coins zu vergeben und Abenteuer zu verwalten.',
    'auth.username' => 'Benutzername oder E-Mail',
    'auth.password' => 'Passwort',
    'auth.login_button' => 'Anmelden',
    'auth.error_failed' => 'Anmeldung fehlgeschlagen. Bitte prüfe deine Angaben — nach zu vielen Versuchen ist das Konto kurz gesperrt.',
    'auth.error_csrf' => 'Sicherheitsprüfung fehlgeschlagen — bitte versuche es erneut.',
    'auth.switch_to_kid' => 'Ich bin ein Kind — zur Heldenauswahl',

    // Kid login/home
    'kid.login_title' => 'Wer spielt heute?',
    'kid.login_lead' => 'Wähle deinen Helden!',
    'kid.no_children' => 'Noch keine Helden hier. Ein Elternteil muss dich zuerst hinzufügen.',
    'kid.pin_label' => 'Deine Geheimzahl',
    'kid.enter' => 'Los geht\'s!',
    'kid.error_pin' => 'Das war nicht die richtige Geheimzahl. Versuche es noch einmal!',
    'kid.level' => 'Level {level}',
    'kid.switch_to_parent' => 'Ich bin ein Elternteil',
    'kid.qr_invalid_title' => 'Dieser Schlüssel passt nicht mehr',
    'kid.qr_invalid_lead' => 'Der Zugangscode wurde erneuert. Frag deine Eltern nach dem neuen QR-Code!',
    'kid.qr_invalid_back' => 'Zur Heldenauswahl',
    'kid.welcome' => 'Hallo {name}!',
    'kid.logout' => 'Abmelden',

    // Parent
    'parent.title' => 'Eltern-Bereich',
    'parent.hello' => 'Hallo {name}!',
    'parent.logout' => 'Abmelden',
    'parent.no_children' => 'Noch keine Kinder angelegt. Füge in den Einstellungen euer erstes Kind hinzu!',

    // Common
    'common.error_csrf' => 'Sicherheitsprüfung fehlgeschlagen — bitte versuche es erneut.',
    'common.maintenance' => 'Gerade läuft eine Wartung — bitte versuche es in ein paar Minuten erneut.',
    'common.edit' => 'Bearbeiten',
    'common.save' => 'Speichern',
    'common.cancel' => 'Abbrechen',
    'common.back' => 'Zurück',
    'common.archive' => 'Archivieren',

    // Navigation
    'nav.dashboard' => 'Übersicht',
    'nav.children' => 'Kinder',
    'nav.templates' => 'Vorlagen',

    // Themes & characters
    'theme.fantasy' => 'Fantasy-Königreich',
    'theme.football' => 'Fussball-Welt',
    'character.knight' => 'Ritter:in',
    'character.archer' => 'Bogenschütz:in',
    'character.mage' => 'Magier:in',
    'character.paladin' => 'Paladin',
    'character.striker' => 'Stürmer:in',
    'character.goalkeeper' => 'Torhüter:in',
    'character.defender' => 'Verteidiger:in',
    'character.midfielder' => 'Mittelfeldspieler:in',

    // Children management
    'children.title' => 'Kinder',
    'children.add' => 'Kind hinzufügen',
    'children.edit' => '{name} bearbeiten',
    'children.name' => 'Name',
    'children.theme' => 'Spielwelt',
    'children.character' => 'Held:in',
    'children.pin' => 'Geheimzahl (PIN)',
    'children.pin_hint' => '4–6 Ziffern. Leer lassen, um die aktuelle PIN zu behalten.',
    'children.pin_none' => 'Keine PIN — Anmeldung durch Antippen',
    'children.pin_set' => 'PIN ist gesetzt — hier neue eingeben',
    'children.pin_clear' => 'PIN entfernen (Anmeldung durch Antippen)',
    'children.sound' => 'Spielsounds aktiviert',
    'children.created' => 'Kind angelegt — das Abenteuer kann beginnen! 🎉',
    'children.updated' => 'Gespeichert.',
    'children.archived' => 'Kind archiviert. Die Geschichte bleibt für immer erhalten.',
    'children.unarchived' => 'Kind wieder aktiviert.',
    'children.archived_badge' => 'archiviert',
    'children.archive' => 'Archivieren',
    'children.unarchive' => 'Aktivieren',
    'children.archive_confirm' => '{name} wirklich archivieren? Anmeldung und QR-Zugang werden deaktiviert; die gesamte Geschichte bleibt erhalten.',
    'children.qr_title' => 'QR-Anmeldung für {name}',
    'children.qr_fresh_lead' => 'Fertig! Diesen QR-Code kann {name} zum Anmelden scannen — am besten ausdrucken.',
    'children.qr_active_lead' => 'Es gibt einen aktiven QR-Zugang. Aus Sicherheitsgründen wird der Code nur direkt nach dem Erstellen angezeigt.',
    'children.qr_none_lead' => 'Noch kein QR-Zugang. Erstelle einen, damit sich dein Kind ohne Tippen anmelden kann.',
    'children.qr_once_warning' => 'Wichtig: Der Code wird nur EINMAL angezeigt. Neu laden = neuen Code erstellen.',
    'children.qr_regenerate' => 'Neuen QR-Code erstellen',
    'children.qr_create' => 'QR-Code erstellen',
    'children.qr_regen_confirm' => 'Der bisherige QR-Code wird dabei ungültig. Fortfahren?',
    'children.qr_regenerated' => 'Neuer QR-Code erstellt — der alte ist ab sofort ungültig.',
    'children.qr_alt' => 'QR-Code für die Anmeldung von {name}',

    // Quick award
    'award.templates_title' => 'Schnellaktionen',
    'award.custom_title' => 'Eigene Aktion',
    'award.what' => 'Was ist passiert?',
    'award.what_placeholder' => 'z. B. Geschirr abgeräumt',
    'award.coins' => 'Coins',
    'award.xp' => 'XP',
    'award.comment' => 'Kommentar',
    'award.comment_placeholder' => 'z. B. Super gemacht!',
    'award.save_template' => 'Als Vorlage speichern',
    'award.submit' => 'Eintragen',
    'award.template_done' => 'Eingetragen! 🪙',
    'award.custom_done' => 'Eingetragen! 🪙',
    'award.deduct_done' => 'Abzug eingetragen.',
    'award.error_insufficient' => 'So viele Coins hat das Kind nicht (reservierte Coins zählen nicht als verfügbar).',
    'award.error_fields' => 'Titel und mindestens Coins oder XP angeben.',
    'award.available' => '{coins} verfügbar',
    'award.available_hint' => 'Verfügbar = Kontostand minus reservierte Coins aus offenen Belohnungswünschen.',
    'award.confirm' => '„{title}" wirklich eintragen?',
    'award.history_title' => 'Zuletzt passiert',

    // Templates
    'templates.title' => 'Vorlagen',
    'templates.add' => 'Vorlage erstellen',
    'templates.edit' => 'Vorlage bearbeiten',
    'templates.name' => 'Titel',
    'templates.name_placeholder' => 'z. B. Geschirr abgeräumt',
    'templates.coins_hint' => 'Negativ für Abzüge (z. B. -5).',
    'templates.scope' => 'Gilt für',
    'templates.scope_all' => 'Alle Kinder',
    'templates.scope_selected' => 'Ausgewählte Kinder',
    'templates.scope_children' => 'Kinder auswählen (bei „Ausgewählte Kinder")',
    'templates.favorite' => 'Favorit (erscheint zuoberst)',
    'templates.favorite_toggle' => 'Favorit umschalten',
    'templates.requires_confirm' => 'Vor dem Eintragen nachfragen',
    'templates.created' => 'Vorlage erstellt.',
    'templates.updated' => 'Vorlage gespeichert.',
    'templates.archived' => 'Vorlage archiviert — die Geschichte bleibt erhalten.',
    'templates.empty' => 'Noch keine Vorlagen. Erstelle die erste — dann geht das Eintragen blitzschnell!',

    'install.no_marker_title' => 'Installer gesperrt',
    'install.no_marker_lead' => 'Zum (erneuten) Installieren erstelle zuerst diese leere Datei über den Dateimanager deines Hostings:',
];
