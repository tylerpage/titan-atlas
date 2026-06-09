<?php

namespace App\Support;

class RichTextSanitizer
{
    protected const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><a><blockquote>';

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $cleaned = strip_tags($html, self::ALLOWED_TAGS);

        return preg_replace_callback(
            '/<a\s+([^>]*href\s*=\s*["\'])([^"\']*)(["\'][^>]*)>/i',
            function (array $matches) {
                $url = trim($matches[2]);

                if (! preg_match('#^https?://#i', $url) && ! str_starts_with($url, '/')) {
                    return '<a href="#" rel="noopener noreferrer">';
                }

                return '<a href="'.htmlspecialchars($url, ENT_QUOTES).'" rel="noopener noreferrer" target="_blank">';
            },
            $cleaned,
        ) ?? $cleaned;
    }
}
