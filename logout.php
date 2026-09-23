<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) audit('Connexion', current_user()['login'], 'Fermeture de session');
$_SESSION = [];
session_destroy();
redirect('login.php');
