CREATE TABLE IF NOT EXISTS annees_scolaires (
  id INT(11) NOT NULL AUTO_INCREMENT,
  libelle VARCHAR(9) NOT NULL,
  date_debut DATE NOT NULL,
  date_fin DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_annees_scolaires_libelle (libelle),
  KEY idx_annees_scolaires_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO annees_scolaires (libelle, date_debut, date_fin, active)
SELECT DISTINCT
  CONCAT(annee_debut, '-', annee_debut + 1) AS libelle,
  STR_TO_DATE(CONCAT(annee_debut, '-10-01'), '%Y-%m-%d') AS date_debut,
  STR_TO_DATE(CONCAT(annee_debut + 1, '-07-31'), '%Y-%m-%d') AS date_fin,
  0 AS active
FROM (
  SELECT
    CASE
      WHEN MONTH(date_seance) >= 10 THEN YEAR(date_seance)
      ELSE YEAR(date_seance) - 1
    END AS annee_debut
  FROM seances_pedagogiques
  WHERE date_seance IS NOT NULL
) source
ON DUPLICATE KEY UPDATE
  date_debut = VALUES(date_debut),
  date_fin = VALUES(date_fin);

INSERT INTO annees_scolaires (libelle, date_debut, date_fin, active)
SELECT
  CONCAT(annee_debut, '-', annee_debut + 1),
  STR_TO_DATE(CONCAT(annee_debut, '-10-01'), '%Y-%m-%d'),
  STR_TO_DATE(CONCAT(annee_debut + 1, '-07-31'), '%Y-%m-%d'),
  1
FROM (
  SELECT CASE WHEN MONTH(CURDATE()) >= 10 THEN YEAR(CURDATE()) ELSE YEAR(CURDATE()) - 1 END AS annee_debut
) current_year
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

UPDATE annees_scolaires
SET active = CASE
  WHEN libelle = CONCAT(
    CASE WHEN MONTH(CURDATE()) >= 10 THEN YEAR(CURDATE()) ELSE YEAR(CURDATE()) - 1 END,
    '-',
    CASE WHEN MONTH(CURDATE()) >= 10 THEN YEAR(CURDATE()) + 1 ELSE YEAR(CURDATE()) END
  ) THEN 1
  ELSE 0
END;

ALTER TABLE seances_pedagogiques
  ADD COLUMN annee_scolaire_id INT(11) DEFAULT NULL AFTER sequence_id,
  ADD INDEX idx_seances_annee_scolaire (annee_scolaire_id);

UPDATE seances_pedagogiques sp
INNER JOIN annees_scolaires a
  ON a.libelle = CONCAT(
    CASE WHEN MONTH(sp.date_seance) >= 10 THEN YEAR(sp.date_seance) ELSE YEAR(sp.date_seance) - 1 END,
    '-',
    CASE WHEN MONTH(sp.date_seance) >= 10 THEN YEAR(sp.date_seance) + 1 ELSE YEAR(sp.date_seance) END
  )
SET sp.annee_scolaire_id = a.id
WHERE sp.date_seance IS NOT NULL
  AND sp.annee_scolaire_id IS NULL;

UPDATE seances_pedagogiques sp
INNER JOIN annees_scolaires a ON a.active = 1
SET sp.annee_scolaire_id = a.id
WHERE sp.annee_scolaire_id IS NULL;

ALTER TABLE seances_pedagogiques
  ADD CONSTRAINT fk_seances_annee_scolaire
  FOREIGN KEY (annee_scolaire_id) REFERENCES annees_scolaires(id)
  ON UPDATE CASCADE
  ON DELETE RESTRICT;
