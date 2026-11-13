<?php
require_once __DIR__ . '/layout.php';
$user = require_roles();
$clientList = clients();
$assets = rows('SELECT a.*,c.client_name FROM brand_assets a JOIN clients c ON c.id=a.client_id WHERE c.deleted_at IS NULL AND c.is_active=1 ORDER BY c.client_name,a.asset_type,a.title');
$withLogo = count(array_filter($clientList, static fn($c) => !empty($c['logo_path'])));
$initials = static function (string $name): string {
    $words = array_values(array_filter(preg_split('/[\s&]+/u', trim($name)) ?: [], static fn($w) => $w !== '' && mb_strtolower($w) !== 'n'));
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) $out .= mb_strtoupper(mb_substr($w, 0, 1));
    return $out ?: '?';
};
$websiteHost = static function (?string $url): string {
    $url = trim((string) $url);
    if ($url === '') return '';
    $host = parse_url(preg_match('~^https?://~i', $url) ? $url : 'https://' . $url, PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./i', '', $host);
};
$websiteHref = static fn(string $url): string => preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
$tones = ['is-mint', 'is-cream', 'is-deep'];
page_start('Brand assets', 'employee-brands-assets.php');
?>
<div class="ba-page">
    <header class="ba-head">
        <div>
            <p class="ba-crumb">Dashboard / Brand assets</p>
            <h1 class="ba-title">Client brand assets</h1>
            <p class="ba-sub">Download client logos and brand files for your work.</p>
        </div>
        <?php if (!is_staff()): ?><span class="ba-pill"><?= icon('lock') ?> View &amp; download only</span><?php endif; ?>
    </header>

    <div class="ba-stats">
        <div class="ba-stat is-brand"><span>Clients</span><strong><?= count($clientList) ?></strong></div>
        <div class="ba-stat"><span>Logos available</span><strong><?= $withLogo ?></strong></div>
        <div class="ba-stat is-dark">
            <div><span class="ba-kicker">All logos</span><strong><?= $withLogo ?> <?= $withLogo === 1 ? 'logo' : 'logos' ?> in one ZIP file</strong></div>
            <?php if ($withLogo): ?><a class="ba-gold-btn" href="download-all-logos.php"><?= icon('download') ?> Download all</a><?php else: ?><span class="ba-gold-btn is-disabled">No logos yet</span><?php endif; ?>
        </div>
    </div>

    <div class="ba-toolbar">
        <label class="ba-search"><?= icon('search') ?><span class="sr-only">Search clients</span><input type="search" placeholder="Search a client to download their logo…" data-ba-search></label>
        <div class="ba-tabs" role="tablist">
            <button type="button" class="active" data-ba-tab="all">All · <?= count($clientList) ?></button>
            <button type="button" data-ba-tab="logo">With logo · <?= $withLogo ?></button>
        </div>
    </div>

    <div class="ba-grid" data-ba-grid>
        <?php foreach ($clientList as $i => $client): $hasLogo = !empty($client['logo_path']); $host = $websiteHost($client['website_url']); ?>
            <article class="ba-card" data-name="<?= h(mb_strtolower($client['client_name'] . ' ' . $host)) ?>" data-logo="<?= $hasLogo ? '1' : '0' ?>">
                <div class="ba-card-top">
                    <?php if ($hasLogo): ?>
                        <span class="ba-logo <?= $tones[$i % 3] ?>"><img src="client-logo.php?id=<?= (int) $client['id'] ?>" alt="<?= h($client['client_name']) ?> logo" loading="lazy" onerror="this.remove()"><b><?= h($initials($client['client_name'])) ?></b></span>
                    <?php else: ?>
                        <span class="ba-logo is-none">No logo</span>
                    <?php endif; ?>
                    <div class="ba-card-text">
                        <strong><?= h($client['client_name']) ?></strong>
                        <?php if ($host): ?><a href="<?= h($websiteHref(trim($client['website_url']))) ?>" target="_blank" rel="noopener noreferrer"><?= h($host) ?> ↗</a><?php else: ?><small>No website</small><?php endif; ?>
                    </div>
                </div>
                <div class="ba-card-foot">
                    <a class="ba-link" href="client-profile.php?id=<?= (int) $client['id'] ?>">View details</a>
                    <?php if ($hasLogo): ?>
                        <a class="ba-dl" href="client-logo.php?id=<?= (int) $client['id'] ?>&amp;download=1" aria-label="Download <?= h($client['client_name']) ?> logo"><?= icon('download') ?> Download logo</a>
                    <?php else: ?>
                        <span class="ba-dl is-disabled">Not available yet</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="ba-empty" data-ba-empty<?= $clientList ? ' hidden' : '' ?>><?= $clientList ? 'No clients match your search.' : 'No active clients yet.' ?></p>

    <?php if ($assets): ?>
        <section class="ba-files">
            <h2>Other brand files <span><?= count($assets) ?></span></h2>
            <div class="ba-file-list">
                <?php foreach ($assets as $asset): ?>
                    <a class="ba-file" href="download-asset.php?id=<?= (int) $asset['id'] ?>" data-name="<?= h(mb_strtolower($asset['client_name'])) ?>">
                        <span class="ba-file-icon"><?= icon('document') ?></span>
                        <span class="ba-file-text"><strong><?= h($asset['title']) ?></strong><small><?= h($asset['client_name']) ?> · <?= h(ucfirst((string) $asset['asset_type'])) ?></small></span>
                        <?= icon('download') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <p class="ba-note">Logo missing or outdated? Message your manager in <a href="chat.php">Chat</a> — only the Managing Director can add or change client details.</p>
</div>
<script>
(() => {
    const search = document.querySelector('[data-ba-search]'), empty = document.querySelector('[data-ba-empty]'), cards = document.querySelectorAll('.ba-card');
    let tab = 'all';
    const apply = () => {
        const q = (search.value || '').trim().toLowerCase();
        let shown = 0;
        cards.forEach(card => {
            const hide = (tab === 'logo' && card.dataset.logo !== '1') || (q !== '' && !card.dataset.name.includes(q));
            card.hidden = hide; if (!hide) shown++;
        });
        if (cards.length) empty.hidden = shown > 0;
    };
    search.addEventListener('input', apply);
    document.querySelectorAll('[data-ba-tab]').forEach(btn => btn.addEventListener('click', () => {
        tab = btn.dataset.baTab;
        document.querySelectorAll('[data-ba-tab]').forEach(b => b.classList.toggle('active', b === btn));
        apply();
    }));
})();
</script>
<?php page_end(); ?>
