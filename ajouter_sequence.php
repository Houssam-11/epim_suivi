<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Vérifier si l'utilisateur est connecté en tant que directeur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit();
}

// Connexion à la base de données
require_once 'db.php';
require_once __DIR__ . '/includes/filiere_helper.php';


$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données']);
    exit();
}
$conn->set_charset("utf8");
filiere_ensure_columns($conn);

// Vérifier si les données nécessaires sont passées
if (isset($_POST['unite_id'], $_POST['intitule'], $_POST['objectif'], $_POST['volume_horaire'])) {
    $unite_id = (int) $_POST['unite_id'];
    $intitule = $_POST['intitule'];
    $objectif = $_POST['objectif'];
    $volume_horaire = (int) $_POST['volume_horaire'];

    // Récupérer la masse horaire totale de l'unité
    $sql_unite = "SELECT uf.masse_horaire, COALESCE(uf.is_archived, 0) AS is_archived, COALESCE(f.is_archived, 0) AS filiere_is_archived
                  FROM unites_de_formation uf
                  LEFT JOIN filieres f ON f.id = uf.filiere_id
                  WHERE uf.id = ?";
    $stmt_unite = $conn->prepare($sql_unite);
    $stmt_unite->bind_param("i", $unite_id);
    $stmt_unite->execute();
    $result_unite = $stmt_unite->get_result();
    $unite = $result_unite->fetch_assoc();

    if (!$unite) {
        echo json_encode(['success' => false, 'message' => 'Unité non trouvée']);
        exit();
    }
    if ((int) $unite['is_archived'] === 1 || (int) $unite['filiere_is_archived'] === 1) {
        echo json_encode(['success' => false, 'message' => 'Cette unité est archivée. Aucune nouvelle séquence ne peut y être ajoutée.']);
        exit();
    }

    $masse_horaire_unite = (int) $unite['masse_horaire'];

    // Calculer le total des volumes horaires des séquences existantes pour cette unité
    $sql_total_sequences = "SELECT SUM(volume_horaire) AS total_sequences_horaire
                            FROM sequences_pedagogiques
                            WHERE unite_id = ? AND COALESCE(is_archived, 0) = 0";
    $stmt_total_sequences = $conn->prepare($sql_total_sequences);
    $stmt_total_sequences->bind_param("i", $unite_id);
    $stmt_total_sequences->execute();
    $result_total_sequences = $stmt_total_sequences->get_result();
    $total_sequences = $result_total_sequences->fetch_assoc();
    $total_volume_horaire = (int) $total_sequences['total_sequences_horaire'];

    // Vérifier si l'ajout de la nouvelle séquence ne dépasse pas la masse horaire de l'unité
    if (($total_volume_horaire + $volume_horaire) > $masse_horaire_unite) {
        echo json_encode(['success' => false, 'message' => 'Le total des volumes horaires dépasse la masse horaire de l\'unité']);
        exit();
    }

    // Insérer la nouvelle séquence dans la base de données
    $sql_insert = "INSERT INTO sequences_pedagogiques (unite_id, intitule, objectif, volume_horaire) VALUES (?, ?, ?, ?)";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->bind_param("issi", $unite_id, $intitule, $objectif, $volume_horaire);

    if ($stmt_insert->execute()) {
        echo json_encode(['success' => true, 'message' => 'Séquence ajoutée avec succès', 'id' => $stmt_insert->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'ajout de la séquence']);
    }

    $stmt_insert->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
}

$conn->close();
?>
