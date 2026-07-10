-- Retour arriere du moteur de recommandation.
-- Sauvegarder les evenements d'usage avant execution si leur conservation est requise.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS recommendation_usage_events;
DROP TABLE IF EXISTS recommendation_links;
DROP TABLE IF EXISTS recommendation_sequence_contents;
DROP TABLE IF EXISTS recommendation_contents;
DROP TABLE IF EXISTS recommendation_import_batches;
SET FOREIGN_KEY_CHECKS = 1;

