<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Vérifier si l'utilisateur est connecté en tant que directeur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Erreur de connexion']);
    exit();
}

$conn->set_charset("utf8");

// Récupérer les données du POST
if (isset($_POST['id'], $_POST['column'], $_POST['value'])) {
    $id = (int)$_POST['id'];
    $column = $conn->real_escape_string($_POST['column']);
    $value = $conn->real_escape_string($_POST['value']);
    $allowedColumns = ['intitule', 'objectif', 'volume_horaire'];
    if (!in_array($column, $allowedColumns, true)) {
        echo json_encode(['success' => false, 'message' => 'Colonne non autorisee']);
        exit();
    }

    // Mettre à jour la séquence
    $sql = "UPDATE sequences_pedagogiques seq
            INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
            LEFT JOIN filieres f ON f.id = uf.filiere_id
            SET seq.$column = ?
            WHERE seq.id = ?
              AND COALESCE(seq.is_archived, 0) = 0
              AND COALESCE(f.is_archived, 0) = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $value, $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
}

$conn->close();
?>
