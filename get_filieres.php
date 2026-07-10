<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Requête pour récupérer les filières
$sql_filieres = "SELECT id, nom FROM filieres";
$result = $conn->query($sql_filieres);

// Générer les options du select pour les filières
echo '<option value="">Choisir une filière</option>';
while ($row = $result->fetch_assoc()) {
    echo '<option value="' . $row['id'] . '">' . $row['nom'] . '</option>';
}

$conn->close();
?>
