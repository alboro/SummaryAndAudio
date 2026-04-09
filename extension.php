<?php
class SummaryAndAudioExtension extends Minz_Extension
{
  private static ?array $i18n = null;

  protected array $csp_policies = [
    'default-src' => '*',
  ];

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButton'));
    $this->registerController('SummaryAndAudio');
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButton($entry)
  {
    // Load configured buttons
    $buttons_json = FreshRSS_Context::$user_conf->oai_buttons;
    $buttons = $buttons_json ? json_decode((string)$buttons_json, true) : null;
    if (empty($buttons) || !is_array($buttons)) {
      // Legacy fallback
      $url   = (string)(FreshRSS_Context::$user_conf->oai_url     ?? '');
      $key   = (string)(FreshRSS_Context::$user_conf->oai_key     ?? '');
      $model = (string)(FreshRSS_Context::$user_conf->oai_model   ?? '');
      $p1    = (string)(FreshRSS_Context::$user_conf->oai_prompt   ?? '');
      $p2    = (string)(FreshRSS_Context::$user_conf->oai_prompt_2 ?? '');
      $buttons = [];
      if (trim($p1) !== '') {
        $buttons[] = ['label' => self::t('summarize'), 'url' => $url, 'key' => $key, 'model' => $model, 'prompt' => $p1];
      }
      if (trim($p2) !== '') {
        $buttons[] = ['label' => self::t('longer_summary'), 'url' => $url, 'key' => $key, 'model' => $model, 'prompt' => $p2];
      }
    }

    if (empty($buttons)) {
      return $entry;
    }

    $url_tts        = Minz_Url::display(['c' => 'SummaryAndAudio', 'a' => 'speak']);
    $icon_tts_play  = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-play" ',  file_get_contents(__DIR__ . '/static/img/play.svg'));
    $icon_tts_pause = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-pause" ', file_get_contents(__DIR__ . '/static/img/pause.svg'));
    $icon_summary   = str_replace('<svg ', '<svg class="oai-summary-icon" ',           file_get_contents(__DIR__ . '/static/img/summary.svg'));

    $paragraph_button = '<button data-request="' . $url_tts . '" class="oai-tts-btn oai-tts-paragraph" '
      . 'aria-label="' . self::t('read_paragraph') . '" title="' . self::t('read_paragraph') . '">'
      . $icon_tts_play . $icon_tts_pause . '</button>';
    $article_content = preg_replace('/<p\b([^>]*)>/', '<p$1>' . $paragraph_button, $entry->content());

    $attrs = [
      'data-read'              => self::t('read'),
      'data-pause'             => self::t('pause'),
      'data-preparing-request' => self::t('preparing_request'),
      'data-pending'           => self::t('pending'),
      'data-preparing-audio'   => self::t('preparing_audio'),
      'data-audio-failed'      => self::t('audio_failed'),
      'data-receiving-answer'  => self::t('receiving_answer'),
      'data-request-failed'    => self::t('request_failed'),
      'data-read-result'       => self::t('read_result'),
      'data-speak-result'      => $url_tts,
    ];
    $attr_str = '';
    foreach ($attrs as $name => $value) {
      $attr_str .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
    }

    // Build summary buttons (one per configured button)
    $summary_buttons_html = '';
    foreach ($buttons as $idx => $btn) {
      $url_btn = Minz_Url::display([
        'c' => 'SummaryAndAudio',
        'a' => 'summarize',
        'params' => ['id' => $entry->id(), 'btn' => $idx],
      ]);
      $label = htmlspecialchars((string)($btn['label'] ?? self::t('summarize')), ENT_QUOTES);
      $summary_buttons_html .= '<button data-request="' . $url_btn . '" data-btn="' . $idx . '" '
        . 'class="oai-summary-btn btn btn-small" '
        . 'aria-label="' . $label . '" title="' . $label . '">'
        . $icon_summary
        . '</button>';
    }

    $entry->_content(
      '<div class="oai-summary-wrap"' . $attr_str . '>'
      . '<button data-request="' . $url_tts . '" class="oai-tts-btn btn btn-small" '
      . 'aria-label="' . self::t('read') . '" title="' . self::t('read') . '">'
      . $icon_tts_play . $icon_tts_pause . '</button>'
      . $summary_buttons_html
      . '<div class="oai-summary-box">'
      . '<div class="oai-summary-loader"></div>'
      . '<div class="oai-summary-log"></div>'
      . '<div class="oai-summary-content"></div>'
      . '</div>'
      . '<div class="oai-summary-article">' . $article_content . '</div>'
      . '</div>'
    );
    return $entry;
  }

  public static function t(string $key): string
  {
    if (self::$i18n === null) {
      $lang = FreshRSS_Context::$user_conf->language ?? 'en';
      $file = __DIR__ . '/i18n/' . $lang . '.php';
      if (!is_file($file)) {
        $file = __DIR__ . '/i18n/en.php';
      }
      self::$i18n = include $file;
    }
    return self::$i18n[$key] ?? $key;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      // Process configurable buttons array
      $raw_buttons = Minz_Request::param('oai_btn', []);
      $buttons = [];
      if (is_array($raw_buttons)) {
        foreach ($raw_buttons as $btn) {
          if (!is_array($btn)) continue;
          $label  = trim((string)($btn['label']  ?? ''));
          $url    = trim((string)($btn['url']    ?? ''));
          $key    = trim((string)($btn['key']    ?? ''));
          $model  = trim((string)($btn['model']  ?? ''));
          $prompt = trim((string)($btn['prompt'] ?? ''));
          if ($label === '' && $prompt === '') continue;
          $buttons[] = compact('label', 'url', 'key', 'model', 'prompt');
        }
      }
      FreshRSS_Context::$user_conf->oai_buttons = json_encode($buttons, JSON_UNESCAPED_UNICODE);

      // TTS settings
      FreshRSS_Context::$user_conf->oai_tts_url   = Minz_Request::param('oai_tts_url',   '');
      FreshRSS_Context::$user_conf->oai_tts_key   = Minz_Request::param('oai_tts_key',   '');
      FreshRSS_Context::$user_conf->oai_tts_model = Minz_Request::param('oai_tts_model', '');
      FreshRSS_Context::$user_conf->oai_voice     = Minz_Request::param('oai_voice',     '');
      $speed = (float)Minz_Request::param('oai_speed', 1.1);
      if ($speed < 0.5 || $speed > 4.0) $speed = 1.1;
      FreshRSS_Context::$user_conf->oai_speed = $speed;
      FreshRSS_Context::$user_conf->save();
    }
  }
}
