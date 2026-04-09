if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function (e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) summarizeButtonClick(target);
        break;
      }
      if (target.matches('.oai-tts-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) ttsButtonClick(target);
        break;
      }
    }
  }, false);
}

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
    buttons.forEach(b => b.disabled = true);
    content.innerHTML = '';
    const oldResultBtn = container.querySelector('.oai-result-tts-btn');
    if (oldResultBtn) oldResultBtn.remove();
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    buttons.forEach(b => b.disabled = false);
    content.innerHTML = '';
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish') {
      buttons.forEach(b => b.disabled = false);
      showResultTtsButton(container);
    }
  }

  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

function showResultTtsButton(container) {
  const speakUrl = container.dataset.speakResult;
  if (!speakUrl) return;
  const box = container.querySelector('.oai-summary-box');
  if (!box) return;

  const existing = box.querySelector('.oai-result-tts-btn');
  if (existing) existing.remove();

  const articleTtsBtn = container.querySelector('.oai-tts-btn:not(.oai-tts-paragraph):not(.oai-result-tts-btn)');
  const playIcon  = articleTtsBtn ? articleTtsBtn.querySelector('.oai-tts-play')  : null;
  const pauseIcon = articleTtsBtn ? articleTtsBtn.querySelector('.oai-tts-pause') : null;

  const btn = document.createElement('button');
  btn.className = 'oai-result-tts-btn oai-tts-btn btn btn-small';
  btn.dataset.request = speakUrl;
  const label = container.dataset.readResult || container.dataset.read || 'Read result';
  btn.setAttribute('aria-label', label);
  btn.setAttribute('title', label);
  if (playIcon)  btn.appendChild(playIcon.cloneNode(true));
  if (pauseIcon) btn.appendChild(pauseIcon.cloneNode(true));

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
    const response = await fetch(url, {
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
  const t = container.dataset;
  try {
    setOaiState(container, 1, t.receivingAnswer, null);
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        if (buffer.trim()) {
          try {
            const json = JSON.parse(buffer.trim());
            if (json.output_text) { text += json.output_text; setOaiState(container, 0, null, marked.parse(text)); }
          } catch (e) { console.error('Error parsing final JSON:', e); }
        }
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      let parts = buffer.split(/\n\n/);
      buffer = parts.pop();
      for (let part of parts) {
        const lines = part.trim().split('\n');
        const dataLine = lines.find(l => l.startsWith('data:'));
        if (!dataLine) continue;
        let data = dataLine.slice(5).trim();
        if (data === '[DONE]') { setOaiState(container, 0, 'finish', null); return; }
        try {
          const json = JSON.parse(data);
          if (json.type === 'response.completed') { setOaiState(container, 0, 'finish', null); return; }
          const delta = json.delta || json.output_text || '';
          if (delta) { text += delta; setOaiState(container, 0, null, marked.parse(text)); }
        } catch (e) { console.error('Error parsing SSE JSON:', e, data); }
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (4)', null);
  }
}

/**
 * Fetch TTS audio from server via POST, return Audio element backed by blob URL.
 * POST avoids URL-length limits on large content and CSRF issues with GET.
 */
async function ttsLoadAudio(url, text) {
  const form = new URLSearchParams();
  form.append('ajax', 'true');
  form.append('_csrf', context.csrf);
  form.append('content', text);

  const testEl = document.createElement('audio');
  let fmt = 'opus';
  if (!testEl.canPlayType('audio/ogg; codecs=opus')) {
    fmt = testEl.canPlayType('audio/mpeg') ? 'mp3' : 'ogg';
  }
  form.append('format', fmt);

  const resp = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form
  });
  if (!resp.ok) throw new Error('TTS: HTTP ' + resp.status);

  const blob = await resp.blob();
  const blobUrl = URL.createObjectURL(blob);
  const audio = new Audio(blobUrl);
  audio._blobUrl = blobUrl;
  return audio;
}

/** Play/pause a standalone TTS audio with clean-up on end/error. */
async function playTtsAudio(audio, target, readLabel, pauseLabel, log, t, onEnded) {
  audio.addEventListener('ended', () => {
    if (audio._blobUrl) { URL.revokeObjectURL(audio._blobUrl); audio._blobUrl = null; }
    target._audio = null;
    target.classList.remove('oai-playing');
    target.setAttribute('aria-label', readLabel);
    target.setAttribute('title', readLabel);
    log.textContent = '';
    log.style.display = 'none';
    if (onEnded) onEnded();
  }, { once: true });
  audio.addEventListener('error', () => {
    if (audio._blobUrl) { URL.revokeObjectURL(audio._blobUrl); audio._blobUrl = null; }
    target._audio = null;
    log.textContent = t.audioFailed;
    log.style.display = 'block';
    target.classList.remove('oai-playing');
    target.setAttribute('aria-label', readLabel);
    target.setAttribute('title', readLabel);
    if (onEnded) onEnded();
  }, { once: true });

  await audio.play();
  target.classList.add('oai-playing');
  target.setAttribute('aria-label', pauseLabel);
  target.setAttribute('title', pauseLabel);
  log.textContent = '';
  log.style.display = 'none';
}

async function resultTtsButtonClick(target) {
  const container = target.closest('.oai-summary-wrap');
  const log = container.querySelector('.oai-summary-log');
  const t = container.dataset;
  const readLabel  = t.readResult || t.read;
  const pauseLabel = t.pause;

  if (target._audio) {
    if (target._audio.paused) {
      try {
        await target._audio.play();
        target.classList.add('oai-playing');
        target.setAttribute('aria-label', pauseLabel);
        target.setAttribute('title', pauseLabel);
        log.textContent = '';
        log.style.display = 'none';
      } catch (err) { console.error(err); }
    } else {
      target._audio.pause();
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
    }
    return;
  }

  const contentEl = container.querySelector('.oai-summary-content');
  const text = contentEl ? contentEl.textContent.trim() : '';
  if (!text) return;

  target.disabled = true;
  log.textContent = t.preparingAudio;
  log.style.display = 'block';

  try {
    const audio = await ttsLoadAudio(target.dataset.request, text);
    target._audio = audio;
    await playTtsAudio(audio, target, readLabel, pauseLabel, log, t, null);
  } catch (err) {
    console.error(err);
    log.textContent = t.audioFailed;
    log.style.display = 'block';
  } finally {
    target.disabled = false;
  }
}

async function ttsButtonClick(target, forceStop = false) {
  if (target.classList.contains('oai-result-tts-btn')) {
    return await resultTtsButtonClick(target);
  }

  const container = target.closest('.oai-summary-wrap');
  const log = container.querySelector('.oai-summary-log');
  const t = container.dataset;
  const readLabel  = t.read;
  const pauseLabel = t.pause;

  // Toggle / stop existing audio
  if (target._audio) {
    if (forceStop) {
      target._audio.pause();
      if (target._audio._blobUrl) { URL.revokeObjectURL(target._audio._blobUrl); }
      target._audio = null;
      log.textContent = '';
      log.style.display = 'none';
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
      if (target._sequenceParent) {
        const parent = target._sequenceParent;
        target._sequenceParent = null;
        parent.classList.remove('oai-playing');
        parent.setAttribute('aria-label', readLabel);
        parent.setAttribute('title', readLabel);
        parent._sequence = null;
      }
      return;
    }
    if (target._audio.paused) {
      try {
        await target._audio.play();
        target.classList.add('oai-playing');
        target.setAttribute('aria-label', pauseLabel);
        target.setAttribute('title', pauseLabel);
        log.textContent = '';
        log.style.display = 'none';
      } catch (err) {
        console.error('Playback failed', err);
        log.textContent = t.audioFailed;
        log.style.display = 'block';
      }
    } else {
      target._audio.pause();
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
    }
    if (target._sequenceParent) {
      const parent = target._sequenceParent;
      if (target._audio && !target._audio.paused) {
        parent.classList.add('oai-playing');
        parent.setAttribute('aria-label', pauseLabel);
        parent.setAttribute('title', pauseLabel);
      } else {
        parent.classList.remove('oai-playing');
        parent.setAttribute('aria-label', readLabel);
        parent.setAttribute('title', readLabel);
        if (!target._audio) parent._sequence = null;
      }
    }
    return;
  }

  // ── Article-level TTS button ──────────────────────────────────────────────
  if (!target.classList.contains('oai-tts-paragraph')) {
    // Resume paused sequence
    if (target._sequence) {
      const currentBtn = target._sequence.currentBtn;
      if (currentBtn) {
        await ttsButtonClick(currentBtn);
        if (currentBtn._audio && !currentBtn._audio.paused) {
          target.classList.add('oai-playing');
          target.setAttribute('aria-label', pauseLabel);
          target.setAttribute('title', pauseLabel);
        } else {
          target.classList.remove('oai-playing');
          target.setAttribute('aria-label', readLabel);
          target.setAttribute('title', readLabel);
        }
      }
      return;
    }

    const paragraphs = Array.from(container.querySelectorAll('.oai-tts-paragraph'));

    // No paragraph buttons → read entire article as one TTS request
    if (paragraphs.length === 0) {
      const article = container.querySelector('.oai-summary-article');
      const text = article ? article.textContent.trim() : '';
      if (!text) return;

      target.disabled = true;
      log.textContent = t.preparingAudio;
      log.style.display = 'block';
      try {
        const audio = await ttsLoadAudio(target.dataset.request, text);
        target._audio = audio;
        await playTtsAudio(audio, target, readLabel, pauseLabel, log, t, null);
      } catch (err) {
        console.error(err);
        log.textContent = t.audioFailed;
        log.style.display = 'block';
      } finally {
        target.disabled = false;
      }
      return;
    }

    // Start sequential paragraph reading
    target._sequence = { buttons: paragraphs, index: 0, currentBtn: null };
    target.classList.add('oai-playing');
    target.setAttribute('aria-label', pauseLabel);
    target.setAttribute('title', pauseLabel);
    target._playNextParagraph = function () {
      const seq = target._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
        target._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      const btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = target;
      ttsButtonClick(btn);
    };
    target._playNextParagraph();
    return;
  }

  // ── Paragraph button ──────────────────────────────────────────────────────
  // If clicked directly (not via sequence), set up sequence from this paragraph
  const articleBtn = container.querySelector('.oai-tts-btn:not(.oai-tts-paragraph):not(.oai-result-tts-btn)');
  if (articleBtn && !target._sequenceParent) {
    if (articleBtn._sequence && articleBtn._sequence.currentBtn && articleBtn._sequence.currentBtn !== target) {
      await ttsButtonClick(articleBtn._sequence.currentBtn, true);
    }
    const paragraphs = Array.from(container.querySelectorAll('.oai-tts-paragraph'));
    articleBtn._sequence = {
      buttons: paragraphs,
      index: paragraphs.indexOf(target) + 1,
      currentBtn: target
    };
    articleBtn.classList.add('oai-playing');
    articleBtn.setAttribute('aria-label', pauseLabel);
    articleBtn.setAttribute('title', pauseLabel);
    articleBtn._playNextParagraph = function () {
      const seq = articleBtn._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        articleBtn.classList.remove('oai-playing');
        articleBtn.setAttribute('aria-label', readLabel);
        articleBtn.setAttribute('title', readLabel);
        articleBtn._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      const btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = articleBtn;
      ttsButtonClick(btn);
    };
    target._sequenceParent = articleBtn;
  }

  // Load and play paragraph audio
  const p = target.closest('p');
  const text = p ? p.textContent.trim() : '';
  if (!text) {
    if (target._sequenceParent) {
      const parent = target._sequenceParent;
      target._sequenceParent = null;
      parent._playNextParagraph();
    }
    return;
  }

  target.disabled = true;
  log.textContent = t.preparingAudio;
  log.style.display = 'block';

  try {
    const audio = await ttsLoadAudio(target.dataset.request, text);
    target._audio = audio;
    await playTtsAudio(audio, target, readLabel, pauseLabel, log, t, () => {
      if (target._sequenceParent) {
        const parent = target._sequenceParent;
        target._sequenceParent = null;
        parent._playNextParagraph();
      }
    });
  } catch (err) {
    console.error(err);
    log.textContent = t.audioFailed;
    log.style.display = 'block';
    if (target._sequenceParent) {
      const parent = target._sequenceParent;
      target._sequenceParent = null;
      parent._playNextParagraph();
    }
  } finally {
    target.disabled = false;
  }
}
