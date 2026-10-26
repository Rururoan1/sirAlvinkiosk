// Staff dashboard: login, summary numbers, trends, recent activity.

const $ = (id) => document.getElementById(id);

function h(tag, className, text) {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (text !== undefined) el.textContent = text;
  return el;
}

async function api(action, body, query = '') {
  const res = await fetch(`api.php?action=${action}${query}`, body ? {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  } : undefined);
  const data = await res.json();
  if (!res.ok) throw Object.assign(new Error(data.error || 'Something went wrong.'), { status: res.status });
  return data;
}

function showLogin() {
  $('login').hidden = false;
  $('dashboard').hidden = true;
  $('who').hidden = true;
}

// ---------- login / logout ----------

$('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('login-error').textContent = '';
  try {
    await api('login', { username: $('username').value.trim(), password: $('password').value });
    $('password').value = '';
    await load(1);
  } catch (err) {
    $('login-error').textContent = err.message;
  }
});

$('btn-logout').addEventListener('click', async () => {
  await api('logout', {});
  showLogin();
});

// ---------- dashboard ----------

async function load(page) {
  try {
    const d = await api('stats', null, `&page=${page}`);
    $('login').hidden = true;
    $('dashboard').hidden = false;
    $('who').hidden = false;
    $('who-name').textContent = d.username;

    renderStats(d);
    renderBars('symptom-bars', d.by_symptom.map((r) => [r.name, r.total]));
    renderBars('week-bars', d.last_7_days.map((r) => [
      new Date(r.day + 'T00:00:00').toLocaleDateString([], { month: 'short', day: 'numeric' }),
      r.total,
    ]));
    renderLog(d.recent);
    renderPager(d.page, d.total_pages);
  } catch (err) {
    if (err.status === 401) showLogin();
    else $('login-error').textContent = err.message;
  }
}

function renderStats(d) {
  const cards = [
    ['Total', d.total, false],
    ['Medicine recommended', d.totals.medicine, false],
    ['Referred: danger sign', d.totals.referral_danger, true],
    ['Referred: severe answer', d.totals.referral_severe, true],
    ['Referred: daily limit', d.totals.limit_reached, true],
  ];
  $('stats').replaceChildren(...cards.map(([label, value, red]) => {
    const card = h('div', red ? 'stat red' : 'stat');
    card.append(h('b', '', value), h('span', '', label));
    return card;
  }));
}

function renderBars(id, rows) {
  const box = $(id);
  if (!rows.length) {
    box.replaceChildren(h('p', '', 'No data yet.'));
    return;
  }
  const max = Math.max(...rows.map(([, value]) => value));
  box.replaceChildren(...rows.map(([label, value]) => {
    const row = h('div', 'bar-row');
    const bar = h('div', 'bar');
    const fill = h('i');
    fill.style.width = `${Math.round((value / max) * 100)}%`;
    bar.appendChild(fill);
    row.append(h('span', '', label), bar, h('b', '', value));
    return row;
  }));
}

const RESULTS = {
  medicine:        ['Medicine given', 'ok'],
  referral_danger: ['Referred: danger sign', 'referred'],
  referral_severe: ['Referred: severe answer', 'referred'],
  limit_reached:   ['Referred: daily limit', 'referred'],
};

function renderLog(rows) {
  if (!rows.length) {
    const tr = h('tr');
    const td = h('td', '', 'No activity yet.');
    td.colSpan = 5;
    tr.appendChild(td);
    $('log-body').replaceChildren(tr);
    return;
  }
  $('log-body').replaceChildren(...rows.map((r) => {
    const [text, cls] = RESULTS[r.result_type];
    const tr = h('tr');
    tr.append(
      h('td', '', new Date(r.created_at.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })),
      h('td', '', r.device_id),
      h('td', '', r.symptom || '-'),
      h('td', cls, text),
      h('td', '', r.medicine || '-')
    );
    return tr;
  }));
}

function renderPager(page, totalPages) {
  if (totalPages <= 1) {
    $('pager').replaceChildren();
    return;
  }
  const prev = h('button', 'btn secondary small', 'Previous');
  prev.disabled = page <= 1;
  prev.addEventListener('click', () => load(page - 1));

  const next = h('button', 'btn secondary small', 'Next');
  next.disabled = page >= totalPages;
  next.addEventListener('click', () => load(page + 1));

  $('pager').replaceChildren(prev, h('span', '', `Page ${page} of ${totalPages}`), next);
}

load(1);
