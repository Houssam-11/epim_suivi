<?php
// Inclure la session et vérifier si l'utilisateur est connecté en tant que directeur
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit();
}

// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données']);
    exit();
}
$conn->set_charset("utf8");

// Vérifier si l'ID de la séquence est passé
if (isset($_POST['id'])) {
    $sequence_id = (int) $_POST['id'];

    // Supprimer la séquence de la base de données
    $sql = "DELETE seq
            FROM sequences_pedagogiques seq
            INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
            LEFT JOIN filieres f ON f.id = uf.filiere_id
            WHERE seq.id = ? AND COALESCE(f.is_archived, 0) = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sequence_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Séquence supprimée avec succès']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression de la séquence']);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'ID de séquence non fourni']);
}

$conn->close();
?>
