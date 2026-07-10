<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Inclure TCPDF
require_once('libs/tcpdf/tcpdf.php');
// Inclure la classe CustomPDF
require_once('libs/CustomPDF.php');

// Connexion a la base de donnees
require_once 'db.php';
require_once __DIR__ . '/annees_scolaires.php';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Recuperer l'unite selectionnee depuis la requete POST
$unite_id = filter_input(INPUT_POST, 'unite_id', FILTER_VALIDATE_INT);
$annee_scolaire_id = filter_input(INPUT_POST, 'annee_scolaire_id', FILTER_VALIDATE_INT);

if (!$unite_id || !$annee_scolaire_id) {
    http_response_code(400);
    exit('Unité de formation et année scolaire obligatoires.');
}

$stmt_annee = $conn->prepare("SELECT libelle, statut FROM annees_scolaires WHERE id = ? AND statut IN ('active', 'archivee') LIMIT 1");
$stmt_annee->bind_param("i", $annee_scolaire_id);
$stmt_annee->execute();
$annee_row = $stmt_annee->get_result()->fetch_assoc();
$stmt_annee->close();

if (!$annee_row) {
    http_response_code(400);
    exit("Année scolaire non imprimable.");
}
$annee_scolaire_label = $annee_row['libelle'];

// Recuperer les informations de l'unite
$sql_unite = "SELECT intitule FROM unites_de_formation WHERE id = ?";
$stmt_unite = $conn->prepare($sql_unite);
$stmt_unite->bind_param("i", $unite_id);
$stmt_unite->execute();
$result_unite = $stmt_unite->get_result();
$unite_row = $result_unite->fetch_assoc();
$unite = $unite_row['intitule'];

// Recuperer les seances validees dans l'ordre chronologique
$sql_seances2 = "SELECT sp.date_seance, sp.objectif_pedagogique, sp.description_activites,
                       sp.observations_formateur, sp.dispositions_prochaine, sp.heures_officielles,
                       sp.controle_continu, sp.sequence_id,
                       sq.intitule AS sequence_nom, sq.volume_horaire AS sequence_volume_horaire,
                       SUM(sp.heures_officielles) OVER (ORDER BY sp.date_seance ASC, sp.id ASC) AS cumul_heures_officielles,
                       s.commentaire_directeur
                FROM seances_pedagogiques sp
                LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
                LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                WHERE sq.unite_id = ? AND sp.annee_scolaire_id = ? AND s.valide_par_directeur = 1
                ORDER BY sp.sequence_id ASC, sp.date_seance ASC, sp.id ASC";

$stmt_seances = $conn->prepare($sql_seances2);
$stmt_seances->bind_param("ii", $unite_id, $annee_scolaire_id);
$stmt_seances->execute();
$result_seances = $stmt_seances->get_result();

// Recuperer la masse horaire de l'unite
$sql_taux_vh = "
    SELECT uf.masse_horaire
    FROM unites_de_formation uf
    WHERE uf.id = ?
    LIMIT 1";

$stmt_taux_vh = $conn->prepare($sql_taux_vh);
$stmt_taux_vh->bind_param("i", $unite_id);
$stmt_taux_vh->execute();
$result_taux_vh = $stmt_taux_vh->get_result();
$taux_vh_row = $result_taux_vh ? $result_taux_vh->fetch_assoc() : null;

$masse_horaire = $taux_vh_row ? (float) $taux_vh_row['masse_horaire'] : 0;

// Recuperer les informations de l'unite et du formateur
$sql_unite_details = "
    SELECT uf.intitule AS unite_intitule, uf.objectif_general, f.nom AS formateur_nom
    FROM unites_de_formation uf
    LEFT JOIN formateurs f ON uf.formateur_id = f.id
    WHERE uf.id = ?
    LIMIT 1";

$stmt_unite_details = $conn->prepare($sql_unite_details);
$stmt_unite_details->bind_param("i", $unite_id);
$stmt_unite_details->execute();
$result_unite_details = $stmt_unite_details->get_result();

// Verifier si des resultats ont ete retournes
if ($result_unite_details && $result_unite_details->num_rows > 0) {
    $details_row = $result_unite_details->fetch_assoc();

    // Les donnees sont déjà en UTF-8 via la connexion MySQL
    $objectif_unite = $details_row['objectif_general'];
    $formateur_nom = $details_row['formateur_nom'];
} else {
    // Valeurs par defaut si aucune donnee n'est retournee
    $objectif_unite = 'Non spécifié';
    $formateur_nom = 'Non spécifié';
}

// Creation du PDF avec TCPDF en mode paysage ('L' pour Landscape)
$pdf = new CustomPDF('L', 'mm', 'A4');

$pdf->unite = $unite;
$pdf->formateur_nom = $formateur_nom;
$pdf->objectif_unite = $objectif_unite;
$pdf->annee_scolaire_label = $annee_scolaire_label;

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('HAMZA BOURKHA');
$pdf->SetTitle('Fiche de gestion - Suivi pédagogique');
$pdf->SetSubject('Suivi pédagogique');

// Definir explicitement les marges (marge superieure a 20mm)
$pdf->SetMargins(10, 20, 10);
$pdf->SetCellPadding(1);
$pdf->setCellHeightRatio(1.05);

// Desactiver la rupture automatique des pages si necessaire
$pdf->SetAutoPageBreak(TRUE, 0);

// Largeurs des colonnes ajustees pour le nouveau format
$col_widths = [25, 55, 60, 50, 40, 15, 15, 20]; // Assurez-vous que la somme est coherente avec la largeur du document

// En-tetes des colonnes
$headers = ['Date & horaire de la séance pédagogique', 'Objectif pédagogique de la séance (Cf. plan programme)',
            'Descriptif du déroulement de la séance (Activités et Supports pédagogiques utilisés)',
            'Observations du formateur en fin de séance', 'Dispositions pour la prochaine séance',
            'Nbr heures', 'Nbr Heures Cumulées', 'Signature du formateur'];

// Hauteur fixe pour l'en-tete
$header_height = 15; // Plus de hauteur pour l'en-tete

$render_table_headers = function () use ($pdf, $headers, $col_widths, $header_height): void {
    foreach ($headers as $i => $header) {
        $pdf->SafeFixedCell($col_widths[$i], $header_height, $header, 1, 'C', 0, '', 7, 'M');
    }
    $pdf->Ln($header_height);
};

// Hauteur fixe pour chaque ligne
$row_height = 30;

// Compteur pour suivre le nombre de lignes affichees par page
$line_counter = 0;
$records_per_page = 3; // Nombre d'enregistrements par page

$commentaires_page = ''; // Commentaires pour la page courante
$dernier_cumul_page = 0; // Derniere valeur affichee dans "Nbr Heures Cumulees" de la page courante
$current_sequence_id = null;
$page_started = false;
$page_footer_ready = false;

$finalize_current_page = function () use ($pdf, $masse_horaire, &$commentaires_page, &$dernier_cumul_page, &$page_footer_ready): void {
    $commentaires_page = rtrim($commentaires_page, ', ');
    $pdf->commentaires_concat = $commentaires_page;
    $taux_realisation_vh = $masse_horaire > 0 ? round(($dernier_cumul_page / $masse_horaire) * 100, 2) : 0;
    $pdf->taux_realisation_vh = number_format($taux_realisation_vh, 2, ',', ' ') . ' %';
    $page_footer_ready = true;
};

$start_sequence_page = function (array $row) use ($pdf, $render_table_headers, &$line_counter, &$commentaires_page, &$dernier_cumul_page, &$page_started, &$page_footer_ready): void {
    $pdf->sequence_nom = trim((string) ($row['sequence_nom'] ?? '')) !== '' ? $row['sequence_nom'] : 'Non spécifié';
    $pdf->sequence_volume_horaire = trim((string) ($row['sequence_volume_horaire'] ?? '')) !== '' ? $row['sequence_volume_horaire'] : 'Non spécifié';
    $pdf->AddPage();
    $pdf->commentaires_concat = '';
    $pdf->taux_realisation_vh = '';
    $pdf->Ln(31);
    $render_table_headers();
    $line_counter = 0;
    $commentaires_page = '';
    $dernier_cumul_page = 0;
    $page_started = true;
    $page_footer_ready = false;
};

// Afficher les donnees des seances
while ($row = $result_seances->fetch_assoc()) {
    $sequence_id = (int) $row['sequence_id'];
    if ($current_sequence_id !== $sequence_id) {
        if ($page_started && !$page_footer_ready) {
            $finalize_current_page();
        }
        $current_sequence_id = $sequence_id;
        $start_sequence_page($row);
    } elseif ($line_counter >= $records_per_page) {
        $start_sequence_page($row);
    }

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

    // Ajouter le commentaire de la seance au commentaire de la page courante
    $commentaires_page .= 'Sc' . ($line_counter + 1) . ' : ' . $row['commentaire_directeur'] . ', ';
    $dernier_cumul_page = (float) $row['cumul_heures_officielles'];

    // Afficher les cellules dans la hauteur fixe prevue pour eviter tout debordement.
    foreach ($cell_texts as $i => $text) {
        $align = ($i >= 5) ? 'C' : 'L';
        if ($i === 2 && (int) $row['controle_continu'] === 1) {
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $description_html = '<span style="color:#c80000;font-weight:bold;">[ CONTROLE CONTINU ]</span><br />'
                . nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));

            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->writeHTMLCell($col_widths[$i], $row_height, $x, $y, $description_html, 1, 0, false, true, 'L', true);
            $pdf->SetXY($x + $col_widths[$i], $y);
            continue;
        }
        $pdf->SafeFixedCell($col_widths[$i], $row_height, $text, 1, $align, 0, 'I', 8, 'T');
    }
    $pdf->SetTextColor(0, 0, 0);

    // Sauter a la ligne suivante
    $pdf->Ln($row_height);

    // Incrementer le compteur
    $line_counter++;

    // Si le nombre d'enregistrements atteint 3, preparer le pied de page courant.
    if ($line_counter >= $records_per_page) {
        $finalize_current_page();
    }
}

// Ajouter les commentaires pour la derniere page
if ($page_started && !$page_footer_ready) {
    $finalize_current_page();
}

// Sortir le fichier PDF dans le navigateur
$pdf->Output('fiche_suivi_pedagogique.pdf', 'I');

$conn->close();
