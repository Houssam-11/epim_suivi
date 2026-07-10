<?php
require_once __DIR__ . '/error_handler.php';

app_error_log('access_denied', 'legacy_unauthorized_page');
header('Location: error.php?type=access_denied');
exit();
