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

if (isset($_POST['unite_id'])) {
    $unite_id = $_POST['unite_id'];

    $sql_sequences = "SELECT id, intitule FROM sequences_pedagogiques WHERE unite_id = ?";
    $stmt = $conn->prepare($sql_sequences);
    $stmt->bind_param("i", $unite_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<option value="">Sélectionner une séquence</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . $row['intitule'] . '</option>';
    }
}
$conn->close();
?>
