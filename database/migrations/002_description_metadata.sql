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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
