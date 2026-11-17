document.querySelectorAll('[data-dialog]').forEach(button => button.addEventListener('click', () => {
  const dialog = document.getElementById(button.dataset.dialog);
  if (dialog) dialog.showModal();
}));
document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => {
  if (event.target === dialog) {
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  }
}));
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
document.addEventListener('click', event => {
  const button = event.target.closest('[data-password-toggle]');
  if (!button) return;
  const input = document.getElementById(button.dataset.passwordToggle);
  if (!input) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-pressed', String(show));
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  button.title = show ? 'Hide password' : 'Show password';
});
const menu = document.querySelector('.menu-toggle');
const sidebar = document.getElementById('portalSidebar');
const setSidebar = open => {
  if (!sidebar) return;
  sidebar.classList.toggle('open', open);
  document.body.classList.toggle('sidebar-open', open);
  if (menu) menu.setAttribute('aria-expanded', String(open));
};
if (menu) menu.addEventListener('click', () => setSidebar(!sidebar.classList.contains('open')));
document.querySelector('.sidebar-close')?.addEventListener('click', () => { setSidebar(false); menu?.focus(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && sidebar?.classList.contains('open')) setSidebar(false); });
document.addEventListener('click', event => {
  if (sidebar?.classList.contains('open') && !sidebar.contains(event.target) && !event.target.closest('.menu-toggle')) setSidebar(false);
});
document.querySelectorAll('[data-department-select]').forEach(department => {
  const employee = document.getElementById(department.dataset.departmentSelect);
  const update = () => {
    Array.from(employee.options).forEach(option => {
      option.hidden = Boolean(option.dataset.department && department.value && department.value !== 'all' && option.dataset.department !== department.value);
      option.disabled = option.hidden;
    });
    if (employee.selectedOptions[0]?.disabled) employee.value = '';
  };
  department.addEventListener('change', update);
  update();
});
document.querySelectorAll('[data-range-preset]').forEach(select => {
  const from = document.querySelector('[name="from_date"]');
  const to = document.querySelector('[name="to_date"]');
  const allDates = select.form.querySelector('[name="all_dates"]');
  if (select.dataset.allDates === '1') select.value = 'all';
  if (allDates) {
    from.disabled = select.value === 'all';
    from.required = select.value !== 'all';
    to.disabled = select.value === 'all';
    to.required = select.value !== 'all';
  }
  select.addEventListener('change', () => {
    const from = document.querySelector('[name="from_date"]');
    const to = document.querySelector('[name="to_date"]');
    const allDates = select.form.querySelector('[name="all_dates"]');
    const isAllDates = select.value === 'all';
    if (allDates) allDates.value = isAllDates ? '1' : '0';
    if (from) { from.disabled = isAllDates; from.required = !isAllDates; }
    if (to) { to.disabled = isAllDates; to.required = !isAllDates; }
    if (select.value === 'custom' || isAllDates) return;
    const end = new Date(select.dataset.today + 'T12:00:00');
    const start = new Date(end);
    if (select.value === 'week') start.setDate(end.getDate() - 6);
    if (select.value === 'month') start.setDate(1);
    const format = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    from.value = format(start); to.value = format(end);
  });
});
document.querySelectorAll('[data-filter-department]').forEach(select => select.addEventListener('change', () => {
  select.form.querySelector('[name="employee"]').value = '';
  select.form.requestSubmit();
}));

document.querySelectorAll('[data-expires-at]').forEach(notification => {
  const expireAt = Date.parse(notification.dataset.expiresAt);
  if (!Number.isFinite(expireAt)) return;
  const expire = () => {
    const remaining = expireAt - Date.now();
    if (remaining > 0) {
      window.setTimeout(expire, Math.min(remaining, 2147483647));
      return;
    }
    const day = notification.closest('.notification-day');
    notification.remove();
    if (day) {
      const count = day.querySelector('[data-notification-day-count]');
      const remainingDayNotifications = day.querySelectorAll('.notification-link').length;
      if (count) count.textContent = `${remainingDayNotifications} notifications`;
      if (!remainingDayNotifications) day.remove();
    }
    const feed = document.querySelector('.employee-notification-feed');
    if (feed) {
      const count = feed.closest('.employee-notifications').querySelector('[data-notification-count]');
      const remainingNotifications = feed.querySelectorAll('.employee-notification-card').length;
      if (count) count.textContent = remainingNotifications;
      if (!remainingNotifications) {
        const empty = document.createElement('p');
        empty.className = 'muted';
        empty.textContent = 'No recent notifications. New messages stay here for 24 hours.';
        feed.replaceWith(empty);
      }
    }
  };
  expire();
});

// Reset only the attendance display at midnight in the portal's timezone.
// Portal authentication and yesterday's unfinished record stay intact.
(() => {
  const attendance = document.querySelector('[data-attendance-date]');
  if (!attendance) return;
  function refreshDay() {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {timeZone:'Asia/Kolkata',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()).map(part => [part.type,part.value]));
    const day = parts.year + '-' + parts.month + '-' + parts.day;
    if (attendance.dataset.attendanceDate === day) return;
    attendance.dataset.attendanceDate = day;
    attendance.querySelector('[name="action"]').value = 'login';
    attendance.querySelector('[data-attendance-time]')?.remove();
    attendance.querySelector('button').textContent = 'Login';
    const card = document.querySelector('[data-attendance-card]');
    if (card) {
      card.querySelector('strong').textContent = 'Not logged in';
      card.querySelector('p').textContent = 'Use Login in the sidebar to record today’s time';
      card.querySelector('[data-attendance-logout]')?.remove();
    }
  }
  document.addEventListener('visibilitychange',refreshDay);
  setInterval(refreshDay,30000);
  refreshDay();
})();

// Wish popup (bottom corner): ✕ closes it for this visit; the next waiting wish slides in.
// Wishes get a burst of sprinkles; warnings show in red.
(() => {
  const dock = document.querySelector('[data-wish-dock]');
  if (!dock) return;
  const canvas = dock.querySelector('[data-wish-sprinkles]');
  const ctx = canvas.getContext('2d');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let pieces = [], frame = 0;
  const resize = () => { canvas.width = window.innerWidth; canvas.height = window.innerHeight; };
  const sprinkle = danger => {
    if (reduce) return;
    resize();
    const colors = danger ? ['#d92d20', '#f04438', '#fda29b', '#b42318'] : ['#d6ad48', '#e2bf62', '#1769d2', '#f04438', '#12b76a', '#7a5af8', '#ffffff'];
    const card = dock.querySelector('.wish-card:not([hidden])')?.getBoundingClientRect();
    const ox = card ? card.left + card.width / 2 : canvas.width - 180, oy = card ? card.top + 20 : canvas.height - 300;
    pieces = Array.from({length: danger ? 60 : 160}, () => ({
      x: danger ? ox : Math.random() * canvas.width, y: danger ? oy : -20 - Math.random() * canvas.height * 0.5,
      vx: danger ? (Math.random() - 0.5) * 9 : (Math.random() - 0.5) * 2.5, vy: danger ? -Math.random() * 8 - 2 : Math.random() * 3 + 2,
      size: Math.random() * 6 + 4, rot: Math.random() * Math.PI, spin: (Math.random() - 0.5) * 0.3,
      color: colors[Math.floor(Math.random() * colors.length)], life: 0
    }));
    cancelAnimationFrame(frame);
    const tick = () => {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      pieces = pieces.filter(p => p.y < canvas.height + 30 && p.life < 420);
      pieces.forEach(p => {
        p.life++; p.vy += danger ? 0.25 : 0.03; p.x += p.vx; p.y += p.vy; p.rot += p.spin;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot); ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2); ctx.restore();
      });
      if (pieces.length) frame = requestAnimationFrame(tick); else ctx.clearRect(0, 0, canvas.width, canvas.height);
    };
    tick();
  };
  const showCurrent = () => {
    const card = dock.querySelector('.wish-card:not([hidden])');
    if (!card) { dock.remove(); return; }
    card.classList.remove('is-in'); void card.offsetWidth; card.classList.add('is-in');
    sprinkle(card.dataset.tone === 'danger');
  };
  dock.addEventListener('click', event => {
    const close = event.target.closest('[data-wish-close]');
    if (!close) return;
    const card = close.closest('.wish-card');
    const body = new FormData(); body.append('csrf', dock.dataset.csrf); body.append('id', card.dataset.wish);
    fetch('wish-seen.php', {method: 'POST', body, credentials: 'same-origin'}).catch(() => {});
    card.classList.add('is-out');
    setTimeout(() => {
      card.remove();
      const next = dock.querySelector('.wish-card[hidden]');
      if (next) next.hidden = false;
      showCurrent();
    }, 220);
  });
  window.addEventListener('resize', () => { if (pieces.length) resize(); });
  setTimeout(showCurrent, 350);
})();

// Big uploads (task videos…): send with a progress bar instead of a page that looks stuck.
// Small uploads keep the normal form submit. The server's reply (or the page it redirects to) replaces this page.
document.querySelectorAll('form[data-upload-progress]').forEach(form => form.addEventListener('submit', event => {
  if (event.defaultPrevented || !window.FormData || !window.XMLHttpRequest) return;
  const files = [...form.querySelectorAll('input[type=file]')].flatMap(input => [...(input.files || [])]);
  const total = files.reduce((sum, file) => sum + file.size, 0);
  if (total < 5 * 1024 * 1024) return;
  event.preventDefault();
  let data;
  try { data = new FormData(form, event.submitter || undefined); } catch (e) {
    data = new FormData(form);
    if (event.submitter && event.submitter.name) data.append(event.submitter.name, event.submitter.value);
  }
  const mb = bytes => (bytes / 1048576).toFixed(bytes < 104857600 ? 1 : 0) + ' MB';
  const box = document.createElement('div');
  box.className = 'upload-progress';
  box.setAttribute('role', 'status');
  box.innerHTML = '<div class="upload-progress-card"><strong>Uploading files…</strong><p data-up-text>Starting…</p><div class="upload-progress-bar"><span data-up-bar></span></div><small>Please keep this page open until it finishes.</small></div>';
  document.body.appendChild(box);
  const text = box.querySelector('[data-up-text]'), bar = box.querySelector('[data-up-bar]');
  form.querySelectorAll('button[type=submit],button:not([type])').forEach(b => { b.disabled = true; });
  const xhr = new XMLHttpRequest();
  const target = form.getAttribute('action') || window.location.href;
  xhr.open('POST', target);
  xhr.upload.addEventListener('progress', e => {
    if (!e.lengthComputable) return;
    const pct = Math.min(100, Math.round(e.loaded / e.total * 100));
    bar.style.width = pct + '%';
    text.textContent = pct < 100 ? `${pct}% · ${mb(e.loaded)} of ${mb(e.total)}` : 'Upload complete — saving…';
  });
  const failed = message => {
    text.textContent = message;
    box.classList.add('is-error');
    form.querySelectorAll('button[type=submit],button:not([type])').forEach(b => { b.disabled = false; });
    setTimeout(() => box.remove(), 6000);
  };
  xhr.addEventListener('load', () => {
    if (xhr.status >= 500 || xhr.status === 0) { failed('Upload failed (server error ' + xhr.status + '). Please try again.'); return; }
    if (xhr.responseURL && xhr.responseURL !== new URL(target, window.location.href).href) history.replaceState(null, '', xhr.responseURL);
    document.open(); document.write(xhr.responseText); document.close();
  });
  xhr.addEventListener('error', () => failed('Upload stopped — check your internet connection and try again.'));
  xhr.addEventListener('timeout', () => failed('Upload took too long. Try fewer or smaller files.'));
  xhr.send(data);
}));

// Live alerts on every page: new chat messages and other notifications pop up (WhatsApp-style card with the
// sender and the message, plus a screen notification when the window is not in front), and the Chat /
// Notifications badges in the sidebar update without reloading. Polls notifications-poll.php every 8 seconds.
(() => {
  const pollUrl = document.body && document.body.dataset.livePoll;
  if (!pollUrl || !window.fetch) return;
  const KEY = 'bhaviLiveNotifAt';
  let after = NaN;
  try { after = parseInt(localStorage.getItem(KEY) || '', 10); } catch (_) {}
  // The chat open on this page (chat.php?id=…), if any: its messages already show live there.
  const openChat = () => { const room = document.querySelector('[data-chat-room][data-conversation]'); return room ? Number(room.dataset.conversation) || 0 : 0; };
  // "Looking at the page" = tab visible AND the browser window in front (not minimised, no other app on top).
  const looking = () => document.visibilityState === 'visible' && document.hasFocus();
  const setBadge = (href, count) => {
    document.querySelectorAll('.portal-sidebar a[href="' + href + '"]').forEach(link => {
      if (link.closest('.sectioned-nav')) {
        let badge = link.querySelector('.nav-badge');
        if (!count) { if (badge) badge.remove(); return; }
        if (!badge) { badge = document.createElement('em'); badge.className = 'nav-badge'; link.querySelector('.nav-dot')?.remove(); link.appendChild(badge); }
        badge.textContent = count > 99 ? '99+' : String(count);
      } else {
        const label = link.querySelector('span'); if (!label) return;
        label.textContent = label.textContent.replace(/\s\(\d+\)$/, '') + (count ? ' (' + count + ')' : '');
      }
    });
  };
  // Short "ding" for a new alert. Browsers allow sound only after the user has clicked on the page once.
  const AC = window.AudioContext || window.webkitAudioContext;
  let audio = null;
  document.addEventListener('pointerdown', () => { try { if (AC && !audio) audio = new AC(); if (audio && audio.state === 'suspended') audio.resume(); } catch (_) {} }, { once: true });
  const ding = () => {
    try {
      if (!audio || audio.state !== 'running') return;
      const t = audio.currentTime;
      [880, 1320].forEach((freq, i) => {
        const o = audio.createOscillator(), g = audio.createGain(), s = t + i * 0.12;
        o.type = 'sine'; o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, s); g.gain.exponentialRampToValueAtTime(0.18, s + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, s + 0.2);
        o.connect(g); g.connect(audio.destination); o.start(s); o.stop(s + 0.22);
      });
    } catch (_) {}
  };
  // WhatsApp-style popup card: round avatar with initials, sender name, "now", the message, and Reply / Open.
  let box = null;
  const initials = name => name.split(/\s+/).filter(Boolean).slice(0, 2).map(p => p.charAt(0).toUpperCase()).join('') || '•';
  const toast = item => {
    if (!box) { box = document.createElement('div'); box.className = 'live-toasts'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const isChat = item.chat > 0 || item.title.indexOf('💬') === 0;
    const name = item.title.replace(/^💬\s*/u, '');
    const card = document.createElement('a'); card.className = 'live-toast' + (isChat ? ' is-chat' : ''); card.href = item.url;
    const avatar = document.createElement('span'); avatar.className = 'live-toast-avatar'; avatar.textContent = isChat ? initials(name.split(' · ')[0]) : '🔔'; avatar.setAttribute('aria-hidden', 'true');
    const head = document.createElement('span'); head.className = 'live-toast-head';
    const title = document.createElement('strong'); title.textContent = name;
    const when = document.createElement('small'); when.textContent = 'now';
    head.append(title, when);
    const text = document.createElement('span'); text.className = 'live-toast-msg'; text.textContent = item.message;
    const action = document.createElement('span'); action.className = 'live-toast-action'; action.textContent = isChat ? '↩ Reply' : 'Open →';
    const close = document.createElement('button'); close.type = 'button'; close.className = 'live-toast-close'; close.setAttribute('aria-label', 'Close'); close.textContent = '×';
    close.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); card.remove(); });
    const body = document.createElement('span'); body.className = 'live-toast-body'; body.append(head, text, action);
    card.append(avatar, body, close); box.prepend(card);
    while (box.children.length > 3) box.lastElementChild.remove();
    // Stays 20 s for chat (10 s for others); paused while the mouse is on it.
    let timer = setTimeout(() => card.remove(), isChat ? 20000 : 10000);
    card.addEventListener('mouseenter', () => clearTimeout(timer));
    card.addEventListener('mouseleave', () => { timer = setTimeout(() => card.remove(), 6000); });
  };
  const desktop = item => {
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted') return false;
      // The tag changes with each newer message, so a chat that gets another message alerts again.
      const n = new Notification(item.title, { body: item.message, tag: 'bhavi-' + item.id + '-' + item.at, icon: 'assets/img/favicon.svg', requireInteraction: item.chat > 0 });
      n.onclick = () => { window.focus(); location.href = item.url; n.close(); };
      return true;
    } catch (_) { return false; }
  };
  // Unseen alerts show in the tab title, e.g. "(2) Chat · Bhavi", until the window is in front again.
  const baseTitle = document.title; let unseen = 0;
  const markUnseen = () => { unseen++; document.title = '(' + unseen + ') ' + baseTitle; };
  window.addEventListener('focus', () => { if (unseen) { unseen = 0; document.title = baseTitle; } });
  // "Turn on alerts" button: browsers only allow the permission prompt after a click, so show a clear button
  // until the user allows (or blocks) screen notifications. Needs https:// (or localhost).
  const canAsk = () => { try { return 'Notification' in window && window.isSecureContext && Notification.permission === 'default'; } catch (_) { return false; } };
  if (canAsk()) {
    let dismissed = false; try { dismissed = sessionStorage.getItem('bhaviAlertsLater') === '1'; } catch (_) {}
    if (!dismissed) {
      const bar = document.createElement('div'); bar.className = 'live-alerts-ask'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Message alerts');
      const text = document.createElement('span'); text.textContent = 'Get chat and message alerts on your screen, even when this tab is in the background.';
      const on = document.createElement('button'); on.type = 'button'; on.className = 'live-alerts-on'; on.textContent = '🔔 Turn on alerts';
      const later = document.createElement('button'); later.type = 'button'; later.className = 'live-alerts-later'; later.textContent = 'Later';
      on.addEventListener('click', () => {
        let answered = false;
        const done = res => {
          if (answered) return; answered = true; bar.remove();
          if (res === 'granted') { try { new Notification('Alerts are on', { body: 'New chat messages and notifications will show here.', icon: 'assets/img/favicon.svg' }); } catch (_) {} }
        };
        try { const r = Notification.requestPermission(done); if (r && r.then) r.then(done); } catch (_) { bar.remove(); }
      });
      later.addEventListener('click', () => { bar.remove(); try { sessionStorage.setItem('bhaviAlertsLater', '1'); } catch (_) {} });
      bar.append(text, on, later); document.body.appendChild(bar);
    }
  }
  let busy = false;
  async function poll() {
    if (busy) return; busy = true;
    try {
      const r = await fetch(pollUrl + '?after=' + (Number.isFinite(after) ? after : 0), { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return;
      const data = await r.json(); if (!data.ok) return;
      setBadge('chat.php', Number(data.chat_unread) || 0);
      setBadge('manager-notification.php', Number(data.unread) || 0);
      if (Number.isFinite(after)) {
        let any = false;
        (data.items || []).slice().reverse().forEach(item => {
          item.chat = Number(item.chat) || 0;
          if (item.chat && item.chat === openChat() && looking()) return; // reading this chat right now
          if (!looking()) { desktop(item); markUnseen(); }
          if (document.visibilityState === 'visible') toast(item);
          any = true;
        });
        if (any && document.visibilityState === 'visible') ding();
      }
      after = Math.max(Number.isFinite(after) ? after : 0, Number(data.latest) || 0);
      try { localStorage.setItem(KEY, String(after)); } catch (_) {}
    } catch (_) {} finally { busy = false; }
  }
  poll();
  setInterval(poll, 8000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
})();
