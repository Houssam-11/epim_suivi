<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/excel_import_helper.php';

const OBJECTIF_SEQUENCE_IMPORT_SESSION_KEY = 'objectif_sequence_import_preview';

function objectif_sequence_import_columns(): array
{
    return [
        'objectif' => 'Objectif pédagogique',
        'volume_horaire' => 'Volume horaire',
    ];
}

function objectif_sequence_import_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function objectif_sequence_import_sequence_exists(mysqli $conn, int $sequenceId): bool
{
    $stmt = $conn->prepare(
        'SELECT seq.id
         FROM sequences_pedagogiques seq
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE seq.id = ?
           AND COALESCE(seq.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         LIMIT 1'
    );
    $stmt->bind_param('i', $sequenceId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function objectif_sequence_import_existing(mysqli $conn, int $sequenceId): array
{
    $stmt = $conn->prepare('SELECT objectif FROM objectifs_sequences WHERE sequence_id = ?');
    $stmt->bind_param('i', $sequenceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[excel_import_normalize_key($row['objectif'])] = true;
    }
    $stmt->close();

    return $existing;
}

function objectif_sequence_import_next_order(mysqli $conn, int $sequenceId): int
{
    $stmt = $conn->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 AS next_order FROM objectifs_sequences WHERE sequence_id = ?');
    $stmt->bind_param('i', $sequenceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['next_order'] ?? 1);
}

function objectif_sequence_import_preview(mysqli $conn, int $sequenceId, string $tmpPath): array
{
    $loaded = excel_import_load_rows($tmpPath, objectif_sequence_import_columns());
    if ($loaded['errors']) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => count($loaded['errors'])],
            'errors' => $loaded['errors'],
            'can_import' => false,
        ];
    }

    $existing = objectif_sequence_import_existing($conn, $sequenceId);
    $seen = [];
    $rows = [];
    $summary = ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 0];

    foreach ($loaded['records'] as $record) {
        $summary['read']++;
        $errors = [];
        $objectif = $record['objectif'];
        $volume = str_replace(',', '.', $record['volume_horaire']);

        foreach (objectif_sequence_import_columns() as $key => $label) {
            if (trim((string) $record[$key]) === '') {
                $errors[] = "{$label} est obligatoire.";
            } elseif (!mb_check_encoding((string) $record[$key], 'UTF-8')) {
                $errors[] = "{$label} doit être en UTF-8.";
            }
        }

        if ($volume === '' || !is_numeric($volume) || (float) $volume <= 0) {
            $errors[] = 'Volume horaire doit être numérique.';
        }

        $key = excel_import_normalize_key($objectif);
        $status = 'Nouvel objectif';
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
            'objectif' => $objectif,
            'volume_horaire' => $volume,
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
    excel_import_output_template('modele_import_objectifs_sequence.xlsx', array_values(objectif_sequence_import_columns()));
}

$sequenceId = filter_input(INPUT_POST, 'sequence_id', FILTER_VALIDATE_INT) ?: 0;
if ($sequenceId <= 0 || !objectif_sequence_import_sequence_exists($conn, $sequenceId)) {
    objectif_sequence_import_json(['success' => false, 'message' => 'Séquence invalide.'], 400);
}

if ($action === 'preview') {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        objectif_sequence_import_json(['success' => false, 'message' => 'Veuillez sélectionner un fichier Excel.'], 400);
    }

    try {
        $preview = objectif_sequence_import_preview($conn, $sequenceId, $_FILES['file']['tmp_name']);
        $_SESSION[OBJECTIF_SEQUENCE_IMPORT_SESSION_KEY] = [
            'sequence_id' => $sequenceId,
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'can_import' => $preview['can_import'],
        ];

        objectif_sequence_import_json(['success' => true] + $preview);
    } catch (Throwable $e) {
        unset($_SESSION[OBJECTIF_SEQUENCE_IMPORT_SESSION_KEY]);
        objectif_sequence_import_json(['success' => false, 'message' => 'Fichier illisible ou invalide.'], 400);
    }
}

if ($action === 'import') {
    $preview = $_SESSION[OBJECTIF_SEQUENCE_IMPORT_SESSION_KEY] ?? null;
    if (!$preview || (int) ($preview['sequence_id'] ?? 0) !== $sequenceId) {
        objectif_sequence_import_json(['success' => false, 'message' => 'Prévisualisation expirée. Veuillez prévisualiser à nouveau.'], 400);
    }
    if (empty($preview['can_import'])) {
        objectif_sequence_import_json(['success' => false, 'message' => 'Le fichier contient des erreurs. Aucun import effectué.', 'summary' => $preview['summary']], 422);
    }

    $inserted = 0;
    $ignored = 0;
    $createdRows = [];
    $ordre = objectif_sequence_import_next_order($conn, $sequenceId);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO objectifs_sequences (sequence_id, objectif, volume_horaire, ordre)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($preview['rows'] as $row) {
            if (($row['status_type'] ?? '') !== 'new') {
                $ignored++;
                continue;
            }
            $objectif = $row['objectif'];
            $volume = (float) $row['volume_horaire'];
            $stmt->bind_param('isdi', $sequenceId, $objectif, $volume, $ordre);
            $stmt->execute();
            $createdRows[] = [
                'id' => $stmt->insert_id,
                'objectif' => $objectif,
                'volume_horaire' => $volume,
            ];
            $ordre++;
            $inserted++;
        }

        $stmt->close();
        $conn->commit();
        unset($_SESSION[OBJECTIF_SEQUENCE_IMPORT_SESSION_KEY]);

        objectif_sequence_import_json([
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
        objectif_sequence_import_json(['success' => false, 'message' => "Erreur lors de l'import."], 500);
    }
}

objectif_sequence_import_json(['success' => false, 'message' => 'Action invalide.'], 400);
