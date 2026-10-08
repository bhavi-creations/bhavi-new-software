(() => {
  document.querySelectorAll('input[name="time_spent_hours"]').forEach(input => {
    const previousHours = Number(input.value);
    const totalMinutes = Number.isFinite(previousHours) ? Math.round(previousHours * 60) : 0;
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    input.type = 'text';
    input.inputMode = 'text';
    input.removeAttribute('min');
    input.removeAttribute('max');
    input.removeAttribute('step');
    input.placeholder = 'e.g. 1 hour 30 min';
    input.value = (hours ? hours + (hours === 1 ? ' hour' : ' hours') : '') +
      (minutes ? (hours ? ' ' : '') + minutes + ' min' : (!hours ? '0 min' : ''));
    const label = input.labels && input.labels[0];
    if (label) label.textContent = 'Time spent (e.g. 1 hour 30 min)';
  });
})();
