<?php
declare(strict_types=1);

require_once __DIR__ . '/unite_helper.php';
require_once __DIR__ . '/PedagogicalCalendar.php';

/**
 * Prepares pedagogical planning data for future Excel exports.
 *
 * This service does not generate or modify any Excel file. It only maps
 * database data into a stable structure that can later feed the official model.
 */
class ExcelPlanningService
{
    private mysqli $conn;

    /** @var array<int, string> */
    private array $monthLabels = [
        1 => 'Janvier',
        2 => 'Février',
        3 => 'Mars',
        4 => 'Avril',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juillet',
        8 => 'Août',
        9 => 'Septembre',
        10 => 'Octobre',
        11 => 'Novembre',
        12 => 'Décembre',
    ];

    /** @var int[] */
    private array $academicMonths = [10, 11, 12, 1, 2, 3, 4, 5, 6, 7];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Returns all data needed by the future planning export.
     */
    public function getPlanningData(int $anneeScolaireId, int $filiereId = 0, int $anneeFormation = 0): array
    {
        $academicYear = $this->getAcademicYear($anneeScolaireId);
        $filieres = $this->getFilieres($filiereId);
        $units = $this->getUnitsForFilieres(array_column($filieres, 'id'), $anneeFormation);
        $unitIds = array_column($units, 'id');
        $unitMetrics = $this->getUnitMetrics($anneeScolaireId, $unitIds);
        $weeklyDistribution = $this->getWeeklyDistribution($anneeScolaireId, $unitIds);

        foreach ($units as &$unit) {
            $unitId = (int) $unit['id'];
            $metrics = $unitMetrics[$unitId] ?? [
                'masse_horaire_realisee' => 0.0,
                'masse_horaire_restante' => (float) $unit['masse_horaire'],
                'pourcentage_realise' => 0.0,
            ];

            $unit['masse_horaire_realisee'] = $metrics['masse_horaire_realisee'];
            $unit['masse_horaire_restante'] = $metrics['masse_horaire_restante'];
            $unit['pourcentage_realise'] = $metrics['pourcentage_realise'];
            $unit['repartition_hebdomadaire'] = $weeklyDistribution[$unitId] ?? $this->emptyMonthlyDistribution();
        }
        unset($unit);

        return [
            'annee_scolaire' => $academicYear,
            'annee_formation' => $anneeFormation,
            'filieres' => $this->attachUnitsToFilieres($filieres, $units),
            'totaux_par_annee_formation' => $this->getYearTotals($units),
            'periodes_examens' => $this->getExamPeriods($anneeScolaireId, $anneeFormation),
            'periodes_vacances' => $this->getVacationPeriods($anneeScolaireId),
        ];
    }

    /**
     * The official model always uses four pedagogical weeks per month.
     */
    public function getPedagogicalWeek(DateTime $date): array
    {
        return PedagogicalCalendar::getPedagogicalWeek($date);
    }

    /**
     * Aggregates validated hours by unit, month and pedagogical week.
     *
     * @param int[] $unitIds
     */
    public function getWeeklyDistribution(int $anneeScolaireId, array $unitIds = []): array
    {
        $distribution = [];
        foreach ($unitIds as $unitId) {
            $distribution[(int) $unitId] = $this->emptyMonthlyDistribution();
        }

        $whereUnits = '';
        if ($unitIds !== []) {
            $whereUnits = ' AND uf.id IN (' . implode(',', array_map('intval', $unitIds)) . ')';
        }

        $rows = $this->fetchAll("
            SELECT
                uf.id AS unite_id,
                DATE(sp.date_seance) AS date_seance,
                SUM(GREATEST(COALESCE(sp.heures_reelles, sp.heures_realisees, 0), 0)) AS heures,
                SUM(CASE WHEN COALESCE(sp.controle_continu, 0) = 1 THEN 1 ELSE 0 END) AS controles_continus
            FROM seances_pedagogiques sp
            INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
            INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
            INNER JOIN suivi_pedagogique suivi ON suivi.seance_id = sp.id
            WHERE sp.annee_scolaire_id = ?
              AND COALESCE(suivi.valide_par_directeur, 0) = 1
              AND sp.date_seance IS NOT NULL
              {$whereUnits}
            GROUP BY uf.id, DATE(sp.date_seance)
            ORDER BY sp.date_seance ASC
        ", 'i', [$anneeScolaireId]);

        foreach ($rows as $row) {
            $unitId = (int) $row['unite_id'];
            if (!isset($distribution[$unitId])) {
                $distribution[$unitId] = $this->emptyMonthlyDistribution();
            }

            $weekInfo = $this->getPedagogicalWeek(new DateTime((string) $row['date_seance']));
            $monthNumber = (int) $weekInfo['month_number'];
            $week = (int) $weekInfo['week'];

            if (!isset($distribution[$unitId][$monthNumber])) {
                $distribution[$unitId][$monthNumber] = $this->emptyMonthBucket($monthNumber);
            }

            $distribution[$unitId][$monthNumber]['weeks'][$week] += (float) $row['heures'];
            $distribution[$unitId][$monthNumber]['controles_continus'][$week] += (int) ($row['controles_continus'] ?? 0);
            $distribution[$unitId][$monthNumber]['total'] += (float) $row['heures'];
        }

        return $distribution;
    }

    /**
     * Builds totals grouped by formation year.
     *
     * @param array<int, array<string, mixed>> $units
     */
    public function getYearTotals(array $units): array
    {
        $totals = [];

        foreach ($units as $unit) {
            $anneeFormation = (int) ($unit['annee_formation'] ?? 0);
            if (!isset($totals[$anneeFormation])) {
                $totals[$anneeFormation] = [
                    'annee_formation' => $anneeFormation,
                    'masse_horaire_totale' => 0.0,
                    'masse_horaire_realisee' => 0.0,
                    'masse_horaire_restante' => 0.0,
                    'pourcentage_global' => 0.0,
                ];
            }

            $totals[$anneeFormation]['masse_horaire_totale'] += (float) ($unit['masse_horaire'] ?? 0);
            $totals[$anneeFormation]['masse_horaire_realisee'] += (float) ($unit['masse_horaire_realisee'] ?? 0);
            $totals[$anneeFormation]['masse_horaire_restante'] += (float) ($unit['masse_horaire_restante'] ?? 0);
        }

        foreach ($totals as &$total) {
            $total['pourcentage_global'] = $total['masse_horaire_totale'] > 0
                ? round(($total['masse_horaire_realisee'] / $total['masse_horaire_totale']) * 100, 2)
                : 0.0;
        }
        unset($total);

        ksort($totals);

        return array_values($totals);
    }

    public function getExamPeriods(int $anneeScolaireId, int $anneeFormation = 0): array
    {
        $sql = "
            SELECT
                annee_formation,
                examen_semestre1_debut,
                examen_semestre1_fin,
                examen_semestre2_debut,
                examen_semestre2_fin
            FROM configurations_examens_annees_formation
            WHERE annee_scolaire_id = ?
        ";
        $types = 'i';
        $params = [$anneeScolaireId];

        if ($anneeFormation > 0) {
            $sql .= ' AND annee_formation = ?';
            $types .= 'i';
            $params[] = $anneeFormation;
        }

        $sql .= ' ORDER BY annee_formation ASC';

        $rows = $this->fetchAll($sql, $types, $params);
        $periods = [];

        foreach ($rows as $row) {
            $periods[] = [
                'annee_formation' => (int) $row['annee_formation'],
                'semestre1' => [
                    'date_debut' => $row['examen_semestre1_debut'],
                    'date_fin' => $row['examen_semestre1_fin'],
                ],
                'semestre2' => [
                    'date_debut' => $row['examen_semestre2_debut'],
                    'date_fin' => $row['examen_semestre2_fin'],
                ],
            ];
        }

        return $periods;
    }

    public function getVacationPeriods(int $anneeScolaireId): array
    {
        $rows = $this->fetchAll("
            SELECT
                v.nom,
                v.date_debut,
                v.date_fin,
                v.ordre
            FROM configurations_dates_academiques_globales cfg
            INNER JOIN configurations_dates_vacances_globales v ON v.configuration_id = cfg.id
            WHERE cfg.annee_scolaire_id = ?
            ORDER BY v.ordre ASC, v.date_debut ASC, v.id ASC
        ", 'i', [$anneeScolaireId]);

        return array_map(static function (array $row): array {
            return [
                'nom' => $row['nom'],
                'date_debut' => $row['date_debut'],
                'date_fin' => $row['date_fin'],
                'ordre' => (int) $row['ordre'],
            ];
        }, $rows);
    }

    private function getAcademicYear(int $anneeScolaireId): ?array
    {
        $rows = $this->fetchAll("
            SELECT id, libelle, date_debut, date_fin, active, statut
            FROM annees_scolaires
            WHERE id = ?
            LIMIT 1
        ", 'i', [$anneeScolaireId]);

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'id' => (int) $row['id'],
            'libelle' => $row['libelle'],
            'date_debut' => $row['date_debut'],
            'date_fin' => $row['date_fin'],
            'active' => (int) $row['active'],
            'statut' => $row['statut'],
        ];
    }

    private function getFilieres(int $filiereId = 0): array
    {
        $sql = "
            SELECT
                id,
                nom,
                niveau,
                COALESCE(annee_formation, 1) AS annee_formation
            FROM filieres
            WHERE 1 = 1
        ";
        $types = '';
        $params = [];

        if ($filiereId > 0) {
            $sql .= ' AND id = ?';
            $types .= 'i';
            $params[] = $filiereId;
        }

        $sql .= ' ORDER BY COALESCE(annee_formation, 1), nom';

        $rows = $this->fetchAll($sql, $types, $params);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nom' => $row['nom'],
                'niveau' => $row['niveau'],
                'annee_formation' => (int) $row['annee_formation'],
                'unites' => [],
            ];
        }, $rows);
    }

    /**
     * @param int[] $filiereIds
     */
    private function getUnitsForFilieres(array $filiereIds, int $anneeFormation = 0): array
    {
        if ($filiereIds === []) {
            return [];
        }

        $whereFormation = '';
        if ($anneeFormation > 0) {
            $whereFormation = ' AND COALESCE(uf.annee_formation, 2) = ' . (int) $anneeFormation;
        }

        $rows = $this->fetchAll("
            SELECT
                uf.id,
                uf.filiere_id,
                uf.intitule,
                uf.masse_horaire,
                uf.semestre,
                uf.type_unite,
                f.nom AS filiere_nom,
                f.niveau AS filiere_niveau,
                COALESCE(uf.annee_formation, 2) AS annee_formation,
                form.nom AS formateur_nom,
                form.email AS formateur_email
            FROM unites_de_formation uf
            INNER JOIN filieres f ON f.id = uf.filiere_id
            LEFT JOIN formateurs form ON form.id = uf.formateur_id
            WHERE uf.filiere_id IN (" . implode(',', array_map('intval', $filiereIds)) . ")
              {$whereFormation}
            ORDER BY COALESCE(uf.annee_formation, 2), f.nom, uf.semestre, uf.id
        ");

        return array_map(static function (array $row): array {
            $typeUnite = function_exists('unite_normalize_type')
                ? unite_normalize_type($row['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE)
                : (string) ($row['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE);

            return [
                'id' => (int) $row['id'],
                'filiere_id' => (int) $row['filiere_id'],
                'filiere_nom' => $row['filiere_nom'],
                'filiere_niveau' => $row['filiere_niveau'],
                'annee_formation' => (int) $row['annee_formation'],
                'intitule' => $row['intitule'],
                'formateur' => [
                    'nom' => $row['formateur_nom'],
                    'email' => $row['formateur_email'],
                ],
                'masse_horaire' => (float) ($row['masse_horaire'] ?? 0),
                'type_unite' => $typeUnite,
                'semestre' => (int) ($row['semestre'] ?? 1),
            ];
        }, $rows);
    }

    /**
     * @param int[] $unitIds
     * @return array<int, array<string, float>>
     */
    private function getUnitMetrics(int $anneeScolaireId, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $rows = $this->fetchAll("
            SELECT
                uf.id AS unite_id,
                COALESCE(uf.masse_horaire, 0) AS masse_horaire,
                SUM(
                    CASE
                        WHEN COALESCE(suivi.valide_par_directeur, 0) = 1
                        THEN GREATEST(COALESCE(sp.heures_reelles, sp.heures_realisees, 0), 0)
                        ELSE 0
                    END
                ) AS heures_validees
            FROM unites_de_formation uf
            LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
            LEFT JOIN seances_pedagogiques sp
                ON sp.sequence_id = seq.id
               AND sp.annee_scolaire_id = ?
            LEFT JOIN suivi_pedagogique suivi ON suivi.seance_id = sp.id
            WHERE uf.id IN (" . implode(',', array_map('intval', $unitIds)) . ")
            GROUP BY uf.id, uf.masse_horaire
        ", 'i', [$anneeScolaireId]);

        $metrics = [];
        foreach ($rows as $row) {
            $unitId = (int) $row['unite_id'];
            $masseHoraire = (float) ($row['masse_horaire'] ?? 0);
            $heuresValidees = (float) ($row['heures_validees'] ?? 0);

            $metrics[$unitId] = [
                'masse_horaire_realisee' => round($heuresValidees, 2),
                'masse_horaire_restante' => round($masseHoraire - $heuresValidees, 2),
                'pourcentage_realise' => $masseHoraire > 0
                    ? round(($heuresValidees / $masseHoraire) * 100, 2)
                    : 0.0,
            ];
        }

        return $metrics;
    }

    /**
     * @param array<int, array<string, mixed>> $filieres
     * @param array<int, array<string, mixed>> $units
     */
    private function attachUnitsToFilieres(array $filieres, array $units): array
    {
        $byFiliere = [];
        foreach ($units as $unit) {
            $byFiliere[(int) $unit['filiere_id']][] = $unit;
        }

        foreach ($filieres as &$filiere) {
            $filiere['unites'] = $byFiliere[(int) $filiere['id']] ?? [];
        }
        unset($filiere);

        return $filieres;
    }

    private function emptyMonthlyDistribution(): array
    {
        $months = [];
        foreach ($this->academicMonths as $monthNumber) {
            $months[$monthNumber] = $this->emptyMonthBucket($monthNumber);
        }

        return $months;
    }

    private function emptyMonthBucket(int $monthNumber): array
    {
        return [
            'label' => $this->monthLabels[$monthNumber] ?? (string) $monthNumber,
            'weeks' => [
                1 => 0.0,
                2 => 0.0,
                3 => 0.0,
                4 => 0.0,
            ],
            'controles_continus' => [
                1 => 0,
                2 => 0,
                3 => 0,
                4 => 0,
            ],
            'total' => 0.0,
        ];
    }

    /**
     * @param mixed[] $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Erreur de préparation SQL export Excel : ' . $this->conn->error);
        }

        if ($types !== '') {
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = &$params[$key];
            }
            $stmt->bind_param($types, ...$refs);
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Erreur SQL export Excel : ' . $error);
        }

        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }
}
