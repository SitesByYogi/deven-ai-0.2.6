( function( wp ) {
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost || {};
  const { PanelBody, Button, TextareaControl, Spinner, Notice } = wp.components;
  const { useState } = wp.element;
  const { __ } = wp.i18n;
  const { select, dispatch } = wp.data;

  if (!PluginSidebar) { return; }

  function askAI(prompt){
    return fetch(DevENAI.restUrl, {
      method: 'POST',
      headers: { 'Content-Type':'application/json','X-WP-Nonce': DevENAI.nonce },
      body: JSON.stringify({ messages: [ { role:'user', content: prompt } ] })
    }).then(r=>r.json());
  }

  function insertAtCursor(text){
    const editor = select('core/editor');
    const content = editor.getEditedPostContent();
    dispatch('core/editor').editPost({ content: content + '\n\n' + text });
  }

  const Sidebar = () => {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    async function go(pfx){
      if (!prompt.trim()) return;
      setLoading(true); setError('');
      const preface = pfx ? pfx + ': ' : '';
      const r = await askAI(preface + prompt);
      setLoading(false);
      if (r.error) { setError(r.error); return; }
      insertAtCursor(r.text || '');
      setPrompt('');
    }

    return (
      <>
        <PluginSidebarMoreMenuItem target="deven-ai-sidebar">
          {__('DevEN AI','deven-ai')}
        </PluginSidebarMoreMenuItem>
        <PluginSidebar name="deven-ai-sidebar" title="DevEN AI">
          <PanelBody title={__('Write with AI','deven-ai')} initialOpen={true}>
            {error ? <Notice status="error" isDismissible>{error}</Notice> : null}
            <TextareaControl
              label={__('Prompt','deven-ai')}
              value={prompt}
              onChange={setPrompt}
            />
            <div style={{display:'flex', gap:'8px', marginTop:'8px', flexWrap:'wrap'}}>
              <Button isPrimary disabled={loading} onClick={()=>go('Write a section that')}>{loading ? <Spinner/> : __('Generate','deven-ai')}</Button>
              <Button disabled={loading} onClick={()=>go('Rewrite and improve clarity; keep markdown')} >{__('Improve','deven-ai')}</Button>
              <Button disabled={loading} onClick={()=>go('Continue writing from this point')} >{__('Continue','deven-ai')}</Button>
              <Button disabled={loading} onClick={()=>go('Change tone to confident, friendly; keep meaning')} >{__('Adjust Tone','deven-ai')}</Button>
            </div>
          </PanelBody>
        </PluginSidebar>
      </>
    );
  };

  registerPlugin('deven-ai', { render: Sidebar, icon: 'art' });
} )( window.wp );
