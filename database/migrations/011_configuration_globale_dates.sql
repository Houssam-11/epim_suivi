CREATE TABLE IF NOT EXISTS configurations_dates_academiques_globales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_scolaire_id INT NOT NULL,
    semestre1_debut DATE NULL,
    semestre1_fin DATE NULL,
    semestre2_debut DATE NULL,
    semestre2_fin DATE NULL,
    examen_semestre1_debut DATE NULL,
    examen_semestre1_fin DATE NULL,
    examen_semestre2_debut DATE NULL,
    examen_semestre2_fin DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_config_dates_annee (annee_scolaire_id),
    KEY idx_config_dates_globales_annee (annee_scolaire_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS configurations_dates_vacances_globales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    configuration_id INT NOT NULL,
    nom VARCHAR(255) NULL,
    date_debut DATE NULL,
    date_fin DATE NULL,
    ordre INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_config_vacances_globales_configuration (configuration_id),
    CONSTRAINT fk_config_vacances_globales_configuration
        FOREIGN KEY (configuration_id)
        REFERENCES configurations_dates_academiques_globales(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS configurations_dates_stages_filieres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_scolaire_id INT NOT NULL,
    filiere_id INT NOT NULL,
    stage_debut DATE NULL,
    stage_fin DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_config_stage_context (annee_scolaire_id, filiere_id),
    KEY idx_config_stage_annee (annee_scolaire_id),
    KEY idx_config_stage_filiere (filiere_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP PROCEDURE IF EXISTS migrate_configuration_dates_legacy;

DELIMITER //
CREATE PROCEDURE migrate_configuration_dates_legacy()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'configurations_dates_academiques'
    ) THEN
        INSERT IGNORE INTO configurations_dates_academiques_globales (
            annee_scolaire_id,
            semestre1_debut,
            semestre1_fin,
            semestre2_debut,
            semestre2_fin,
            examen_semestre1_debut,
            examen_semestre1_fin,
            examen_semestre2_debut,
            examen_semestre2_fin
        )
        SELECT
            c.annee_scolaire_id,
            MAX(c.semestre1_debut),
            MAX(c.semestre1_fin),
            MAX(c.semestre2_debut),
            MAX(c.semestre2_fin),
            MAX(c.examen_semestre1),
            MAX(c.examen_semestre1),
            MAX(c.examen_semestre2),
            MAX(c.examen_semestre2)
        FROM configurations_dates_academiques c
        GROUP BY c.annee_scolaire_id;

        INSERT IGNORE INTO configurations_dates_stages_filieres (
            annee_scolaire_id,
            filiere_id,
            stage_debut,
            stage_fin
        )
        SELECT annee_scolaire_id, filiere_id, stage_debut, stage_fin
        FROM configurations_dates_academiques;

        IF EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'configurations_dates_vacances'
        ) THEN
            INSERT INTO configurations_dates_vacances_globales (configuration_id, nom, date_debut, date_fin, ordre)
            SELECT g.id, v.nom, v.date_debut, v.date_fin, v.ordre
            FROM configurations_dates_vacances v
            INNER JOIN configurations_dates_academiques c ON c.id = v.configuration_id
            INNER JOIN configurations_dates_academiques_globales g ON g.annee_scolaire_id = c.annee_scolaire_id
            LEFT JOIN configurations_dates_vacances_globales existing
                ON existing.configuration_id = g.id
                AND COALESCE(existing.nom, '') = COALESCE(v.nom, '')
                AND COALESCE(existing.date_debut, '1000-01-01') = COALESCE(v.date_debut, '1000-01-01')
                AND COALESCE(existing.date_fin, '1000-01-01') = COALESCE(v.date_fin, '1000-01-01')
            WHERE existing.id IS NULL;
        END IF;
    END IF;
END //
DELIMITER ;

CALL migrate_configuration_dates_legacy();
DROP PROCEDURE IF EXISTS migrate_configuration_dates_legacy;
