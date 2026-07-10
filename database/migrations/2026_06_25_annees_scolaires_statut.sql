ALTER TABLE annees_scolaires
    ADD COLUMN IF NOT EXISTS statut ENUM('archivee', 'active', 'preparee') NULL AFTER active;

UPDATE annees_scolaires
SET statut = CASE
    WHEN active = 1 THEN 'active'
    WHEN date_fin < CURDATE() THEN 'archivee'
    ELSE 'preparee'
END
WHERE statut IS NULL OR statut = '';

ALTER TABLE annees_scolaires
    MODIFY statut ENUM('archivee', 'active', 'preparee') NOT NULL DEFAULT 'preparee';

UPDATE annees_scolaires
SET active = CASE WHEN statut = 'active' THEN 1 ELSE 0 END;

SET @active_count := (SELECT COUNT(*) FROM annees_scolaires WHERE statut = 'active');

UPDATE annees_scolaires
SET statut = 'active', active = 1
WHERE @active_count = 0
  AND libelle = CONCAT(
      CASE WHEN MONTH(CURDATE()) >= 10 THEN YEAR(CURDATE()) ELSE YEAR(CURDATE()) - 1 END,
      '-',
      CASE WHEN MONTH(CURDATE()) >= 10 THEN YEAR(CURDATE()) + 1 ELSE YEAR(CURDATE()) END
  );

UPDATE annees_scolaires
SET statut = 'preparee', active = 0
WHERE id NOT IN (
    SELECT id FROM (
        SELECT id
        FROM annees_scolaires
        WHERE statut = 'active'
        ORDER BY date_debut DESC
        LIMIT 1
    ) AS active_year
)
AND statut = 'active';

UPDATE annees_scolaires
SET active = CASE WHEN statut = 'active' THEN 1 ELSE 0 END;

CREATE INDEX IF NOT EXISTS idx_annees_scolaires_statut ON annees_scolaires (statut);
