<?php

declare(strict_types=1);

/**
 * Converts HTML to a plain-text Markdown representation suitable for LLM/TTS input.
 * Extracted from the original SummaryAndAudio controller for reuse and testability.
 */
class HtmlToMarkdownConverter
{
    public function convert(string $content): string
    {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return trim(strip_tags($content));
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $processNode = function ($node, int $indentLevel = 0) use (&$processNode) {
            $markdown = '';

            if ($node->nodeType === XML_TEXT_NODE) {
                return trim($node->nodeValue);
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return '';
            }

            switch ($node->nodeName) {
                case 'p':
                case 'div':
                    foreach ($node->childNodes as $child) {
                        $markdown .= $processNode($child);
                    }
                    $markdown .= "\n\n";
                    break;
                case 'h1':
                    $markdown .= '# ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'h2':
                    $markdown .= '## ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'h3':
                    $markdown .= '### ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'h4':
                    $markdown .= '#### ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'h5':
                    $markdown .= '##### ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'h6':
                    $markdown .= '###### ' . $processNode($node->firstChild) . "\n\n";
                    break;
                case 'a':
                    $markdown .= '`' . $processNode($node->firstChild) . '`';
                    break;
                case 'img':
                    $alt = $node->getAttribute('alt');
                    $markdown .= 'img: `' . $alt . '`';
                    break;
                case 'strong':
                case 'b':
                    $markdown .= '**' . $processNode($node->firstChild) . '**';
                    break;
                case 'em':
                case 'i':
                    $markdown .= '*' . $processNode($node->firstChild) . '*';
                    break;
                case 'ul':
                case 'ol':
                    $markdown .= "\n";
                    foreach ($node->childNodes as $child) {
                        if ($child->nodeName === 'li') {
                            $markdown .= str_repeat('  ', $indentLevel) . '- ';
                            $markdown .= $processNode($child, $indentLevel + 1);
                            $markdown .= "\n";
                        }
                    }
                    $markdown .= "\n";
                    break;
                case 'li':
                    foreach ($node->childNodes as $child) {
                        $markdown .= $processNode($child, $indentLevel + 1);
                    }
                    break;
                case 'br':
                    $markdown .= "\n";
                    break;
                case 'audio':
                case 'video':
                    $alt = $node->getAttribute('alt');
                    $markdown .= '[' . ($alt ?: 'Media') . ']';
                    break;
                default:
                    foreach ($node->childNodes as $child) {
                        $markdown .= $processNode($child);
                    }
                    break;
            }

            return $markdown;
        };

        $nodes    = $xpath->query('//body/*');
        $markdown = '';
        foreach ($nodes as $node) {
            $markdown .= $processNode($node);
        }

        return (string)preg_replace('/(\n){3,}/', "\n\n", $markdown);
    }
}

