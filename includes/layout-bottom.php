</main>

<?php
// Smarty mounts for shop users only, when enabled by Super Admin
$__u = current_user();
if ($__u && in_array($__u['user_type'] ?? '', ['shop_owner', 'staff'], true) && smarty_enabled()):
?>
  <link href="<?= url('assets/css/smarty.css') ?>?v=1" rel="stylesheet">
  <script>
    window.SB_URL = window.SB_URL || {};
    window.SB_URL.smarty = '<?= e(url('ajax/smarty.php')) ?>';
    window.SB_URL.csrf   = '<?= e(csrf_token()) ?>';
    window.SB_URL.shopId = <?= (int)$__u['shop_id'] ?>;
  </script>
  <script src="<?= url('assets/js/smarty.js') ?>?v=1" defer></script>
<?php endif; ?>

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
