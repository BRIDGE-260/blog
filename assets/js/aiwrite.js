(function () {
  var root = document.querySelector('[data-ai-write]');
  var editor = document.getElementById('editor');
  var title = document.querySelector('.wf-title');
  var category = document.querySelector('select[name="category"]');
  var tagBox = document.querySelector('.taginput');
  if (!root || !editor || !title) return;
  var topic = root.querySelector('[data-ai-topic]');
  var status = root.querySelector('[data-ai-status]');
  var results = root.querySelector('[data-ai-results]');
  var buttons = root.querySelectorAll('[data-ai-mode]');
  var currentMode = '';

  function appendText(text) {
    if (editor.innerText.trim() !== '') editor.appendChild(document.createElement('br'));
    String(text).split('\n').forEach(function (line, index, lines) {
      if (line) editor.appendChild(document.createTextNode(line));
      if (index < lines.length - 1) editor.appendChild(document.createElement('br'));
    });
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    editor.focus();
  }

  function applySuggestion(value) {
    if (currentMode === 'title') {
      title.value = value;
      title.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (currentMode === 'outline') {
      appendText(value);
    } else if (currentMode === 'tags' && tagBox) {
      tagBox.dispatchEvent(new CustomEvent('bridge:set-tags', { detail: [value], bubbles: true }));
    }
    window.showToast && window.showToast('AI 제안을 글에 적용했어요.', false);
  }

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      currentMode = button.getAttribute('data-ai-mode');
      buttons.forEach(function (item) { item.disabled = true; });
      status.textContent = '글의 방향을 정리하고 있어요…';
      results.innerHTML = '';
      var memo = topic.value.trim() || [title.value.trim(), editor.innerText.trim().slice(0, 1200)].filter(Boolean).join('\n');
      var body = new URLSearchParams({ mode: currentMode, topic: memo, category: category ? category.value : '' });
      fetch('../api/ai_assist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
        .then(function (response) { if (!response.ok) throw new Error('request failed'); return response.json(); })
        .then(function (data) {
          if (!data.ok || !Array.isArray(data.suggestions)) throw new Error('invalid response');
          data.suggestions.forEach(function (suggestion) {
            var item = document.createElement('button');
            item.type = 'button';
            item.textContent = suggestion;
            item.addEventListener('click', function () { applySuggestion(suggestion); });
            results.appendChild(item);
          });
          status.textContent = (data.source === 'openai' ? 'AI' : '로컬 도우미') + ' 추천 · 원하는 결과를 눌러 적용하세요.';
        })
        .catch(function () { status.textContent = '추천을 불러오지 못했어요. 잠시 후 다시 시도해주세요.'; })
        .finally(function () { buttons.forEach(function (item) { item.disabled = false; }); });
    });
  });
})();
