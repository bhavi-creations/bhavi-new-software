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
document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
  const input = document.getElementById(button.dataset.passwordToggle);
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.textContent = show ? 'Hide' : 'Show';
  button.setAttribute('aria-pressed', String(show));
}));
const menu = document.querySelector('.menu-toggle');
if (menu) menu.addEventListener('click', () => {
  const open = document.getElementById('portalSidebar').classList.toggle('open');
  menu.setAttribute('aria-expanded', String(open));
});
document.querySelectorAll('[data-department-select]').forEach(department => {
  const employee = document.getElementById(department.dataset.departmentSelect);
  const update = () => {
    Array.from(employee.options).forEach(option => {
      option.hidden = Boolean(option.dataset.department && department.value && option.dataset.department !== department.value);
      option.disabled = option.hidden;
    });
    if (employee.selectedOptions[0]?.disabled) employee.value = '';
  };
  department.addEventListener('change', update);
  update();
});
document.querySelectorAll('[data-range-preset]').forEach(select => select.addEventListener('change', () => {
  const from = document.querySelector('[name="from_date"]');
  const to = document.querySelector('[name="to_date"]');
  if (select.value === 'custom') return;
  const end = new Date(select.dataset.today + 'T12:00:00');
  const start = new Date(end);
  if (select.value === 'week') start.setDate(end.getDate() - 6);
  if (select.value === 'month') start.setDate(1);
  const format = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  from.value = format(start); to.value = format(end);
}));
document.querySelectorAll('[data-filter-department]').forEach(select => select.addEventListener('change', () => {
  select.form.querySelector('[name="employee"]').value = '';
  select.form.requestSubmit();
}));
