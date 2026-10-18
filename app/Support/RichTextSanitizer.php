<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Membersihkan HTML dari rich text editor dengan allowlist (anti-XSS).
 * Hanya tag yang bisa dihasilkan editor yang diizinkan; script, style, event handler (on*),
 * iframe, dan link javascript: otomatis dibuang.
 *
 * Gambar (<img>) hanya diizinkan bila diminta (allowImages) dan src-nya harus berawalan
 * salah satu $imageUrlPrefixes (folder upload milik aplikasi sendiri), bukan URL luar / data:.
 */
class RichTextSanitizer
{
    /** Batas panjang input mentah sebelum disanitasi */
    public const MAX_INPUT_LENGTH = 200_000;

    private ?HtmlSanitizer $textSanitizer = null;

    private ?HtmlSanitizer $imageSanitizer = null;

    /**
     * @param  list<string>  $imageUrlPrefixes  URL folder gambar yang boleh dipakai di <img src>
     */
    public function __construct(private readonly array $imageUrlPrefixes = []) {}

    /**
     * HTML bersih, atau null jika tidak ada isi yang tersisa (mis. "<p></p>" dari editor kosong).
     */
    public function clean(?string $html, bool $allowImages = false): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim($this->sanitizer($allowImages)->sanitize($html));

        if ($allowImages) {
            // <img> yang src-nya ditolak (URL luar) dibuang seluruhnya, bukan dibiarkan kosong
            $clean = trim((string) preg_replace('/<img(?![^>]*\ssrc=)[^>]*>/i', '', $clean));
        }

        return self::isBlank($clean) ? null : $clean;
    }

    private function sanitizer(bool $allowImages): HtmlSanitizer
    {
        if ($allowImages) {
            return $this->imageSanitizer ??= new HtmlSanitizer(
                $this->baseConfig()
                    ->allowElement('img', ['src', 'alt', 'title'])
                    ->allowMediaSchemes(['http', 'https'])
                    ->allowRelativeMedias(false)
                    ->withAttributeSanitizer($this->imageSourceSanitizer())
            );
        }

        return $this->textSanitizer ??= new HtmlSanitizer($this->baseConfig());
    }

    private function baseConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('ul')
            ->allowElement('ol', ['start'])
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('hr')
            ->allowElement('a', ['href', 'target'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);
    }

    /**
     * Hanya izinkan <img src> dari folder upload aplikasi (mencegah hotlink / tracking pixel dari luar).
     */
    private function imageSourceSanitizer(): AttributeSanitizerInterface
    {
        $prefixes = $this->imageUrlPrefixes;

        return new class($prefixes) implements AttributeSanitizerInterface
        {
            /** @param list<string> $prefixes */
            public function __construct(private readonly array $prefixes) {}

            public function getSupportedElements(): ?array
            {
                return ['img'];
            }

            public function getSupportedAttributes(): ?array
            {
                return ['src'];
            }

            public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
            {
                foreach ($this->prefixes as $prefix) {
                    if ($prefix !== '' && str_starts_with($value, $prefix) && ! str_contains($value, '..')) {
                        return $value;
                    }
                }

                return null;
            }
        };
    }

    private static function isBlank(string $html): bool
    {
        // Konten yang hanya berisi gambar tetap dianggap berisi
        if (stripos($html, '<img') !== false) {
            return false;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }
}
