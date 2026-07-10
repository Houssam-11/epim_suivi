SET @has_semestre := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'unites_de_formation'
      AND column_name = 'semestre'
);

SET @sql := IF(
    @has_semestre = 0,
    'ALTER TABLE unites_de_formation ADD COLUMN semestre TINYINT NOT NULL DEFAULT 1 AFTER masse_horaire',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE unites_de_formation
SET semestre = 1
WHERE semestre IS NULL OR semestre NOT IN (1, 2);

SET @has_semestre_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'unites_de_formation'
      AND index_name = 'idx_unites_semestre'
);

SET @sql := IF(
    @has_semestre_index = 0,
    'CREATE INDEX idx_unites_semestre ON unites_de_formation (semestre)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
