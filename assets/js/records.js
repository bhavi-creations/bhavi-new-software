(() => {
  const format = n => 'INR ' + (n / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const cents = value => Math.round((Number(value) || 0) * 100);
  function refresh() {
    const remaining = document.getElementById('remaining-preview');
    if (remaining) {
      let paid = cents(remaining.dataset.paid);
      document.querySelectorAll('#payment-rows input[type="number"]').forEach(el => paid += cents(el.value));
      const total = cents(document.getElementById('payment_total').value);
      remaining.textContent = format(total - paid);
      document.getElementById('total-preview').textContent = format(total);
      document.getElementById('paid-preview').textContent = format(paid);
      remaining.closest('.money-card').classList.toggle('overpaid', paid > total);
    }
    const other = document.querySelector('[name="benefit_other"]');
    if (other) document.getElementById('other-benefits-field').hidden = !other.checked;
    const preview = document.getElementById('salary-preview');
    if (preview) {
      const pf = document.querySelector('[name="benefit_pf"]').checked;
      const esi = document.querySelector('[name="benefit_esi"]').checked;
      preview.replaceChildren();
      document.querySelectorAll('#salary-rows input[type="number"]').forEach((el, i) => {
        if (!el.value) return;
        const gross = cents(el.value), base = Math.round(gross / 2);
        const employeePf = pf ? Math.round(base * 12 / 100) : 0;
        const employeeEsi = esi ? Math.round(base * 75 / 10000) : 0;
        const companyEsi = esi ? Math.round(base * 325 / 10000) : 0;
        const section = document.createElement('section'); section.className = 'salary-estimate';
        const heading = document.createElement('h3'); heading.textContent = 'Salary ' + (i + 1) + ' · Preview'; section.append(heading);
        const cards = document.createElement('div'); cards.className = 'money-summary';
        [['Monthly salary',gross],['Deducted from salary',employeePf + employeeEsi],['Employee receives',gross - employeePf - employeeEsi]].forEach(([label,amount],index) => {
          const card = document.createElement('div'); card.className = 'money-card' + (index === 2 ? ' highlight' : '');
          const title = document.createElement('span'); title.textContent = label;
          const value = document.createElement('strong'); value.textContent = format(amount);
          card.append(title,value); cards.append(card);
        });
        section.append(cards);
        const details = document.createElement('details'); details.className = 'simple-details';
        const summary = document.createElement('summary'); summary.textContent = 'View PF & ESI breakdown'; details.append(summary);
        const description = document.createElement('p'); description.textContent = 'Calculation base: half of salary = ' + format(base) + '.'; details.append(description);
        const employee = document.createElement('p'); employee.textContent = 'Employee deduction: PF ' + format(employeePf) + ' + ESI ' + format(employeeEsi) + '.';
        const company = document.createElement('p'); company.textContent = 'Company pays separately: PF ' + format(employeePf) + ' + ESI ' + format(companyEsi) + '. This does not reduce employee salary.';
        details.append(employee,company); section.append(details); preview.append(section);
      });
    }
  }
  document.addEventListener('click', event => {
    const removeAssignmentTask = event.target.closest('[data-remove-assignment-task]');
    if (removeAssignmentTask) {
      removeAssignmentTask.closest('[data-assignment-task]').remove();
      document.querySelectorAll('[data-assignment-task]').forEach((task, index) => {
        task.querySelector('[data-task-heading]').textContent = 'Task ' + (index + 1);
        task.querySelectorAll('input,textarea').forEach(field => {
          field.name = field.name.replace(/tasks\[\d+\]/, 'tasks[' + index + ']');
          field.id = field.id.replace(/-\d+$/, '-' + index);
          field.previousElementSibling?.setAttribute('for', field.id);
        });
      });
      return;
    }
    const addAssignmentTask = event.target.closest('[data-add-assignment-task]');
    if (addAssignmentTask) {
      const container = document.querySelector('[data-assignment-tasks]');
      const index = container.querySelectorAll('[data-assignment-task]').length;
      const panel = document.createElement('div'); panel.className = 'panel assignment-task'; panel.dataset.assignmentTask = '';
      const heading = document.createElement('h3'); heading.dataset.taskHeading = ''; heading.textContent = 'Task ' + (index + 1); panel.append(heading);
      [['title','Task title','input'],['description','Task brief','textarea']].forEach(([key,label,tag]) => {
        const field = document.createElement('div'); field.className = 'field full';
        const wrapper = document.createElement('label'); wrapper.textContent = label; wrapper.htmlFor = 'task-' + key + '-' + index;
        const control = document.createElement(tag); control.id = wrapper.htmlFor;
        control.name = 'tasks[' + index + '][' + key + ']';
        if (key === 'title') { control.maxLength = 255; control.required = true; }
        else { control.maxLength = 5000; control.required = true; }
        field.append(wrapper,control); panel.append(field);
      });
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button secondary'; remove.dataset.removeAssignmentTask = ''; remove.textContent = 'Remove task'; panel.append(remove);
      container.append(panel); panel.querySelector('input').focus(); return;
    }
    const remove = event.target.closest('[data-remove-row]');
    if (remove) { remove.closest('.repeat-row').remove(); refresh(); }
    const add = event.target.closest('[data-add-row]');
    if (!add) return;
    const salary = add.dataset.addRow === 'salary';
    const social = add.dataset.addRow === 'social';
    const container = document.getElementById(salary ? 'salary-rows' : social ? 'social-link-rows' : 'payment-rows');
    const indices = [...container.querySelectorAll('input')].map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] || 0));
    const index = Math.max(-1, ...indices) + 1;
    const row = document.createElement('div'); row.className = 'fields repeat-row';
    if (social) row.classList.add('social-link-row');
    const fields = salary ? [['amount','Monthly salary (₹)','number'],['from','Salary starts on','date'],['to','Ends on (optional)','date']] : social ? [['platform','Platform name','text'],['url','Social media URL','url']] : [['date','Date received','date'],['amount','Amount received (₹)','number']];
    fields.forEach(([key,label,type]) => {
      const field = document.createElement('div'); field.className = 'field';
      const wrapper = document.createElement('label'); wrapper.textContent = label;
      const input = document.createElement('input'); input.type = type; input.name = (salary ? 'salary' : social ? 'social_links' : 'payments') + '[' + index + '][' + (social && key === 'platform' ? 'platform' : social && key === 'url' ? 'url' : key) + ']';
      if (social && key === 'platform') { input.maxLength = 100; input.placeholder = 'e.g. Instagram'; }
      if (social && key === 'url') { input.maxLength = 2048; input.placeholder = 'https://'; }
      if (type === 'number') { input.min = '0.01'; input.max = '9999999999.99'; input.step = '0.01'; }
      wrapper.append(input); field.append(wrapper); row.append(field);
    });
    const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.dataset.removeRow = ''; button.textContent = 'Remove'; row.append(button);
    container.append(row); row.querySelector('input').focus(); refresh();
  });
  document.addEventListener('invalid', event => {
    let parent = event.target.parentElement;
    while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
  }, true);
  document.addEventListener('input', refresh); document.addEventListener('change', refresh); refresh();
})();
