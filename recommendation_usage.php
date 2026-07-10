<?php
declare(strict_types=1);

function record_recommendation_usage(
    mysqli $conn,
    int $seanceId,
    int $sequenceId,
    array $submittedValues,
    array $recommendedIds
): void {
    $types = ['objectif', 'description', 'observation', 'disposition'];
    $select = $conn->prepare(
        "SELECT content_text FROM recommendation_contents WHERE id = ? AND content_type = ? AND status = 'active'"
    );
    $insert = $conn->prepare(
        'INSERT INTO recommendation_usage_events
         (seance_id, sequence_id, content_type, recommended_content_id, submitted_text, action_type)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $increment = $conn->prepare(
        'UPDATE recommendation_contents SET usage_count = usage_count + 1 WHERE id = ?'
    );

    if (!$select || !$insert || !$increment) {
        throw new RuntimeException('Impossible de preparer le suivi des recommandations.');
    }

    foreach ($types as $type) {
        $submitted = trim((string) ($submittedValues[$type] ?? ''));
        $recommendedId = filter_var($recommendedIds[$type] ?? null, FILTER_VALIDATE_INT) ?: null;
        $action = 'custom';

        if ($recommendedId !== null) {
            $select->bind_param('is', $recommendedId, $type);
            $select->execute();
            $result = $select->get_result();
            $recommended = $result->fetch_assoc();
            if ($recommended) {
                $action = trim($recommended['content_text']) === $submitted ? 'accepted' : 'modified';
                $increment->bind_param('i', $recommendedId);
                $increment->execute();
            } else {
                $recommendedId = null;
            }
        }

        $insert->bind_param(
            'iisiss',
            $seanceId,
            $sequenceId,
            $type,
            $recommendedId,
            $submitted,
            $action
        );
        $insert->execute();
    }

    $select->close();
    $insert->close();
    $increment->close();
}
