<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Traits;

/**
 * Removes trailing slashes from self-closing HTML void elements in Blade files.
 */
trait RemoveTrailingSlashes
{
    protected string $htmlTagRegex = '/<(?:TAGS)([^>]*)>/i';

    protected string $tagPlaceholder = 'TAGS';

    /**
     * HTML5 void elements — must not carry self-closing slashes.
     *
     * @var array<string>
     */
    protected array $voidElements = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen',
    ];

    /**
     * Remove trailing slashes from all void element tags in the given HTML content.
     */
    protected function processAutoClosingTags(string $htmlContent): string
    {
        $voidElementsPattern = implode('|', $this->voidElements);
        $regex               = str_replace($this->tagPlaceholder, $voidElementsPattern, $this->htmlTagRegex);

        return (string) preg_replace_callback($regex, function (array $matches): string {
            $tag = $matches[0];

            // Remove any trailing slash before the closing >
            return (string) preg_replace('/\s*\/>$/', '>', $tag);
        }, $htmlContent);
    }
}
