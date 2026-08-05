<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Home is the dealers list — no agency board.
$user = require_login();
redirect('clients.php');
