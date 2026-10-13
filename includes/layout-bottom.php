</main>
<?php require __DIR__ . '/footer.php'; ?>
<!-- DataTables (jQuery + Bootstrap 5 integration) -->
<link  href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
  jQuery(function ($) {
    $('.sb-datatable').each(function () {
      const $t = $(this);
      $t.DataTable({
        pageLength: parseInt($t.data('page') || 10, 10),
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: $t.data('order') ? JSON.parse($t.data('order')) : [],
        columnDefs: $t.data('nosort')
          ? String($t.data('nosort')).split(',').map(i => ({ orderable: false, targets: parseInt(i, 10) }))
          : [],
        language: {
          search: '',
          searchPlaceholder: 'Search…',
          emptyTable: 'No records',
          info: 'Showing _START_–_END_ of _TOTAL_',
          infoEmpty: '0 records',
          infoFiltered: '(from _MAX_)',
          paginate: { previous: '‹', next: '›' },
          lengthMenu: '_MENU_ per page',
        },
      });
    });
  });
</script>
