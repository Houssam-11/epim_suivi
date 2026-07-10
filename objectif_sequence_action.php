<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';

function objectif_sequence_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function objectif_sequence_require_sequence(mysqli $conn, int $sequenceId): bool
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

function objectif_sequence_require_objectif_editable(mysqli $conn, int $objectifId): bool
{
    $stmt = $conn->prepare(
        'SELECT o.id
         FROM objectifs_sequences o
         INNER JOIN sequences_pedagogiques seq ON seq.id = o.sequence_id
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE o.id = ?
           AND COALESCE(seq.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         LIMIT 1'
    );
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function objectif_sequence_next_order(mysqli $conn, int $sequenceId): int
{
    $stmt = $conn->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 AS next_order FROM objectifs_sequences WHERE sequence_id = ?');
    $stmt->bind_param('i', $sequenceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['next_order'] ?? 1);
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $sequenceId = filter_input(INPUT_POST, 'sequence_id', FILTER_VALIDATE_INT) ?: 0;
    $objectif = trim((string) ($_POST['objectif'] ?? ''));
    $volumeInput = str_replace(',', '.', trim((string) ($_POST['volume_horaire'] ?? '')));

    if ($sequenceId < 1 || !objectif_sequence_require_sequence($conn, $sequenceId)) {
        objectif_sequence_json(['success' => false, 'message' => 'Séquence invalide.'], 400);
    }
    if ($objectif === '' || $volumeInput === '' || !is_numeric($volumeInput) || (float) $volumeInput <= 0) {
        objectif_sequence_json(['success' => false, 'message' => 'Tous les champs sont obligatoires et le volume doit être numérique.'], 422);
    }

    $ordre = objectif_sequence_next_order($conn, $sequenceId);
    $volume = (float) $volumeInput;
    $stmt = $conn->prepare(
        'INSERT INTO objectifs_sequences (sequence_id, objectif, volume_horaire, ordre)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isdi', $sequenceId, $objectif, $volume, $ordre);
    if (!$stmt->execute()) {
        objectif_sequence_json(['success' => false, 'message' => "Erreur lors de l'ajout de l'objectif."], 500);
    }

    objectif_sequence_json([
        'success' => true,
        'row' => [
            'id' => $stmt->insert_id,
            'objectif' => $objectif,
            'volume_horaire' => $volume,
        ],
    ]);
}

if ($action === 'update') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $objectif = trim((string) ($_POST['objectif'] ?? ''));
    $volumeInput = str_replace(',', '.', trim((string) ($_POST['volume_horaire'] ?? '')));

    if ($id < 1 || !objectif_sequence_require_objectif_editable($conn, $id) || $objectif === '' || $volumeInput === '' || !is_numeric($volumeInput) || (float) $volumeInput <= 0) {
        objectif_sequence_json(['success' => false, 'message' => 'Données invalides.'], 422);
    }

    $volume = (float) $volumeInput;
    $stmt = $conn->prepare('UPDATE objectifs_sequences SET objectif = ?, volume_horaire = ? WHERE id = ?');
    $stmt->bind_param('sdi', $objectif, $volume, $id);
    if (!$stmt->execute()) {
        objectif_sequence_json(['success' => false, 'message' => "Erreur lors de la modification."], 500);
    }

    objectif_sequence_json(['success' => true]);
}

if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    if ($id < 1 || !objectif_sequence_require_objectif_editable($conn, $id)) {
        objectif_sequence_json(['success' => false, 'message' => 'Objectif invalide.'], 422);
    }

    $stmt = $conn->prepare('DELETE FROM objectifs_sequences WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        objectif_sequence_json(['success' => false, 'message' => "Erreur lors de la suppression."], 500);
    }

    objectif_sequence_json(['success' => true]);
}

objectif_sequence_json(['success' => false, 'message' => 'Action invalide.'], 400);
