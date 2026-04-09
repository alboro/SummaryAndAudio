/* ── Summary button logic ──────────────────────────────────────────────────── */

function setOaiState(container, statusType, statusMsg, summaryText) {
  const buttons = container.querySelectorAll('.oai-summary-btn');
  const content = container.querySelector('.oai-summary-content');
  const log = container.querySelector('.oai-summary-log');

  if (statusMsg !== null) {
    if (statusMsg === 'finish') {
      log.textContent = '';
      log.style.display = 'none';
    } else {
      log.textContent = statusMsg;
      log.style.display = 'block';
    }
  }

  if (statusType === 1) {
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    buttons.forEach(function (b) {
      b.disabled = true;
      b.classList.add('oai-waiting');
    });
    content.innerHTML = '';
    var oldResultBtn = container.querySelector('.oai-result-tts-btn');
    if (oldResultBtn) oldResultBtn.remove();
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    buttons.forEach(function (b) {
      b.disabled = false;
      b.classList.remove('oai-waiting');
    });
    content.innerHTML = '';
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish') {
      buttons.forEach(function (b) {
        b.disabled = false;
        b.classList.remove('oai-waiting');
      });
      showResultTtsButton(container);
    }
  }

  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

function showResultTtsButton(container) {
  var speakUrl = container.dataset.speakResult;
  if (!speakUrl) return;
  var box = container.querySelector('.oai-summary-box');
  if (!box) return;

  var existing = box.querySelector('.oai-result-tts-btn');
  if (existing) existing.remove();

  var articleTtsBtn = container.querySelector('.oai-tts-btn:not(.oai-tts-paragraph):not(.oai-result-tts-btn)');
  var playIcon  = articleTtsBtn ? articleTtsBtn.querySelector('.oai-tts-play')  : null;
  var pauseIcon = articleTtsBtn ? articleTtsBtn.querySelector('.oai-tts-pause') : null;

  var btn = document.createElement('button');
  btn.className = 'oai-result-tts-btn oai-tts-btn btn btn-small';
  btn.dataset.request = speakUrl;
  var label = container.dataset.readResult || container.dataset.read || 'Read result';
  btn.setAttribute('aria-label', label);
  btn.setAttribute('title', label);

  if (playIcon)  btn.appendChild(playIcon.cloneNode(true));
  if (pauseIcon) btn.appendChild(pauseIcon.cloneNode(true));
  if (!playIcon && !pauseIcon) {
    var span = document.createElement('span');
    span.textContent = label;
    btn.appendChild(span);
  }

  box.appendChild(btn);
}

async function summarizeButtonClick(target) {
  var container = target.closest('.oai-summary-wrap');
  var t = container.dataset;
  if (container.classList.contains('oai-loading')) return;

  container.classList.add('oai-summary-active');
  setOaiState(container, 1, t.preparingRequest, null);

  var url = target.dataset.request;
  var data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);

  try {
    var response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: data
    });
    if (!response.ok) throw new Error(t.requestFailed + ' (1)');
    setOaiState(container, 1, t.pending, null);
    await streamSummary(container, response);
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (2)', null);
  }
}

async function streamSummary(container, response) {
  var t = container.dataset;
  try {
    setOaiState(container, 1, t.receivingAnswer, null);
    var reader = response.body.getReader();
    var decoder = new TextDecoder('utf-8');
    var text = '';
    var buffer = '';

    while (true) {
      var chunk = await reader.read();
      if (chunk.done) {
        if (buffer.trim()) {
          try {
            var json = JSON.parse(buffer.trim());
            if (json.output_text) { text += json.output_text; setOaiState(container, 0, null, marked.parse(text)); }
          } catch (e) { console.error('Error parsing final JSON:', e); }
        }
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(chunk.value, { stream: true });
      var parts = buffer.split(/\n\n/);
      buffer = parts.pop();
      for (var i = 0; i < parts.length; i++) {
        var lines = parts[i].trim().split('\n');
        var dataLine = lines.find(function (l) { return l.startsWith('data:'); });
        if (!dataLine) continue;
        var data = dataLine.slice(5).trim();
        if (data === '[DONE]') { setOaiState(container, 0, 'finish', null); return; }
        try {
          var json = JSON.parse(data);
          if (json.type === 'response.completed') { setOaiState(container, 0, 'finish', null); return; }
          var delta = json.delta || json.output_text || '';
          if (delta) { text += delta; setOaiState(container, 0, null, marked.parse(text)); }
        } catch (e) { console.error('Error parsing SSE JSON:', e, data); }
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (4)', null);
  }
}

