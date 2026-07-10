<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Vérifiez si l'utilisateur est directeur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    header('Location: index.php');
    exit();
}

// Connexion à la base de données
require_once 'db.php';
require_once __DIR__ . '/includes/filiere_helper.php';
require_once __DIR__ . '/includes/unite_helper.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");
filiere_ensure_columns($conn);
unite_ensure_columns($conn);

// Récupérer les données du formulaire
$unite_id = $_POST['id'];
$intitule = $_POST['intitule'];
$objectif_general = $_POST['objectif_general'];
$heures_defaut = $_POST['heures_par_seance_defaut'];
$masse_horaire = $_POST['masse_horaire']; // Nouvelle donnée récupérée
$formateur_id = $_POST['formateur_id'];
$semestre = unite_normalize_semestre($_POST['semestre'] ?? 1);
$anneeFormation = unite_normalize_annee_formation($_POST['annee_formation'] ?? null);
$type_unite = unite_normalize_type($_POST['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE);

if ($anneeFormation <= 0) {
    echo "L'année de formation est obligatoire.";
    exit();
}

$stmt_archive = $conn->prepare(
    'SELECT COALESCE(uf.is_archived, 0) AS unite_archived, COALESCE(f.is_archived, 0) AS filiere_archived
     FROM unites_de_formation uf
     LEFT JOIN filieres f ON f.id = uf.filiere_id
     WHERE uf.id = ?
     LIMIT 1'
);
$stmt_archive->bind_param('i', $unite_id);
$stmt_archive->execute();
$archive_row = $stmt_archive->get_result()->fetch_assoc();
$stmt_archive->close();

if (!$archive_row || (int) $archive_row['unite_archived'] === 1 || (int) $archive_row['filiere_archived'] === 1) {
    echo "Cette unité est en lecture seule.";
    exit();
}

// Requête SQL pour mettre à jour l'unité de formation avec la masse horaire
$sql = "UPDATE unites_de_formation
        SET intitule = ?, objectif_general = ?, heures_par_seance_defaut = ?, masse_horaire = ?, semestre = ?, annee_formation = ?, type_unite = ?, formateur_id = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssiiiisii", $intitule, $objectif_general, $heures_defaut, $masse_horaire, $semestre, $anneeFormation, $type_unite, $formateur_id, $unite_id);

if ($stmt->execute()) {
    // Rediriger après succès
    header('Location: gerer_unite.php?id=' . $unite_id . '&success=1');
} else {
    // Gérer l'échec
    echo "Erreur lors de la mise à jour : " . $conn->error;
}

$stmt->close();
$conn->close();
?>
