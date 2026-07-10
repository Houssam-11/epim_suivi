<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
// Connexion à la base de données
require_once 'db.php';
require_once __DIR__ . '/includes/unite_helper.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");
unite_ensure_columns($conn);

if (isset($_POST['filiere_id'])) {
    $filiere_id = $_POST['filiere_id'];

    $sql_unites = "SELECT id, intitule, COALESCE(semestre, 1) AS semestre FROM unites_de_formation WHERE filiere_id = ?";
    $stmt = $conn->prepare($sql_unites);
    $stmt->bind_param("i", $filiere_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<option value="">Sélectionner une unité</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . $row['intitule'] . '</option>';
    }
}
$conn->close();
?>
