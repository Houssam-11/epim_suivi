<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Inclure TCPDF
require_once('libs/tcpdf/tcpdf.php');

// Connexion à la base de données
require_once 'db.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Récupérer l'unité sélectionnée depuis la requête POST
$unite_id = isset($_POST['unite_id']) ? $_POST['unite_id'] : 1;

// Récupérer les informations de l'unité
$sql_unite = "SELECT intitule FROM unites_de_formation WHERE id = ?";
$stmt_unite = $conn->prepare($sql_unite);
$stmt_unite->bind_param("i", $unite_id);
$stmt_unite->execute();
$result_unite = $stmt_unite->get_result();
$unite_row = $result_unite->fetch_assoc();
$unite = $unite_row['intitule'];

// Récupérer les séances validées
$sql_seances = "SELECT sp.date_seance, sp.objectif_pedagogique, sp.description_activites, sp.observations_formateur, sp.heures_reelles
                FROM seances_pedagogiques sp
                LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
                LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                WHERE sq.unite_id = ? AND s.valide_par_directeur = 1 LIMIT 3";
$stmt_seances = $conn->prepare($sql_seances);
$stmt_seances->bind_param("i", $unite_id);
$stmt_seances->execute();
$result_seances = $stmt_seances->get_result();

// Création du PDF avec TCPDF en mode paysage ('L' pour Landscape)
$pdf = new TCPDF('L', 'mm', 'A4');
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Nom de l\'auteur');
$pdf->SetTitle('Fiche de gestion - Suivi pédagogique');
$pdf->SetSubject('Suivi pédagogique');

// Ajout de la première page
$pdf->AddPage();

// Titre
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Fiche de gestion - Suivi pédagogique', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 10, "Unité de formation: $unite", 0, 1);

// Saut de ligne
$pdf->Ln(10);

// Largeurs des colonnes
$col_widths = [50, 60, 80, 50, 40];

// En-têtes des colonnes
$pdf->SetFont('helvetica', 'B', 12);
$headers = ['Date de la séance', 'Objectif pédagogique', 'Description des activités', 'Observations', 'Nombre d\'heures'];

// Afficher les en-têtes
foreach ($headers as $i => $header) {
    $pdf->Cell($col_widths[$i], 10, $header, 1, 0, 'C');
}
$pdf->Ln();

// Hauteur fixe pour chaque ligne
$row_height = 30;

// Afficher les données des séances
$pdf->SetFont('helvetica', '', 12);
while ($row = $result_seances->fetch_assoc()) {
    $cell_texts = [
        $row['date_seance'],
        $row['objectif_pedagogique'],
        $row['description_activites'],
        $row['observations_formateur'],
        $row['heures_reelles'] . 'h'
    ];

    // Calculer la hauteur maximale nécessaire pour les cellules
    $max_height = 0;
    foreach ($cell_texts as $i => $text) {
        // Simuler MultiCell pour obtenir le nombre de lignes nécessaires
        $nb_lines = $pdf->getNumLines($text, $col_widths[$i]);
        $cell_height = $nb_lines * 6;  // Chaque ligne a une hauteur de 6 mm
        if ($cell_height > $max_height) {
            $max_height = $cell_height;
        }
    }

    // Fixer la hauteur de la ligne à la valeur maximale (ou la hauteur fixe si nécessaire)
    $line_height = max($row_height, $max_height);

    // Afficher les cellules avec MultiCell sans bordure
    foreach ($cell_texts as $i => $text) {
        $x = $pdf->GetX();  // Position X actuelle
        $y = $pdf->GetY();  // Position Y actuelle

        // Afficher le texte avec MultiCell (pas de bordure ici)
        $pdf->MultiCell($col_widths[$i], 6, $text, 0, 'L');

        // Dessiner manuellement la bordure de la cellule avec Rect()
        $pdf->Rect($x, $y, $col_widths[$i], $line_height);

        // Revenir à la position de départ pour dessiner les autres colonnes
        $pdf->SetXY($x + $col_widths[$i], $y);
    }

    // Sauter à la ligne suivante
    $pdf->Ln($line_height);
}

// Sortir le fichier PDF dans le navigateur
$pdf->Output('fiche_suivi_pedagogique.pdf', 'I');

$conn->close();
?>
