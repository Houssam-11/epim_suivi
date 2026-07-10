ALTER TABLE unites_de_formation
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER masse_horaire,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER is_archived;

ALTER TABLE sequences_pedagogiques
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER volume_horaire,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER is_archived;

ALTER TABLE objectif_seance
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER id_sequence,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER is_archived;

CREATE INDEX IF NOT EXISTS idx_unites_archived ON unites_de_formation (is_archived);
CREATE INDEX IF NOT EXISTS idx_sequences_archived ON sequences_pedagogiques (is_archived);
CREATE INDEX IF NOT EXISTS idx_objectifs_archived ON objectif_seance (is_archived);
