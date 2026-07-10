DROP PROCEDURE IF EXISTS migrate_unites_type_unite;

DELIMITER //

CREATE PROCEDURE migrate_unites_type_unite()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'unites_de_formation'
          AND column_name = 'type_unite'
    ) THEN
        ALTER TABLE unites_de_formation
            ADD COLUMN type_unite VARCHAR(30) NOT NULL DEFAULT 'pedagogique' AFTER semestre;
    END IF;

    UPDATE unites_de_formation
    SET type_unite = 'pedagogique'
    WHERE type_unite IS NULL
       OR type_unite = ''
       OR type_unite NOT IN ('pedagogique', 'stage');

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'unites_de_formation'
          AND index_name = 'idx_unites_type_unite'
    ) THEN
        CREATE INDEX idx_unites_type_unite ON unites_de_formation (type_unite);
    END IF;
END //

DELIMITER ;

CALL migrate_unites_type_unite();

DROP PROCEDURE IF EXISTS migrate_unites_type_unite;
