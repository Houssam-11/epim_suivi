<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

function recommendation_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function recommendation_require_formateur(): void
{
    auth_require_role('formateur');
}

function recommendation_positive_int(string $name): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 1) {
        recommendation_fail(422, "Parametre invalide : {$name}.");
    }
    return $value;
}

function recommendation_limit(): int
{
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    return max(1, min($limit ?: 10, 25));
}

function recommendation_send(mysqli $conn, string $type, ?string $parentType = null): void
{
    recommendation_require_formateur();
    $sequenceId = recommendation_positive_int('sequence_id');
    $limit = recommendation_limit();

    if ($parentType === null) {
        $sql = "
            SELECT c.id, c.content_text, sc.source,
                   (sc.base_score + LOG10(1 + sc.occurrence_count) + LOG10(1 + c.usage_count)) AS score,
                   sc.occurrence_count, c.usage_count
            FROM recommendation_sequence_contents sc
            INNER JOIN recommendation_contents c ON c.id = sc.content_id
            WHERE sc.sequence_id = ? AND c.content_type = ? AND c.status = 'active'
            ORDER BY score DESC, c.content_text ASC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            recommendation_fail(500, 'Le moteur de recommandation est indisponible.');
        }
        $stmt->bind_param('isi', $sequenceId, $type, $limit);
    } else {
        $parentId = recommendation_positive_int('parent_id');
        $sql = "
            SELECT child.id, child.content_text, links.source,
                   (links.confidence_score + LOG10(1 + links.occurrence_count) + LOG10(1 + child.usage_count)) AS score,
                   links.occurrence_count, child.usage_count
            FROM recommendation_links links
            INNER JOIN recommendation_contents parent ON parent.id = links.parent_content_id
            INNER JOIN recommendation_contents child ON child.id = links.child_content_id
            WHERE links.sequence_id = ?
              AND links.parent_content_id = ?
              AND parent.content_type = ?
              AND child.content_type = ?
              AND child.status = 'active'
            ORDER BY score DESC, child.content_text ASC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            recommendation_fail(500, 'Le moteur de recommandation est indisponible.');
        }
        $stmt->bind_param('iissi', $sequenceId, $parentId, $parentType, $type, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'text' => $row['content_text'],
            'source' => $row['source'],
            'score' => round((float) $row['score'], 4),
            'occurrences' => (int) $row['occurrence_count'],
            'usages' => (int) $row['usage_count'],
        ];
    }
    $stmt->close();
    $conn->close();

    echo json_encode([
        'data' => $items,
        'meta' => [
            'sequence_id' => $sequenceId,
            'type' => $type,
            'count' => count($items),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
