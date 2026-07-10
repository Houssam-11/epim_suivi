<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../description_metadata_lib.php';

function csv_metadata_files(): array
{
    return [
        __DIR__ . '/../suivi pédagogique(Développement informatique).csv',
        __DIR__ . '/../suivi pédagogique(Gestion des entreprises).csv',
        __DIR__ . '/../suivi pédagogique(Infographiste).csv',
    ];
}

function normalized_key(string $text): string
{
    return mb_strtolower(dm_normalize($text), 'UTF-8');
}

dm_create_table($conn);

$descriptions = [];
$result = $conn->query(
    "SELECT c.id, c.content_text,
            GROUP_CONCAT(DISTINCT CONCAT_WS(' ', f.nom, uf.intitule, sq.intitule) SEPARATOR ' ') AS context_text,
            GROUP_CONCAT(DISTINCT parent.content_text SEPARATOR ' | ') AS objective_text
     FROM recommendation_contents c
     LEFT JOIN recommendation_sequence_contents sc ON sc.content_id = c.id
     LEFT JOIN sequences_pedagogiques sq ON sq.id = sc.sequence_id
     LEFT JOIN unites_de_formation uf ON uf.id = sq.unite_id
     LEFT JOIN filieres f ON f.id = uf.filiere_id
     LEFT JOIN recommendation_links links ON links.child_content_id = c.id
     LEFT JOIN recommendation_contents parent ON parent.id = links.parent_content_id AND parent.content_type = 'objectif'
     WHERE c.content_type = 'description' AND c.status = 'active'
     GROUP BY c.id, c.content_text"
);

if (!$result) {
    throw new RuntimeException('Impossible de lire les descriptions : ' . $conn->error);
}

while ($row = $result->fetch_assoc()) {
    $id = (int) $row['id'];
    $description = (string) $row['content_text'];
    $context = (string) ($row['context_text'] ?? '');
    $objective = (string) ($row['objective_text'] ?? '');
    $metadata = dm_generate_metadata($description, $context, $objective);

    dm_upsert($conn, $id, $metadata['sujet_pedagogique'], $metadata['famille_pedagogique'], 'auto_db');
    $descriptions[normalized_key($description)][] = [
        'id' => $id,
        'description' => $description,
    ];
}

$csvMatched = 0;
$csvUnmatched = 0;
foreach (csv_metadata_files() as $csvPath) {
    if (!is_file($csvPath)) {
        continue;
    }

    $handle = fopen($csvPath, 'rb');
    if (!$handle) {
        continue;
    }

    $header = fgetcsv($handle, 0, ';');
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $description = dm_normalize((string) ($row[3] ?? ''));
        if ($description === '') {
            continue;
        }

        $key = normalized_key($description);
        if (!isset($descriptions[$key])) {
            $csvUnmatched++;
            continue;
        }

        $objective = dm_normalize((string) ($row[2] ?? ''));
        $context = trim(($row[0] ?? '') . ' ' . ($row[1] ?? '') . ' ' . $objective);
        $metadata = dm_generate_metadata($description, $context, $objective);
        foreach ($descriptions[$key] as $match) {
            dm_upsert($conn, (int) $match['id'], $metadata['sujet_pedagogique'], $metadata['famille_pedagogique'], 'auto_csv');
            $csvMatched++;
        }
    }
    fclose($handle);
}

$countResult = $conn->query('SELECT COUNT(*) AS total FROM description_metadata');
$total = $countResult ? (int) $countResult->fetch_assoc()['total'] : 0;
$conn->close();

echo "description_metadata generated\n";
echo "total={$total}\n";
echo "csv_matched={$csvMatched}\n";
echo "csv_unmatched={$csvUnmatched}\n";

