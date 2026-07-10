    </div> <!-- /#content -->

    <script>
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var content = document.getElementById('content');
            sidebar.classList.toggle('collapsed');
            content.classList.toggle('collapsed');
        }

        document.addEventListener('DOMContentLoaded', function () {
            var rowsPerPage = 10;

            function createPageItem(label, page, disabled, active) {
                var li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                var a = document.createElement(disabled ? 'span' : 'a');
                a.className = 'page-link';
                a.textContent = label;
                if (!disabled) {
                    a.href = '#';
                    a.dataset.page = String(page);
                }
                li.appendChild(a);
                return li;
            }

            function paginateTable(table, options) {
                options = options || {};
                var tbody = table.tBodies && table.tBodies[0];
                if (!tbody) {
                    return;
                }

                var wrapper = table.closest('.table-responsive');
                if (!wrapper || !wrapper.classList.contains('epim-data-table')) {
                    return;
                }

                if (table._epimPaginationWrap) {
                    table._epimPaginationWrap.remove();
                    table._epimPaginationWrap = null;
                }

                var visibleRowSelector = options.rowSelector || 'tr';
                var rows = Array.prototype.slice.call(tbody.querySelectorAll(visibleRowSelector)).filter(function (row) {
                    return row.querySelectorAll('td,th').length > 0 &&
                        !row.querySelector('[colspan]') &&
                        !row.classList.contains('epim-table-filter-hidden');
                });

                if (rows.length <= rowsPerPage) {
                    rows.forEach(function (row) {
                        row.style.display = '';
                    });
                    return;
                }

                var currentPage = 1;
                var totalPages = Math.ceil(rows.length / rowsPerPage);
                var paginationWrap = document.createElement('div');
                paginationWrap.className = 'epim-table-pagination';
                table._epimPaginationWrap = paginationWrap;
                var info = document.createElement('div');
                info.className = 'small text-muted';
                var nav = document.createElement('nav');
                nav.setAttribute('aria-label', 'Pagination du tableau');
                var ul = document.createElement('ul');
                ul.className = 'pagination';
                nav.appendChild(ul);
                paginationWrap.appendChild(info);
                paginationWrap.appendChild(nav);
                wrapper.insertAdjacentElement('afterend', paginationWrap);

                function render(page) {
                    currentPage = Math.min(Math.max(page, 1), totalPages);
                    var startIndex = (currentPage - 1) * rowsPerPage;
                    var endIndex = startIndex + rowsPerPage;
                    rows.forEach(function (row, index) {
                        row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
                    });

                    info.textContent = 'Affichage ' + (startIndex + 1) + '-' + Math.min(endIndex, rows.length) + ' sur ' + rows.length;
                    ul.innerHTML = '';
                    ul.appendChild(createPageItem('Précédent', currentPage - 1, currentPage === 1, false));

                    var firstPage = Math.max(1, currentPage - 2);
                    var lastPage = Math.min(totalPages, firstPage + 4);
                    firstPage = Math.max(1, lastPage - 4);
                    for (var pageNumber = firstPage; pageNumber <= lastPage; pageNumber++) {
                        ul.appendChild(createPageItem(String(pageNumber), pageNumber, false, pageNumber === currentPage));
                    }

                    ul.appendChild(createPageItem('Suivant', currentPage + 1, currentPage === totalPages, false));
                }

                ul.addEventListener('click', function (event) {
                    var target = event.target.closest('a[data-page]');
                    if (!target) {
                        return;
                    }
                    event.preventDefault();
                    render(parseInt(target.dataset.page, 10) || 1);
                    wrapper.scrollTop = 0;
                });

                render(currentPage);
            }

            window.EpimDataTables = {
                refresh: function (scope, options) {
                    var root = scope || document;
                    var tables = [];
                    if (root.matches && root.matches('.table-responsive.epim-data-table')) {
                        var directTable = root.querySelector(':scope > table.epim-table');
                        if (directTable) {
                            tables.push(directTable);
                        }
                    }
                    root.querySelectorAll('.table-responsive.epim-data-table > table.epim-table').forEach(function (table) {
                        if (tables.indexOf(table) === -1) {
                            tables.push(table);
                        }
                    });
                    tables.forEach(function (table) {
                        paginateTable(table, options);
                    });
                }
            };

            window.EpimDataTables.refresh(document);
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>

</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}
?>
