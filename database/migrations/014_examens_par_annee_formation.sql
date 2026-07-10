CREATE TABLE IF NOT EXISTS configurations_examens_annees_formation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_scolaire_id INT NOT NULL,
    annee_formation INT NOT NULL,
    examen_semestre1_debut DATE NULL,
    examen_semestre1_fin DATE NULL,
    examen_semestre2_debut DATE NULL,
    examen_semestre2_fin DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_config_examens_annee_formation (annee_scolaire_id, annee_formation),
    KEY idx_config_examens_annee (annee_scolaire_id),
    KEY idx_config_examens_formation (annee_formation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP PROCEDURE IF EXISTS migrate_examens_par_annee_formation;

DELIMITER //

CREATE PROCEDURE migrate_examens_par_annee_formation()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'configurations_dates_academiques_globales'
          AND column_name = 'examen_semestre1_debut'
    ) THEN
        INSERT IGNORE INTO configurations_examens_annees_formation (
            annee_scolaire_id,
            annee_formation,
            examen_semestre1_debut,
            examen_semestre1_fin,
            examen_semestre2_debut,
            examen_semestre2_fin
        )
        SELECT
            g.annee_scolaire_id,
            formations.annee_formation,
            g.examen_semestre1_debut,
            g.examen_semestre1_fin,
            g.examen_semestre2_debut,
            g.examen_semestre2_fin
        FROM configurations_dates_academiques_globales g
        CROSS JOIN (
            SELECT DISTINCT COALESCE(annee_formation, 1) AS annee_formation
            FROM filieres
            WHERE COALESCE(annee_formation, 1) > 0
        ) formations;
    END IF;
END //

DELIMITER ;

CALL migrate_examens_par_annee_formation();

DROP PROCEDURE IF EXISTS migrate_examens_par_annee_formation;
