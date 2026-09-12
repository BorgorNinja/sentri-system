<div class="table-wrap">
 <table role="table" aria-label="Incident Reports Table" class="report-table">
 <thead>
 <tr>
  <th scope="col" class="col-id">#</th>
  <th scope="col" class="col-title">Title</th>
  <th scope="col" class="col-cat">Category</th>
  <th scope="col" class="col-status">Status</th>
  <th scope="col" class="col-loc">Location</th>
  <th scope="col" class="col-user">Reported By</th>
  <th scope="col" class="col-date">Date</th>
  <th scope="col" class="col-actions">Actions</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach($reports as $r):
 $escalated = !empty($r['escalated_to_lgu']) ? 1 : 0;
 ?>
 <tr>
 <td class="col-id" style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
 <td class="col-title" style="font-weight:600;">
 <div class="cell-title"><?= htmlspecialchars(mb_strimwidth($r['title'],0,55,'…')) ?><?php if($escalated): ?><span class="desktop-lgu" title="Escalated to LGU" style="margin-left:4px;display:inline-flex;align-items:center;gap:3px;font-size:0.65rem;font-weight:700;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;border:1px solid #fde68a;vertical-align:middle;white-space:nowrap;"><i class="fas fa-arrow-up-from-bracket"></i> LGU</span><?php endif; ?></div>
 <div class="mobile-sub-meta" style="display:none;align-items:center;gap:5px;margin-top:3px;flex-wrap:wrap;">
  <span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span>
  <?php if($escalated): ?><span title="Escalated to LGU" style="display:inline-flex;align-items:center;gap:3px;font-size:0.63rem;font-weight:700;color:#92400e;background:#fef3c7;padding:1px 5px;border-radius:4px;border:1px solid #fde68a;vertical-align:middle;white-space:nowrap;"><i class="fas fa-arrow-up-from-bracket"></i> LGU</span><?php endif; ?>
 </div>
 </td>
 <td class="col-cat"><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
 <td class="col-status"><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
 <td class="col-loc" style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></td>
 <td class="col-user" style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
 <td class="col-date" style="font-size:0.74rem;color:var(--muted);white-space:nowrap;"><?= date('M j, Y',strtotime($r['created_at'])) ?></td>
 <td class="col-actions">
 <div style="display:flex;gap:5px;align-items:center;">
 <?php
 $r_title_js = htmlspecialchars(json_encode($r['title'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_cat_js = htmlspecialchars(json_encode($r['category'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_stat_js = htmlspecialchars(json_encode($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_loc_js = htmlspecialchars(json_encode($r['barangay'] ?? $r['city'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_user_js = htmlspecialchars(json_encode(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
 $r_date_js = htmlspecialchars(json_encode(date('M j, Y', strtotime($r['created_at']))), ENT_QUOTES, 'UTF-8');
 $r_desc_js = htmlspecialchars(json_encode($r['description'] ?? ''), ENT_QUOTES, 'UTF-8');
 ?>
 <button class="btn-icon btn-view" title="View Details" aria-label="View details for report #<?= $r['id'] ?>"
 onclick="viewReport(<?= $r['id'] ?>, <?= $r_title_js ?>, <?= $r_cat_js ?>, <?= $r_stat_js ?>, <?= $r_loc_js ?>, <?= $r_user_js ?>, <?= $r_date_js ?>, <?= $r_desc_js ?>, <?= $escalated ?>)">
 <i class="fas fa-eye"></i>
 </button>
 <?php if($r['status'] !== 'safe'): ?>
 <button class="btn-icon btn-resolve" title="Mark Resolved" aria-label="Mark report #<?= $r['id'] ?> as resolved"
 onclick="quickResolve(<?= $r['id'] ?>,this)">
 <i class="fas fa-check"></i>
 </button>
 <?php if(!$escalated): ?>
 <button class="btn-icon btn-escalate" title="Escalate to LGU" aria-label="Escalate report #<?= $r['id'] ?> to LGU"
 onclick="quickEscalate(<?= $r['id'] ?>,this)">
 <i class="fas fa-arrow-up-from-bracket"></i>
 </button>
 <?php else: ?>
 <button class="btn-icon" title="Already Escalated to LGU" aria-label="Report #<?= $r['id'] ?> already escalated" disabled
 style="background:#fef3c7;color:#92400e;opacity:0.7;cursor:default;">
 <i class="fas fa-check"></i>
 </button>
 <?php endif; ?>
 <?php endif; ?>
 </div>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
</div>
