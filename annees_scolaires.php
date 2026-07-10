<?php
declare(strict_types=1);

function annee_scolaire_label_for_date(string $date): string
{
    $timestamp = strtotime($date);
    if (!$timestamp) {
        $timestamp = time();
    }

    $year = (int) date('Y', $timestamp);
    $month = (int) date('n', $timestamp);
    $startYear = $month >= 10 ? $year : $year - 1;

    return $startYear . '-' . ($startYear + 1);
}

function annee_scolaire_current_label(): string
{
    return annee_scolaire_label_for_date(date('Y-m-d'));
}

function annee_scolaire_options(mysqli $conn): array
{
    return annee_scolaire_options_by_status($conn);
}

function annee_scolaire_print_options(mysqli $conn): array
{
    return annee_scolaire_options_by_status($conn, ['active', 'archivee']);
}

function annee_scolaire_options_by_status(mysqli $conn, array $statuses = []): array
{
    $result = $conn->query("SELECT id, libelle, active, statut FROM annees_scolaires ORDER BY date_debut DESC");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $statut = annee_scolaire_normalize_status($row['statut'] ?? ((int) $row['active'] === 1 ? 'active' : 'archivee'));
            if ($statuses && !in_array($statut, $statuses, true)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'label' => $row['libelle'],
                'active' => $statut === 'active' ? 1 : 0,
                'statut' => $statut,
                'statut_label' => annee_scolaire_status_label($statut),
                'display_label' => $row['libelle'] . ' (' . annee_scolaire_status_label($statut) . ')',
            ];
        }
    }

    return $rows;
}

function annee_scolaire_active_id(mysqli $conn): int
{
    $result = $conn->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY date_debut DESC LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ? (int) $row['id'] : 0;
}

function annee_scolaire_working_session_key(): string
{
    return 'annee_scolaire_travail_id';
}

function annee_scolaire_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function annee_scolaire_exists(mysqli $conn, int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id FROM annees_scolaires WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function setCurrentWorkingAcademicYear(mysqli $conn, int $id): int
{
    if (!annee_scolaire_exists($conn, $id)) {
        return getCurrentWorkingAcademicYear($conn);
    }

    annee_scolaire_ensure_session();
    $_SESSION[annee_scolaire_working_session_key()] = $id;

    return $id;
}

function getCurrentWorkingAcademicYear(mysqli $conn, $requestedId = null): int
{
    $requested = filter_var($requestedId, FILTER_VALIDATE_INT);
    if ($requested && $requested > 0 && annee_scolaire_exists($conn, (int) $requested)) {
        return setCurrentWorkingAcademicYear($conn, (int) $requested);
    }

    annee_scolaire_ensure_session();
    $sessionId = filter_var($_SESSION[annee_scolaire_working_session_key()] ?? null, FILTER_VALIDATE_INT);
    if ($sessionId && $sessionId > 0 && annee_scolaire_exists($conn, (int) $sessionId)) {
        return (int) $sessionId;
    }

    $activeId = annee_scolaire_active_id($conn);
    if ($activeId > 0) {
        $_SESSION[annee_scolaire_working_session_key()] = $activeId;
    }

    return $activeId;
}

function annee_scolaire_selected_id(mysqli $conn, $value = null): int
{
    return getCurrentWorkingAcademicYear($conn, $value);
}

function annee_scolaire_id_for_date(mysqli $conn, string $date): int
{
    $label = annee_scolaire_label_for_date($date);
    $stmt = $conn->prepare("SELECT id FROM annees_scolaires WHERE libelle = ? LIMIT 1");
    $stmt->bind_param('s', $label);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) $row['id'] : annee_scolaire_active_id($conn);
}

function annee_scolaire_period(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id, libelle, date_debut, date_fin, statut FROM annees_scolaires WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $row['id'] = (int) $row['id'];
    $row['statut'] = annee_scolaire_normalize_status($row['statut'] ?? 'archivee');

    return $row;
}

function annee_scolaire_date_in_period(mysqli $conn, int $id, string $date): bool
{
    $period = annee_scolaire_period($conn, $id);
    $timestamp = strtotime($date);
    if (!$period || !$timestamp) {
        return false;
    }

    $dateValue = date('Y-m-d', $timestamp);
    $start = date('Y-m-d', strtotime((string) $period['date_debut']));
    $end = date('Y-m-d', strtotime((string) $period['date_fin']));

    return $dateValue >= $start && $dateValue <= $end;
}

function annee_scolaire_is_active(mysqli $conn, int $id): bool
{
    return annee_scolaire_status($conn, $id) === 'active';
}

function annee_scolaire_is_editable(mysqli $conn, int $id): bool
{
    return annee_scolaire_status($conn, $id) !== 'archivee';
}

function annee_scolaire_temp_session_key(): string
{
    return 'annees_scolaires_temporairement_reactivees';
}

function annee_scolaire_director_temp_session_key(): string
{
    return 'annees_scolaires_directeur_temporairement_reactivees';
}

function annee_scolaire_is_temp_reactivated(int $id): bool
{
    $items = $_SESSION[annee_scolaire_temp_session_key()] ?? [];
    return isset($items[$id]) && $items[$id] === true;
}

function annee_scolaire_set_temp_reactivated(int $id, bool $active): void
{
    if (!isset($_SESSION[annee_scolaire_temp_session_key()]) || !is_array($_SESSION[annee_scolaire_temp_session_key()])) {
        $_SESSION[annee_scolaire_temp_session_key()] = [];
    }

    if ($active) {
        $_SESSION[annee_scolaire_temp_session_key()][$id] = true;
    } else {
        unset($_SESSION[annee_scolaire_temp_session_key()][$id]);
    }
}

function annee_scolaire_mark_director_temp_reactivated(int $id, bool $active): void
{
    annee_scolaire_ensure_session();
    $key = annee_scolaire_director_temp_session_key();
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }

    if ($active) {
        $_SESSION[$key][$id] = true;
    } else {
        unset($_SESSION[$key][$id]);
    }
}

function annee_scolaire_cleanup_session_reactivations(mysqli $conn): void
{
    annee_scolaire_ensure_session();
    $key = annee_scolaire_director_temp_session_key();
    $items = $_SESSION[$key] ?? [];
    if (!is_array($items) || !$items) {
        unset($_SESSION[annee_scolaire_temp_session_key()]);
        return;
    }

    $stmt = $conn->prepare("UPDATE annees_scolaires SET statut = 'archivee', active = 0 WHERE id = ? AND statut = 'preparee'");
    if ($stmt) {
        foreach (array_keys($items) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    unset($_SESSION[$key], $_SESSION[annee_scolaire_temp_session_key()]);
}

function annee_scolaire_is_editable_for_current_user(mysqli $conn, int $id): bool
{
    $status = annee_scolaire_status($conn, $id);
    if ($status !== 'archivee') {
        return true;
    }

    return ($_SESSION['role'] ?? '') === 'formateur' && annee_scolaire_is_temp_reactivated($id);
}

function annee_scolaire_status(mysqli $conn, int $id): string
{
    $stmt = $conn->prepare("SELECT statut FROM annees_scolaires WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return annee_scolaire_normalize_status($row['statut'] ?? 'archivee');
}

function annee_scolaire_normalize_status(?string $status): string
{
    $status = strtolower(trim((string) $status));
    return in_array($status, ['archivee', 'active', 'preparee'], true) ? $status : 'archivee';
}

function annee_scolaire_status_label(string $status): string
{
    return match (annee_scolaire_normalize_status($status)) {
        'active' => 'Active',
        'preparee' => 'Préparée',
        default => 'Archivée',
    };
}

function annee_scolaire_status_badge_class(string $status): string
{
    return match (annee_scolaire_normalize_status($status)) {
        'active' => 'badge-epim-success',
        'preparee' => 'badge-epim-info',
        default => 'badge-epim-orange',
    };
}
