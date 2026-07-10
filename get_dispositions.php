<?php
declare(strict_types=1);

require_once __DIR__ . '/recommendation_api.php';

const DISPOSITION_VIRTUAL_ID_BASE = 950000000;

function disposition_level_from_observation_id(int $observationId): string
{
    $index = $observationId % 10;
    return match ($index) {
        1 => 'bien_assimile',
        2 => 'partiellement_assimile',
        3 => 'en_cours_assimilation',
        4 => 'non_assimile',
        default => 'partiellement_assimile',
    };
}

function disposition_level(): string
{
    $allowed = ['bien_assimile', 'partiellement_assimile', 'en_cours_assimilation', 'non_assimile'];
    $level = trim((string) ($_GET['observation_level'] ?? ''));
    if (in_array($level, $allowed, true)) {
        return $level;
    }

    return disposition_level_from_observation_id(recommendation_positive_int('parent_id'));
}

function disposition_templates(string $level): array
{
    $templates = [
        'bien_assimile' => [
            'Passer à la séquence suivante',
            'Introduire un nouveau concept',
            'Démarrer la partie suivante du programme',
            'Renforcement léger des acquis',
        ],
        'partiellement_assimile' => [
            'Prévoir des exercices complémentaires',
            'Consolider les acquis',
            'Réaliser des applications supplémentaires',
            'Renforcer la pratique',
        ],
        'en_cours_assimilation' => [
            'Reprendre les notions essentielles',
            'Prévoir une séance de consolidation',
            "Renforcer l'accompagnement pédagogique",
            'Ajouter des exercices guidés',
        ],
        'non_assimile' => [
            'Revoir les fondamentaux',
            'Reprogrammer la séquence',
            'Prévoir un accompagnement renforcé',
            'Reprendre les objectifs non atteints',
        ],
    ];

    return $templates[$level] ?? $templates['partiellement_assimile'];
}

recommendation_require_formateur();
$sequenceId = recommendation_positive_int('sequence_id');
$parentId = recommendation_positive_int('parent_id');
$limit = recommendation_limit();
$level = disposition_level();

$items = [];
foreach (array_slice(disposition_templates($level), 0, $limit) as $index => $text) {
    $items[] = [
        'id' => DISPOSITION_VIRTUAL_ID_BASE + ($parentId * 10) + $index + 1,
        'text' => $text,
        'source' => 'dynamic_' . $level,
        'score' => round(1 - ($index * 0.05), 4),
        'occurrences' => 0,
        'usages' => 0,
        'generated' => true,
        'level' => $level,
    ];
}

$conn->close();

echo json_encode([
    'data' => $items,
    'meta' => [
        'sequence_id' => $sequenceId,
        'type' => 'disposition',
        'count' => count($items),
        'generated' => true,
        'level' => $level,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
