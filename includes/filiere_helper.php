<?php
declare(strict_types=1);

function filiere_column_exists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'filieres'
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

function filiere_ensure_secteur_column(mysqli $conn): void
{
    if (!filiere_column_exists($conn, 'secteur')) {
        $conn->query("ALTER TABLE filieres ADD COLUMN secteur VARCHAR(255) NULL DEFAULT NULL AFTER niveau");
    }
}

function filiere_ensure_archive_column(mysqli $conn): void
{
    if (!filiere_column_exists($conn, 'is_archived')) {
        $conn->query("ALTER TABLE filieres ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER secteur_id");
        $conn->query("CREATE INDEX idx_filieres_archived ON filieres (is_archived)");
    }
}

function filiere_ensure_annee_formation_column(mysqli $conn): void
{
    if (!filiere_column_exists($conn, 'annee_formation')) {
        $conn->query("ALTER TABLE filieres ADD COLUMN annee_formation INT NOT NULL DEFAULT 1 AFTER niveau");
        $conn->query("CREATE INDEX idx_filieres_annee_formation ON filieres (annee_formation)");
    }
}

function filiere_ensure_columns(mysqli $conn): void
{
    filiere_ensure_secteur_column($conn);
    filiere_ensure_annee_formation_column($conn);
    filiere_ensure_archive_column($conn);
}

function filiere_normalize_niveau(string $niveau): string
{
    $niveau = trim($niveau);
    if ($niveau === 'Technicien') {
        return 'Technicien';
    }

    return 'Technicien Specialisé';
}

function filiere_niveau_label(?string $niveau): string
{
    return $niveau === 'Technicien' ? 'Technicien' : 'Technicien Spécialisé';
}

function filiere_annees_formation_options(): array
{
    return [1, 2, 3, 4, 5];
}

function filiere_normalize_annee_formation($value): int
{
    $annee = filter_var($value, FILTER_VALIDATE_INT);
    return $annee && $annee > 0 ? (int) $annee : 0;
}

function filiere_annee_formation_label($value): string
{
    $annee = filiere_normalize_annee_formation($value);
    if ($annee <= 0) {
        return '-';
    }

    return $annee . ($annee === 1 ? 'ère année' : 'ème année');
}

function filiere_duree_formation_label($value): string
{
    $duree = filiere_normalize_annee_formation($value);
    if ($duree <= 0) {
        return '-';
    }

    return $duree . ' ' . ($duree === 1 ? 'an' : 'ans');
}

function filiere_annees_formation_presentes(mysqli $conn): array
{
    filiere_ensure_columns($conn);

    $result = $conn->query("
        SELECT DISTINCT COALESCE(annee_formation, 1) AS annee_formation
        FROM filieres
        WHERE COALESCE(annee_formation, 1) > 0
        ORDER BY annee_formation ASC
    ");
    $rows = [
        [
            'id' => 0,
            'label' => 'Toutes',
            'display_label' => 'Toutes',
        ],
    ];

    while ($result && $row = $result->fetch_assoc()) {
        $annee = filiere_normalize_annee_formation($row['annee_formation'] ?? 0);
        if ($annee <= 0) {
            continue;
        }

        $rows[] = [
            'id' => $annee,
            'label' => filiere_annee_formation_label($annee),
            'display_label' => filiere_annee_formation_label($annee),
        ];
    }

    return $rows;
}

function filiere_options_by_annee_formation(mysqli $conn, int $anneeFormation = 0): array
{
    filiere_ensure_columns($conn);

    $sql = "
        SELECT id, nom, COALESCE(annee_formation, 1) AS annee_formation
        FROM filieres
    ";

    if ($anneeFormation > 0) {
        $sql .= " WHERE COALESCE(annee_formation, 1) = " . (int) $anneeFormation;
    }

    $sql .= " ORDER BY nom";
    $result = $conn->query($sql);
    $rows = [];
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'label' => $row['nom'],
            'display_label' => $row['nom'],
            'annee_formation' => filiere_normalize_annee_formation($row['annee_formation'] ?? 1),
        ];
    }

    return $rows;
}

function filiere_name_exists(mysqli $conn, string $nom, int $excludeId = 0): bool
{
    $sql = "SELECT id FROM filieres WHERE LOWER(TRIM(nom)) = LOWER(?)";
    if ($excludeId > 0) {
        $sql .= " AND id <> ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($excludeId > 0) {
        $stmt->bind_param('si', $nom, $excludeId);
    } else {
        $stmt->bind_param('s', $nom);
    }
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}
