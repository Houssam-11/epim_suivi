<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filiere_id = $_POST['filiere_id'];
    $nom = $_POST['nom'];
    $objectif_general = $_POST['objectif_general'];
    $heures_defaut = $_POST['heures_defaut'];
    $masse_horaire = $_POST['masse_horaire'] ?? '';
    $semestre = $_POST['semestre'] ?? '';
    $annee_formation = $_POST['annee_formation'] ?? '';
    $type_unite = $_POST['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE;
    $formateur_id = $_POST['formateur_id'];

    if (!empty($nom) && !empty($filiere_id) && !empty($formateur_id) && $masse_horaire !== '' && in_array((int) $semestre, [1, 2], true) && unite_normalize_annee_formation($annee_formation) > 0 && unite_type_is_valid($type_unite)) {
        $stmt_archive = $conn->prepare('SELECT COALESCE(is_archived, 0) AS is_archived FROM filieres WHERE id = ? LIMIT 1');
        $stmt_archive->bind_param('i', $filiere_id);
        $stmt_archive->execute();
        $archive_row = $stmt_archive->get_result()->fetch_assoc();
        $stmt_archive->close();

        if (!$archive_row || (int) $archive_row['is_archived'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Cette filière est archivée. Ajout désactivé.']);
            $conn->close();
            exit();
        }

        $semestre = unite_normalize_semestre($semestre);
        $annee_formation = unite_normalize_annee_formation($annee_formation);
        $type_unite = unite_normalize_type($type_unite);
        $sql = "INSERT INTO unites_de_formation (intitule, objectif_general, filiere_id, heures_par_seance_defaut, masse_horaire, semestre, annee_formation, type_unite, formateur_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiiiiisi", $nom, $objectif_general, $filiere_id, $heures_defaut, $masse_horaire, $semestre, $annee_formation, $type_unite, $formateur_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'insertion dans la base de données']);
        }

        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    }
}

$conn->close();
?>
