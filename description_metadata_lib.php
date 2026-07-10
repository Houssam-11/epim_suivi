<?php
declare(strict_types=1);

function dm_normalize(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return trim($text, " \t\n\r\0\x0B.,;:!?");
}

function dm_first_sentence(string $description): string
{
    $description = dm_normalize($description);
    $parts = preg_split('/(?<=[.!?])\s+/u', $description, 2);
    return dm_normalize($parts[0] ?? $description);
}

function dm_strip_activity_prefix(string $text): string
{
    $text = dm_normalize($text);

    if (preg_match('/«\s*([^»]{5,180})\s*»/u', $text, $matches)) {
        return dm_normalize((string) $matches[1]);
    }

    $patterns = [
        '/^(démonstration visuelle puis atelier de création consacré à|étude d[’\']un cas d[’\']entreprise portant sur)\s+/iu',
        '/^(présentation|presentation|étude|etude|analyse|explication|introduction|découverte|decouverte|identification|initiation)\s+(des?|du|de la|de l[’\']|d[’\']|les?|la)\s+/iu',
        '/^(simulation|atelier|exercice|application|pratique|réalisation|realisation|création|creation|conception|développement|developpement)\s+(des?|du|de la|de l[’\']|d[’\']|les?|la|sur)\s+/iu',
        '/^(utilisation|manipulation|configuration|traitement|comparaison|classement|schématisation|schematisation)\s+(des?|du|de la|de l[’\']|d[’\']|les?|la)\s+/iu',
        '/^les\s+(stagiaires|étudiants|etudiants|apprenants)\s+ont\s+(appris|travaillé|travaille|étudié|etudie|découvert|decouvert)\s+(à|a|sur|de|des?|du|la|les)\s+/iu',
    ];

    foreach ($patterns as $pattern) {
        $candidate = preg_replace($pattern, '', $text);
        if (is_string($candidate) && $candidate !== $text && trim($candidate) !== '') {
            return dm_normalize($candidate);
        }
    }

    return $text;
}

function dm_trim_clauses(string $subject): string
{
    $subject = dm_normalize($subject);
    $splitters = [
        '/\s*,\s*(avec|incluant|suivie?|à partir|a partir|en utilisant|à l[’\']aide|a l[’\']aide)\b/iu',
        '/\s+\bet\s+(les\s+)?(stagiaires|étudiants|etudiants|apprenants)\s+ont\b/iu',
        '/\s+\bdans\s+(un|une)\s+(cadre|contexte)\b/iu',
        '/\s+\bavec\s+(des?|plusieurs|une?|les?)\b/iu',
    ];

    foreach ($splitters as $splitter) {
        $parts = preg_split($splitter, $subject, 2);
        if (is_array($parts) && trim($parts[0] ?? '') !== '') {
            $subject = dm_normalize($parts[0]);
        }
    }

    return $subject;
}

function dm_clean_subject(string $subject): string
{
    $subject = dm_trim_clauses(dm_strip_activity_prefix($subject));
    for ($i = 0; $i < 3; $i++) {
        $before = $subject;
        $subject = preg_replace('/^(et\s+)?(identifier|analyser|appliquer|définir|definir|respecter|calculer|traduire|établir|etablir|comptabiliser|choisir|déterminer|determiner|exploiter|gérer|gerer|utiliser|mettre en oeuvre|mettre en œuvre|réaliser|realiser|schématiser|schematiser)\s+/iu', '', $subject) ?? $subject;
        $subject = preg_replace('/^(clairement|correctement|exactement|de manière exacte|de maniere exacte|de manière constructive|de maniere constructive)\s+/iu', '', $subject) ?? $subject;
        if ($subject === $before) {
            break;
        }
    }
    $subject = preg_replace('/^(différentes?|differentes?|principales?|principaux|plusieurs|tous|toutes)\s+/iu', '', $subject) ?? $subject;
    $subject = preg_replace('/^(les|des|la|le|un|une|l[’\'])\s*/iu', '', $subject) ?? $subject;
    return dm_normalize($subject);
}

function dm_limit_subject(string $subject): string
{
    $subject = dm_normalize($subject);
    $words = preg_split('/\s+/u', $subject) ?: [];
    if (count($words) > 7) {
        $subject = implode(' ', array_slice($words, 0, 7));
    }
    if (mb_strlen($subject, 'UTF-8') > 65) {
        $subject = dm_normalize(mb_substr($subject, 0, 65, 'UTF-8'));
        $subject = preg_replace('/\s+\S*$/u', '', $subject) ?? $subject;
    }
    $subject = preg_replace('/\s+(sur|sur les|en|dans|de|des|du|pour|avec|et)$/iu', '', $subject) ?? $subject;
    return $subject;
}

function dm_subject_from_description(string $description, string $family, string $objective = ''): string
{
    $first = dm_first_sentence($description);

    if ($family === 'maitrise' && preg_match('/cr[ée]ation/iu', $first) && preg_match('/contrat/iu', $first)) {
        return 'étapes de création de contrat';
    }
    if ($family === 'maitrise' && preg_match('/cr[ée]ation/iu', $first)) {
        return 'étapes de création';
    }

    $subject = dm_clean_subject($first);
    $wordCount = count(preg_split('/\s+/u', $subject) ?: []);
    if ((mb_strlen($subject, 'UTF-8') > 75 || $wordCount > 8) && dm_normalize($objective) !== '') {
        $subject = dm_clean_subject($objective);
    }

    $subject = dm_limit_subject($subject);
    return $subject !== '' ? $subject : 'notions abordées';
}

function dm_family_from_description(string $description, string $context = ''): string
{
    $text = mb_strtolower($description . ' ' . $context, 'UTF-8');
    $families = [
        'participation' => ['participation', 'échange', 'echange', 'discussion', 'débat', 'debat', 'travail en groupe', 'collaboratif', 'présentation orale', 'presentation orale'],
        'realisation' => ['réalisation', 'realisation', 'production', 'projet', 'maquette', 'story-board', 'storyboard', 'montage', 'dessin', 'croquis', 'graphique', 'infographie', 'développement', 'developpement', 'programme', 'reproduire', 'élaboration', 'elaboration'],
        'maitrise' => ['simulation', 'création', 'creation', 'utilisation', 'manipulation', 'configuration', 'traitement', 'appliquer', 'contrat', 'procédure', 'procedure', 'étapes', 'etapes', 'méthode', 'methode', 'technique', 'outil', 'logiciel', 'matériaux', 'materiaux', 'conversion', 'calcul'],
        'assimilation' => ['étude', 'etude', 'identifier', 'identification', 'reconnaissance', 'reconnaitre', 'comprendre', 'introduction', 'présentation', 'presentation', 'analyse', 'explication', 'notion', 'principe', 'règle', 'regle', 'écriture', 'ecriture', 'comptable', 'marché', 'marche', 'théorie', 'theorie', 'caractéristique', 'caracteristique'],
    ];

    foreach ($families as $family => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                return $family;
            }
        }
    }

    return 'assimilation';
}

function dm_generate_metadata(string $description, string $context = '', string $objective = ''): array
{
    $family = dm_family_from_description($description, $context . ' ' . $objective);

    return [
        'famille_pedagogique' => $family,
        'sujet_pedagogique' => dm_subject_from_description($description, $family, $objective),
    ];
}

function dm_create_table(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS description_metadata (
            id INT AUTO_INCREMENT PRIMARY KEY,
            description_id INT NOT NULL,
            sujet_pedagogique VARCHAR(255) NOT NULL,
            famille_pedagogique VARCHAR(50) NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'auto',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_description_metadata_description (description_id),
            KEY idx_description_metadata_description (description_id),
            KEY idx_description_metadata_family (famille_pedagogique)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Impossible de créer description_metadata : ' . $conn->error);
    }
}

function dm_upsert(mysqli $conn, int $descriptionId, string $subject, string $family, string $source): void
{
    $stmt = $conn->prepare(
        "INSERT INTO description_metadata (description_id, sujet_pedagogique, famille_pedagogique, source)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             sujet_pedagogique = VALUES(sujet_pedagogique),
             famille_pedagogique = VALUES(famille_pedagogique),
             source = VALUES(source),
             updated_at = CURRENT_TIMESTAMP"
    );
    if (!$stmt) {
        throw new RuntimeException('Impossible de préparer description_metadata : ' . $conn->error);
    }
    $stmt->bind_param('isss', $descriptionId, $subject, $family, $source);
    $stmt->execute();
    $stmt->close();
}
