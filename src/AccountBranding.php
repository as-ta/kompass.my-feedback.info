<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DOMDocument;
use DOMElement;
use PDO;

/**
 * Eigenes Erscheinungsbild eines Kontos: Logo und bis zu zwei Farben für die Teilnahmeseite.
 * Ohne Angaben gilt das myFeedback-Standardlayout.
 */
final class AccountBranding
{
    public const MAX_BYTES = 1048576;
    private const FILE_PATTERN = '/\A[0-9a-f]{32}\.(png|jpg|svg)\z/';
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

    public function __construct(private readonly PDO $db)
    {
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/data/logos';
    }

    public function find(int $user): array
    {
        $read = $this->db->prepare('SELECT primary_color, accent_color, logo_file FROM account_branding WHERE account_id = ?');
        $read->execute([$user]);
        $row = $read->fetch() ?: [];
        $billing = (new AccountBilling($this->db))->find($user);
        return [
            'primary' => $row['primary_color'] ?? null,
            'accent' => $row['accent_color'] ?? null,
            'logo' => $row['logo_file'] ?? null,
            'name' => trim((string) ($billing['company'] ?? '')) !== '' ? trim((string) $billing['company']) : null,
        ];
    }

    public function isEmpty(array $branding): bool
    {
        return $branding['primary'] === null && $branding['accent'] === null && $branding['logo'] === null;
    }

    /** Speichert Farben und optional ein neues Logo. Gibt Fehlermeldungen je Feld zurück. */
    public function save(int $user, array $post, ?array $upload): array
    {
        $errors = [];
        $primary = self::color($post['primary_color'] ?? '', !empty($post['primary_default']));
        $accent = self::color($post['accent_color'] ?? '', !empty($post['accent_default']));
        if ($primary === false) {
            $errors['primary_color'] = 'Bitte geben Sie die Farbe im Format #1A2B3C an.';
        }
        if ($accent === false) {
            $errors['accent_color'] = 'Bitte geben Sie die Farbe im Format #1A2B3C an.';
        }

        $current = $this->find($user)['logo'];
        $logo = !empty($post['remove_logo']) ? null : $current;
        if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$file, $error] = $this->storeLogo($upload);
            if ($error !== null) {
                $errors['logo'] = $error;
            } else {
                $logo = $file;
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $write = $this->db->prepare(
            'INSERT INTO account_branding (account_id, primary_color, accent_color, logo_file) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE primary_color = VALUES(primary_color), accent_color = VALUES(accent_color), logo_file = VALUES(logo_file)'
        );
        $write->execute([$user, $primary, $accent, $logo]);
        if ($current !== null && $current !== $logo) {
            @unlink(self::directory() . '/' . $current);
        }
        return [];
    }

    /** null = Standardfarbe, false = ungültig */
    private static function color(mixed $value, bool $useDefault): string|null|false
    {
        if ($useDefault || !is_string($value) || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        return preg_match('/\A#[0-9a-f]{6}\z/', $value) ? $value : false;
    }

    private function storeLogo(array $upload): array
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            return [null, 'Das Logo konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.'];
        }
        if ((int) $upload['size'] > self::MAX_BYTES) {
            return [null, 'Das Logo darf höchstens 1 MB groß sein.'];
        }
        $content = (string) file_get_contents($upload['tmp_name']);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $extension = match (true) {
            $type === 'image/png' && @getimagesizefromstring($content) !== false => 'png',
            $type === 'image/jpeg' && @getimagesizefromstring($content) !== false => 'jpg',
            in_array($type, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'], true) && str_contains($content, '<svg') => 'svg',
            default => null,
        };
        if ($extension === null) {
            return [null, 'Bitte laden Sie eine PNG-, JPG- oder SVG-Datei hoch.'];
        }
        if ($extension === 'svg') {
            $content = self::cleanSvg($content);
            if ($content === null) {
                return [null, 'Diese SVG-Datei konnte nicht gelesen werden. Versuchen Sie es mit einer PNG-Datei.'];
            }
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            return [null, 'Das Logo konnte gerade nicht gespeichert werden.'];
        }
        $file = bin2hex(random_bytes(16)) . '.' . $extension;
        if (@file_put_contents($directory . '/' . $file, $content) === false) {
            return [null, 'Das Logo konnte gerade nicht gespeichert werden.'];
        }
        return [$file, null];
    }

    /** Entfernt aus einer SVG-Datei alles, was Code ausführen oder fremde Inhalte nachladen könnte. */
    public static function cleanSvg(string $content): ?string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            return null;
        }
        $document = new DOMDocument();
        if (!@$document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS) || $document->documentElement?->localName !== 'svg') {
            return null;
        }
        $remove = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animate', 'set', 'handler'], true)) {
                $remove[] = $element;
                continue;
            }
            /** @var DOMElement $element */
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = strtolower(preg_replace('/\s+/', '', $attribute->nodeValue));
                if (str_starts_with($name, 'on') || (str_ends_with($name, 'href') && !str_starts_with($value, '#') && !preg_match('#\Adata:image/(png|jpe?g|gif|webp);#', $value))
                    || str_contains($value, 'javascript:') || str_contains($value, 'url(http') || str_contains($value, '@import')) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
        foreach ($remove as $element) {
            $element->parentNode?->removeChild($element);
        }
        return $document->saveXML($document->documentElement) ?: null;
    }

    /** Liefert ein Logo aus. Gibt [Inhalt, Typ] oder null zurück. */
    public static function logo(string $file): ?array
    {
        if (!preg_match(self::FILE_PATTERN, $file, $match) || !is_file(self::directory() . '/' . $file)) {
            return null;
        }
        return [(string) file_get_contents(self::directory() . '/' . $file), self::TYPES[$match[1]]];
    }

    /** CSS-Variablen für die Teilnahmeseite. Leeres Ergebnis = Standardfarben. */
    public static function cssVariables(array $branding): string
    {
        $vars = [];
        if ($branding['primary'] !== null) {
            $primary = $branding['primary'];
            $vars['--green'] = $primary;
            $vars['--brand-on'] = self::contrast($primary, '#ffffff') >= 4.5 ? '#ffffff' : '#192921';
            $vars['--brand-dark'] = self::mix($primary, '#000000', 0.18);
            $vars['--brand-tint'] = self::mix($primary, '#ffffff', 0.9);
        }
        if ($branding['accent'] !== null) {
            $vars['--accent'] = $branding['accent'];
        } elseif ($branding['primary'] !== null) {
            $vars['--accent'] = $branding['primary'];
        }
        return implode('; ', array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($vars), $vars));
    }

    /** Hinweis, wenn sich eine Farbe kaum vom weißen Hintergrund abhebt. */
    public static function lowContrast(?string $color): bool
    {
        return $color !== null && self::contrast($color, '#ffffff') < 1.6;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $part): float {
            $value = hexdec($part) / 255;
            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function mix(string $a, string $b, float $amount): string
    {
        $pa = array_map('hexdec', str_split(ltrim($a, '#'), 2));
        $pb = array_map('hexdec', str_split(ltrim($b, '#'), 2));
        return '#' . implode('', array_map(
            static fn (int $x, int $y): string => str_pad(dechex((int) round($x + ($y - $x) * $amount)), 2, '0', STR_PAD_LEFT),
            $pa,
            $pb,
        ));
    }
}
