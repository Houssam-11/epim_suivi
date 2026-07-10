DROP PROCEDURE IF EXISTS migrate_filieres_annee_formation;

DELIMITER //
CREATE PROCEDURE migrate_filieres_annee_formation()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'filieres'
          AND column_name = 'annee_formation'
    ) THEN
        ALTER TABLE filieres
            ADD COLUMN annee_formation INT NOT NULL DEFAULT 1 AFTER niveau;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'filieres'
          AND index_name = 'idx_filieres_annee_formation'
    ) THEN
        CREATE INDEX idx_filieres_annee_formation
            ON filieres (annee_formation);
    END IF;
END //
DELIMITER ;

CALL migrate_filieres_annee_formation();
DROP PROCEDURE IF EXISTS migrate_filieres_annee_formation;
