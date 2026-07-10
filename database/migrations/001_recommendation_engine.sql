-- Moteur de recommandation pedagogique V1
-- Compatible MySQL 8.0+ et MariaDB 10.5+

SET NAMES utf8mb4;

ALTER TABLE seances_pedagogiques
    MODIFY heures_reelles DECIMAL(5,2) DEFAULT 3.00;

CREATE TABLE IF NOT EXISTS recommendation_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_name VARCHAR(255) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recommendation_batch_hash (source_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recommendation_contents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type ENUM('objectif', 'description', 'observation', 'disposition') NOT NULL,
    content_text TEXT NOT NULL,
    normalized_text TEXT NOT NULL,
    normalized_hash CHAR(64) NOT NULL,
    source ENUM('historical', 'generated', 'trainer') NOT NULL DEFAULT 'historical',
    status ENUM('active', 'pending', 'archived') NOT NULL DEFAULT 'active',
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    usage_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recommendation_content (content_type, normalized_hash),
    KEY idx_recommendation_content_rank (content_type, status, usage_count, occurrence_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recommendation_sequence_contents (
    sequence_id INT NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    source ENUM('historical', 'generated', 'trainer') NOT NULL DEFAULT 'historical',
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    base_score DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sequence_id, content_id),
    KEY idx_recommendation_sequence_rank (sequence_id, base_score, occurrence_count),
    CONSTRAINT fk_recommendation_sequence
        FOREIGN KEY (sequence_id) REFERENCES sequences_pedagogiques (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recommendation_sequence_content
        FOREIGN KEY (content_id) REFERENCES recommendation_contents (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recommendation_links (
    sequence_id INT NOT NULL,
    parent_content_id BIGINT UNSIGNED NOT NULL,
    child_content_id BIGINT UNSIGNED NOT NULL,
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    confidence_score DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    source ENUM('historical', 'generated', 'trainer') NOT NULL DEFAULT 'historical',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sequence_id, parent_content_id, child_content_id),
    KEY idx_recommendation_link_parent (sequence_id, parent_content_id, confidence_score, occurrence_count),
    KEY idx_recommendation_link_child (child_content_id),
    CONSTRAINT fk_recommendation_link_sequence
        FOREIGN KEY (sequence_id) REFERENCES sequences_pedagogiques (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recommendation_link_parent
        FOREIGN KEY (parent_content_id) REFERENCES recommendation_contents (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recommendation_link_child
        FOREIGN KEY (child_content_id) REFERENCES recommendation_contents (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_recommendation_link_distinct CHECK (parent_content_id <> child_content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recommendation_usage_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seance_id INT NULL,
    sequence_id INT NOT NULL,
    content_type ENUM('objectif', 'description', 'observation', 'disposition') NOT NULL,
    recommended_content_id BIGINT UNSIGNED NULL,
    submitted_text TEXT NOT NULL,
    action_type ENUM('accepted', 'modified', 'custom') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recommendation_usage_sequence (sequence_id, content_type, created_at),
    KEY idx_recommendation_usage_content (recommended_content_id, action_type),
    CONSTRAINT fk_recommendation_usage_seance
        FOREIGN KEY (seance_id) REFERENCES seances_pedagogiques (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_recommendation_usage_sequence
        FOREIGN KEY (sequence_id) REFERENCES sequences_pedagogiques (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recommendation_usage_content
        FOREIGN KEY (recommended_content_id) REFERENCES recommendation_contents (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
