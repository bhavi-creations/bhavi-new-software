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
  refresh();
})();
