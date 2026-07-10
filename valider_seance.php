<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Vérifier si l'utilisateur est connecté et a le rôle "directeur"
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    header("Location: index.php");
    exit();
}

// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Récupérer les données du formulaire
$seance_id = $_POST['seance_id'];
$action = $_POST['action']; // "valider" ou "refuser"
$commentaire = $_POST['commentaire_directeur'];

// Définir le statut de validation en fonction de l'action
$valide_par_directeur = ($action === 'valider') ? 1 : 0;

// Mise à jour de la table suivi_pedagogique avec le statut de validation et le commentaire
$sql = "UPDATE suivi_pedagogique SET valide_par_directeur = ?, commentaire_directeur = ? WHERE seance_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isi", $valide_par_directeur, $commentaire, $seance_id);

if ($stmt->execute()) {
    header("Location: tableau_bord_directeur.php");
    exit();
} else {
    echo "Erreur lors de la mise à jour de la séance.";
}

$stmt->close();
$conn->close();
?>
