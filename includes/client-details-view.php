<?php
// Client "Project details" view. Expects $client (clients row) and $socialLinks from client-profile.php.
/** "2 yrs 10 months" since the start date. */
function cdv_partner_for(?string $date): string
{
    if (!$date) return '—';
    $start=new DateTimeImmutable($date); $now=new DateTimeImmutable('today');
    if ($start>$now) return 'Starts '.$start->format('j M Y');
    $d=$start->diff($now); $parts=[];
    if ($d->y) $parts[]=$d->y.' '.($d->y===1?'yr':'yrs');
    if ($d->m) $parts[]=$d->m.' '.($d->m===1?'month':'months');
    return $parts?implode(' ',$parts):($d->d.' '.($d->d===1?'day':'days'));
}
function cdv_safe_url(string $url): ?string
{
    return in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)?$url:null;
}
/** Platform key, short badge text, display name, and the handle to show under it. */
function cdv_platform(string $name, string $url): array
{
    $host=strtolower((string)parse_url($url,PHP_URL_HOST)); $host=preg_replace('/^(www\.|m\.)/','',$host);
    $path=trim((string)parse_url($url,PHP_URL_PATH),'/'); $first=$path!==''?explode('/',$path)[0]:'';
    $hay=strtolower($name.' '.$host);
    $map=[
        'instagram'=>['instagram','IG','Instagram'],'facebook'=>['facebook|fb\.com','FB','Facebook'],'linkedin'=>['linkedin','LI','LinkedIn'],
        'youtube'=>['youtube|youtu\.be','YT','YouTube'],'pinterest'=>['pinterest|pin\.it','PI','Pinterest'],'x'=>['twitter|x\.com|^x\b|\bx\s','X','X (Twitter)'],
        'whatsapp'=>['whatsapp|wa\.me','WA','WhatsApp'],'threads'=>['threads','TH','Threads'],
    ];
    $key='other'; $short=strtoupper(mb_substr(trim($name)?:'L',0,2)); $label=trim($name)?:'Link';
    foreach ($map as $k=>[$pattern,$s,$l]) if (preg_match('/'.$pattern.'/i',$hay)) { $key=$k; $short=$s; $label=$l; break; }
    $handle=$host.($path!==''?'/'.$path:'');
    switch ($key) {
        case 'instagram': case 'x': case 'threads': if ($first!=='' && !in_array($first,['p','reel','status','i'],true)) $handle='@'.ltrim($first,'@'); break;
        case 'facebook': if ($first!=='' && !in_array($first,['share','posts','watch','profile.php'],true)) $handle='/'.$first; break;
        case 'youtube': if ($first!=='' && str_starts_with($first,'@')) $handle=$first; elseif (in_array($first,['c','channel','user'],true)) $handle=explode('/',$path)[1]??$handle; break;
        case 'linkedin': $handle='View on LinkedIn'; break;
    }
    // A link to a single post/video rather than the account page.
    $isPost=(bool)preg_match('~/(p|reel|reels|posts|feed/update|status|watch|shorts|pin)/|[?&]v=|/share/~i','/'.$path.'/'.(string)parse_url($url,PHP_URL_QUERY));
    if ($key==='x' && preg_match('~/status/~',$url)) $isPost=true;
    return [$key,$short,$label,$handle,$isPost];
}

$cdName=(string)$client['client_name'];
$cdInitials=strtoupper(implode('',array_map(static fn($w)=>mb_substr($w,0,1),array_slice(preg_split('/\s+/',trim(preg_replace('/[^\p{L}\p{N}\s]/u',' ',$cdName)))?:[],0,2)))) ?: 'B';
$cdPackage=trim((string)($client['package']??''));
$cdType=trim((string)($client['client_type']??''));
$cdBadge=$cdPackage!==''?$cdPackage:($cdType!==''?ucfirst($cdType):'');
$cdFacts=[
    'Phone'=>$client['phone']?:'—',
    'Started'=>$client['starting_date']?date('j M Y',strtotime($client['starting_date'])):'—',
    'Partner for'=>cdv_partner_for($client['starting_date']),
    'Contract ends'=>$client['ending_date']?date('j M Y',strtotime($client['ending_date'])):'Ongoing',
];
if ((float)$client['payment_total']>0) $cdFacts['Package value']=rupees_label($client['payment_total']);
$cdPlan=['Reels'=>(int)$client['monthly_reels'],'Posters'=>(int)$client['monthly_posters'],'Carousels'=>(int)$client['monthly_carousels'],'Blogs'=>(int)$client['monthly_blogs']];
$cdNote=trim((string)($client['monthly_work_remark']??''));
$cdHasSecrets=(bool)array_filter($socialLinks,static fn($l)=>!empty($l['password_cipher']));
?>
<div class="pd-page">
    <header class="pd-head">
        <div>
            <p class="pd-crumb">Dashboard / Project details</p>
            <h1 class="pd-title">Project details</h1>
            <p class="pd-sub">Your package, monthly plan and the accounts we manage for you.</p>
        </div>
        <a class="pd-change" href="client-reuirement.php?type=other">Request a change to details</a>
    </header>

    <div class="pd-grid">
        <aside class="pd-profile">
            <div class="pd-profile-top">
                <span class="pd-logo"><?= h($cdInitials) ?><?php if ($client['logo_path']): ?><img src="client-logo.php?id=<?= (int)$client['id'] ?>" alt="" onerror="this.remove()"><?php endif; ?></span>
                <h2><?= h($cdName) ?></h2>
                <div class="pd-pills">
                    <?php if ($cdBadge!==''): ?><span class="pd-pill is-gold">★ <?= h($cdBadge) ?></span><?php endif; ?>
                    <span class="pd-pill">● <?= $client['is_active']?'Active':'Inactive' ?></span>
                </div>
            </div>
            <dl class="pd-facts">
                <?php foreach ($cdFacts as $label=>$value): ?><div><dt><?= h($label) ?></dt><dd><?= h($value) ?></dd></div><?php endforeach; ?>
            </dl>
        </aside>

        <div class="pd-main">
            <section class="pd-card">
                <div class="pd-card-head">
                    <h2>Your monthly plan</h2>
                    <a href="client-reports.php">See this month’s progress →</a>
                </div>
                <div class="pd-plan">
                    <?php foreach ($cdPlan as $label=>$count): ?>
                        <div class="pd-plan-box<?= $count?'':' is-off' ?>"><span><?= h($label) ?></span><strong><?= $count ?></strong><small><?= $count?'per month':'Not in your plan' ?></small></div>
                    <?php endforeach; ?>
                </div>
                <p class="pd-note">Notes from Bhavi: <?= $cdNote!==''?nl2br(h($cdNote)):'none yet' ?></p>
            </section>

            <section class="pd-card">
                <div class="pd-card-head">
                    <h2>Website &amp; social accounts</h2>
                    <?php if ($cdHasSecrets): ?><span class="pd-lock"><?= icon('lock') ?> Passwords need your login password to view</span><?php endif; ?>
                </div>
                <div class="pd-accounts">
                    <?php if ($client['website_url']): $url=cdv_safe_url($client['website_url']); $host=preg_replace('/^www\./','',(string)parse_url($client['website_url'],PHP_URL_HOST)) ?: $client['website_url']; ?>
                        <article class="pd-acc">
                            <div class="pd-acc-head"><span class="pd-badge is-web">WEB</span><span class="pd-acc-name"><strong>Website</strong><?php if ($url): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($host) ?> ↗</a><?php else: ?><small><?= h($client['website_url']) ?></small><?php endif; ?></span></div>
                        </article>
                    <?php endif; ?>
                    <?php if ($client['gmb_url']): $url=cdv_safe_url($client['gmb_url']); ?>
                        <article class="pd-acc">
                            <div class="pd-acc-head"><span class="pd-badge is-gbp">GBP</span><span class="pd-acc-name"><strong>Google Business Profile</strong><?php if ($url): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer">Open on Google Maps ↗</a><?php else: ?><small><?= h($client['gmb_url']) ?></small><?php endif; ?></span></div>
                        </article>
                    <?php endif; ?>
                    <?php foreach ($socialLinks as $link): [$key,$short,$label,$handle,$isPost]=cdv_platform((string)$link['platform_name'],(string)$link['url']); $url=cdv_safe_url((string)$link['url']); ?>
                        <article class="pd-acc">
                            <div class="pd-acc-head"><span class="pd-badge is-<?= h($key) ?>"><?= h($short) ?></span><span class="pd-acc-name"><strong><?= h($label) ?></strong><?php if ($url): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($handle) ?> ↗</a><?php elseif ($link['url']): ?><small><?= h($link['url']) ?></small><?php endif; ?></span></div>
                            <?php if (($link['username']??'')!=='' || !empty($link['password_cipher'])): ?>
                                <dl class="pd-cred">
                                    <div><dt>Username</dt><dd title="<?= h($link['username']) ?>"><?= h($link['username']?:'—') ?></dd></div>
                                    <?php if (!empty($link['password_cipher'])): ?><div><dt>Password</dt><dd class="pd-secret"><span data-pd-secret>••••••••</span><button type="button" data-pd-show="<?= (int)$link['id'] ?>">Show</button></dd></div><?php endif; ?>
                                </dl>
                            <?php endif; ?>
                            <?php if ($isPost): ?><p class="pd-warn">Link points to a post, not your page</p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$client['website_url'] && !$client['gmb_url'] && !$socialLinks): ?><p class="pd-empty">No accounts saved yet. Use “Request a change to details” to send them to us.</p><?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<dialog class="pd-dialog" data-pd-dialog>
    <form method="dialog" data-pd-form>
        <h3>Confirm it’s you</h3>
        <p>Enter your login password to view saved account passwords.</p>
        <input type="password" autocomplete="current-password" required data-pd-password placeholder="Your login password">
        <p class="pd-dialog-error" data-pd-error hidden></p>
        <div class="pd-dialog-actions"><button type="button" value="cancel" data-pd-cancel>Cancel</button><button type="submit" class="is-primary">View password</button></div>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.querySelector('[data-pd-dialog]'); if (!dialog) return;
    const form = dialog.querySelector('[data-pd-form]'), pass = dialog.querySelector('[data-pd-password]'), err = dialog.querySelector('[data-pd-error]');
    const csrf = <?= json_encode(csrf_token()) ?>;
    let pending = null;
    const reveal = async (button, password) => {
        const body = new URLSearchParams({ csrf, link: button.dataset.pdShow });
        if (password) body.set('password', password);
        const res = await fetch('client-reveal-password.php', { method: 'POST', body, credentials: 'same-origin' });
        let data = {}; try { data = await res.json(); } catch (e) { data = { error: 'Something went wrong. Please refresh and try again.' }; }
        return { ok: res.ok, data };
    };
    const show = (button, text) => {
        const secret = button.parentElement.querySelector('[data-pd-secret]');
        secret.textContent = text; secret.classList.add('is-shown'); button.textContent = 'Hide'; button.dataset.shown = '1';
    };
    document.querySelectorAll('[data-pd-show]').forEach(button => button.addEventListener('click', async () => {
        if (button.dataset.shown) { const s = button.parentElement.querySelector('[data-pd-secret]'); s.textContent = '••••••••'; s.classList.remove('is-shown'); button.textContent = 'Show'; delete button.dataset.shown; return; }
        const { ok, data } = await reveal(button, '');
        if (ok) { show(button, data.password); return; }
        if (data.needPassword) { pending = button; err.hidden = true; pass.value = ''; dialog.showModal(); pass.focus(); return; }
        alert(data.error || 'Could not show the password.');
    }));
    form.addEventListener('submit', async e => {
        e.preventDefault(); if (!pending) return;
        const { ok, data } = await reveal(pending, pass.value);
        if (ok) { show(pending, data.password); dialog.close(); pending = null; return; }
        err.textContent = data.error || 'Could not show the password.'; err.hidden = false; pass.select();
    });
    dialog.querySelector('[data-pd-cancel]').addEventListener('click', () => { dialog.close(); pending = null; });
})();
</script>
