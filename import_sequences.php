<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/excel_import_helper.php';

const SEQUENCE_IMPORT_SESSION_KEY = 'sequence_import_preview';

function sequence_import_columns(): array
{
    return [
        'intitule' => 'Intitulé de la séquence',
        'volume_horaire' => 'Volume horaire',
        'objectif' => 'Objectif général',
    ];
}

function sequence_import_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function sequence_import_unit(mysqli $conn, int $uniteId): ?array
{
    $stmt = $conn->prepare(
        'SELECT uf.id, uf.masse_horaire,
                CASE WHEN COALESCE(uf.is_archived, 0) = 1 OR COALESCE(f.is_archived, 0) = 1 THEN 1 ELSE 0 END AS is_archived
         FROM unites_de_formation uf
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE uf.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $uniteId);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $unit;
}

function sequence_import_existing_sequences(mysqli $conn, int $uniteId): array
{
    $stmt = $conn->prepare('SELECT intitule FROM sequences_pedagogiques WHERE unite_id = ? AND COALESCE(is_archived, 0) = 0');
    $stmt->bind_param('i', $uniteId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[excel_import_normalize_key($row['intitule'])] = true;
    }
    $stmt->close();

    return $existing;
}

function sequence_import_current_volume(mysqli $conn, int $uniteId): float
{
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(volume_horaire), 0) AS total
         FROM sequences_pedagogiques
         WHERE unite_id = ? AND COALESCE(is_archived, 0) = 0'
    );
    $stmt->bind_param('i', $uniteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float) ($row['total'] ?? 0);
}

function sequence_import_preview(mysqli $conn, int $uniteId, string $tmpPath): array
{
    $unit = sequence_import_unit($conn, $uniteId);
    if (!$unit) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 1],
            'errors' => ['Unité de formation introuvable.'],
            'can_import' => false,
        ];
    }
    if ((int) $unit['is_archived'] === 1) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 1],
            'errors' => ['Cette unité est archivée. Aucune nouvelle séquence ne peut y être ajoutée.'],
            'can_import' => false,
        ];
    }

    $loaded = excel_import_load_rows($tmpPath, sequence_import_columns());
    if ($loaded['errors']) {
        return [
            'rows' => [],
            'summary' => ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => count($loaded['errors'])],
            'errors' => $loaded['errors'],
            'can_import' => false,
        ];
    }

    $existingSequences = sequence_import_existing_sequences($conn, $uniteId);
    $seenInFile = [];
    $previewRows = [];
    $summary = ['read' => 0, 'new' => 0, 'existing' => 0, 'errors' => 0];
    $currentVolume = sequence_import_current_volume($conn, $uniteId);
    $plannedVolume = $currentVolume;
    $massHoraire = (float) ($unit['masse_horaire'] ?? 0);

    foreach ($loaded['records'] as $record) {
        $summary['read']++;
        $errors = [];
        $intitule = $record['intitule'];
        $objectif = $record['objectif'];
        $volume = str_replace(',', '.', $record['volume_horaire']);

        foreach (sequence_import_columns() as $key => $label) {
            if (trim((string) $record[$key]) === '') {
                $errors[] = "{$label} est obligatoire.";
            } elseif (!mb_check_encoding((string) $record[$key], 'UTF-8')) {
                $errors[] = "{$label} doit être en UTF-8.";
            }
        }

        if ($volume === '' || !is_numeric($volume) || (float) $volume <= 0) {
            $errors[] = 'Volume horaire doit être numérique.';
        }

        $sequenceKey = excel_import_normalize_key($intitule);
        $status = 'Nouvelle séquence';
        $statusType = 'new';

        if (!$errors && isset($existingSequences[$sequenceKey])) {
            $status = 'Déjà existante';
            $statusType = 'existing';
        } elseif (!$errors && isset($seenInFile[$sequenceKey])) {
            $errors[] = 'Doublon dans le fichier.';
        }

        if (!$errors && $statusType === 'new') {
            $nextVolume = $plannedVolume + (float) $volume;
            if ($massHoraire > 0 && $nextVolume > $massHoraire) {
                $errors[] = "Le total des volumes horaires dépasse la masse horaire de l'unité.";
            } else {
                $plannedVolume = $nextVolume;
            }
        }

        if ($errors) {
            $status = 'Erreur';
            $statusType = 'error';
            $summary['errors']++;
        } elseif ($statusType === 'existing') {
            $summary['existing']++;
        } else {
            $summary['new']++;
            $seenInFile[$sequenceKey] = true;
        }

        $previewRows[] = [
            'row' => (int) $record['_row'],
            'intitule' => $intitule,
            'volume_horaire' => $volume,
            'objectif' => $objectif,
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
    excel_import_output_template('modele_import_sequences.xlsx', array_values(sequence_import_columns()));
}

$uniteId = filter_input(INPUT_POST, 'unite_id', FILTER_VALIDATE_INT) ?: 0;
if ($uniteId <= 0 || !sequence_import_unit($conn, $uniteId)) {
    sequence_import_json(['success' => false, 'message' => 'Unité invalide.'], 400);
}

if ($action === 'preview') {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        sequence_import_json(['success' => false, 'message' => 'Veuillez sélectionner un fichier Excel.'], 400);
    }

    try {
        $preview = sequence_import_preview($conn, $uniteId, $_FILES['file']['tmp_name']);
        $_SESSION[SEQUENCE_IMPORT_SESSION_KEY] = [
            'unite_id' => $uniteId,
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'can_import' => $preview['can_import'],
        ];

        sequence_import_json(['success' => true] + $preview);
    } catch (Throwable $e) {
        unset($_SESSION[SEQUENCE_IMPORT_SESSION_KEY]);
        sequence_import_json(['success' => false, 'message' => 'Fichier illisible ou invalide.'], 400);
    }
}

if ($action === 'import') {
    $preview = $_SESSION[SEQUENCE_IMPORT_SESSION_KEY] ?? null;
    if (!$preview || (int) ($preview['unite_id'] ?? 0) !== $uniteId) {
        sequence_import_json(['success' => false, 'message' => 'Prévisualisation expirée. Veuillez prévisualiser à nouveau.'], 400);
    }

    if (empty($preview['can_import'])) {
        sequence_import_json(['success' => false, 'message' => 'Le fichier contient des erreurs. Aucun import effectué.', 'summary' => $preview['summary']], 422);
    }

    $inserted = 0;
    $ignored = 0;
    $createdRows = [];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO sequences_pedagogiques (unite_id, intitule, objectif, volume_horaire)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($preview['rows'] as $row) {
            if (($row['status_type'] ?? '') !== 'new') {
                $ignored++;
                continue;
            }

            $intitule = $row['intitule'];
            $objectif = $row['objectif'];
            $volume = (int) $row['volume_horaire'];
            $stmt->bind_param('issi', $uniteId, $intitule, $objectif, $volume);
            $stmt->execute();
            $createdRows[] = [
                'id' => $stmt->insert_id,
                'intitule' => $intitule,
                'objectif' => $objectif,
                'volume_horaire' => $volume,
            ];
            $inserted++;
        }

        $stmt->close();
        $conn->commit();
        unset($_SESSION[SEQUENCE_IMPORT_SESSION_KEY]);

        sequence_import_json([
            'success' => true,
            'message' => 'Import terminé.',
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
        sequence_import_json(['success' => false, 'message' => "Erreur lors de l'import."], 500);
    }
}

sequence_import_json(['success' => false, 'message' => 'Action invalide.'], 400);
