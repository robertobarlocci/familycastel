<?php

/**
 * English translations. Falls back to German (default) for missing keys.
 */

declare(strict_types=1);

return [
    'app.name' => 'Family Castel',

    'maintenance.title' => 'The castle is taking a short break',
    'maintenance.body' => 'Family Castel is being updated. The adventure continues in a few minutes!',

    'error.404_title' => 'This page is off the map',
    'error.404_body' => 'Nothing to discover here. Return to the castle!',
    'error.500_title' => 'Oh no!',
    'error.500_body' => 'Something went wrong in the castle. Please try again in a moment.',

    'install.title' => 'Installation',
    'install.progress_label' => 'Installation progress',
    'install.continue' => 'Continue',
    'install.optional' => 'optional',

    'install.step_welcome' => 'Welcome',
    'install.step_check' => 'System check',
    'install.step_ownership' => 'Ownership proof',
    'install.step_database' => 'Database',
    'install.step_family' => 'Family',
    'install.step_parent' => 'Parent account',
    'install.step_run' => 'Installation',

    'install.welcome_title' => 'Welcome to Family Castel',
    'install.welcome_lead' => 'Turn everyday family life into an adventure: parents award Coins and create Sidequests — children earn XP, level up and grow their own world.',
    'install.welcome_feature_coins' => 'Collect Coins and redeem them for rewards',
    'install.welcome_feature_sidequests' => 'Accept and complete Sidequests',
    'install.welcome_feature_milestones' => 'Save up for big milestones',
    'install.welcome_feature_private' => '100% private on your own web space',
    'install.welcome_start' => 'Start installation',

    'install.check_lead' => 'Family Castel checks whether your hosting has everything it needs.',
    'install.check_recheck' => 'Check again',
    'install.error_checks_failed' => 'There are still red items — please fix them and check again.',

    'install.ownership_lead' => 'As a security step, confirm that this web space belongs to you.',
    'install.ownership_how_1' => 'Open your hosting file manager or FTP access.',
    'install.ownership_how_2' => 'Open the file {path}.',
    'install.ownership_how_3' => 'Type the 8-character code here.',
    'install.ownership_code_label' => 'Setup code',
    'install.error_setup_code' => 'The code does not match. Open storage/setup-token.txt and try again.',

    'install.database_lead' => 'Connect Family Castel to your MySQL/MariaDB database.',
    'install.db_host' => 'Database host',
    'install.db_port' => 'Port',
    'install.db_name' => 'Database name',
    'install.db_user' => 'Username',
    'install.db_password' => 'Password',
    'install.db_prefix' => 'Table prefix',
    'install.database_test' => 'Test connection & continue',
    'install.error_db_fields' => 'Host, database name and username are required.',
    'install.error_db_prefix' => 'The prefix may only contain letters, numbers and _ (max. 16 characters).',
    'install.error_db_connect' => 'Connection failed: {message}',

    'install.family_lead' => 'Tell us about your family.',
    'install.family_name' => 'Family name',
    'install.family_name_placeholder' => 'e.g. The Barlocci Family',
    'install.family_language' => 'Language',
    'install.family_timezone' => 'Timezone',
    'install.error_family_name' => 'Please enter a family name.',
    'install.error_family_fields' => 'Please choose a valid language and timezone.',

    'install.parent_lead' => 'The first parent account manages Family Castel.',
    'install.parent_name' => 'Name',
    'install.parent_username' => 'Username or email',
    'install.parent_password' => 'Password',
    'install.parent_password_hint' => 'At least 10 characters.',
    'install.parent_password_confirm' => 'Confirm password',
    'install.error_parent_fields' => 'Name and username are required.',
    'install.error_parent_password' => 'The password must be at least 10 characters long.',
    'install.error_parent_confirm' => 'The passwords do not match.',

    'install.run_lead' => 'All set! Review your details and start the installation.',
    'install.run_button' => 'Install Family Castel',
    'install.error_failed' => 'Installation failed: {message}',
    'install.error_state_expired' => 'The installation session expired — please start over.',
    'install.error_csrf' => 'Security check failed — please try again.',
    'install.error_session_binding' => 'This installation was started in a different browser. Please confirm the setup code again.',
    'install.warn_leftovers' => 'Please delete these files manually via your file manager: {files}',

    'install.done_title' => 'Family Castel is ready!',
    'install.done_lead' => 'Enjoy the adventure, {family}!',
    'install.done_enter' => 'Enter Family Castel',

    'install.locked_title' => 'Already installed',
    'install.locked_lead' => 'Family Castel is already set up on this web space. The installer is locked.',
    'install.locked_home' => 'Go to the homepage',

    // Auth
    'auth.login_title' => 'Parent sign-in',
    'auth.login_lead' => 'Sign in to award Coins and manage adventures.',
    'auth.username' => 'Username or email',
    'auth.password' => 'Password',
    'auth.login_button' => 'Sign in',
    'auth.error_failed' => 'Sign-in failed. Check your details — after too many attempts the account is briefly locked.',
    'auth.error_csrf' => 'Security check failed — please try again.',
    'auth.switch_to_kid' => 'I am a child — to the hero picker',

    // Kid login/home
    'kid.login_title' => 'Who is playing today?',
    'kid.login_lead' => 'Choose your hero!',
    'kid.no_children' => 'No heroes here yet. A parent has to add you first.',
    'kid.pin_label' => 'Your secret number',
    'kid.enter' => 'Let\'s go!',
    'kid.error_pin' => 'That was not the right secret number. Try again!',
    'kid.level' => 'Level {level}',
    'kid.switch_to_parent' => 'I am a parent',
    'kid.qr_invalid_title' => 'This key no longer fits',
    'kid.qr_invalid_lead' => 'The access code was renewed. Ask your parents for the new QR code!',
    'kid.qr_invalid_back' => 'To the hero picker',
    'kid.welcome' => 'Hello {name}!',
    'kid.logout' => 'Sign out',

    // Parent
    'parent.title' => 'Parent area',
    'parent.hello' => 'Hello {name}!',
    'parent.logout' => 'Sign out',
    'parent.no_children' => 'No children yet. Add your first child in the settings!',

    'install.no_marker_title' => 'Installer locked',
    'install.no_marker_lead' => 'To (re)install, first create this empty file via your hosting file manager:',
];
