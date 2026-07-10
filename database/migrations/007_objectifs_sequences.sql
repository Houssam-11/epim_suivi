CREATE TABLE IF NOT EXISTS objectifs_sequences (
    id INT NOT NULL AUTO_INCREMENT,
    sequence_id INT NOT NULL,
    objectif TEXT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    volume_horaire DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    ordre INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_objectifs_sequences_sequence (sequence_id, ordre),
    KEY idx_objectifs_sequences_sujet (sujet),
    CONSTRAINT fk_objectifs_sequences_sequence
        FOREIGN KEY (sequence_id) REFERENCES sequences_pedagogiques (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
