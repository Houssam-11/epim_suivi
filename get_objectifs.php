<?php
declare(strict_types=1);
require_once __DIR__ . '/recommendation_api.php';

const CONFIG_OBJECTIF_ID_BASE = 2000000000;

function objectifs_sequences_table_exists(mysqli $conn): bool
{
    foreach (['objectifs_sequences', 'descriptifs_objectifs_sequences'] as $table) {
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$result || $result->num_rows === 0) {
            return false;
        }
    }
    return true;
}

function objectifs_sequences_send(mysqli $conn): bool
{
    recommendation_require_formateur();
    $sequenceId = recommendation_positive_int('sequence_id');
    $limit = recommendation_limit();

    if (!objectifs_sequences_table_exists($conn)) {
        return false;
    }

    $sql = "
        SELECT os.id AS configured_id, os.objectif, os.volume_horaire
        FROM objectifs_sequences os
        WHERE os.sequence_id = ?
          AND EXISTS (
              SELECT 1
              FROM descriptifs_objectifs_sequences dos
              WHERE dos.objectif_sequence_id = os.id
          )
        ORDER BY os.ordre ASC, os.id ASC
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $sequenceId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => CONFIG_OBJECTIF_ID_BASE + (int) $row['configured_id'],
            'text' => $row['objectif'],
            'source' => 'configuration',
            'score' => 1,
            'occurrences' => 1,
            'usages' => 0,
            'volume_horaire' => (float) $row['volume_horaire'],
        ];
    }
    $stmt->close();

    if (!$items) {
        return false;
    }

    $conn->close();
    echo json_encode([
        'data' => $items,
        'meta' => [
            'sequence_id' => $sequenceId,
            'type' => 'objectif',
            'count' => count($items),
            'source' => 'configuration',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

if (objectifs_sequences_send($conn)) {
    exit;
}

recommendation_send($conn, 'objectif');
