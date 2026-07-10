ALTER TABLE unites_de_formation
    ADD COLUMN IF NOT EXISTS annee_formation INT NOT NULL DEFAULT 2 AFTER type_unite;

CREATE INDEX IF NOT EXISTS idx_unites_annee_formation
    ON unites_de_formation (annee_formation);

-- Les unités non reconnues restent à la valeur par défaut : 2ème année.
-- La migration applicative dans includes/unite_helper.php applique la
-- correspondance métier fournie avec comparaison normalisée.
