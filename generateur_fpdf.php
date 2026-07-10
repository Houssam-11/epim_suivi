<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

require('fpdf.php');

// Connexion à la base de données
require_once 'db.php';


$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Récupérer l'unité sélectionnée depuis la requête POST
$unite_id = isset($_POST['unite_id']) ? $_POST['unite_id'] : 1;  // Par défaut, unité 1

// Récupérer les informations de l'unité
$sql_unite = "SELECT nom FROM unites_de_formation WHERE id = ?";
$stmt_unite = $conn->prepare($sql_unite);
$stmt_unite->bind_param("i", $unite_id);
$stmt_unite->execute();
$result_unite = $stmt_unite->get_result();
$unite = $result_unite->fetch_assoc()['nom'];

// Vérifier le nombre de séances validées pour cette unité
$sql_valid_seances = "SELECT COUNT(*) AS seances_validees FROM seances_pedagogiques sp
                      LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                      LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
                      WHERE sq.unite_id = ? AND s.valide_par_directeur = 1";
$stmt_seances = $conn->prepare($sql_valid_seances);
$stmt_seances->bind_param("i", $unite_id);
$stmt_seances->execute();
$result_seances = $stmt_seances->get_result();
$seances_validees = $result_seances->fetch_assoc()['seances_validees'];

// Autoriser l'impression si au moins 3 séances sont validées
if ($seances_validees < 3) {
    die("Moins de 3 séances validées. Impression non autorisée.");
}

// Récupérer les informations des séances validées
$sql = "SELECT sp.date_seance, sp.objectif_pedagogique, sp.description_activites, s.commentaire_directeur, sp.heures_reelles
        FROM seances_pedagogiques sp
        LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
        LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
        WHERE sq.unite_id = ? AND s.valide_par_directeur = 1 LIMIT 3";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $unite_id);
$stmt->execute();
$result = $stmt->get_result();

// Création du PDF en mode portrait
$pdf = new FPDF('P', 'mm', 'A4'); // 'P' pour Portrait
$pdf->AddPage();

// Titre
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'FICHE DE GESTION - SUIVI PEDAGOGIQUE', 0, 1, 'C');

// Détails de l'unité
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, "Unité de formation: $unite", 0, 1);
$pdf->Cell(0, 10, "Nombre de séances validées: $seances_validees", 0, 1);

// Ajout des séances validées
$pdf->Ln(10);  // Saut de ligne
while ($row = $result->fetch_assoc()) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Séance', 0, 1);

    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Date: ' . $row['date_seance'], 0, 1);
    $pdf->Cell(0, 10, 'Objectif pédagogique: ' . $row['objectif_pedagogique'], 0, 1);
    $pdf->Cell(0, 10, 'Description: ' . $row['description_activites'], 0, 1);
    $pdf->Cell(0, 10, 'Commentaire du directeur: ' . $row['commentaire_directeur'], 0, 1);
    $pdf->Cell(0, 10, 'Nombre d\'heures: ' . $row['heures_reelles'], 0, 1);
    $pdf->Ln(10);  // Saut de ligne entre les séances
}

// Sortie du fichier PDF dans le navigateur
$pdf->Output();
?>
