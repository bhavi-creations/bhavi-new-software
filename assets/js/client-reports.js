(() => {
  let preview, timer;
  const remove = () => { clearTimeout(timer); preview?.remove(); preview = null; };
  const closeSoon = () => { timer = setTimeout(remove, 180); };
  function show(button) {
    remove();
    const dialog = document.getElementById(button.dataset.dialog);
    if (!dialog || dialog.open) return;
    preview = document.createElement('aside');
    preview.className = 'cp-hover-preview';
    preview.setAttribute('aria-hidden', 'true');
    preview.append(dialog.querySelector('.cp-report-content').cloneNode(true));
    document.body.append(preview);
    const rect = button.getBoundingClientRect();
    preview.style.left = Math.max(12, Math.min(rect.left, innerWidth - preview.offsetWidth - 12)) + 'px';
    preview.style.top = Math.max(12, Math.min(rect.bottom + 8, innerHeight - preview.offsetHeight - 12)) + 'px';
    preview.addEventListener('pointerenter', () => clearTimeout(timer));
    preview.addEventListener('pointerleave', closeSoon);
  }
  document.querySelectorAll('[data-report-preview]').forEach(button => {
    button.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse') show(button); });
    button.addEventListener('pointerleave', closeSoon);
    button.addEventListener('focus', () => show(button));
    button.addEventListener('blur', remove);
    button.addEventListener('click', remove);
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') remove(); });
  window.addEventListener('resize', remove);
  window.addEventListener('scroll', event => { if (preview && !preview.contains(event.target)) remove(); }, true);
})();
