<div class="table-wrap">
  <table>
    <thead>
      <tr><th>#</th><th>Title</th><th>Category</th><th>Status</th><th>Location</th><th>Reported By</th><th>Date</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach($reports as $r): ?>
      <tr>
        <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
        <td style="font-weight:600;max-width:200px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,55,'…')) ?></td>
        <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
        <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
        <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></td>
        <td style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
        <td style="font-size:0.74rem;color:var(--muted);white-space:nowrap;"><?= date('M j, Y',strtotime($r['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:5px;">
            <button class="btn-icon btn-view" title="View Details"
              onclick="viewReport(
                <?= $r['id'] ?>,
                '<?= addslashes(htmlspecialchars($r['title'])) ?>',
                '<?= $r['category'] ?>',
                '<?= $r['status'] ?>',
                '<?= addslashes(htmlspecialchars($r['barangay']??$r['city']??'')) ?>',
                '<?= addslashes(htmlspecialchars($r['first_name'].' '.$r['last_name'])) ?>',
                '<?= date('M j, Y',strtotime($r['created_at'])) ?>',
                '<?= addslashes(htmlspecialchars($r['description']??'')) ?>'
              )">
              <i class="fas fa-eye"></i>
            </button>
            <?php if($r['status'] !== 'safe'): ?>
            <button class="btn-icon btn-resolve" title="Mark Resolved"
              onclick="quickResolve(<?= $r['id'] ?>,this)">
              <i class="fas fa-check"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
