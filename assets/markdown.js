// Lightweight Markdown → HTML renderer with smarter lists, definition blocks, timeline tagging + highlight hook
(function (global) {
  function escHtml(s) {
    // Don't escape ">" so blockquote detection still works
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  }
  function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  function normalizeInlineLists(md) {
    if (/\b1\.\s.+\b2\.\s/.test(md)) md = md.replace(/(^|\s)(\d+)\.\s/g, '\n$2. ');
    if (/(^|[\s\S])- \S+.*- \S+/.test(md)) md = md.replace(/(^|\s)-\s/g, '\n- ');
    return md;
  }

  function renderMarkdown(md) {
    if (!md) return '';
    md = md.replace(/\r\n?/g, '\n');
    md = normalizeInlineLists(md);

    // 1) Extract fenced code first
    const fences = [];
    md = md.replace(/```(\w+)?\n([\s\S]*?)```/g, (_, lang, code) => {
      const idx = fences.length;
      fences.push({ lang: (lang || '').toLowerCase(), code });
      return `@@FENCE${idx}@@`;
    });

    // 2) Extract & allowlist safe HTML so it survives escaping
    const allowed = [];
    const ALLOWED = /<\/?(?:h[1-6]|p|ul|ol|li|blockquote|strong|b|em|i|br|pre|code|a)(\s+[^>]*)?>/ig;
    md = md.replace(ALLOWED, (raw) => {
      let tag = raw;

      // sanitize attributes
      tag = tag.replace(/\son\w+\s*=\s*(['"]).*?\1/gi, '');    // drop on*
      tag = tag.replace(/javascript:/gi, '');                  // drop js:
      if (/^<a\b/i.test(tag)) {
        const m = tag.match(/\bhref\s*=\s*(['"])(.*?)\1/i);
        let href = m ? m[2] : '#';
        if (!/^https?:\/\//i.test(href)) href = '#';
        const closing = /\/>$/.test(tag) ? '/>' : '>';
        tag = '<a href="' + href.replace(/"/g, '&quot;') + `" target="_blank" rel="noopener noreferrer"${closing}`;
      }

      const idx = allowed.length;
      allowed.push(tag);
      return `@@RAW${idx}@@`;
    });

    // 3) Escape remaining text (keep '>' so > quotes still parse if needed)
    md = escHtml(md);

    // 4) Inline code
    md = md.replace(/`([^`]+?)`/g, '<code class="md-inline-code">$1</code>');

    // 5) Headings (markdown)
    md = md.replace(/^###### (.*)$/gm, '<h6>$1</h6>')
      .replace(/^##### (.*)$/gm, '<h5>$1</h5>')
      .replace(/^#### (.*)$/gm, '<h4>$1</h4>')
      .replace(/^### (.*)$/gm, '<h3>$1</h3>')
      .replace(/^## (.*)$/gm, '<h2>$1</h2>')
      .replace(/^# (.*)$/gm, '<h1>$1</h1>');

    // 6) Blockquotes
    md = md.replace(/^> (.*)$/gm, '<blockquote>$1</blockquote>');

    // 7) Bold / Italic
    md = md.replace(/\*\*([^\*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^\*]+)\*/g, '<em>$1</em>')
      .replace(/__([^_]+)__/g, '<strong>$1</strong>')
      .replace(/_([^_]+)_/g, '<em>$1</em>');

    // 8) Links [text](url)
    md = md.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

    // 9) Unordered lists
    md = md.replace(/(?:^|\n)([-*] .+(?:\n[-*] .+)*)/g, (_, group) => {
      const items = group.trim().split('\n').map(x => x.replace(/^[-*] (.*)$/, '<li>$1</li>')).join('');
      return '\n<ul>' + items + '</ul>';
    });

    // 10) Ordered lists
    md = md.replace(/(?:^|\n)((?:\d+\. .+(?:\n\d+\. .+)*))/g, (_, group) => {
      const items = group.trim().split('\n').map(x => x.replace(/^\d+\. (.*)$/, '<li>$1</li>')).join('');
      return '\n<ol>' + items + '</ol>';
    });

    // 11) Paragraphs — split on blank lines
    md = md.split(/\n{2,}/).map(block => {
      const t = block.trim();
      if (!t) return '';
      if (/^<(h\d|ul|ol|li|pre|blockquote|div|table|hr|p|\/)/i.test(t)) return t;
      return '<p>' + t.replace(/\n+/g, '<br>') + '</p>';
    }).join('\n');

    // 12) Fallback: <p>1. …</p> sequences → <ol>
    md = md.replace(/(?:^|\n)((?:<p>\s*\d+\.\s[\s\S]*?<\/p>\s*){2,})/g, block => {
      const lines = block.trim().split(/\n/).filter(Boolean);
      const firstMatch = lines[0].match(/<p>\s*(\d+)\.\s/);
      const start = firstMatch ? parseInt(firstMatch[1], 10) || 1 : 1;
      const items = lines.map(l => l.replace(/^<p>\s*\d+\.\s([\s\S]*?)<\/p>$/, '<li>$1</li>')).join('');
      const startAttr = (start !== 1) ? (' start="' + start + '"') : '';
      return '<ol' + startAttr + '>' + items + '</ol>';
    });

    // 13) Restore allowed HTML tags
    md = md.replace(/@@RAW(\d+)@@/g, (_, i) => allowed[Number(i)] || '');

    // 14) Definition blocks
    md = md.replace(/<p><strong>([^<]+)<\/strong>:\s*([\s\S]*?)<\/p>/g,
      '<div class="md-term"><div class="md-term-title">$1</div><div class="md-term-desc">$2</div></div>');
    md = md.replace(/<li>\s*(?:<p>)?<strong>([^<]+)<\/strong>:\s*([\s\S]*?)(?:<\/p>)?\s*<\/li>/g,
      '<li><div class="md-term"><div class="md-term-title">$1</div><div class="md-term-desc">$2</div></div></li>');

    // 15) Timeline tagging
    (function () {
      function tagTimeline(tag) {
        return md.replace(new RegExp('<' + tag + '>([\\s\\S]*?)</' + tag + '>', 'g'), (_, inner) => {
          const items = inner.match(/<li>[\s\S]*?<\/li>/g) || [];
          if (!items.length) return '<' + tag + '>' + inner + '</' + tag + '>';
          const yearLike = /^(\d{3,4}(?:s)?)(?:\s*[–-]\s*(\d{3,4}(?:s)?))?$/;
          const ok = items.every(li => {
            const m = li.match(/<div class="md-term-title">([^<]+)<\/div>/);
            return m && yearLike.test(m[1].trim());
          });
          return '<' + tag + (ok ? ' class="md-timeline"' : '') + '>' + inner + '</' + tag + '>';
        });
      }
      md = tagTimeline('ol');
      md = tagTimeline('ul');
    })();

    // 16) Restore code fences last
    md = md.replace(/@@FENCE(\d+)@@/g, (_, idx) => {
      const f = fences[Number(idx)] || { lang: '', code: '' };
      const safe = escHtml(f.code);
      const langClass = f.lang ? (' language-' + f.lang) : '';
      return `<pre class="md-code"><code class="md-code-block${langClass}">` + safe + `</code></pre>`;
    });

    return md;
  }

  function highlightAll(container) {
    if (!global.hljs) return;
    const root = container || document;
    root.querySelectorAll('pre.md-code code').forEach(block => {
      global.hljs.highlightElement(block);
    });
  }

  global.DevENMarkdown = { renderMarkdown, highlightAll };
})(window);
