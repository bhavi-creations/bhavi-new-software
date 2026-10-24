(() => {
  const form = document.querySelector('[data-daily-work-form]');
  if (!form) return;
  const container = form.querySelector('[data-daily-tasks]');
  const template = document.getElementById('daily-task-template');
  let nextIndex = container.children.length;
  function refresh() {
    const tasks = [...container.querySelectorAll('[data-daily-task]')];
    tasks.forEach((task, index) => {
      task.querySelector('[data-daily-task-heading]').textContent = 'Task ' + (index + 1);
      task.querySelector('[data-remove-daily-task]').disabled = tasks.length === 1;
    });
    form.querySelector('[name="task_count"]').value = tasks.length;
    form.querySelector('[data-daily-task-summary]').textContent = tasks.length + (tasks.length === 1 ? ' task' : ' tasks') + ' will be submitted.';
  }
  form.addEventListener('click', event => {
    if (event.target.closest('[data-add-daily-task]')) {
      const fragment = document.createElement('template');
      fragment.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
      container.append(fragment.content.cloneNode(true));
      refresh();
      container.lastElementChild.querySelector('select').focus();
    }
    const remove = event.target.closest('[data-remove-daily-task]');
    if (remove && container.children.length > 1) {
      const task = remove.closest('[data-daily-task]');
      const next = task.nextElementSibling || task.previousElementSibling;
      task.remove();
      refresh();
      next.querySelector('select').focus();
    }
  });
  if (form.hasAttribute('data-social-work-form')) {
    const accounts = window.socialClientPlatforms || {};
    form.addEventListener('change', event => {
      const select = event.target.closest('[data-social-client]');
      if (!select) return;
      const task = select.closest('[data-daily-task]');
      const box = task.querySelector('[data-social-platforms]');
      box.querySelectorAll('label').forEach(label => label.remove());
      const links = accounts[select.value] || [];
      links.forEach(link => {
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = select.name.replace('[client_id]', '[platforms][]');
        checkbox.value = link.id;
        label.append(checkbox, document.createTextNode(link.platform_name));
        box.insertBefore(label, box.querySelector('[data-social-empty]'));
      });
      box.querySelector('[data-social-empty]').textContent = links.length ? '' : 'No social accounts are saved for this client.';
    });
  }
  refresh();
})();
