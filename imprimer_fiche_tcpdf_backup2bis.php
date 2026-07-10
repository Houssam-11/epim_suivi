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

// Récupérer les séances validées dans l'ordre chronologique
$sql_seances2 = "SELECT sp.date_seance, sp.objectif_pedagogique, sp.description_activites, 
                       sp.observations_formateur, sp.dispositions_prochaine, sp.heures_officielles,
                       SUM(sp.heures_officielles) OVER (ORDER BY sp.date_seance) AS cumul_heures_officielles
                FROM seances_pedagogiques sp
                LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
                LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                WHERE sq.unite_id = ? AND s.valide_par_directeur = 1
                ORDER BY sp.date_seance ASC
                LIMIT 3";


$stmt_seances = $conn->prepare($sql_seances2);
$stmt_seances->bind_param("i", $unite_id);
$stmt_seances->execute();
$result_seances = $stmt_seances->get_result();

// Récupérer les informations de l'unité, du formateur et de la première séquence
$sql_unite_details = "
    SELECT uf.intitule AS unite_intitule, uf.objectif_general, f.nom AS formateur_nom, 
           sq.intitule AS sequence_nom, sq.volume_horaire 
    FROM unites_de_formation uf
    LEFT JOIN formateurs f ON uf.formateur_id = f.id
    LEFT JOIN sequences_pedagogiques sq ON uf.id = sq.unite_id
    LEFT JOIN seances_pedagogiques sp ON sq.id = sp.sequence_id
    WHERE uf.id = ? 
    ORDER BY sp.date_seance ASC 
    LIMIT 1";

$stmt_unite_details = $conn->prepare($sql_unite_details);
$stmt_unite_details->bind_param("i", $unite_id);
$stmt_unite_details->execute();
$result_unite_details = $stmt_unite_details->get_result();

// Vérifier si des résultats ont été retournés
if ($result_unite_details && $result_unite_details->num_rows > 0) {
    $details_row = $result_unite_details->fetch_assoc();
    
    // Convertir les résultats en UTF-8
    $objectif_unite = utf8_encode($details_row['objectif_general']); // Encodage UTF-8
    $formateur_nom = utf8_encode($details_row['formateur_nom']); // Encodage UTF-8
    $sequence_nom = utf8_encode($details_row['sequence_nom']); // Encodage UTF-8
    $sequence_volume_horaire = utf8_encode($details_row['volume_horaire']); // Encodage UTF-8
} else {
    // Valeurs par défaut si aucune donnée n'est retournée
    $objectif_unite = 'Non spécifié';
    $formateur_nom = 'Non spécifié';
    $sequence_nom = 'Non spécifié';
    $sequence_volume_horaire = 'Non spécifié';
}

// Récupérer les commentaires des trois premières séances validées
$sql = "SELECT s.commentaire_directeur 
        FROM suivi_pedagogique s 
        LEFT JOIN seances_pedagogiques sp ON s.seance_id = sp.id 
        WHERE s.valide_par_directeur = 1 
        LIMIT 3";

$sequence_id = 1; // Exemple : changer selon votre logique
$stmt = $conn->prepare($sql);
//$stmt->bind_param("i", $sequence_id);
$stmt->execute();
$result = $stmt->get_result();

// Concaténer les commentaires des trois séances
$commentaires = [];
// Concaténer les commentaires des trois séances au format 'Sc1 : comment, Sc2 : comment, ...'
$commentaires_concat = '';
$sc_number = 1;
while ($row = $result->fetch_assoc()) {
    $comment = $row['commentaire_directeur'];
    $commentaires_concat .= "Sc{$sc_number} : {$comment}, ";
    $sc_number++;
}

// Supprimer la dernière virgule et espace
$commentaires_concat = rtrim($commentaires_concat, ', ');


// Création du PDF avec TCPDF en mode paysage ('L' pour Landscape)
$pdf = new TCPDF('L', 'mm', 'A4');
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('HAMZA BOURKHA');
$pdf->SetTitle('Fiche de gestion - Suivi pédagogique');
$pdf->SetSubject('Suivi pédagogique');

// Définir explicitement les marges (marge supérieure à 20mm)
$pdf->SetMargins(10, 20, 10);

// Désactiver la rupture automatique des pages si nécessaire
$pdf->SetAutoPageBreak(TRUE, 0);

// Ajout de la première page sans appel à Ln() au début
$pdf->AddPage();

// Ajouter un tableau avec trois lignes et quatre colonnes au-dessus du tableau principal

// Largeurs des colonnes pour ce tableau (identiques à celles du tableau principal)
$col_widths_top = [50, 80, 70, 70,10]; // Ces largeurs doivent correspondre au tableau principal

// Définir la couleur de fond (gris clair)
$pdf->SetFillColor(220, 220, 220); // Gris clair

// Première ligne fusionnée pour le titre du document avec fond gris
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(array_sum($col_widths_top), 15, 'ÉTAT D\'AVANCEMENT ET DE RÉALISATION DU PROGRAMME DE FORMATION', 1, 1, 'C', 1); // '1' pour le fond

// Passer à la ligne suivante
$pdf->Ln(0);

// Appliquer la couleur de fond pour les autres lignes également
$pdf->SetFont('helvetica', '', 8);

// Deuxième ligne avec fond gris
$pdf->Cell($col_widths_top[0], 10, 'Intitulé de l\'unité de formation :', 1, 0, 'C', 1);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($col_widths_top[1], 10, $unite, 1, 0, 'C', 1);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell($col_widths_top[2], 10, 'Formateur', 1, 0, 'C', 1);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($col_widths_top[3] + $col_widths_top[4], 10, $formateur_nom, 1, 1, 'C', 1); // '1' pour le fond

// Troisième ligne avec fond gris
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell($col_widths_top[0], 10, 'Objectif général de l’unité de formation :', 1, 0, 'C', 1);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($col_widths_top[1], 10, $objectif_unite, 1, 0, 'C', 1);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell($col_widths_top[2], 10, 'Objectif/Intitulé de la séq. pédagogique et vol. horaire :', 1, 0, 'C', 1);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($col_widths_top[3] + $col_widths_top[4], 10, $sequence_nom . ' / Durée : ' . $sequence_volume_horaire . 'h', 1, 1, 'C', 1); // '1' pour le fond

// Saut de ligne avant de passer au tableau principal
$pdf->Ln(1);
// Largeurs des colonnes ajustées pour le nouveau format
$col_widths = [25, 55, 60, 50, 40, 15, 15,20]; // Assurez-vous que la somme est cohérente avec la largeur du document

// En-têtes des colonnes
$pdf->SetFont('helvetica', '', 8);
$headers = ['Date & horaire
de la séance pédagogique
', 'Objectif pédagogique de la séance (Cf. plan programme)', 'Descriptif du déroulement de la séance 
(Activités et Supports pédagogiques utilisés pendant la séance)
', 'Observations du formateur en fin de séance','Dispositions pour la prochaine séance', 'Nbr heures', 'Nbr Heures Cumulées','Signature du formateur'];

// Hauteur fixe pour l'en-tête
$header_height = 15; // Plus de hauteur pour l'en-tête

// Afficher les en-têtes
foreach ($headers as $i => $header) {
    $x = $pdf->GetX(); // Sauvegarder la position X
    $y = $pdf->GetY(); // Sauvegarder la position Y

    // Utiliser MultiCell pour appliquer le retour à la ligne automatique pour toutes les colonnes
    $pdf->MultiCell($col_widths[$i], 10, $header, 0, 'C', 0, 0); // Pas de bordure ici

    // Dessiner manuellement la bordure avec Rect() pour une hauteur fixe
    $pdf->Rect($x, $y, $col_widths[$i], $header_height);

    // Revenir à la position X pour aligner correctement les autres colonnes
    $pdf->SetXY($x + $col_widths[$i], $y);
}

// Passer à la ligne suivante après les en-têtes
$pdf->Ln($header_height);



// Hauteur fixe pour chaque ligne
$row_height = 30;

// Afficher les données des séances
$pdf->SetFont('helvetica', 'I', 9);
while ($row = $result_seances->fetch_assoc()) {
    $cell_texts = [
        $row['date_seance'],
        $row['objectif_pedagogique'],
        $row['description_activites'],
        $row['observations_formateur'],
        $row['dispositions_prochaine'],
        $row['heures_officielles'] . 'h',
        $row['cumul_heures_officielles'] . 'h',
        '     '
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

// Saut de ligne avant d'ajouter le tableau
$pdf->Ln(1);

// Définir la couleur de fond (gris clair)
$pdf->SetFillColor(220, 220, 220); // Gris clair

// Ajouter un tableau avec deux lignes et deux colonnes

// Largeurs des colonnes
$total_width = 280; // Largeur totale de la page (A4)
// Définir les largeurs des colonnes du tableau
$first_row_col1_width = 0.80 * $total_width; // 80% pour la première colonne de la première ligne
$first_row_col2_width = 0.20 * $total_width; // 20% pour la deuxième colonne de la première ligne
$second_row_col1_width = 0.35 * $total_width; // 30% pour la première colonne de la deuxième ligne
$second_row_col2_width = 0.65 * $total_width; // 70% pour la deuxième colonne de la deuxième ligne

// Ajouter la première ligne du tableau (80%-20%)
$pdf->Cell($first_row_col1_width, 8, 'Taux de réalisation du VH', 1, 0, 'R',1);
$pdf->Cell($first_row_col2_width, 8, '', 1, 1, 'R',1);

// Ajouter la deuxième ligne du tableau (30%-70%)
$pdf->Cell($second_row_col1_width, 8, 'Commentaire du directeur sur le degré de réalisation des objectifs', 1, 0, 'L',1);
$pdf->Cell($second_row_col2_width, 8, $commentaires_concat, 1, 1, 'C',1);


// Sortir le fichier PDF dans le navigateur
$pdf->Output('fiche_suivi_pedagogique.pdf', 'I',1);

$conn->close();
?>
