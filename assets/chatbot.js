
(function(){
  function h(tag, cls, text){ const el = document.createElement(tag); if(cls) el.className = cls; if(text) el.textContent = text; return el; }

  function mount(box){
    const messages = box.querySelector('.deven-chatbot__messages');
    const form = box.querySelector('.deven-chatbot__form');
    const input = box.querySelector('.deven-chatbot__input');
    const welcome = messages.dataset.welcome || '';

    function add(role, content, asHtml){
      const row = h('div', 'deven-chatbot__msg deven-chatbot__msg--'+role);
      const bubble = h('div','deven-chatbot__bubble');
      bubble.classList.add('deven-output');
      if (asHtml) bubble.innerHTML = content; else bubble.textContent = content;
      row.appendChild(bubble);
      messages.appendChild(row);
      messages.scrollTop = messages.scrollHeight;
      return bubble;
    }

    if (welcome) add('bot', welcome, false);

    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      add('user', text, false);
      input.value = '';

      const loading = add('bot', '…', false);

      try {
        const r = await fetch(DevENAIFront.restUrl, {
          method:'POST',
          headers:{'Content-Type':'application/json','X-WP-Nonce': DevENAIFront.nonce},
          body: JSON.stringify({ messages:[{role:'user', content:text}] })
        }).then(r=>r.json());
        const txt = r.text || JSON.stringify(r);
        const html = (window.DevENMarkdown ? DevENMarkdown.renderMarkdown(txt) : txt);
        loading.innerHTML = html;
        if (window.DevENMarkdown) DevENMarkdown.highlightAll(messages);
      } catch (err) {
        loading.textContent = 'Error. Please try again.';
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.deven-chatbot').forEach(mount);
  });
})();
