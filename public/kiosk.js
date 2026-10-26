// Kiosk screens: danger checklist -> symptom -> questions -> result.
// Plain JavaScript, no libraries. Each screen is a <section data-screen="...">.

const $ = (id) => document.getElementById(id);

// Small helper: make an element with a class and text (text is never treated as HTML).
function h(tag, className, text) {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (text !== undefined) el.textContent = text;
  return el;
}

// Anonymous per-browser id. Only used to enforce the daily medicine limit.
function deviceId() {
  let id = localStorage.getItem('kiosk_device_id');
  if (!id) {
    id = 'dev-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem('kiosk_device_id', id);
  }
  return id;
}

async function api(action, body, query = '') {
  const res = await fetch(`api.php?action=${action}${query}`, body ? {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  } : undefined);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Something went wrong.');
  return data;
}

function show(name) {
  document.querySelectorAll('[data-screen]').forEach((s) => { s.hidden = s.dataset.screen !== name; });
  $('error').textContent = '';
  window.scrollTo(0, 0);
}

function showError(err) {
  $('error').textContent = err.message === 'Failed to fetch'
    ? 'Cannot reach the kiosk server. Please ask a staff member for help.'
    : err.message;
}

let start = null;               // danger signs + symptoms from the server
let dangerChecked = new Set();  // ids of checked danger signs
let symptom = null;             // chosen symptom
let questions = [];
let answers = {};               // question_key -> option_value

// ---------- 1. danger checklist ----------

function renderDanger() {
  dangerChecked = new Set();
  $('danger-list').replaceChildren(...start.danger_signs.map((sign) => {
    const label = h('label', 'check');
    const box = h('input');
    box.type = 'checkbox';
    box.addEventListener('change', () => {
      label.classList.toggle('checked', box.checked);
      box.checked ? dangerChecked.add(sign.id) : dangerChecked.delete(sign.id);
      updateDangerButton();
    });
    label.append(box, h('span', '', sign.label));
    return label;
  }));
  updateDangerButton();
}

function updateDangerButton() {
  const btn = $('btn-danger-next');
  const anyChecked = dangerChecked.size > 0;
  btn.textContent = anyChecked ? 'See what to do next' : 'None of these \u2013 continue';
  btn.classList.toggle('danger', anyChecked);
}

$('btn-danger-next').addEventListener('click', () => {
  if (dangerChecked.size > 0) submit(null);
  else show('symptoms');
});

// ---------- 2. symptom ----------

function renderSymptoms() {
  $('symptom-list').replaceChildren(...start.symptoms.map((s) => {
    const btn = h('button', 'choice', s.name);
    btn.type = 'button';
    btn.addEventListener('click', () => pickSymptom(s));
    return btn;
  }));
}

$('btn-back-symptoms').addEventListener('click', () => show('danger'));

// ---------- 3. questions ----------

async function pickSymptom(s) {
  try {
    const data = await api('questions', null, `&symptom_id=${s.id}`);
    symptom = s;
    questions = data.questions;
    answers = {};
    $('symptom-title').textContent = s.name;
    renderQuestions();
    show('questions');
  } catch (err) {
    showError(err);
  }
}

function renderQuestions() {
  $('btn-submit').disabled = true;
  $('question-list').replaceChildren(...questions.map((q) => {
    const block = h('div', 'question');
    const grid = h('div', 'grid');

    q.options.forEach((opt) => {
      const btn = h('button', 'choice', opt.option_text);
      btn.type = 'button';
      btn.addEventListener('click', () => {
        grid.querySelectorAll('.choice').forEach((b) => b.classList.remove('selected'));
        btn.classList.add('selected');
        answers[q.question_key] = opt.option_value;
        $('btn-submit').disabled = questions.some((x) => !answers[x.question_key]);
      });
      grid.appendChild(btn);
    });

    block.append(h('h3', '', q.question_text), grid);
    return block;
  }));
}

$('btn-back-questions').addEventListener('click', () => show('symptoms'));
$('btn-submit').addEventListener('click', () => submit(symptom.id));

// ---------- 4. result ----------

async function submit(symptomId) {
  const buttons = [$('btn-danger-next'), $('btn-submit')];
  buttons.forEach((b) => { b.disabled = true; }); // stops a double tap from logging twice
  try {
    const data = await api('submit', {
      device_id: deviceId(),
      symptom_id: symptomId,
      danger_flags: [...dangerChecked],
      answers: symptomId ? answers : {},
    });
    renderResult(data.result);
    show('result');
  } catch (err) {
    showError(err);
  } finally {
    $('btn-danger-next').disabled = false;
    $('btn-submit').disabled = questions.some((x) => !answers[x.question_key]);
  }
}

function renderResult(result) {
  const box = h('div', 'result');

  if (result.type === 'referral') {
    box.classList.add('referral');
    box.append(h('h2', '', 'Go to the school clinic'), h('p', 'message', result.message));
  } else {
    const m = result.medicine;
    const dl = h('dl');
    [['Purpose', m.purpose, 'plain'], ['Dosage', m.dosage, ''], ['When to take it', m.timing, '']]
      .forEach(([label, value, cls]) => dl.append(h('dt', '', label), h('dd', cls, value)));

    const left = result.remaining_today;
    const remaining = left === 0
      ? 'This was your last recommendation for this medicine today.'
      : `You can get this recommendation ${left} more time${left === 1 ? '' : 's'} today.`;

    box.append(
      h('p', 'kicker', 'Recommended medicine'),
      h('h2', 'medicine-name', m.name),
      dl,
      h('p', 'remaining', remaining),
      h('p', 'note', 'General guidance only, not a diagnosis. If you feel worse, go to the school clinic.')
    );
  }

  $('result').replaceChildren(box);
}

$('btn-restart').addEventListener('click', () => {
  symptom = null;
  questions = [];
  answers = {};
  renderDanger();
  show('danger');
});

// ---------- start ----------

(async function init() {
  try {
    start = await api('start');
    renderDanger();
    renderSymptoms();
  } catch (err) {
    showError(err);
  }
})();
