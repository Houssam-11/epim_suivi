CREATE TABLE IF NOT EXISTS edition_etats_calendrier (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_scolaire_id INT NOT NULL,
    filiere_id INT NOT NULL,
    vacances_debut DATE NULL,
    vacances_fin DATE NULL,
    stage_debut DATE NULL,
    stage_fin DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_edition_etats_calendrier (annee_scolaire_id, filiere_id),
    KEY idx_edition_etats_calendrier_annee (annee_scolaire_id),
    KEY idx_edition_etats_calendrier_filiere (filiere_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS edition_etats_examens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_scolaire_id INT NOT NULL,
    filiere_id INT NOT NULL,
    unite_id INT NOT NULL,
    date_examen DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_edition_etats_examen (annee_scolaire_id, filiere_id, unite_id),
    KEY idx_edition_etats_examens_annee (annee_scolaire_id),
    KEY idx_edition_etats_examens_filiere (filiere_id),
    KEY idx_edition_etats_examens_unite (unite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
