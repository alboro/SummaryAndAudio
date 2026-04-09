<?php

class FreshExtension_SummaryAndAudio_Controller extends Minz_ActionController
{
  /**
   * Load configured buttons array.
   * Falls back to legacy flat config keys (oai_url/oai_key/oai_model/oai_prompt) if oai_buttons is not set.
   */
  private function loadButtons(): array
  {
    $json = FreshRSS_Context::$user_conf->oai_buttons;
    if ($json) {
      $buttons = json_decode((string)$json, true);
      if (is_array($buttons) && count($buttons) > 0) {
        return $buttons;
      }
    }
    // Legacy fallback
    $url     = (string)(FreshRSS_Context::$user_conf->oai_url      ?? '');
    $key     = (string)(FreshRSS_Context::$user_conf->oai_key      ?? '');
    $model   = (string)(FreshRSS_Context::$user_conf->oai_model    ?? '');
    $prompt1 = (string)(FreshRSS_Context::$user_conf->oai_prompt   ?? '');
    $prompt2 = (string)(FreshRSS_Context::$user_conf->oai_prompt_2 ?? '');
    $buttons = [];
    if (trim($prompt1) !== '') {
      $buttons[] = ['label' => 'Summarize', 'url' => $url, 'key' => $key, 'model' => $model, 'prompt' => $prompt1];
    }
    if (trim($prompt2) !== '') {
      $buttons[] = ['label' => '+', 'url' => $url, 'key' => $key, 'model' => $model, 'prompt' => $prompt2];
    }
    return $buttons;
  }

  /**
   * Disable Apache mod_deflate buffering and clear all PHP output buffers.
   * Must be called before the first SSE echo so chunks stream immediately.
   */
  private function prepareSseHeaders(string $contentType): void
  {
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    if (function_exists('apache_setenv')) {
      apache_setenv('no-gzip', '1');
      apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    ob_implicit_flush(true);
  }

  /**
   * Fetch full article HTML from URL and convert to Markdown.
   * Used when RSS entry content is too short (< 200 chars).
   */
  private function fetchFullContent(string $url): string
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FreshRSS)',
      CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) {
      return '';
    }
    return $this->htmlToMarkdown((string)$html);
  }

  public function summarizeAction()
  {
    $this->view->_layout(false);

    $buttons = $this->loadButtons();
    $btn_idx = (int)(Minz_Request::param('btn') ?? 0);
    $button  = $buttons[$btn_idx] ?? null;

    if (empty($button)) {
      header('Content-Type: application/json');
      echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
      return;
    }

    $oai_url    = trim((string)($button['url']    ?? ''));
    $oai_key    = trim((string)($button['key']    ?? ''));
    $oai_model  = trim((string)($button['model']  ?? ''));
    $oai_prompt = trim((string)($button['prompt'] ?? ''));

    if ($this->isEmpty($oai_url) || $this->isEmpty($oai_key) || $this->isEmpty($oai_model) || $this->isEmpty($oai_prompt)) {
      header('Content-Type: application/json');
      echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
      return;
    }

    $entry_id  = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry     = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      header('Content-Type: application/json');
      echo json_encode(['status' => 404]);
      return;
    }

    // Auto-fetch full article when RSS content is too short
    $content = $entry->content();
    if (strlen(strip_tags($content)) < 200) {
      $entryUrl = method_exists($entry, 'link') ? $entry->link() : '';
      if ($entryUrl) {
        $fetched = $this->fetchFullContent($entryUrl);
        $markdownContent = strlen($fetched) > strlen(strip_tags($content))
          ? $fetched
          : $this->htmlToMarkdown($content);
      } else {
        $markdownContent = $this->htmlToMarkdown($content);
      }
    } else {
      $markdownContent = $this->htmlToMarkdown($content);
    }

    $oai_url = rtrim($oai_url, '/');
    if (!preg_match('/\/v\d+\/?$/', $oai_url)) {
      $oai_url .= '/v1';
    }

    $payload = json_encode([
      'model'             => $oai_model,
      'input'             => $inputMessages,
      'reasoning'         => ['effort' => 'low'],
      'max_output_tokens' => 2048,
      'temperature'       => 1,
      'stream'            => true,
    ]);

    $summaryUrl = $oai_url . '/responses';

    $headersSent     = false;
    $statusCode      = 0;
    $respContentType = '';
    $errorBody       = '';

    $ch = curl_init($summaryUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_TIMEOUT        => 180,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $oai_key,
      ],
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$statusCode, &$respContentType) {
        $len = strlen($header);
        if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
          $statusCode = (int)$m[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
          $respContentType = trim(substr($header, 13));
        }
        return $len;
      },
      CURLOPT_WRITEFUNCTION  => function ($curl, $data) use (&$headersSent, &$statusCode, &$respContentType, &$errorBody) {
        if (!$headersSent) {
          if ($statusCode >= 200 && $statusCode < 300) {
            $this->prepareSseHeaders($respContentType ?: 'text/event-stream');
          } else {
            header('Content-Type: application/json', true, $statusCode ?: 500);
          }
          $headersSent = true;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
          echo $data;
          flush();
        } else {
          $errorBody .= $data;
        }
        return strlen($data);
      },
    ]);

    $result = curl_exec($ch);
    if ($result === false && !$headersSent) {
      $errorBody = curl_error($ch);
    }
    curl_close($ch);

    if ($result === false || $statusCode < 200 || $statusCode >= 300) {
      if (!$headersSent) {
        header('Content-Type: application/json', true, $statusCode ?: 500);
      }
      $msg     = 'Summary request failed';
      $decoded = json_decode($errorBody, true);
      if ($decoded && isset($decoded['error']['message'])) {
        $msg = $decoded['error']['message'];
      }
      echo json_encode(['response' => ['data' => '', 'error' => $msg], 'status' => $statusCode ?: 500]);
    }
    return;
  }

  public function speakAction()
  {
    $this->view->_layout(false);

    // TTS connection: dedicated oai_tts_url/oai_tts_key, fallback to first button
    $tts_url = trim((string)(FreshRSS_Context::$user_conf->oai_tts_url ?? ''));
    $tts_key = trim((string)(FreshRSS_Context::$user_conf->oai_tts_key ?? ''));
    if ($this->isEmpty($tts_url) || $this->isEmpty($tts_key)) {
      $buttons = $this->loadButtons();
      $btn0    = $buttons[0] ?? [];
      if ($this->isEmpty($tts_url)) {
        $tts_url = trim((string)($btn0['url'] ?? ''));
      }
      if ($this->isEmpty($tts_key)) {
        $tts_key = trim((string)($btn0['key'] ?? ''));
      }
    }

    $tts_model = trim((string)(FreshRSS_Context::$user_conf->oai_tts_model ?? 'tts-1'));
    $voice     = trim((string)(FreshRSS_Context::$user_conf->oai_voice     ?? 'alloy'));
    $speed     = FreshRSS_Context::$user_conf->oai_speed;
    if ($speed === null || !is_numeric($speed)) {
      $speed = 1.1;
    }
    $speed   = max(0.5, min(4.0, (float)$speed));
    $content = trim((string)(Minz_Request::param('content') ?? ''));
    $format  = (string)(Minz_Request::param('format') ?? 'opus');
    $format  = in_array($format, ['mp3', 'ogg', 'opus']) ? $format : 'opus';

    if ($this->isEmpty($tts_url) || $this->isEmpty($tts_key) || $this->isEmpty($tts_model) || $this->isEmpty($voice) || $this->isEmpty($content)) {
      header('Content-Type: application/json');
      echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
      return;
    }

    $tts_url = rtrim($tts_url, '/');
    if (!preg_match('/\/v\d+\/?$/', $tts_url)) {
      $tts_url .= '/v1';
    }
    $summaryUrl = $oai_url . '/responses';

    // Support %rss_content% placeholder in prompt:
    // If present, content is embedded directly in the user message (no separate system role).
    // If absent, use default: system = prompt, user = article content.
    if (strpos($oai_prompt, '%rss_content%') !== false) {
      $inputMessages = [
        ['role' => 'user', 'content' => str_replace('%rss_content%', $markdownContent, $oai_prompt)],
      ];
    } else {
      $inputMessages = [
        ['role' => 'system', 'content' => $oai_prompt],
        ['role' => 'user',   'content' => "input: \n" . $markdownContent],
      ];
    }

    $payload = json_encode([
      'model'  => $tts_model,
      'voice'  => $voice,
      'speed'  => $speed,
      'input'  => $content,
      'format' => $format,
    ]);

    $headersSent     = false;
    $statusCode      = 0;
    $respContentType = '';
    $errorBody       = '';

    $ch = curl_init($tts_endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $tts_key,
      ],
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$statusCode, &$respContentType) {
        $len = strlen($header);
        if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
          $statusCode = (int)$m[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
          $respContentType = trim(substr($header, 13));
        }
        return $len;
      },
      CURLOPT_WRITEFUNCTION  => function ($curl, $data) use (&$headersSent, &$statusCode, &$respContentType, &$errorBody) {
        if (!$headersSent) {
          if ($statusCode >= 200 && $statusCode < 300) {
            header('Content-Type: ' . ($respContentType ?: 'audio/ogg'));
            header('Cache-Control: no-cache');
            while (ob_get_level() > 0) { ob_end_clean(); }
            ob_implicit_flush(true);
          } else {
            header('Content-Type: application/json', true, $statusCode ?: 500);
          }
          $headersSent = true;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
          echo $data;
          flush();
        } else {
          $errorBody .= $data;
        }
        return strlen($data);
      },
    ]);

    $result = curl_exec($ch);
    if ($result === false && !$headersSent) {
      $errorBody = curl_error($ch);
    }
    curl_close($ch);

    if ($result === false || $statusCode < 200 || $statusCode >= 300) {
      if (!$headersSent) {
        header('Content-Type: application/json', true, $statusCode ?: 500);
      }
      $msg     = 'Audio request failed';
      $decoded = json_decode($errorBody, true);
      if ($decoded && isset($decoded['error']['message'])) {
        $msg = $decoded['error']['message'];
      }
      echo json_encode(['response' => ['data' => '', 'error' => $msg], 'status' => $statusCode ?: 500]);
    }
    return;
  }

  /**
   * Disabled for security: previously leaked oai_key to the browser.
   */
  public function fetchTtsParamsAction()
  {
    $this->view->_layout(false);
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'This endpoint is disabled for security reasons.']);
    return;
  }

  private function isEmpty($item): bool
  {
    return $item === null || (is_string($item) && trim($item) === '');
  }

  private function htmlToMarkdown($content)
  {
    // Ensure DOM extension is available; otherwise fall back to plain text
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
      return trim(strip_tags($content));
    }

    // Creating DOMDocument objects
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath objects
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Processing text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Processing element nodes
      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'a':
            $markdown .= "`";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);

    return $markdown;
  }

}
