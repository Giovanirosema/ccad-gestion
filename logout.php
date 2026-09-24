<?php
require_once __DIR__ . '/includes/functions.php';

// La déconnexion se fait uniquement par formulaire (POST + jeton), pas par simple lien
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(current_user() ? 'index.php' : 'login.php');
check_csrf();
if (current_user()) audit('Connexion', current_user()['login'], 'Fermeture de session');
fermer_session();
redirect('login.php?sortie=1');
