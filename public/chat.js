// Chatbot widget. Talks to api.php?action=chat, which talks to Ollama.
// The AI's rules live on the server (prompts/chatbot_system_prompt.txt), not here.

(function () {
  const log = document.getElementById('chat-log');
  const input = document.getElementById('chat-input');
  const sendBtn = document.getElementById('chat-send');
  const panel = document.getElementById('chat-panel');
  const toggle = document.getElementById('chat-toggle');

  let messages = []; // {role: 'user' | 'assistant', content}

  function bubble(who, text, extraClass) {
    const el = document.createElement('div');
    el.className = `bubble ${who}${extraClass ? ' ' + extraClass : ''}`;
    el.textContent = text; // text only, never HTML
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  function greet() {
    bubble('bot', 'Hi! I can answer simple questions about common symptoms like headache, cough, colds, fever, body pain, or sore throat. What are you feeling?');
  }

  function openPanel(open) {
    panel.hidden = !open;
    toggle.hidden = open;
    toggle.setAttribute('aria-expanded', String(open));
    if (open) {
      if (!log.children.length) greet();
      input.focus();
    }
  }

  async function send() {
    const text = input.value.trim();
    if (!text || sendBtn.disabled) return;

    input.value = '';
    sendBtn.disabled = true;
    messages.push({ role: 'user', content: text });
    bubble('user', text);
    const typing = bubble('bot', 'Typing...', 'typing');

    try {
      const res = await fetch('api.php?action=chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Something went wrong.');

      typing.remove();
      bubble('bot', data.reply, data.type === 'emergency' ? 'emergency' : '');
      messages.push({ role: 'assistant', content: data.reply });
    } catch (err) {
      typing.remove();
      messages.pop(); // let the student try again
      bubble('bot', err.message === 'Failed to fetch'
        ? 'Cannot reach the kiosk server. Please ask a staff member for help.'
        : err.message, 'emergency');
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  }

  toggle.addEventListener('click', () => openPanel(true));
  document.getElementById('chat-close').addEventListener('click', () => openPanel(false));
  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') send(); });

  // A kiosk is shared: starting over on the main screen also clears the chat.
  document.getElementById('btn-restart').addEventListener('click', () => {
    messages = [];
    log.replaceChildren();
  });
})();
