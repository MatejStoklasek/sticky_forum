<?php
require_once __DIR__ . '/db.php';
start_session_once();

remember_me_clear();

$_SESSION = array();
session_destroy();

header('Location: login.php');
exit;