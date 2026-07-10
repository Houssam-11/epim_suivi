ALTER TABLE suivi_pedagogique
  ADD COLUMN validateur_id INT(11) DEFAULT NULL AFTER valide_par_directeur,
  ADD INDEX idx_suivi_validateur (validateur_id);

ALTER TABLE suivi_pedagogique
  ADD CONSTRAINT fk_suivi_validateur
  FOREIGN KEY (validateur_id) REFERENCES utilisateurs(id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;
