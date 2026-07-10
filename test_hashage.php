<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

$mot_de_passe = "123456789";

// Utiliser la fonction password_hash pour hasher le mot de passe
$mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_BCRYPT);

// Afficher le mot de passe hashé
echo $mot_de_passe_hash;
?>
