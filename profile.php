<?php
// Profile has been merged into Settings (Account tab).
require_once __DIR__ . '/includes/auth.php';
require_login();
redirect('setting.php?tab=account');
