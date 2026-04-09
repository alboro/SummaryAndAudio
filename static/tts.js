/* ── TTS playback logic ────────────────────────────────────────────────────── */

/**
 * Fetch full article markdown from the server for the given entry.
 * Returns empty string on failure.
 */
async function getArticleText(textUrl) {
  try {
    var form = new URLSearchParams();
    form.append('ajax', 'true');
    form.append('_csrf', context.csrf);
    var resp = await fetch(textUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form
    });
    if (!resp.ok) return '';
    var json = await resp.json();
    return (typeof json.text === 'string') ? json.text : '';
  } catch (e) {
    console.error('[SAA] getArticleText failed', e);
    return '';
  }
}

/**
 * Split text into chunks of at most maxChars characters, breaking at paragraph
 * or sentence boundaries where possible.
 */
function splitTextForTts(text, maxChars) {
  maxChars = maxChars || 4000;
  if (text.length <= maxChars) return [text];
  var chunks = [];
  var remaining = text;
  while (remaining.length > maxChars) {
    var cutAt = remaining.lastIndexOf('\n\n', maxChars);
    if (cutAt <= 0) cutAt = remaining.lastIndexOf('. ', maxChars);
    if (cutAt <= 0) cutAt = maxChars;
    else cutAt += 1;
    chunks.push(remaining.substring(0, cutAt).trim());
    remaining = remaining.substring(cutAt).trim();
  }
  if (remaining) chunks.push(remaining);
  return chunks.filter(function (c) { return c.length > 0; });
}

/** Detect audio format supported by the browser. */
function detectAudioFormat() {
  var testEl = document.createElement('audio');
  if (testEl.canPlayType('audio/ogg; codecs=opus')) return 'opus';
  if (testEl.canPlayType('audio/mpeg')) return 'mp3';
  return 'ogg';
}

/**
 * Fetch TTS audio from server via POST, return a promise resolving to Audio.
 * @param {string} url - speak endpoint URL
 * @param {string} text - text content to speak
 * @param {string} [voice] - optional voice override
 * @param {string} [title] - optional article title (for %article_title% in LLM prompt)
 */
function ttsLoadAudio(url, text, voice, title) {
  var form = new URLSearchParams();
  form.append('ajax', 'true');
  form.append('_csrf', context.csrf);
  form.append('content', text);
  form.append('format', detectAudioFormat());
  if (voice) form.append('voice', voice);
  if (title) form.append('title', title);

  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form
  }).then(function (resp) {
    if (!resp.ok) {
      return resp.text().catch(function () { return ''; }).then(function (body) {
        throw new Error('TTS: HTTP ' + resp.status);
      });
    }
    return resp.blob();
  }).then(function (blob) {
    var blobUrl = URL.createObjectURL(blob);
    var audio = new Audio(blobUrl);
    audio._blobUrl = blobUrl;
    return audio;
  });
}

/** Clean up a blob URL from an Audio element. */
function cleanupAudio(audio) {
  if (audio && audio._blobUrl) {
    URL.revokeObjectURL(audio._blobUrl);
    audio._blobUrl = null;
  }
}

/** Set button to waiting state (spinner icon). */
function setButtonWaiting(target, waiting) {
  if (waiting) {
    target.classList.add('oai-waiting');
  } else {
    target.classList.remove('oai-waiting');
  }
}

/** Play/pause a standalone TTS audio with clean-up on end/error. */
async function playTtsAudio(audio, target, readLabel, pauseLabel, log, t, onEnded) {
  audio.addEventListener('ended', function () {
    cleanupAudio(audio);
    target._audio = null;
    target.classList.remove('oai-playing');
    target.setAttribute('aria-label', readLabel);
    target.setAttribute('title', readLabel);
    log.textContent = '';
    log.style.display = 'none';
    if (onEnded) onEnded();
  }, { once: true });
  audio.addEventListener('error', function () {
    cleanupAudio(audio);
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

/**
 * Play TTS chunks with PREFETCH — start loading the next chunk as soon as
 * the current one starts playing, so there is no gap between them.
 * @param {string} [title] - article title passed to TTS API for %article_title% substitution
 */
async function playTtsChunks(chunks, speakUrl, target, readLabel, pauseLabel, log, t, voice, title) {
  var prefetched = null; // Promise<Audio> for the next chunk

  for (var i = 0; i < chunks.length; i++) {
    if (target._ttsStopped) break;

    var statusText = (t.preparingAudio || 'Preparing audio...');
    if (chunks.length > 1) statusText += ' (' + (i + 1) + '/' + chunks.length + ')';

    var audio;
    if (prefetched) {
      // We already started fetching this chunk — wait for it
      log.textContent = statusText;
      log.style.display = 'block';
      setButtonWaiting(target, true);
      try {
        audio = await prefetched;
      } catch (err) {
        console.error('[SAA] prefetch failed chunk', i, err);
        setButtonWaiting(target, false);
        break;
      }
      prefetched = null;
      setButtonWaiting(target, false);
    } else {
      // First chunk or no prefetch available
      log.textContent = statusText;
      log.style.display = 'block';
      setButtonWaiting(target, true);
      try {
        audio = await ttsLoadAudio(speakUrl, chunks[i], voice, title);
      } catch (err) {
        console.error('[SAA] load failed chunk', i, err);
        setButtonWaiting(target, false);
        break;
      }
      setButtonWaiting(target, false);
    }

    if (target._ttsStopped) {
      cleanupAudio(audio);
      break;
    }

    // Start prefetching the NEXT chunk immediately
    if (i + 1 < chunks.length && !target._ttsStopped) {
      prefetched = ttsLoadAudio(speakUrl, chunks[i + 1], voice, title).catch(function (err) {
        console.error('[SAA] prefetch error', err);
        return null;
      });
    }

    target._audio = audio;
    await new Promise(function (resolve) {
      playTtsAudio(audio, target, readLabel, pauseLabel, log, t, resolve);
    });
    target._audio = null;
  }

  // Clean up any pending prefetch
  if (prefetched) {
    prefetched.then(function (a) { cleanupAudio(a); }).catch(function () {});
  }

  target._ttsStopped = false;
  target.classList.remove('oai-playing');
  setButtonWaiting(target, false);
  target.setAttribute('aria-label', readLabel);
  target.setAttribute('title', readLabel);
  log.textContent = '';
  log.style.display = 'none';
}

async function resultTtsButtonClick(target) {
  var container = target.closest('.oai-summary-wrap');
  var log = container.querySelector('.oai-summary-log');
  var t = container.dataset;
  var readLabel  = t.readResult || t.read || 'Read result';
  var pauseLabel = t.pause || 'Pause';

  // Toggle existing playback
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

  // Stop ongoing chunk sequence
  if (target._ttsActive) {
    target._ttsStopped = true;
    if (target._audio) { target._audio.pause(); target._audio = null; }
    target._ttsActive = false;
    target.classList.remove('oai-playing');
    setButtonWaiting(target, false);
    target.setAttribute('aria-label', readLabel);
    target.setAttribute('title', readLabel);
    log.textContent = '';
    log.style.display = 'none';
    return;
  }

  // Read the summary content text
  var contentEl = container.querySelector('.oai-summary-content');
  var text = contentEl ? contentEl.textContent.trim() : '';

  log.textContent = t.preparingAudio || 'Preparing audio...';
  log.style.display = 'block';

  if (!text) {
    log.textContent = t.audioFailed || 'No content to read.';
    return;
  }

  target.disabled = true;
  target._ttsActive = true;
  target._ttsStopped = false;
  setButtonWaiting(target, true);

  try {
    var chunks = splitTextForTts(text, 600);
    target.disabled = false;
    setButtonWaiting(target, false);
    var voiceResult = container.dataset.voiceResult || '';
    var titleResult = container.dataset.entryTitle || '';
    await playTtsChunks(chunks, target.dataset.request, target, readLabel, pauseLabel, log, t, voiceResult, titleResult);
  } catch (err) {
    console.error(err);
    log.textContent = t.audioFailed || 'Audio playback failed';
    log.style.display = 'block';
  } finally {
    target.disabled = false;
    target._ttsActive = false;
    setButtonWaiting(target, false);
  }
}

async function ttsButtonClick(target, forceStop) {
  if (target.classList.contains('oai-result-tts-btn')) {
    return await resultTtsButtonClick(target);
  }

  var container = target.closest('.oai-summary-wrap');
  var log = container.querySelector('.oai-summary-log');
  var t = container.dataset;
  var readLabel  = t.read || 'Read';
  var pauseLabel = t.pause || 'Pause';

  // Toggle / stop existing audio
  if (target._audio) {
    if (forceStop) {
      target._audio.pause();
      cleanupAudio(target._audio);
      target._audio = null;
      log.textContent = '';
      log.style.display = 'none';
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
      if (target._sequenceParent) {
        var parent = target._sequenceParent;
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
      var sp = target._sequenceParent;
      if (target._audio && !target._audio.paused) {
        sp.classList.add('oai-playing');
        sp.setAttribute('aria-label', pauseLabel);
        sp.setAttribute('title', pauseLabel);
      } else {
        sp.classList.remove('oai-playing');
        sp.setAttribute('aria-label', readLabel);
        sp.setAttribute('title', readLabel);
        if (!target._audio) sp._sequence = null;
      }
    }
    return;
  }

  // ── Article-level TTS button ──────────────────────────────────────────────
  if (!target.classList.contains('oai-tts-paragraph')) {
    if (target._sequence) {
      var currentBtn = target._sequence.currentBtn;
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

    if (target._ttsActive) {
      target._ttsStopped = true;
      if (target._audio) { target._audio.pause(); target._audio = null; }
      target._ttsActive = false;
      target.classList.remove('oai-playing');
      setButtonWaiting(target, false);
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
      log.textContent = '';
      log.style.display = 'none';
      return;
    }

    // Fetch full article text from server
    var textUrl = container.dataset.textUrl;
    if (textUrl) {
      target.disabled = true;
      target._ttsActive = true;
      target._ttsStopped = false;
      log.textContent = t.preparingAudio || 'Preparing audio...';
      log.style.display = 'block';
      setButtonWaiting(target, true);
      try {
        var text = await getArticleText(textUrl);
        setButtonWaiting(target, false);
        if (!text || target._ttsStopped) {
          log.textContent = text ? '' : (t.audioFailed || 'No article text');
          log.style.display = text ? 'none' : 'block';
          return;
        }
        var chunks = splitTextForTts(text, 600);
        target.classList.add('oai-playing');
        target.setAttribute('aria-label', pauseLabel);
        target.setAttribute('title', pauseLabel);
        target.disabled = false;
        var voiceArticle = container.dataset.voice || '';
        var titleArticle = container.dataset.entryTitle || '';
        await playTtsChunks(chunks, target.dataset.request, target, readLabel, pauseLabel, log, t, voiceArticle, titleArticle);
      } catch (err) {
        console.error(err);
        log.textContent = t.audioFailed || 'Audio playback failed';
        log.style.display = 'block';
      } finally {
        target.disabled = false;
        target._ttsActive = false;
        setButtonWaiting(target, false);
      }
      return;
    }

    // Fallback: no textUrl — read from DOM
    var paragraphs = Array.from(container.querySelectorAll('.oai-tts-paragraph'));

    if (paragraphs.length === 0) {
      var article = container.querySelector('.oai-summary-article');
      var text2 = article ? article.textContent.trim() : '';
      if (!text2) return;

      target.disabled = true;
      log.textContent = t.preparingAudio;
      log.style.display = 'block';
      setButtonWaiting(target, true);
      try {
        var voiceFb = container.dataset.voice || '';
        var titleFb = container.dataset.entryTitle || '';
        var audio = await ttsLoadAudio(target.dataset.request, text2.substring(0, 4000), voiceFb, titleFb);
        setButtonWaiting(target, false);
        target._audio = audio;
        await playTtsAudio(audio, target, readLabel, pauseLabel, log, t, null);
      } catch (err) {
        console.error(err);
        log.textContent = t.audioFailed;
        log.style.display = 'block';
      } finally {
        target.disabled = false;
        setButtonWaiting(target, false);
      }
      return;
    }

    // Start sequential paragraph reading
    target._sequence = { buttons: paragraphs, index: 0, currentBtn: null };
    target.classList.add('oai-playing');
    target.setAttribute('aria-label', pauseLabel);
    target.setAttribute('title', pauseLabel);
    target._playNextParagraph = function () {
      var seq = target._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
        target._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      var btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = target;
      ttsButtonClick(btn);
    };
    target._playNextParagraph();
    return;
  }

  // ── Paragraph button ──────────────────────────────────────────────────────
  var articleBtn = container.querySelector('.oai-tts-btn:not(.oai-tts-paragraph):not(.oai-result-tts-btn)');
  if (articleBtn && !target._sequenceParent) {
    if (articleBtn._sequence && articleBtn._sequence.currentBtn && articleBtn._sequence.currentBtn !== target) {
      await ttsButtonClick(articleBtn._sequence.currentBtn, true);
    }
    var paragraphsList = Array.from(container.querySelectorAll('.oai-tts-paragraph'));
    articleBtn._sequence = {
      buttons: paragraphsList,
      index: paragraphsList.indexOf(target) + 1,
      currentBtn: target
    };
    articleBtn.classList.add('oai-playing');
    articleBtn.setAttribute('aria-label', pauseLabel);
    articleBtn.setAttribute('title', pauseLabel);
    articleBtn._playNextParagraph = function () {
      var seq = articleBtn._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        articleBtn.classList.remove('oai-playing');
        articleBtn.setAttribute('aria-label', readLabel);
        articleBtn.setAttribute('title', readLabel);
        articleBtn._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      var btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = articleBtn;
      ttsButtonClick(btn);
    };
    target._sequenceParent = articleBtn;
  }

  var p = target.closest('p');
  var pText = p ? p.textContent.trim() : '';
  if (!pText) {
    if (target._sequenceParent) {
      var par = target._sequenceParent;
      target._sequenceParent = null;
      par._playNextParagraph();
    }
    return;
  }

  target.disabled = true;
  log.textContent = t.preparingAudio;
  log.style.display = 'block';
  setButtonWaiting(target, true);

  try {
    var voicePar = container.dataset.voice || '';
    var titlePar = container.dataset.entryTitle || '';
    var pAudio = await ttsLoadAudio(target.dataset.request, pText, voicePar, titlePar);
    setButtonWaiting(target, false);
    target._audio = pAudio;
    await playTtsAudio(pAudio, target, readLabel, pauseLabel, log, t, function () {
      if (target._sequenceParent) {
        var par2 = target._sequenceParent;
        target._sequenceParent = null;
        par2._playNextParagraph();
      }
    });
  } catch (err) {
    console.error(err);
    log.textContent = t.audioFailed;
    log.style.display = 'block';
    if (target._sequenceParent) {
      var par3 = target._sequenceParent;
      target._sequenceParent = null;
      par3._playNextParagraph();
    }
  } finally {
    target.disabled = false;
    setButtonWaiting(target, false);
  }
}

