ALTER TABLE objectifs_sequences
    MODIFY sujet VARCHAR(255) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS descriptifs_objectifs_sequences (
    id INT NOT NULL AUTO_INCREMENT,
    objectif_sequence_id INT NOT NULL,
    description TEXT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    ordre INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_descriptifs_objectif (objectif_sequence_id, ordre),
    KEY idx_descriptifs_sujet (sujet),
    CONSTRAINT fk_descriptifs_objectif_sequence
        FOREIGN KEY (objectif_sequence_id) REFERENCES objectifs_sequences (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
