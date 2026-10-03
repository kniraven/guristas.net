<?php
declare(strict_types=1);
/** Reusable, conservative rich-text sanitizer. Never render submitted HTML directly. */
function guristas_rich_clean(string $html): string {
    if (strlen($html) > 60000) throw new InvalidArgumentException('Rich text is too long (60 KB maximum).');
    if (!class_exists('DOMDocument')) throw new RuntimeException('Rich text requires the PHP DOM extension.');
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $doc->getElementsByTagName('body')->item(0);
        $render = function (DOMNode $node, int $depth = 0) use (&$render): string {
            if ($depth > 50) return '';
            if ($node instanceof DOMText) return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (!($node instanceof DOMElement)) return '';
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script','style','iframe','object','embed','svg','math','template','form','input','button','textarea','select','link','meta'], true)) return '';
            $content = '';
            foreach ($node->childNodes as $child) $content .= $render($child, $depth + 1);
            $tag = ['b'=>'strong', 'i'=>'em', 'div'=>'p', 'strike'=>'s'][$tag] ?? $tag;
            if ($tag === 'br') return '<br>';
            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if (!preg_match('~^https?://~i', $href) || !filter_var($href, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $href)) return $content;
                return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">'.$content.'</a>';
            }
            if (!in_array($tag, ['p','strong','em','u','s','ul','ol','li','blockquote','pre','code','h2','h3'], true)) return $content;
            return '<'.$tag.'>'.$content.'</'.$tag.'>';
        };
        $result = '';
        if ($body) foreach ($body->childNodes as $child) $result .= $render($child);
        return $result;
    } finally {
        libxml_clear_errors(); libxml_use_internal_errors($previous);
    }
}
