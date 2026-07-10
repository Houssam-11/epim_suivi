<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';

function descriptif_objectif_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function descriptif_objectif_exists(mysqli $conn, int $objectifId): bool
{
    $stmt = $conn->prepare(
        'SELECT o.id
         FROM objectifs_sequences o
         INNER JOIN sequences_pedagogiques seq ON seq.id = o.sequence_id
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE o.id = ? AND COALESCE(f.is_archived, 0) = 0
         LIMIT 1'
    );
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function descriptif_objectif_editable(mysqli $conn, int $descriptifId): bool
{
    $stmt = $conn->prepare(
        'SELECT d.id
         FROM descriptifs_objectifs_sequences d
         INNER JOIN objectifs_sequences o ON o.id = d.objectif_sequence_id
         INNER JOIN sequences_pedagogiques seq ON seq.id = o.sequence_id
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE d.id = ? AND COALESCE(f.is_archived, 0) = 0
         LIMIT 1'
    );
    $stmt->bind_param('i', $descriptifId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function descriptif_objectif_next_order(mysqli $conn, int $objectifId): int
{
    $stmt = $conn->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 AS next_order FROM descriptifs_objectifs_sequences WHERE objectif_sequence_id = ?');
    $stmt->bind_param('i', $objectifId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['next_order'] ?? 1);
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $objectifId = filter_input(INPUT_POST, 'objectif_id', FILTER_VALIDATE_INT) ?: 0;
    $description = trim((string) ($_POST['description'] ?? ''));
    $sujet = trim((string) ($_POST['sujet'] ?? ''));

    if ($objectifId < 1 || !descriptif_objectif_exists($conn, $objectifId)) {
        descriptif_objectif_json(['success' => false, 'message' => 'Objectif invalide.'], 400);
    }
    if ($description === '' || $sujet === '') {
        descriptif_objectif_json(['success' => false, 'message' => 'La description et le sujet sont obligatoires.'], 422);
    }
    if (mb_strlen($sujet, 'UTF-8') > 100) {
        descriptif_objectif_json(['success' => false, 'message' => 'Le sujet ne doit pas dépasser 100 caractères.'], 422);
    }

    $ordre = descriptif_objectif_next_order($conn, $objectifId);
    $stmt = $conn->prepare(
        'INSERT INTO descriptifs_objectifs_sequences (objectif_sequence_id, description, sujet, ordre)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('issi', $objectifId, $description, $sujet, $ordre);
    if (!$stmt->execute()) {
        descriptif_objectif_json(['success' => false, 'message' => "Erreur lors de l'ajout du descriptif."], 500);
    }

    descriptif_objectif_json([
        'success' => true,
        'row' => [
            'id' => $stmt->insert_id,
            'objectif_id' => $objectifId,
            'description' => $description,
            'sujet' => $sujet,
        ],
    ]);
}

if ($action === 'update') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $description = trim((string) ($_POST['description'] ?? ''));
    $sujet = trim((string) ($_POST['sujet'] ?? ''));

    if ($id < 1 || !descriptif_objectif_editable($conn, $id) || $description === '' || $sujet === '') {
        descriptif_objectif_json(['success' => false, 'message' => 'Données invalides.'], 422);
    }
    if (mb_strlen($sujet, 'UTF-8') > 100) {
        descriptif_objectif_json(['success' => false, 'message' => 'Le sujet ne doit pas dépasser 100 caractères.'], 422);
    }

    $stmt = $conn->prepare('UPDATE descriptifs_objectifs_sequences SET description = ?, sujet = ? WHERE id = ?');
    $stmt->bind_param('ssi', $description, $sujet, $id);
    if (!$stmt->execute()) {
        descriptif_objectif_json(['success' => false, 'message' => 'Erreur lors de la modification.'], 500);
    }

    descriptif_objectif_json(['success' => true]);
}

if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    if ($id < 1 || !descriptif_objectif_editable($conn, $id)) {
        descriptif_objectif_json(['success' => false, 'message' => 'Descriptif invalide.'], 422);
    }

    $stmt = $conn->prepare('DELETE FROM descriptifs_objectifs_sequences WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        descriptif_objectif_json(['success' => false, 'message' => 'Erreur lors de la suppression.'], 500);
    }

    descriptif_objectif_json(['success' => true]);
}

descriptif_objectif_json(['success' => false, 'message' => 'Action invalide.'], 400);
