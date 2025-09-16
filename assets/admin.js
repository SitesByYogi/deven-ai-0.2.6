
(function ($) {
  $(document).on('click', '#deven-test-send', async function (e) {
    e.preventDefault();
    const q = $('#deven-test-input').val().trim();
    if (!q) return;
    const outEl = $('#deven-test-output');
    outEl.removeClass('is-error').html('…');
    const r = await fetch(DevENAI.restUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DevENAI.nonce },
      body: JSON.stringify({ messages: [{ role: 'user', content: q }] })
    }).then(r => r.json());

    const text = r.text || (r?.detail?.error?.message) || JSON.stringify(r, null, 2);
    const html = (window.DevENMarkdown ? DevENMarkdown.renderMarkdown(text) : text);
    if (r && r.error) outEl.addClass('is-error');
    outEl.addClass('deven-output').html(html);
    if (window.DevENMarkdown) DevENMarkdown.highlightAll(outEl[0]);
  });
})(jQuery);
