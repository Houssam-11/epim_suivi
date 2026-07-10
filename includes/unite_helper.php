<?php
declare(strict_types=1);

const TYPE_UNITE_PEDAGOGIQUE = 'pedagogique';
const TYPE_UNITE_STAGE = 'stage';

function unite_column_exists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'unites_de_formation'
          AND column_name = ?
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count > 0;
}

function unite_type_options(): array
{
    return [
        TYPE_UNITE_PEDAGOGIQUE => 'Unité pédagogique',
        TYPE_UNITE_STAGE => 'Stage',
    ];
}

function unite_type_is_valid($value): bool
{
    return array_key_exists(trim((string) $value), unite_type_options());
}

function unite_normalize_type($value): string
{
    $type = trim((string) $value);

    return unite_type_is_valid($type) ? $type : TYPE_UNITE_PEDAGOGIQUE;
}

function unite_type_label($value): string
{
    $type = unite_normalize_type($value);
    $options = unite_type_options();

    return $options[$type];
}

function unite_annees_formation_options(): array
{
    return [1, 2, 3, 4, 5];
}

function unite_normalize_annee_formation($value): int
{
    $annee = filter_var($value, FILTER_VALIDATE_INT);
    return $annee && $annee > 0 ? (int) $annee : 0;
}

function unite_annee_formation_label($value): string
{
    $annee = unite_normalize_annee_formation($value);
    if ($annee <= 0) {
        return '-';
    }

    return $annee . ($annee === 1 ? 'ère année' : 'ème année');
}

function unite_ensure_semestre_column(mysqli $conn): void
{
    if (!unite_column_exists($conn, 'semestre')) {
        $conn->query("ALTER TABLE unites_de_formation ADD COLUMN semestre TINYINT NOT NULL DEFAULT 1 AFTER masse_horaire");
        $conn->query("CREATE INDEX idx_unites_semestre ON unites_de_formation (semestre)");
    }
}

function unite_ensure_type_column(mysqli $conn): void
{
    if (!unite_column_exists($conn, 'type_unite')) {
        $conn->query("ALTER TABLE unites_de_formation ADD COLUMN type_unite VARCHAR(30) NOT NULL DEFAULT '" . TYPE_UNITE_PEDAGOGIQUE . "' AFTER semestre");
        $conn->query("UPDATE unites_de_formation SET type_unite = '" . TYPE_UNITE_PEDAGOGIQUE . "' WHERE type_unite IS NULL OR type_unite = ''");
        $conn->query("CREATE INDEX idx_unites_type_unite ON unites_de_formation (type_unite)");
    }
}

function unite_normalize_mapping_key(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = str_replace(["\xc2\xa0", "\u{00A0}", '’', '`'], [' ', ' ', "'", "'"], $value);
    $value = strtr($value, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ]);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii !== false ? $ascii : $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    $value = preg_replace('/^uf\s+\d+\s+\d+\s+/', '', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function unite_annee_formation_mapping(): array
{
    $mapping = [
        'developpement informatique' => [
            1 => [
                'Métier et formation',
                "L’entreprise et son environnement",
                "Notions de mathématiques appliquées à l'informatique",
                'Gestion du temps',
                'Veille technologique',
                'Production de documents',
                'Communication interpersonnelle',
                "Logiciels d’application",
                'Programmation événementielle',
                'Techniques de programmation structurée',
                'Langage de programmation structurée',
                'Programmation orientée objet',
                "Conception et modélisation d’un système d’information",
                "Installation d’un poste informatique",
                'Communication en anglais dans un contexte de travail',
                'Assistance technique à la clientèle',
                'Soutien technique en milieu de travail (Stage I)',
            ],
            2 => [
                'Système de gestion de base de donnée I',
                'Analyse et conception orientée objet',
                'Programmation client-serveur',
                "Déploiement d’application",
                'Introduction aux réseaux informatiques',
                'Système de gestion de base de donnée II',
                'Applications hypermédias',
                'Programmation de sites web dynamiques',
                'Initiation à la gestion de projets informatiques',
                "Projet de conception de fin d’études",
                "Recherche d’emploi",
                'Intégration au milieu du travail (Stage II)',
            ],
        ],
        'gestion des entreprises' => [
            1 => [
                'UF 1.1 Comptabilité générale',
                'UF 1.2 Organisation des entreprises',
                'UF 1.3 Economie générale',
                'UF 1.4 Bureautique',
                'UF 1.5 Statistique',
                'UF 1.6 Mathématiques financières',
                'UF 1.7 Marketing fondamental',
                'UF 1.8 Droit civil et droit commercial',
                'UF 1.9 Communication en Français',
                'UF 1.10 Communication en Anglais',
                'UF 1.11 Stage en Entreprise',
            ],
            2 => [
                'UF 2.1 Comptabilité Analytique',
                'UF 2.2 Gestion Financière',
                'UF 2.3 Gestion de la Production',
                'UF 2.4 Gestion des Stocks',
                'UF 2.5 Contrôle de Gestion',
                'UF 2.6 Comptabilité des Sociétés',
                'UF 2.7 Gestion des Ressources Humaines',
                'UF 2.8 Fiscalité des Entreprises',
                'UF 2.9 Législation du Travail',
                'UF 2.10 Informatique & Multimédias',
                'UF 2.11 Communication Professionnelle en Français',
                'UF 2.12 Communication Professionnelle en Arabe',
                'UF 2.13 Communication Professionnelle en Anglais',
                'UF 2.14 Stage En Entreprise',
            ],
        ],
        'infographiste' => [
            1 => [
                "Dessin d’observation et illustration",
                "Logiciels d’infographie",
                'Théorie et pratique de la couleur',
                'Conception graphique',
                "Histoire de l’art",
                'Chaîne graphique',
                'Photographie',
                "Initiation à l'informatique",
                'Bureautique',
                'Internet',
                "Organisation d'entreprise",
                'Statistiques Gestion Appliquées',
                'Comptabilité Générale',
                'Communication en Français',
                'Communication en Anglais',
                'Stage En Entreprise',
            ],
            2 => [
                'Multimédia interactif',
                'Techniques de créativité',
                "Production d’un projet graphique",
                'Typographie',
                "Estimation et commercialisation d’un projet prépresse",
                'Dessin graphique en 3D',
                'Packaging',
                "Traitement d’image numérique",
                'Comptabilité Analytique',
                'Marketing',
                'Droit des Affaires',
                'Communication Professionnelle en Français',
                'Communication Professionnelle en Arabe',
                'Communication Professionnelle en Anglais',
                'Stage En Entreprise',
            ],
        ],
    ];

    $normalized = [];
    foreach ($mapping as $filiere => $years) {
        $filiereKey = unite_normalize_mapping_key($filiere);
        foreach ($years as $year => $units) {
            foreach ($units as $unit) {
                $normalized[$filiereKey][unite_normalize_mapping_key($unit)] = (int) $year;
            }
        }
    }

    return $normalized;
}

function unite_migrate_annee_formation(mysqli $conn): void
{
    $mapping = unite_annee_formation_mapping();
    $result = $conn->query("
        SELECT uf.id, uf.intitule, f.nom AS filiere_nom
        FROM unites_de_formation uf
        LEFT JOIN filieres f ON f.id = uf.filiere_id
    ");
    if (!$result) {
        return;
    }

    $stmt = $conn->prepare('UPDATE unites_de_formation SET annee_formation = ? WHERE id = ?');
    if (!$stmt) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $filiereKey = unite_normalize_mapping_key((string) ($row['filiere_nom'] ?? ''));
        $unitKey = unite_normalize_mapping_key((string) ($row['intitule'] ?? ''));
        $annee = $mapping[$filiereKey][$unitKey] ?? 2;
        $id = (int) $row['id'];
        $stmt->bind_param('ii', $annee, $id);
        $stmt->execute();
    }

    $stmt->close();
}

function unite_ensure_annee_formation_column(mysqli $conn): void
{
    if (!unite_column_exists($conn, 'annee_formation')) {
        $conn->query("ALTER TABLE unites_de_formation ADD COLUMN annee_formation INT NOT NULL DEFAULT 2 AFTER type_unite");
        $conn->query("CREATE INDEX idx_unites_annee_formation ON unites_de_formation (annee_formation)");
        unite_migrate_annee_formation($conn);
    }
}

function unite_ensure_columns(mysqli $conn): void
{
    unite_ensure_semestre_column($conn);
    unite_ensure_type_column($conn);
    unite_ensure_annee_formation_column($conn);
}

function unite_normalize_semestre($value): int
{
    return (int) $value === 2 ? 2 : 1;
}

function unite_semestre_label($value): string
{
    return unite_normalize_semestre($value) === 2 ? '2ème semestre' : '1er semestre';
}
