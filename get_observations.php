<?php
declare(strict_types=1);

require_once __DIR__ . '/recommendation_api.php';
require_once __DIR__ . '/description_metadata_lib.php';

const OBSERVATION_VIRTUAL_ID_BASE = 900000000;
const CONFIG_DESCRIPTION_ID_BASE = 1800000000;

function observation_de_subject(string $subject): string
{
    $subject = dm_normalize($subject);
    if ($subject === '') {
        return 'des notions abordées';
    }
    if (preg_match('/^[aeiouyàâäéèêëîïôöùûü]/iu', $subject)) {
        return "d'" . $subject;
    }
    if (preg_match('/s$/iu', $subject)) {
        return 'des ' . $subject;
    }
    return 'de ' . $subject;
}

function observation_templates(string $family, string $subject): array
{
    $deSubject = observation_de_subject($subject);
    $templates = [
        'assimilation' => [
            ['level' => 'bien_assimile', 'text' => 'Les stagiaires ont bien assimilé %s', 'subject' => $subject],
            ['level' => 'partiellement_assimile', 'text' => 'Les stagiaires ont partiellement assimilé %s', 'subject' => $subject],
            ['level' => 'en_cours_assimilation', 'text' => "Les stagiaires sont en cours d'assimilation %s", 'subject' => $deSubject],
            ['level' => 'non_assimile', 'text' => "Les stagiaires n'ont pas assimilé %s", 'subject' => $subject],
        ],
        'maitrise' => [
            ['level' => 'bien_assimile', 'text' => 'Bonne maîtrise %s', 'subject' => $deSubject],
            ['level' => 'partiellement_assimile', 'text' => 'Maîtrise partielle %s', 'subject' => $deSubject],
            ['level' => 'en_cours_assimilation', 'text' => 'Difficultés rencontrées dans %s', 'subject' => str_starts_with($subject, 'étapes') ? 'les ' . $subject : $subject],
            ['level' => 'non_assimile', 'text' => '%s non maîtrisé', 'subject' => $subject],
        ],
        'participation' => [
            ['level' => 'bien_assimile', 'text' => 'Participation active concernant %s', 'subject' => $subject],
            ['level' => 'partiellement_assimile', 'text' => 'Participation satisfaisante concernant %s', 'subject' => $subject],
            ['level' => 'en_cours_assimilation', 'text' => 'Participation limitée concernant %s', 'subject' => $subject],
            ['level' => 'non_assimile', 'text' => 'Participation insuffisante concernant %s', 'subject' => $subject],
        ],
        'realisation' => [
            ['level' => 'bien_assimile', 'text' => 'Objectif atteint concernant %s', 'subject' => $subject],
            ['level' => 'partiellement_assimile', 'text' => 'Objectif partiellement atteint concernant %s', 'subject' => $subject],
            ['level' => 'en_cours_assimilation', 'text' => "Objectif en cours d'atteinte concernant %s", 'subject' => $subject],
            ['level' => 'non_assimile', 'text' => 'Objectif non atteint concernant %s', 'subject' => $subject],
        ],
    ];

    return $templates[$family] ?? $templates['assimilation'];
}

recommendation_require_formateur();
$sequenceId = recommendation_positive_int('sequence_id');
$descriptionId = recommendation_positive_int('parent_id');
$limit = recommendation_limit();

dm_create_table($conn);

if ($descriptionId >= CONFIG_DESCRIPTION_ID_BASE) {
    $configuredDescriptionId = $descriptionId - CONFIG_DESCRIPTION_ID_BASE;
    $sql = "
        SELECT d.description, d.sujet, sq.intitule AS sequence_nom, uf.intitule AS unite_nom, f.nom AS filiere_nom,
               o.objectif AS objective_text
        FROM descriptifs_objectifs_sequences d
        INNER JOIN objectifs_sequences o ON o.id = d.objectif_sequence_id
        INNER JOIN sequences_pedagogiques sq ON sq.id = o.sequence_id
        LEFT JOIN unites_de_formation uf ON uf.id = sq.unite_id
        LEFT JOIN filieres f ON f.id = uf.filiere_id
        WHERE d.id = ? AND o.sequence_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        recommendation_fail(500, 'La génération des observations est indisponible.');
    }
    $stmt->bind_param('ii', $configuredDescriptionId, $sequenceId);
    $stmt->execute();
    $configuredRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($configuredRow) {
        $subject = dm_normalize((string) $configuredRow['sujet']);
        $context = trim(($configuredRow['filiere_nom'] ?? '') . ' ' . ($configuredRow['unite_nom'] ?? '') . ' ' . ($configuredRow['sequence_nom'] ?? ''));
        $family = dm_family_from_description((string) $configuredRow['description'], $context . ' ' . (string) ($configuredRow['objective_text'] ?? ''));
        $items = [];
        foreach (array_slice(observation_templates($family, $subject), 0, $limit) as $index => $template) {
            $items[] = [
                'id' => OBSERVATION_VIRTUAL_ID_BASE + ($descriptionId * 10) + $index + 1,
                'text' => sprintf($template['text'], $template['subject']),
                'source' => 'configuration_' . $family,
                'score' => round(1 - ($index * 0.05), 4),
                'occurrences' => 0,
                'usages' => 0,
                'generated' => true,
                'family' => $family,
                'subject' => $subject,
                'level' => $template['level'],
            ];
        }

        $conn->close();
        echo json_encode([
            'data' => $items,
            'meta' => [
                'sequence_id' => $sequenceId,
                'type' => 'observation',
                'count' => count($items),
                'generated' => true,
                'family' => $family,
                'subject' => $subject,
                'source' => 'configuration',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$sql = "
    SELECT c.content_text, sq.intitule AS sequence_nom, uf.intitule AS unite_nom, f.nom AS filiere_nom,
           dm.sujet_pedagogique, dm.famille_pedagogique,
           GROUP_CONCAT(DISTINCT parent.content_text SEPARATOR ' | ') AS objective_text
    FROM recommendation_contents c
    LEFT JOIN sequences_pedagogiques sq ON sq.id = ?
    LEFT JOIN unites_de_formation uf ON uf.id = sq.unite_id
    LEFT JOIN filieres f ON f.id = uf.filiere_id
    LEFT JOIN description_metadata dm ON dm.description_id = c.id
    LEFT JOIN recommendation_links links ON links.child_content_id = c.id
    LEFT JOIN recommendation_contents parent ON parent.id = links.parent_content_id AND parent.content_type = 'objectif'
    WHERE c.id = ? AND c.content_type = 'description' AND c.status = 'active'
    GROUP BY c.id, c.content_text, sq.intitule, uf.intitule, f.nom, dm.sujet_pedagogique, dm.famille_pedagogique
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    recommendation_fail(500, 'La génération des observations est indisponible.');
}
$stmt->bind_param('ii', $sequenceId, $descriptionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $conn->close();
    echo json_encode([
        'data' => [],
        'meta' => ['sequence_id' => $sequenceId, 'type' => 'observation', 'count' => 0],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$subject = dm_normalize((string) ($row['sujet_pedagogique'] ?? ''));
$family = dm_normalize((string) ($row['famille_pedagogique'] ?? ''));
if ($subject === '' || $family === '') {
    $context = trim(($row['filiere_nom'] ?? '') . ' ' . ($row['unite_nom'] ?? '') . ' ' . ($row['sequence_nom'] ?? ''));
    $metadata = dm_generate_metadata((string) $row['content_text'], $context, (string) ($row['objective_text'] ?? ''));
    $subject = $metadata['sujet_pedagogique'];
    $family = $metadata['famille_pedagogique'];
    dm_upsert($conn, $descriptionId, $subject, $family, 'auto_runtime');
}

$items = [];
foreach (array_slice(observation_templates($family, $subject), 0, $limit) as $index => $template) {
    $items[] = [
        'id' => OBSERVATION_VIRTUAL_ID_BASE + ($descriptionId * 10) + $index + 1,
        'text' => sprintf($template['text'], $template['subject']),
        'source' => 'metadata_' . $family,
        'score' => round(1 - ($index * 0.05), 4),
        'occurrences' => 0,
        'usages' => 0,
        'generated' => true,
        'family' => $family,
        'subject' => $subject,
        'level' => $template['level'],
    ];
}

$conn->close();

echo json_encode([
    'data' => $items,
    'meta' => [
        'sequence_id' => $sequenceId,
        'type' => 'observation',
        'count' => count($items),
        'generated' => true,
        'family' => $family,
        'subject' => $subject,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
