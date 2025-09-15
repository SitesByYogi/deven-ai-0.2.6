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

    // 1) Extract fenced code blocks first
    const fences = [];
    md = md.replace(/```(\w+)?\n([\s\S]*?)```/g, (_, lang, code) => {
      const idx = fences.length;
      fences.push({ lang: (lang || '').toLowerCase(), code });
      return `@@FENCE${idx}@@`;
    });

    // 2) Headings & blockquotes BEFORE escaping (since we keep ">" unescaped we could also do after, but earlier is clearer)
    md = md.replace(/^###### (.*)$/gm, '<h6>$1</h6>')
      .replace(/^##### (.*)$/gm, '<h5>$1</h5>')
      .replace(/^#### (.*)$/gm, '<h4>$1</h4>')
      .replace(/^### (.*)$/gm, '<h3>$1</h3>')
      .replace(/^## (.*)$/gm, '<h2>$1</h2>')
      .replace(/^# (.*)$/gm, '<h1>$1</h1>');

    // Simple blockquotes (single level)
    md = md.replace(/^> (.*)$/gm, '<blockquote>$1</blockquote>');

    // 3) Escape remaining text
    md = escHtml(md);

    // 4) Inline code
    md = md.replace(/`([^`]+?)`/g, '<code class="md-inline-code">$1</code>');

    // 5) Bold / Italic
    md = md.replace(/\*\*([^\*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^\*]+)\*/g, '<em>$1</em>')
      .replace(/__([^_]+)__/g, '<strong>$1</strong>')
      .replace(/_([^_]+)_/g, '<em>$1</em>');

    // 6) Links [text](url) with safe href
    md = md.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, text, url) =>
      `<a href="${escAttr(url)}" target="_blank" rel="noopener noreferrer">${text}</a>`
    );

    // 7) Unordered lists
    md = md.replace(/(?:^|\n)([-*] .+(?:\n[-*] .+)*)/g, (match, group) => {
      const items = group.trim().split('\n')
        .map(x => x.replace(/^[-*] (.*)$/, '<li>$1</li>')).join('');
      return '\n<ul>' + items + '</ul>';
    });

    // 8) Ordered lists
    md = md.replace(/(?:^|\n)((?:\d+\. .+(?:\n\d+\. .+)*))/g, (match, group) => {
      const items = group.trim().split('\n')
        .map(x => x.replace(/^\d+\. (.*)$/, '<li>$1</li>')).join('');
      return '\n<ol>' + items + '</ol>';
    });

    // 9) Paragraphs — split by blank lines, not per line
    md = md.split(/\n{2,}/).map(block => {
      const trimmed = block.trim();
      if (!trimmed) return '';
      // If block already starts with a block-level tag, keep as-is
      if (/^<(h\d|ul|ol|li|pre|blockquote|div|table|hr|p|\/)/.test(trimmed)) return trimmed;
      // Otherwise wrap and preserve single newlines inside with <br>
      return '<p>' + trimmed.replace(/\n+/g, '<br>') + '</p>';
    }).join('\n');

    // 10) Fallback: sequences of <p>1. ...</p> → <ol>
    md = md.replace(/(?:^|\n)((?:<p>\s*\d+\.\s[\s\S]*?<\/p>\s*){2,})/g, block => {
      const lines = block.trim().split(/\n/).filter(Boolean);
      const firstMatch = lines[0].match(/<p>\s*(\d+)\.\s/);
      const start = firstMatch ? parseInt(firstMatch[1], 10) || 1 : 1;
      const items = lines.map(l => l.replace(/^<p>\s*\d+\.\s([\s\S]*?)<\/p>$/, '<li>$1</li>')).join('');
      const startAttr = (start !== 1) ? (' start="' + start + '"') : '';
      return '<ol' + startAttr + '>' + items + '</ol>';
    });

    // 11) Restore fences (escaped safely)
    md = md.replace(/@@FENCE(\d+)@@/g, (_, idx) => {
      const f = fences[Number(idx)] || { lang: '', code: '' };
      const safe = escHtml(f.code);
      const langClass = f.lang ? (' language-' + f.lang) : '';
      return `<pre class="md-code"><code class="md-code-block${langClass}">` + safe + `</code></pre>`;
    });

    // 12) Definition blocks for "**Title**: Description"
    md = md.replace(/<p><strong>([^<]+)<\/strong>:\s*([\s\S]*?)<\/p>/g,
      (_, title, desc) => '<div class="md-term"><div class="md-term-title">' + title + '</div><div class="md-term-desc">' + desc + '</div></div>'
    );
    // Same pattern inside list items
    md = md.replace(/<li>\s*(?:<p>)?<strong>([^<]+)<\/strong>:\s*([\s\S]*?)(?:<\/p>)?\s*<\/li>/g,
      (_, title, desc) => '<li><div class="md-term"><div class="md-term-title">' + title + '</div><div class="md-term-desc">' + desc + '</div></div></li>'
    );

    // 13) Timeline tagging (unchanged)
    (function () {
      function tagTimeline(listTag) {
        return md.replace(new RegExp('<' + listTag + '>([\\s\\S]*?)</' + listTag + '>', 'g'), (_, inner) => {
          const items = inner.match(/<li>[\s\S]*?<\/li>/g) || [];
          if (!items.length) return '<' + listTag + '>' + inner + '</' + listTag + '>';
          const yearLike = /^(\d{3,4}(?:s)?)(?:\s*[–-]\s*(\d{3,4}(?:s)?))?$/;
          const ok = items.every(li => {
            const m = li.match(/<div class="md-term-title">([^<]+)<\/div>/);
            return m && yearLike.test(m[1].trim());
          });
          if (ok) return '<' + listTag + ' class="md-timeline">' + inner + '</' + listTag + '>';
          return '<' + listTag + '>' + inner + '</' + listTag + '>';
        });
      }
      md = tagTimeline('ol');
      md = tagTimeline('ul');
    })();

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
