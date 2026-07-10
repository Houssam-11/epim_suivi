<?php
include 'page_directeur.php';
?>

<div class="dashboard-page fade-in">
    <div class="page-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-end mb-4">
        <div>
            <h1 class="page-title">Tableau de bord directeur</h1>
            <p class="page-subtitle mb-0">Vue de pilotage du suivi pédagogique et des validations.</p>
        </div>
        <button type="button" class="btn btn-outline-epim mt-3 mt-lg-0" id="refreshDashboard">
            <i class="fas fa-sync-alt mr-2"></i>Actualiser
        </button>
    </div>

    <section class="epim-card filter-panel no-hover mb-4">
        <div class="filter-panel-header">
            <span class="badge-epim-info" id="dashboardState" hidden>Chargement</span>
        </div>
        <form id="dashboardFilters" class="dashboard-filters">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label for="filiere_id">Filière</label>
                    <select class="form-control" id="filiere_id" name="filiere_id">
                        <option value="">Toutes les filières</option>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="unite_id">Unité de formation</label>
                    <select class="form-control" id="unite_id" name="unite_id">
                        <option value="">Toutes les unités</option>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="sequence_id">Séquence</label>
                    <select class="form-control" id="sequence_id" name="sequence_id">
                        <option value="">Toutes les séquences</option>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="annee_scolaire_id">Année scolaire</label>
                    <select class="form-control" id="annee_scolaire_id" name="annee_scolaire_id">
                        <option value="">Chargement...</option>
                    </select>
                </div>
            </div>
        </form>
    </section>

    <section class="director-kpi-grid" id="kpiGrid" aria-live="polite">
        <article class="stat-card bg-blue director-kpi-card" data-kpi="filieres">
            <i class="fas fa-layer-group"></i>
            <div class="stat-value">--</div>
            <div class="stat-label">Filières actives</div>
        </article>
        <article class="stat-card bg-orange director-kpi-card" data-kpi="formateurs">
            <i class="fas fa-chalkboard-teacher"></i>
            <div class="stat-value">--</div>
            <div class="stat-label">Formateurs</div>
        </article>
        <article class="stat-card bg-dark director-kpi-card" data-kpi="seances_total">
            <i class="fas fa-calendar-alt"></i>
            <div class="stat-value">--</div>
            <div class="stat-label">Séances enregistrées</div>
        </article>
        <article class="stat-card bg-blue director-kpi-card" data-kpi="seances_attente">
            <i class="fas fa-hourglass-half"></i>
            <div class="stat-value">--</div>
            <div class="stat-label">En attente de validation</div>
        </article>
        <article class="stat-card bg-orange director-kpi-card" data-kpi="taux_moyen">
            <i class="fas fa-chart-line"></i>
            <div class="stat-value">--</div>
            <div class="stat-label">Taux moyen d'avancement</div>
        </article>        
    </section>

    <section class="epim-card no-hover mt-4">
        <div class="section-header">
            <div>
                <h2>Dernières séances enregistrées</h2>
                <p>Activité récente selon les filtres sélectionnés.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table dashboard-table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Formateur</th>
                        <th>Filière</th>
                        <th>Unité de formation</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="recentSessionsBody">
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Chargement des données...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const endpoint = 'dashboard_directeur_data.php';
    const filters = document.getElementById('dashboardFilters');
    const filiereSelect = document.getElementById('filiere_id');
    const uniteSelect = document.getElementById('unite_id');
    const sequenceSelect = document.getElementById('sequence_id');
    const anneeSelect = document.getElementById('annee_scolaire_id');
    const stateBadge = document.getElementById('dashboardState');
    const refreshButton = document.getElementById('refreshDashboard');
    const recentBody = document.getElementById('recentSessionsBody');
    const selectState = { filiere_id: '', unite_id: '', sequence_id: '', annee_scolaire_id: '' };
    let yearOptions = [];
    const formatNumber = new Intl.NumberFormat('fr-FR');

    function setState(text, className) {
        stateBadge.className = className;
        stateBadge.textContent = text;
    }

    function fillSelect(select, options, placeholder, selectedValue) {
        const current = selectedValue || '';
        select.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = placeholder;
        select.appendChild(defaultOption);

        options.forEach(function(option) {
            const item = document.createElement('option');
            item.value = option.id;
            item.textContent = option.display_label || option.label;
            if (String(option.id) === String(current)) {
                item.selected = true;
            }
            select.appendChild(item);
        });
    }

    function setKpiValue(key, value) {
        const card = document.querySelector('[data-kpi="' + key + '"] .stat-value');
        if (card) {
            card.textContent = value;
        }
    }

    function updateKpis(kpis) {
        Object.keys(kpis).forEach(function(key) {
            const value = kpis[key];
            setKpiValue(key, typeof value === 'number' ? formatNumber.format(value) : (value || '--'));
        });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char];
        });
    }

    function statusBadge(status) {
        const badgeClass = {
            'Validée': 'badge-epim-success',
            'Refusée': 'badge-epim-danger',
            'En attente': 'badge-epim-warning'
        }[status] || 'badge-epim-info';

        return '<span class="' + badgeClass + '">' + escapeHtml(status) + '</span>';
    }

    function updateRecentSessions(rows) {
        if (!rows.length) {
            recentBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Aucune séance trouvée pour ces filtres.</td></tr>';
            return;
        }

        recentBody.innerHTML = rows.map(function(row) {
            return '<tr>' +
                '<td>' + escapeHtml(row.date) + '</td>' +
                '<td>' + escapeHtml(row.formateur) + '</td>' +
                '<td>' + escapeHtml(row.filiere) + '</td>' +
                '<td>' + escapeHtml(row.unite) + '</td>' +
                '<td>' + statusBadge(row.statut) + '</td>' +
            '</tr>';
        }).join('');
    }

    function buildQuery() {
        const params = new URLSearchParams();
        selectState.filiere_id = filiereSelect.value;
        selectState.unite_id = uniteSelect.value;
        selectState.sequence_id = sequenceSelect.value;
        selectState.annee_scolaire_id = anneeSelect.value;

        Object.keys(selectState).forEach(function(key) {
            if (selectState[key]) {
                params.append(key, selectState[key]);
            }
        });

        return params.toString();
    }

    async function loadDashboard() {
        setState('Chargement', 'badge-epim-info');
        document.body.classList.add('dashboard-loading');

        try {
            const query = buildQuery();
            const response = await fetch(endpoint + (query ? '?' + query : ''), {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                throw new Error('Réponse serveur invalide');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Données indisponibles');
            }

            yearOptions = data.filters.annees_scolaires || [];
            fillSelect(filiereSelect, data.filters.filieres, 'Toutes les filières', selectState.filiere_id);
            fillSelect(uniteSelect, data.filters.unites, 'Toutes les unités', selectState.unite_id);
            fillSelect(sequenceSelect, data.filters.sequences, 'Toutes les séquences', selectState.sequence_id);
            fillSelect(anneeSelect, data.filters.annees_scolaires, 'Année scolaire', selectState.annee_scolaire_id || data.filters.annee_scolaire_id);
            updateKpis(data.kpis);
            updateRecentSessions(data.recent_sessions);
            setState('À jour', 'badge-epim-success');
        } catch (error) {
            setState('Erreur', 'badge-epim-danger');
            recentBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Données indisponibles, réessayez plus tard.</td></tr>';
        } finally {
            document.body.classList.remove('dashboard-loading');
        }
    }

    filiereSelect.addEventListener('change', function() {
        uniteSelect.value = '';
        sequenceSelect.value = '';
        loadDashboard();
    });

    uniteSelect.addEventListener('change', function() {
        sequenceSelect.value = '';
        loadDashboard();
    });

    sequenceSelect.addEventListener('change', loadDashboard);
    anneeSelect.addEventListener('change', function() {
        filiereSelect.value = '';
        uniteSelect.value = '';
        sequenceSelect.value = '';
        loadDashboard();
    });
    refreshButton.addEventListener('click', loadDashboard);
    filters.addEventListener('submit', function(event) {
        event.preventDefault();
        loadDashboard();
    });

    loadDashboard();
});
</script>

<?php include 'footer.php'; ?>
