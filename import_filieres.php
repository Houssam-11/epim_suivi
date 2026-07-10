<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/excel_import_helper.php';
require_once __DIR__ . '/includes/filiere_helper.php';

const FILIERE_IMPORT_SESSION_KEY = 'filiere_import_preview';

filiere_ensure_columns($conn);

function filiere_import_columns(): array
{
    return [
        'nom' => 'Nom',
        'niveau' => 'Niveau',
        'annee_formation' => 'Durée de la formation',
        'secteur' => 'Secteur',
    ];
}

function filiere_import_legacy_columns(): array
{
    $columns = filiere_import_columns();
    $columns['annee_formation'] = 'Année de formation';

    return $columns;
}

function filiere_import_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function filiere_import_existing(mysqli $conn): array
{
    $result = $conn->query('SELECT nom FROM filieres');
    $existing = [];
    while ($result && $row = $result->fetch_assoc()) {
        $existing[excel_import_normalize_key($row['nom'])] = true;
    }

    return $existing;
}

function filiere_import_validate_niveau(string $niveau): bool
{
    $normalized = excel_import_normalize_key($niveau);
    return in_array($normalized, ['technicien', 'technicien specialise', 'technicien specialisee'], true);
}

function filiere_import_preview(mysqli $conn, string $tmpPath): array
{
    $loaded = excel_import_load_rows($tmpPath, filiere_import_columns());
    if ($loaded['errors']) {
        $legacyLoaded = excel_import_load_rows($tmpPath, filiere_import_legacy_columns());
        if (!$legacyLoaded['errors']) {
            $loaded = $legacyLoaded;
        }
    }
    if ($loaded['errors']) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => count($loaded['errors'])],
            'errors' => $loaded['errors'],
            'can_import' => false,
        ];
    }

    $existing = filiere_import_existing($conn);
    $seenInFile = [];
    $previewRows = [];
    $summary = ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 0];

    foreach ($loaded['records'] as $record) {
        $summary['read']++;
        $errors = [];
        $nom = trim((string) $record['nom']);
        $niveauRaw = trim((string) $record['niveau']);
        $anneeFormationRaw = trim((string) $record['annee_formation']);
        $anneeFormation = filiere_normalize_annee_formation($anneeFormationRaw);
        $secteur = trim((string) $record['secteur']);

        if ($nom === '') {
            $errors[] = 'Nom est obligatoire.';
        } elseif (!mb_check_encoding($nom, 'UTF-8')) {
            $errors[] = 'Nom doit être en UTF-8.';
        }

        if ($niveauRaw === '') {
            $errors[] = 'Niveau est obligatoire.';
        } elseif (!mb_check_encoding($niveauRaw, 'UTF-8')) {
            $errors[] = 'Niveau doit être en UTF-8.';
        } elseif (!filiere_import_validate_niveau($niveauRaw)) {
            $errors[] = 'Niveau doit être Technicien ou Technicien Spécialisé.';
        }

        if ($anneeFormationRaw === '') {
            $errors[] = 'Durée de la formation est obligatoire.';
        } elseif ($anneeFormation <= 0) {
            $errors[] = 'Durée de la formation doit être une valeur numérique valide.';
        }

        if ($secteur !== '' && !mb_check_encoding($secteur, 'UTF-8')) {
            $errors[] = 'Secteur doit être en UTF-8.';
        }

        $filiereKey = excel_import_normalize_key($nom);
        $status = 'Nouvelle filière';
        $statusType = 'new';

        if (!$errors && isset($existing[$filiereKey])) {
            $status = 'Déjà existante';
            $statusType = 'existing';
        } elseif (!$errors && isset($seenInFile[$filiereKey])) {
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
            $seenInFile[$filiereKey] = true;
        }

        $niveau = filiere_normalize_niveau($niveauRaw);
        $previewRows[] = [
            'row' => (int) $record['_row'],
            'nom' => $nom,
            'niveau' => $niveau,
            'niveau_label' => filiere_niveau_label($niveau),
            'annee_formation' => $anneeFormation,
            'annee_formation_label' => filiere_annee_formation_label($anneeFormation),
            'duree_formation_label' => filiere_duree_formation_label($anneeFormation),
            'secteur' => $secteur,
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
    excel_import_output_template('modele_import_filieres.xlsx', array_values(filiere_import_columns()));
}

if ($action === 'preview') {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        filiere_import_json(['success' => false, 'message' => 'Veuillez sélectionner un fichier Excel ou CSV.'], 400);
    }

    try {
        $preview = filiere_import_preview($conn, $_FILES['file']['tmp_name']);
        $_SESSION[FILIERE_IMPORT_SESSION_KEY] = [
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'can_import' => $preview['can_import'],
        ];

        filiere_import_json(['success' => true] + $preview);
    } catch (Throwable $e) {
        unset($_SESSION[FILIERE_IMPORT_SESSION_KEY]);
        filiere_import_json(['success' => false, 'message' => 'Fichier illisible ou invalide.'], 400);
    }
}

if ($action === 'import') {
    $preview = $_SESSION[FILIERE_IMPORT_SESSION_KEY] ?? null;
    if (!$preview) {
        filiere_import_json(['success' => false, 'message' => 'Prévisualisation expirée. Veuillez prévisualiser à nouveau.'], 400);
    }

    if (empty($preview['can_import'])) {
        filiere_import_json(['success' => false, 'message' => 'Le fichier contient des erreurs. Aucun import effectué.', 'summary' => $preview['summary']], 422);
    }

    $inserted = 0;
    $ignored = 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO filieres (nom, niveau, annee_formation, secteur, secteur_id) VALUES (?, ?, ?, ?, NULL)');
        foreach ($preview['rows'] as $row) {
            if (($row['status_type'] ?? '') !== 'new') {
                $ignored++;
                continue;
            }

            if (filiere_name_exists($conn, (string) $row['nom'])) {
                $ignored++;
                continue;
            }

            $nom = (string) $row['nom'];
            $niveau = filiere_normalize_niveau((string) $row['niveau']);
            $anneeFormation = filiere_normalize_annee_formation($row['annee_formation'] ?? null);
            $secteur = (string) $row['secteur'];
            $stmt->bind_param('ssis', $nom, $niveau, $anneeFormation, $secteur);
            $stmt->execute();
            $inserted++;
        }
        $stmt->close();
        $conn->commit();
        unset($_SESSION[FILIERE_IMPORT_SESSION_KEY]);

        filiere_import_json([
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
        filiere_import_json(['success' => false, 'message' => "Erreur lors de l'import."], 500);
    }
}

filiere_import_json(['success' => false, 'message' => 'Action invalide.'], 400);
