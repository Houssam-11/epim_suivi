<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/excel_import_helper.php';

const DESCRIPTIF_OBJECTIF_IMPORT_SESSION_KEY = 'descriptif_objectif_import_preview';

function descriptif_import_columns(): array
{
    return [
        'description' => 'Description pédagogique',
        'sujet' => 'Sujet',
    ];
}

function descriptif_import_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function descriptif_import_objectif_exists(mysqli $conn, int $objectifId): bool
{
    $stmt = $conn->prepare(
        'SELECT o.id
         FROM objectifs_sequences o
         INNER JOIN sequences_pedagogiques seq ON seq.id = o.sequence_id
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE o.id = ?
           AND COALESCE(f.is_archived, 0) = 0
         LIMIT 1'
    );
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function descriptif_import_existing(mysqli $conn, int $objectifId): array
{
    $stmt = $conn->prepare('SELECT description FROM descriptifs_objectifs_sequences WHERE objectif_sequence_id = ?');
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[excel_import_normalize_key($row['description'])] = true;
    }
    $stmt->close();

    return $existing;
}

function descriptif_import_next_order(mysqli $conn, int $objectifId): int
{
    $stmt = $conn->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 AS next_order FROM descriptifs_objectifs_sequences WHERE objectif_sequence_id = ?');
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['next_order'] ?? 1);
}

function descriptif_import_preview(mysqli $conn, int $objectifId, string $tmpPath): array
{
    $loaded = excel_import_load_rows($tmpPath, descriptif_import_columns());
    if ($loaded['errors']) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => count($loaded['errors'])],
            'errors' => $loaded['errors'],
            'can_import' => false,
        ];
    }

    $existing = descriptif_import_existing($conn, $objectifId);
    $seen = [];
    $rows = [];
    $summary = ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 0];

    foreach ($loaded['records'] as $record) {
        $summary['read']++;
        $errors = [];
        $description = $record['description'];
        $sujet = $record['sujet'];

        foreach (descriptif_import_columns() as $key => $label) {
            if (trim((string) $record[$key]) === '') {
                $errors[] = "{$label} est obligatoire.";
            } elseif (!mb_check_encoding((string) $record[$key], 'UTF-8')) {
                $errors[] = "{$label} doit être en UTF-8.";
            }
        }
        if ($sujet !== '' && mb_strlen($sujet, 'UTF-8') > 100) {
            $errors[] = 'Sujet ne doit pas dépasser 100 caractères.';
        }

        $key = excel_import_normalize_key($description);
        $status = 'Nouveau descriptif';
        $statusType = 'new';
        if (!$errors && isset($existing[$key])) {
            $status = 'Déjà existant';
            $statusType = 'existing';
        } elseif (!$errors && isset($seen[$key])) {
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
            $seen[$key] = true;
        }

        $rows[] = [
            'row' => (int) $record['_row'],
            'description' => $description,
            'sujet' => $sujet,
            'status' => $status,
            'status_type' => $statusType,
            'errors' => $errors,
        ];
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
        'errors' => [],
        'can_import' => $summary['errors'] === 0,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'template') {
    excel_import_output_template('modele_import_descriptifs_objectif.xlsx', array_values(descriptif_import_columns()));
}

$objectifId = filter_input(INPUT_POST, 'objectif_id', FILTER_VALIDATE_INT) ?: 0;
if ($objectifId <= 0 || !descriptif_import_objectif_exists($conn, $objectifId)) {
    descriptif_import_json(['success' => false, 'message' => 'Objectif invalide.'], 400);
}

if ($action === 'preview') {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        descriptif_import_json(['success' => false, 'message' => 'Veuillez sélectionner un fichier Excel.'], 400);
    }

    try {
        $preview = descriptif_import_preview($conn, $objectifId, $_FILES['file']['tmp_name']);
        $_SESSION[DESCRIPTIF_OBJECTIF_IMPORT_SESSION_KEY] = [
            'objectif_id' => $objectifId,
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'can_import' => $preview['can_import'],
        ];
        descriptif_import_json(['success' => true] + $preview);
    } catch (Throwable $e) {
        unset($_SESSION[DESCRIPTIF_OBJECTIF_IMPORT_SESSION_KEY]);
        descriptif_import_json(['success' => false, 'message' => 'Fichier illisible ou invalide.'], 400);
    }
}

if ($action === 'import') {
    $preview = $_SESSION[DESCRIPTIF_OBJECTIF_IMPORT_SESSION_KEY] ?? null;
    if (!$preview || (int) ($preview['objectif_id'] ?? 0) !== $objectifId) {
        descriptif_import_json(['success' => false, 'message' => 'Prévisualisation expirée. Veuillez prévisualiser à nouveau.'], 400);
    }
    if (empty($preview['can_import'])) {
        descriptif_import_json(['success' => false, 'message' => 'Le fichier contient des erreurs. Aucun import effectué.', 'summary' => $preview['summary']], 422);
    }

    $inserted = 0;
    $ignored = 0;
    $createdRows = [];
    $ordre = descriptif_import_next_order($conn, $objectifId);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO descriptifs_objectifs_sequences (objectif_sequence_id, description, sujet, ordre)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($preview['rows'] as $row) {
            if (($row['status_type'] ?? '') !== 'new') {
                $ignored++;
                continue;
            }
            $description = $row['description'];
            $sujet = $row['sujet'];
            $stmt->bind_param('issi', $objectifId, $description, $sujet, $ordre);
            $stmt->execute();
            $createdRows[] = [
                'id' => $stmt->insert_id,
                'objectif_id' => $objectifId,
                'description' => $description,
                'sujet' => $sujet,
            ];
            $ordre++;
            $inserted++;
        }
        $stmt->close();
        $conn->commit();
        unset($_SESSION[DESCRIPTIF_OBJECTIF_IMPORT_SESSION_KEY]);

        descriptif_import_json([
            'success' => true,
            'created_rows' => $createdRows,
            'summary' => [
                'read' => (int) $preview['summary']['read'],
                'imported' => $inserted,
                'ignored' => $ignored,
                'errors' => (int) $preview['summary']['errors'],
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        descriptif_import_json(['success' => false, 'message' => "Erreur lors de l'import."], 500);
    }
}

descriptif_import_json(['success' => false, 'message' => 'Action invalide.'], 400);
