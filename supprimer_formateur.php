<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];

    // Récupérer l'utilisateur associé
    $sql = "SELECT utilisateur_id FROM formateurs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($utilisateur_id);
    $stmt->fetch();
    $stmt->close();

    // Supprimer le formateur
    $sql = "DELETE FROM formateurs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Supprimer l'utilisateur associé
    $sql = "DELETE FROM utilisateurs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $utilisateur_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
}
$conn->close();
?>
