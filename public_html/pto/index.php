<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

// Entry point: logged in -> dashboard, else -> login.
redirect(current_user() === null ? 'login.php' : 'dashboard.php');
