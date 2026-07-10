ALTER TABLE seances_pedagogiques
  ADD COLUMN controle_continu TINYINT(1) NOT NULL DEFAULT 0 AFTER heures_reelles,
  ADD INDEX idx_seances_controle_continu (controle_continu);

UPDATE seances_pedagogiques
SET controle_continu = 0
WHERE controle_continu IS NULL;
