<?php
declare(strict_types=1);

/**
 * PTO Tracker configuration template (SPEC section 3).
 * Copy to pto_data/config.php (outside the web root; mode 600 on the server) and fill in.
 * tools/dev_reset.php writes a local copy automatically when none exists.
 */
return [
    // MySQL / MariaDB connection. The user needs full rights on this one database.
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'pto_local',          // server: CPUSER_pto
        'user' => 'root',               // server: CPUSER_pto
        'pass' => '',
    ],

    // Public URL of the app with a trailing slash. Only its path part is used for links inside the app,
    // so a local server on any port works; the full value is used for absolute links (emails, M2).
    'base_url' => 'http://127.0.0.1:8020/',

    // 'production' | 'local'. Anything but 'production' shows error details on the error page and,
    // in Milestone 2, forces calendar sync to dry-run and refuses every Google write.
    'environment' => 'local',

    // 32+ random bytes as hex. Signs the viewer-page trust cookies. Changing it logs every viewer device out.
    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'secret' => 'CHANGE-ME-64-HEX-CHARS',

    // One-time token that gates setup.php?token=... on a fresh install. Delete setup.php afterwards.
    'install_token' => 'CHANGE-ME',

    // Milestone 2: where alerts (sync failures, backup problems) are sent, and the From address.
    'alert_email' => 'chris@lightsaberpromotions.com',
    'alert_from'  => 'pto@lightsaberpromotions.com',
];
