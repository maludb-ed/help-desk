<?php
/** The dashboard (screen `dashboard`). Data: needsMe, myTickets, canCreate, canQueue, canKb, tz
 *  Three regions, each an empty state naming the slice that fills it (docs/build-specs/sso-shell.md); the shape is fixed now. */
?>
<?= view('shared/header.php', ['id' => 'dashboard', 'title' => 'Home', 'crumbs' => [[app_name(), null]],
    'action' => $canCreate ? hx_link('/tickets/new', '<i class="feather-plus-circle me-1"></i>New request', 'btn btn-primary btn-touch', 'id="dashboard-new-btn"') : '']) ?>
<div class="main-content" id="dashboard-content">
    <div class="row g-3">
        <?php if ($canQueue): ?>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-needs-me">
                <div class="card-header"><h5 class="card-title">Needs me</h5><?= hx_link('/inbox', 'Inbox', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($needsMe === []): ?>
                        <div class="empty-state" id="home-needs-me-empty">
                            <span class="avatar-text avatar-lg rounded"><i class="feather-check-circle"></i></span>
                            <div><div class="fw-semibold">Nothing needs you right now</div>
                                <div class="fs-12 text-muted">Tickets assigned to you and the unassigned ones in your queues, by how soon they are due, appear here.</div></div>
                        </div>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($needsMe as $t): ?>
                                <li class="mb-1 d-flex gap-2 align-items-start" id="home-needs-<?= (int) $t['ticket_id'] ?>"><span class="badge bg-<?= e($t['status_color']) ?> mt-1"><?= e($t['status_label']) ?></span><div class="min-w-0"><?= hx_link(with_back('/tickets/' . (int) $t['ticket_id'], '/'), '<span class="fw-semibold">' . e($t['number']) . '</span> ' . e($t['subject']), 'text-dark') ?><div class="fs-12 text-muted"><?= e($t['reason']) ?><?= ($w = sla_due_words($t['sla_next_due_at'], $t['sla_state'])) !== '' ? ' · ' . e($w) : '' ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-my-tickets">
                <div class="card-header"><h5 class="card-title">My tickets</h5><?= hx_link('/my-tickets', 'All', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($myTickets === []): ?>
                        <div class="empty-state" id="home-my-tickets-empty">
                            <span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span>
                            <div><div class="fw-semibold">You have asked for nothing yet</div>
                                <div class="fs-12 text-muted"><?= $canCreate ? 'Press New request when something needs doing; its progress shows here.' : 'The tickets you ask for show here.' ?></div></div>
                        </div>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($myTickets as $t): ?>
                                <li class="mb-1 d-flex gap-2 align-items-start" id="home-ticket-<?= (int) $t['ticket_id'] ?>"><span class="badge bg-<?= e($t['status_color']) ?> mt-1"><?= e($t['status_label']) ?></span><div class="min-w-0"><?= hx_link(with_back('/tickets/' . (int) $t['ticket_id'], '/'), '<span class="fw-semibold">' . e($t['number']) . '</span> ' . e($t['subject']), 'text-dark') ?><div class="fs-12 text-muted">last <?= e(format_ts($t['last_activity_at'], $tz, 'M j, g:i A')) ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($canKb): ?>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-kb">
                <div class="card-header"><h5 class="card-title">Knowledge base</h5><?= hx_link('/kb/', 'Browse', 'fs-12') ?></div>
                <div class="card-body">
                    <form method="get" action="/kb/" id="home-kb-search" hx-get="/kb/" hx-target="#page-content" hx-push-url="true" class="input-group mb-2">
                        <input type="search" name="q" id="home-kb-search-field" class="form-control btn-touch" placeholder="Search for an answer…" aria-label="Search the knowledge base" maxlength="120">
                        <button type="submit" class="btn btn-light btn-touch" id="home-kb-search-btn" aria-label="Search"><i class="feather-search"></i></button>
                    </form>
                    <?php if (($kbArticles ?? []) === []): ?><div class="fs-12 text-muted" id="home-kb-empty">Articles that answer the common questions appear here when the knowledge base is written.</div>
                    <?php else: ?><div class="list-group list-group-flush" id="home-kb-articles"><?php foreach ($kbArticles as $a): ?><a href="/kb/<?= e($a['slug']) ?>" class="list-group-item list-group-item-action px-0 py-2 fs-12 btn-touch" id="home-kb-article-<?= (int) $a['article_id'] ?>"><i class="feather-file-text me-2 text-muted"></i><?= e($a['title']) ?></a><?php endforeach; ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
