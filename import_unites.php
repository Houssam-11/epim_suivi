<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/excel_import_helper.php';
require_once __DIR__ . '/includes/filiere_helper.php';
require_once __DIR__ . '/includes/unite_helper.php';

const UNITE_IMPORT_SESSION_KEY = 'unite_import_preview';

filiere_ensure_columns($conn);
unite_ensure_columns($conn);

function unite_import_columns(): array
{
    return [
        'nom' => "Nom de l'unité",
        'formateur' => 'Formateur',
        'objectif_general' => 'Objectif général',
        'heures_defaut' => 'Heures par séance par défaut',
        'masse_horaire' => 'Masse horaire',
        'annee_formation' => 'Année de formation',
        'semestre' => 'Semestre',
    ];
}

function unite_import_optional_columns(): array
{
    return [
        'type_unite' => "Type d'unité",
    ];
}

function unite_import_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function unite_import_filiere_exists(mysqli $conn, int $filiereId): bool
{
    $stmt = $conn->prepare('SELECT id FROM filieres WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $filiereId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function unite_import_filiere_archived(mysqli $conn, int $filiereId): bool
{
    $stmt = $conn->prepare('SELECT COALESCE(is_archived, 0) AS is_archived FROM filieres WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $filiereId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !$row || (int) $row['is_archived'] === 1;
}

function unite_import_formateurs(mysqli $conn): array
{
    $result = $conn->query('SELECT id, nom FROM formateurs ORDER BY nom');
    $formateurs = [];
    while ($result && $row = $result->fetch_assoc()) {
        $formateurs[excel_import_normalize_key($row['nom'])] = [
            'id' => (int) $row['id'],
            'nom' => $row['nom'],
        ];
    }

    return $formateurs;
}

function unite_import_existing_units(mysqli $conn, int $filiereId): array
{
    $stmt = $conn->prepare('SELECT intitule FROM unites_de_formation WHERE filiere_id = ?');
    $stmt->bind_param('i', $filiereId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[excel_import_normalize_key($row['intitule'])] = true;
    }
    $stmt->close();

    return $existing;
}

function unite_import_preview(mysqli $conn, int $filiereId, string $tmpPath): array
{
    $requiredColumns = unite_import_columns();
    $optionalColumns = unite_import_optional_columns();
    $loaded = excel_import_load_rows($tmpPath, $requiredColumns, $optionalColumns);
    if ($loaded['errors']) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => count($loaded['errors'])],
            'errors' => $loaded['errors'],
            'can_import' => false,
        ];
    }

    $formateurs = unite_import_formateurs($conn);
    $existingUnits = unite_import_existing_units($conn, $filiereId);
    $seenInFile = [];
    $previewRows = [];
    $summary = ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 0];

    foreach ($loaded['records'] as $record) {
        $summary['read']++;
        $errors = [];
        $nom = $record['nom'];
        $objectif = $record['objectif_general'];
        $formateurName = $record['formateur'];
        $heures = str_replace(',', '.', $record['heures_defaut']);
        $masse = str_replace(',', '.', $record['masse_horaire']);
        $anneeFormation = trim((string) $record['annee_formation']);
        $semestre = trim((string) $record['semestre']);
        $typeUnite = trim((string) ($record['type_unite'] ?? ''));
        if ($typeUnite === '') {
            $typeUnite = TYPE_UNITE_PEDAGOGIQUE;
        }

        foreach (unite_import_columns() as $key => $label) {
            if (trim((string) $record[$key]) === '') {
                $errors[] = "{$label} est obligatoire.";
            } elseif (!mb_check_encoding((string) $record[$key], 'UTF-8')) {
                $errors[] = "{$label} doit être en UTF-8.";
            }
        }

        if ($heures === '' || !is_numeric($heures) || (float) $heures <= 0) {
            $errors[] = 'Heures par séance par défaut doit être numérique.';
        }
        if ($masse === '' || !is_numeric($masse) || (float) $masse <= 0) {
            $errors[] = 'Masse horaire doit être numérique.';
        }

        if ($anneeFormation === '' || unite_normalize_annee_formation($anneeFormation) <= 0) {
            $errors[] = 'Année de formation doit être une valeur numérique valide.';
        }

        if (!in_array($semestre, ['1', '2'], true)) {
            $errors[] = 'Semestre doit contenir uniquement la valeur 1 ou 2.';
        }

        if (!mb_check_encoding((string) $typeUnite, 'UTF-8')) {
            $errors[] = "Type d'unité doit être en UTF-8.";
        }

        if (!unite_type_is_valid($typeUnite)) {
            $errors[] = "Type d'unité doit contenir uniquement pedagogique ou stage.";
        }

        $formateurKey = excel_import_normalize_key($formateurName);
        $formateur = $formateurs[$formateurKey] ?? null;
        if (!$formateur) {
            $errors[] = 'Formateur introuvable dans la base.';
        }

        $unitKey = excel_import_normalize_key($nom);
        $status = 'Nouvelle unité';
        $statusType = 'new';

        if (!$errors && isset($existingUnits[$unitKey])) {
            $status = 'Déjà existante';
            $statusType = 'existing';
        } elseif (!$errors && isset($seenInFile[$unitKey])) {
            $errors[] = 'Doublon dans le fichier.';
        }

        if ($errors) {
            $status = 'Erreur';
            $statusType = 'error';
            $summary['errors']++;
        } elseif ($statusType === 'existing') {
            $summary['existing']++;
        } else {
            $summary['new']++;
            $seenInFile[$unitKey] = true;
        }

        $previewRows[] = [
            'row' => (int) $record['_row'],
            'nom' => $nom,
            'formateur' => $formateurName,
            'formateur_id' => $formateur['id'] ?? null,
            'objectif_general' => $objectif,
            'heures_defaut' => $heures,
            'masse_horaire' => $masse,
            'annee_formation' => unite_normalize_annee_formation($anneeFormation),
            'annee_formation_label' => unite_annee_formation_label($anneeFormation),
            'semestre' => $semestre,
            'type_unite' => unite_normalize_type($typeUnite),
            'status' => $status,
            'status_type' => $statusType,
            'errors' => $errors,
        ];
    }

    return [
        'rows' => $previewRows,
        'summary' => $summary,
        'errors' => [],
        'can_import' => $summary['errors'] === 0,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'template') {
    excel_import_output_template('modele_import_unites.xlsx', array_values(unite_import_columns() + unite_import_optional_columns()));
}

$filiereId = filter_input(INPUT_POST, 'filiere_id', FILTER_VALIDATE_INT) ?: 0;
if ($filiereId <= 0 || !unite_import_filiere_exists($conn, $filiereId)) {
    unite_import_json(['success' => false, 'message' => 'Filière invalide.'], 400);
}

if (unite_import_filiere_archived($conn, $filiereId)) {
    unite_import_json(['success' => false, 'message' => 'Cette filière est archivée. Import désactivé.'], 403);
}

if ($action === 'preview') {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        unite_import_json(['success' => false, 'message' => 'Veuillez sélectionner un fichier Excel.'], 400);
    }

    try {
        $preview = unite_import_preview($conn, $filiereId, $_FILES['file']['tmp_name']);
        $_SESSION[UNITE_IMPORT_SESSION_KEY] = [
            'filiere_id' => $filiereId,
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'can_import' => $preview['can_import'],
        ];

        unite_import_json(['success' => true] + $preview);
    } catch (Throwable $e) {
        unset($_SESSION[UNITE_IMPORT_SESSION_KEY]);
        unite_import_json(['success' => false, 'message' => 'Fichier illisible ou invalide.'], 400);
    }
}

if ($action === 'import') {
    $preview = $_SESSION[UNITE_IMPORT_SESSION_KEY] ?? null;
    if (!$preview || (int) ($preview['filiere_id'] ?? 0) !== $filiereId) {
        unite_import_json(['success' => false, 'message' => 'Prévisualisation expirée. Veuillez prévisualiser à nouveau.'], 400);
    }

    if (empty($preview['can_import'])) {
        unite_import_json(['success' => false, 'message' => 'Le fichier contient des erreurs. Aucun import effectué.', 'summary' => $preview['summary']], 422);
    }

    $inserted = 0;
    $ignored = 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO unites_de_formation (filiere_id, intitule, objectif_general, heures_par_seance_defaut, masse_horaire, semestre, annee_formation, type_unite, formateur_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($preview['rows'] as $row) {
            if (($row['status_type'] ?? '') !== 'new') {
                $ignored++;
                continue;
            }

            $nom = $row['nom'];
            $objectif = $row['objectif_general'];
            $heures = (float) $row['heures_defaut'];
            $masse = (float) $row['masse_horaire'];
            $semestre = unite_normalize_semestre($row['semestre'] ?? 1);
            $anneeFormation = unite_normalize_annee_formation($row['annee_formation'] ?? null);
            $typeUnite = unite_normalize_type($row['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE);
            $formateurId = (int) $row['formateur_id'];
            $stmt->bind_param('issddiisi', $filiereId, $nom, $objectif, $heures, $masse, $semestre, $anneeFormation, $typeUnite, $formateurId);
            $stmt->execute();
            $inserted++;
        }

        $stmt->close();
        $conn->commit();
        unset($_SESSION[UNITE_IMPORT_SESSION_KEY]);

        unite_import_json([
            'success' => true,
            'message' => 'Import terminé.',
            'summary' => [
                'read' => (int) $preview['summary']['read'],
                'imported' => $inserted,
                'ignored' => $ignored,
                'errors' => (int) $preview['summary']['errors'],
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        unite_import_json(['success' => false, 'message' => "Erreur lors de l'import."], 500);
    }
}

unite_import_json(['success' => false, 'message' => 'Action invalide.'], 400);
