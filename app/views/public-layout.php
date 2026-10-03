<?php
/** The bare layout for the public doors (slice 6): the nxl look without the shell — no sidebar, no tab bar, no command bar, no link into the application; the business's name; 375 px first. Data: title, content, screen */
$title = $title ?? app_name(); $content = $content ?? ''; $screen = $screen ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <meta name="theme-color" content="#3454d1" />
    <meta name="csrf-token" id="csrf-token-meta" content="<?= e(csrf_token()) ?>" />
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png" />
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css" />
</head>
<body class="public-body">
    <main class="public-main" id="page-content" data-screen="<?= e($screen) ?>">
        <header class="public-header d-flex align-items-center gap-2 mb-3"><span class="avatar-text avatar-md bg-primary text-white rounded"><i class="feather-life-buoy"></i></span><span class="fw-bold fs-5"><?= e(app_name()) ?></span></header>
        <div id="flash"></div>
        <?= $content ?>
        <footer class="public-footer fs-11 text-muted mt-4">© <?= date('Y') ?> <?= e(app_name()) ?></footer>
    </main>
</body>
</html>
