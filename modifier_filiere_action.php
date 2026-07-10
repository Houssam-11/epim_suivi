<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/includes/filiere_helper.php';

// Vérifier si l'utilisateur est connecté et a le rôle de directeur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    header('Location: index.php');
    exit();
}

// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");
filiere_ensure_columns($conn);

// Vérifier que l'ID de la filière est passé
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id'])) {
    $filiere_id = (int)$_POST['id'];
    $nom = trim($_POST['nom']);
    $niveau = filiere_normalize_niveau((string) ($_POST['niveau'] ?? ''));
    $anneeFormation = filiere_normalize_annee_formation($_POST['annee_formation'] ?? null);

    if ($nom === '') {
        echo "Le nom de la filière est obligatoire.";
        exit();
    }

    if ($anneeFormation <= 0) {
        echo "La durée de la formation est obligatoire.";
        exit();
    }

    if (filiere_name_exists($conn, $nom, $filiere_id)) {
        echo "Une filière avec ce nom existe déjà.";
        exit();
    }

    $stmt_archive = $conn->prepare('SELECT COALESCE(is_archived, 0) AS is_archived FROM filieres WHERE id = ? LIMIT 1');
    $stmt_archive->bind_param('i', $filiere_id);
    $stmt_archive->execute();
    $archive_row = $stmt_archive->get_result()->fetch_assoc();
    $stmt_archive->close();

    if (!$archive_row || (int) $archive_row['is_archived'] === 1) {
        echo "Cette filière est archivée et ne peut pas être modifiée.";
        exit();
    }

    // Mise à jour de la filière
    $sql_update = "UPDATE filieres SET nom = ?, niveau = ?, annee_formation = ? WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ssii", $nom, $niveau, $anneeFormation, $filiere_id);

    if ($stmt_update->execute()) {
        // Redirection vers la liste des filières après mise à jour
        header('Location: liste_filieres.php?message=modification_reussie');
    } else {
        echo "Erreur lors de la mise à jour : " . $stmt_update->error;
    }

    $stmt_update->close();
} else {
    echo "Données manquantes ou invalides.";
}

$conn->close();
?>
