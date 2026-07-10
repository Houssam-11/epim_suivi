<?php
declare(strict_types=1);
require_once __DIR__ . '/recommendation_api.php';

const CONFIG_OBJECTIF_ID_BASE = 2000000000;
const CONFIG_DESCRIPTION_ID_BASE = 1800000000;

function configured_descriptions_tables_exist(mysqli $conn): bool
{
    foreach (['objectifs_sequences', 'descriptifs_objectifs_sequences'] as $table) {
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$result || $result->num_rows === 0) {
            return false;
        }
    }
    return true;
}

function configured_descriptions_send(mysqli $conn): bool
{
    recommendation_require_formateur();
    $sequenceId = recommendation_positive_int('sequence_id');
    $parentId = recommendation_positive_int('parent_id');
    $limit = recommendation_limit();

    if ($parentId < CONFIG_OBJECTIF_ID_BASE) {
        return false;
    }

    $objectifId = $parentId - CONFIG_OBJECTIF_ID_BASE;
    if ($objectifId < 1) {
        return false;
    }

    if (!configured_descriptions_tables_exist($conn)) {
        return false;
    }

    $sql = "
        SELECT d.id, d.description, d.sujet
        FROM descriptifs_objectifs_sequences d
        INNER JOIN objectifs_sequences o ON o.id = d.objectif_sequence_id
        WHERE d.objectif_sequence_id = ?
          AND o.sequence_id = ?
        ORDER BY d.ordre ASC, d.id ASC
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $objectifId, $sequenceId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => CONFIG_DESCRIPTION_ID_BASE + (int) $row['id'],
            'text' => $row['description'],
            'source' => 'configuration',
            'score' => 1,
            'occurrences' => 1,
            'usages' => 0,
            'subject' => $row['sujet'],
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
            'type' => 'description',
            'count' => count($items),
            'source' => 'configuration',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

if (configured_descriptions_send($conn)) {
    exit;
}

recommendation_send($conn, 'description', 'objectif');
