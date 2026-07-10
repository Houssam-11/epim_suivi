<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

require('libs/fpdf/fpdf.php');

// Connexion à la base de données
require_once 'db.php';
require_once __DIR__ . '/annees_scolaires.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Récupérer l'unité sélectionnée depuis la requête POST
$unite_id = filter_input(INPUT_POST, 'unite_id', FILTER_VALIDATE_INT);
$annee_scolaire_id = filter_input(INPUT_POST, 'annee_scolaire_id', FILTER_VALIDATE_INT);

if (!$unite_id || !$annee_scolaire_id) {
    http_response_code(400);
    exit('Unité de formation et année scolaire obligatoires.');
}

$stmt_annee = $conn->prepare("SELECT libelle FROM annees_scolaires WHERE id = ? AND statut IN ('active', 'archivee') LIMIT 1");
$stmt_annee->bind_param("i", $annee_scolaire_id);
$stmt_annee->execute();
$annee_row = $stmt_annee->get_result()->fetch_assoc();
$stmt_annee->close();

if (!$annee_row) {
    http_response_code(400);
    exit("Année scolaire non imprimable.");
}

// Récupérer les informations de l'unité
$sql_unite = "SELECT intitule FROM unites_de_formation WHERE id = ?";
$stmt_unite = $conn->prepare($sql_unite);

if ($stmt_unite === false) {
    die("Erreur dans la préparation de la requête SQL : " . $conn->error);
}

$stmt_unite->bind_param("i", $unite_id);
$stmt_unite->execute();
$result_unite = $stmt_unite->get_result();
$unite = $result_unite->fetch_assoc()['intitule'];

// Récupérer les séances validées
$sql_seances = "SELECT sp.date_seance, sp.objectif_pedagogique, sp.description_activites, sp.observations_formateur, sp.heures_reelles, sp.controle_continu
                FROM seances_pedagogiques sp
                LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
                LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                WHERE sq.unite_id = ? AND sp.annee_scolaire_id = ? AND s.valide_par_directeur = 1 LIMIT 3";
$stmt_seances = $conn->prepare($sql_seances);
$stmt_seances->bind_param("ii", $unite_id, $annee_scolaire_id);
$stmt_seances->execute();
$result_seances = $stmt_seances->get_result();

// Création du PDF en mode paysage ('L' pour Landscape)
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->AddPage();

// Titre
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Fiche de gestion - Suivi pédagogique', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, "Unité de formation: $unite", 0, 1);
$pdf->Cell(0, 10, "Année scolaire: " . $annee_row['libelle'], 0, 1);

// Saut de ligne
$pdf->Ln(10);

// Hauteur fixe pour chaque ligne (y compris les en-têtes)
$fixed_height = 30;

// En-têtes des colonnes
$pdf->SetFont('Arial', 'B', 12);
$col_widths = [60, 60, 90, 60, 30];  // Largeurs pour les en-têtes
$headers = ['Date de la séance', 'Objectif pédagogique', 'Description des activités', 'Observations', 'Nombre d\'heures'];

// Dessiner les en-têtes
foreach ($headers as $i => $header) {
    $pdf->Cell($col_widths[$i], $fixed_height, $header, 1, 0, 'C');  // Ajout du bordure "1" pour le quadrillage
}
$pdf->Ln();

// Définir les largeurs spécifiques pour les données uniquement
$data_col_widths = [70, 70, 100, 70, 40]; // Largeurs spécifiques pour les données

// Afficher les données des séances
$pdf->SetFont('Arial', '', 12);
while ($row = $result_seances->fetch_assoc()) {
    // Stocker les textes de chaque colonne
    $cell_texts = [
        $row['date_seance'],
        $row['objectif_pedagogique'],
        $row['description_activites'],
        $row['observations_formateur'],
        $row['heures_reelles'] . 'h'
    ];

    // Sauvegarder la position Y de départ
    $y_start = $pdf->GetY();

    // Calculer la hauteur maximale nécessaire pour les cellules de cette ligne
    $max_height = 0;
    foreach ($cell_texts as $i => $text) {
        // Simuler MultiCell pour obtenir le nombre de lignes nécessaires
        $nb_lines = $pdf->GetStringWidth($text) / $data_col_widths[$i];  // Utiliser les largeurs spécifiques aux données
        $cell_height = ceil($nb_lines) * 10;  // 10 mm est la hauteur d'une ligne
        if ($cell_height > $max_height) {
            $max_height = $cell_height;
        }
    }

    // Réinitialiser Y pour aligner les cellules sur la même ligne avec la même hauteur
    $y_start = $pdf->GetY();

    // Afficher les cellules avec MultiCell pour gérer les retours à la ligne
    foreach ($cell_texts as $i => $text) {
        $x = $pdf->GetX();  // Position X actuelle
        if ($i === 2 && (int) $row['controle_continu'] === 1) {
            $y = $pdf->GetY();
            $pdf->Rect($x, $y, $data_col_widths[$i], $fixed_height);
            $pdf->SetTextColor(200, 0, 0);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($data_col_widths[$i], 6, '[ CONTROLE CONTINU ]', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 12);
            $pdf->SetXY($x, $y + 6);
            $pdf->MultiCell($data_col_widths[$i], 10, $text, 0, 'L');
            $pdf->SetXY($x + $data_col_widths[$i], $y_start);
            continue;
        }

        // Afficher le texte avec MultiCell
        $pdf->MultiCell($data_col_widths[$i], 10, $text, 1, 'L');  // Dessin du texte avec bordure "1"
        $pdf->SetTextColor(0, 0, 0);

        // Revenir à la position initiale de la ligne pour aligner les colonnes
        $pdf->SetXY($x + $data_col_widths[$i], $y_start);
    }

    // Dessiner les bordures manuellement avec la hauteur fixe
    foreach ($data_col_widths as $i => $width) {
        $pdf->Rect($pdf->GetX() - array_sum(array_slice($data_col_widths, 0, $i + 1)), $y_start, $width, $fixed_height);
    }

    // Passer à la ligne suivante (avec une hauteur fixe)
    $pdf->Ln($fixed_height);
}

// Sortir le fichier PDF dans le navigateur
$pdf->Output();

$conn->close();
?>
