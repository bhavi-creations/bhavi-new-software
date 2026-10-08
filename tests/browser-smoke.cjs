// Optional Chrome/Edge smoke checks, using Node's built-in WebSocket and CDP.
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const [browserPath, baseUrl, password] = process.argv.slice(2);
const storage = path.resolve(__dirname, '../storage');
fs.mkdirSync(storage, { recursive: true });
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'bhavi-browser-'));
const browser = spawn(browserPath, ['--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--in-process-gpu', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'], env: { ...process.env, SystemDrive: process.env.SystemDrive || path.parse(os.homedir()).root.slice(0, 2), ProgramData: process.env.ProgramData || path.join(path.parse(os.homedir()).root, 'ProgramData'), LOCALAPPDATA: process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local'), APPDATA: process.env.APPDATA || path.join(os.homedir(), 'AppData', 'Roaming') } });
let browserErrors = '';
browser.stderr.on('data', data => { browserErrors = (browserErrors + data).slice(-4000); });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket, messageId = 0;
const pending = new Map();
async function command(method, params = {}) {
  const id = ++messageId;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('Browser command timed out: ' + method)); }, 15000);
    pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
    socket.send(JSON.stringify({ id, method, params }));
  });
}
async function evaluate(expression) {
  const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
}
async function waitFor(expression) {
  for (let i = 0; i < 120; i++) {
    try { if (await evaluate(expression)) return; } catch {}
    await delay(100);
  }
  throw new Error('Browser wait timed out: ' + expression);
}
async function navigate(route) {
  const destination = new URL(route, `${baseUrl}/`).href;
  await command('Page.navigate', { url: destination });
  await waitFor(`location.href === ${JSON.stringify(destination)} && document.readyState === "complete"`);
}
async function login(username, dashboard) {
  await navigate('login.php');
  await waitFor('Boolean(document.querySelector("#username"))');
  await evaluate(`document.querySelector('#username').value=${JSON.stringify(username)}; document.querySelector('#password').value=${JSON.stringify(password)}; document.querySelector('form').requestSubmit(); true`);
  await waitFor(`location.pathname.endsWith(${JSON.stringify(dashboard)}) && document.readyState === 'complete' && Boolean(document.querySelector('.portal-topbar'))`);
}
async function screenshot(filename, width, height) {
  await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 800 });
  await delay(150);
  const result = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(path.join(storage, filename), Buffer.from(result.data, 'base64'));
}
(async () => {
  try {
    const portFile = path.join(profile, 'DevToolsActivePort');
    let port;
    for (let i = 0; i < 150; i++) {
      try {
        const lines = fs.readFileSync(portFile, 'utf8').trim().split('\n');
        if (/^\d+$/.test(lines[0]) && lines[1]?.startsWith('/devtools/')) { port = lines[0]; break; }
      } catch (error) {
        if (!['ENOENT', 'EBUSY', 'EACCES', 'EPERM'].includes(error.code)) throw error;
      }
      await delay(100);
    }
    if (!port) throw new Error('Headless browser did not start. ' + browserErrors);
    const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
    const target = targets.find(item => item.type === 'page');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!pending.has(message.id)) return;
      const request = pending.get(message.id); pending.delete(message.id);
      message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result);
    });
    socket.addEventListener('close', () => {
      for (const request of pending.values()) request.reject(new Error('Browser disconnected before checks completed. ' + browserErrors));
      pending.clear();
    });
    await command('Page.enable');
    await login('manager.test', 'manager-dashboard.php');
    await screenshot('manager-dashboard.png', 1440, 1000);
    assert.equal(await evaluate("document.querySelector('.role-badge').textContent"), 'Manager');
    await navigate('admin-employees.php');
    await evaluate("document.querySelector('[data-dialog]').click(); true");
    assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('Username')"), true);
    await evaluate("document.querySelector('dialog[open] [data-close-dialog]').click(); true");
    assert.equal(await evaluate("Boolean(document.querySelector('dialog[open]'))"), false);
    assert.equal(await evaluate('document.querySelectorAll("img[src^=\'employee-photo.php\']").length > 0'), true);
    await navigate('add-client.php');
    const clientProfileUrl = await evaluate("document.querySelector('a[href^=\"client-profile.php?id=\"]').getAttribute('href')");
    await navigate(clientProfileUrl);
    assert.equal(await evaluate("document.body.textContent.includes('PRIVATE-PACKAGE-TEST') && document.body.textContent.includes('3,900.00')"), true);
    await screenshot('client-profile.png', 1440, 1100);
    await screenshot('client-profile-mobile.png', 390, 844);
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await command('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('admin-add-client.php');
    await evaluate("document.querySelector('#payment_total').value='10000'; document.querySelector('#payment-rows input[type=number]').value='2000'; document.querySelector('#payment_total').dispatchEvent(new Event('input', {bubbles:true})); true");
    assert.equal(await evaluate("document.querySelector('#remaining-preview').textContent"), 'INR 8,000.00');
    await evaluate("document.querySelector('[data-add-row=payment]').click(); true");
    assert.equal(await evaluate("document.querySelectorAll('#payment-rows .repeat-row').length"), 2);
    await evaluate("document.querySelector('#payment-rows .repeat-row:last-child [data-remove-row]').click(); true");
    assert.equal(await evaluate("document.querySelectorAll('#payment-rows .repeat-row').length"), 1);
    await screenshot('client-payment-form.png', 1440, 1100);
    await navigate('admin-employees.php');
    const employeeEditUrl = await evaluate("document.querySelector('a[href^=\"admin-add-employee.php?id=\"]').getAttribute('href')");
    await navigate(employeeEditUrl);
    await evaluate("document.querySelector('#salary-rows input[type=number]').value='14000'; document.querySelector('[name=benefit_pf]').checked=true; document.querySelector('[name=benefit_esi]').checked=true; document.querySelector('#salary-rows input[type=number]').dispatchEvent(new Event('input', {bubbles:true})); true");
    assert.equal(await evaluate("document.querySelector('#salary-preview').textContent.includes('13,107.50')"), true);
    await evaluate("document.querySelector('[data-add-row=salary]').click(); true");
    assert.equal(await evaluate("document.querySelectorAll('#salary-rows .repeat-row').length"), 2);
    await screenshot('employee-salary-form.png', 1440, 1100);
    await screenshot('employee-salary-form-mobile.png', 390, 844);
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await command('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('manager-leave-requist.php');
    assert.equal(await evaluate('Boolean(document.querySelector("input[value=\'delete_leave\']"))'), true);
    await navigate('manager-dailywork.php');
    await evaluate("document.querySelector('[data-range-preset]').value='week'; document.querySelector('[data-range-preset]').dispatchEvent(new Event('change')); true");
    const dates = await evaluate("[document.querySelector('[name=from_date]').value,document.querySelector('[name=to_date]').value]");
    assert.equal((new Date(dates[1]) - new Date(dates[0])) / 86400000, 6);
    await evaluate("const select=document.querySelector('[data-filter-department]'); select.value=Array.from(select.options).find(option=>option.textContent==='Website').value; select.dispatchEvent(new Event('change')); true");
    await waitFor("location.search.includes('department=') && document.readyState === 'complete'");
    await waitFor("!Array.from(document.querySelector('#employee').options).some(option=>option.textContent==='Employee seo')");
    await evaluate("document.querySelector('.signout').requestSubmit(); true");
    await waitFor("location.pathname.endsWith('login.php') && Boolean(document.querySelector('#username'))");
    await login('website.test', 'website-employee-dashboard.php');
    await screenshot('employee-dashboard.png', 1440, 1100);
    assert.equal(await evaluate("document.querySelector('.role-badge').textContent"), 'Employee');
    await navigate('employee-profile.php');
    assert.equal(await evaluate("document.body.textContent.includes('13,107.50')"), true);
    assert.equal(await evaluate("document.body.textContent.includes('website.test') && document.body.textContent.includes('Guardian number') && document.body.textContent.includes('Role / job title')"), true);
    await screenshot('employee-own-salary.png', 1440, 1100);
    await screenshot('employee-own-salary-mobile.png', 390, 844);
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await command('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('admin-employees.php');
    assert.equal(await evaluate("document.querySelectorAll('table tbody tr').length === 1 && !document.body.textContent.includes('Employee seo')"), true);
    assert.equal(await evaluate("Boolean(document.querySelector('a[href^=\"employee-profile.php?id=\"]'))"), true);
    await navigate(clientProfileUrl);
    assert.equal(await evaluate("document.body.textContent.includes('Pinterest') && !document.body.textContent.includes('PRIVATE-PACKAGE-TEST') && !document.body.textContent.includes('3,900.00')"), true);
    await navigate('employee-assigned-work.php');
    const assignedDateUrl = await evaluate("document.querySelector('#assigned-work a[href*=assignment_date]').getAttribute('href')");
    await navigate(assignedDateUrl);
    assert.equal(await evaluate("document.querySelectorAll('#assigned-work input[type=radio]').length >= 2"), true);
    assert.equal(await evaluate("document.querySelector('[name=time_spent_hours]').value"), '6 hours 15 min');
    await evaluate("document.querySelector('[name=time_spent_hours]').value='24:00'; document.querySelector('#assigned-work form').requestSubmit(); true");
    await waitFor("location.search.includes('assignment_date=') && document.readyState==='complete' && document.querySelector('[name=time_spent_hours]')?.value==='24 hours'");
    await navigate(assignedDateUrl);
    assert.equal(await evaluate("document.querySelector('[name=time_spent_hours]').value"), '24 hours');
    await navigate('employee-daily-work.php');
    assert.equal(await evaluate("document.querySelector('#client_id').required"), false);
    assert.equal(await evaluate("document.querySelector('[name=website_new_count]').required"), false);
    assert.equal(await evaluate("Boolean(document.querySelector('#assigned-work'))"), false);
    assert.equal(await evaluate("document.querySelector('[name=time_spent_hours]').type"), 'text');
    await screenshot('employee-daily-work.png', 1440, 1000);
    await navigate('manager-notification.php');
    assert.equal(await evaluate("document.querySelectorAll('.notification-day').length >= 2"), true);
    assert.equal(await evaluate("document.querySelector('.notification-day').open"), false);
    await evaluate("document.querySelector('.notification-day summary').click(); true");
    assert.equal(await evaluate("document.querySelector('.notification-day').open"), true);
    await screenshot('notifications-by-day.png', 1440, 1000);
    await navigate('my-leave-requests.php');
    assert.equal(await evaluate("document.body.textContent.includes('Please complete the handover <today>')"), true);
    await screenshot('employee-mobile.png', 390, 844);
    await evaluate("document.querySelector('.menu-toggle').click(); true");
    assert.equal(await evaluate("document.querySelector('.portal-sidebar').classList.contains('open')"), true);
    assert.equal(await evaluate("document.documentElement.scrollWidth <= innerWidth"), true);
    console.log('PASS: Browser client profiles, own employee details, 24-hour time persistence, dialogs, filters and mobile layouts.');
  } finally {
    if (socket) socket.close();
    browser.kill();
    await new Promise(resolve => { if (browser.exitCode !== null) resolve(); else { browser.once('exit', resolve); setTimeout(resolve, 5000); } });
    const resolvedProfile = path.resolve(profile);
    const resolvedTemp = path.resolve(os.tmpdir());
    if (resolvedProfile.startsWith(resolvedTemp + path.sep) && path.basename(resolvedProfile).startsWith('bhavi-browser-')) { try { fs.rmSync(resolvedProfile, { recursive: true, force: true, maxRetries: 5, retryDelay: 250 }); } catch (error) { console.warn('Temporary browser profile cleanup:', error.code); } }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
