<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DateTimeImmutable;

/**
 * Teilen-Paket: Einladungswege je Befragungsart und fertige, vorbefüllte Einladungstexte.
 * Leitgedanke: Der Kunde wählt nur seinen Weg, alles andere ist vorbereitet.
 */
final class ShareKit
{
    /** Kanäle in Anzeigereihenfolge */
    private const CHANNELS = [
        'email' => ['group' => 'Direkt', 'label' => 'E-Mail', 'icon' => 'email'],
        'whatsapp' => ['group' => 'Direkt', 'label' => 'WhatsApp / SMS', 'icon' => 'chat'],
        'poster' => ['group' => 'Vor Ort', 'label' => 'Aushang', 'icon' => 'poster'],
        'cards' => ['group' => 'Vor Ort', 'label' => 'Kärtchen', 'icon' => 'card'],
        'tablet' => ['group' => 'Vor Ort', 'label' => 'Tablet', 'icon' => 'tablet'],
        'website' => ['group' => 'Online', 'label' => 'Website / Social Media', 'icon' => 'web'],
        'signature' => ['group' => 'Online', 'label' => 'E-Mail-Signatur', 'icon' => 'pen'],
    ];

    /** Empfohlene Wege, Anlass („Wir möchten gern wissen, …“) und Überschrift je Befragungsart */
    private const PROFILES = [
        'cancellation' => [['email', 'whatsapp'], 'was wir hätten besser machen können', 'Was hätten wir besser machen können?'],
        'repurchase' => [['email', 'whatsapp'], 'was wir besser machen können, damit Sie gern wieder bei uns einkaufen', 'Was können wir besser machen?'],
        'renewal' => [['email', 'signature'], 'wie zufrieden Sie mit uns sind', 'Wie zufrieden sind Sie mit uns?'],
        'return' => [['email', 'cards'], 'wie zufrieden Sie mit uns sind und ob Sie wiederkommen würden', 'Wie zufrieden sind Sie mit uns?'],
        'store-satisfaction' => [['cards', 'poster', 'tablet'], 'wie Ihr Einkauf bei uns war', 'Wie war Ihr Einkauf heute?'],
        'online-satisfaction' => [['email', 'website'], 'wie Ihre Bestellung bei uns gelaufen ist', 'Wie war Ihre Bestellung?'],
        'service-satisfaction' => [['email', 'signature', 'whatsapp'], 'wie Sie unseren Service erlebt haben', 'Wie war unser Service?'],
        'transaction-nps' => [['email', 'cards'], 'wie zufrieden Sie mit uns sind', 'Wie zufrieden sind Sie mit uns?'],
        'relationship-nps' => [['email', 'signature'], 'wie zufrieden Sie mit uns sind', 'Wie zufrieden sind Sie mit uns?'],
        'purchase-effort' => [['email', 'cards'], 'wie einfach der Kauf bei uns für Sie war', 'Wie einfach war Ihr Kauf?'],
        'service-effort' => [['email', 'signature', 'whatsapp'], 'wie einfach Sie bei uns Hilfe bekommen haben', 'Wie einfach war es, Hilfe zu bekommen?'],
        'idea-customers' => [['email', 'poster', 'website'], 'was Sie von einer neuen Idee halten', 'Was halten Sie von unserer neuen Idee?'],
        'idea-prospects' => [['website', 'whatsapp', 'email'], 'was Sie von einer neuen Idee halten', 'Was halten Sie von dieser Idee?'],
        'product-use' => [['email', 'website'], 'wie Ihnen unser Produkt gefällt', 'Wie gefällt Ihnen unser Produkt?'],
        'product-nonpurchase' => [['email'], 'warum Sie sich gegen unser Angebot entschieden haben', 'Was hat Ihnen gefehlt?'],
        'employee-team' => [['email', 'poster', 'whatsapp'], 'wie die Zusammenarbeit im Team läuft', 'Wie läuft die Zusammenarbeit?'],
        'employee-workload' => [['email', 'poster', 'whatsapp'], 'wie es Ihnen im Arbeitsalltag geht', 'Wie geht es Ihnen bei der Arbeit?'],
        'employee-orientation' => [['email', 'whatsapp'], 'wie Ihre ersten Wochen bei uns waren', 'Wie waren Ihre ersten Wochen?'],
        'employee-training' => [['email', 'whatsapp'], 'wie Ihre Einarbeitung gelaufen ist', 'Wie war Ihre Einarbeitung?'],
    ];

    private const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /**
     * @param array $poll Fragebogen aus PollDrafts::find (catalog_key, wizard_answers, questions, comment_id)
     * @param ?string $company Firmenname (Rechnungsdaten)
     * @param ?DateTimeImmutable $ends Teilnahmeschluss (Ortszeit) oder null, wenn offen (Abo, noch nicht gestartet)
     */
    public static function build(array $poll, ?string $company, ?DateTimeImmutable $ends, string $url): array
    {
        $key = self::baseKey((string) ($poll['catalog_key'] ?? ''));
        $answers = json_decode((string) ($poll['wizard_answers'] ?? ''), true);
        $answers = is_array($answers) ? $answers : [];
        [$recommended, $topic, $headline] = self::PROFILES[$key]
            ?? [['email', 'cards', 'poster'], 'wie zufrieden Sie mit uns sind', 'Wie zufrieden sind Sie mit uns?'];
        if ($key === 'return' && ($answers['relationship'] ?? null) === 'store') {
            $recommended = ['cards', 'poster', 'email'];
        }
        $employees = str_starts_with($key, 'employee-');
        $count = count($poll['questions'] ?? []);
        $minutes = max(1, (int) ceil(($count * 10 + (isset($poll['comment_id']) ? 30 : 0)) / 60));
        $vars = [
            'topic' => $topic,
            'headline' => $headline,
            'duration' => $minutes <= 1 ? 'nur etwa eine Minute' : 'nur etwa ' . $minutes . ' Minuten',
            'durationShort' => $minutes <= 1 ? '1 Minute' : $minutes . ' Minuten',
            'until' => $ends === null ? null : $ends->format('j.') . ' ' . self::MONTHS[(int) $ends->format('n')],
            'company' => $company,
            'url' => $url,
            'employees' => $employees,
        ];

        $channels = [];
        foreach (self::CHANNELS as $id => $channel) {
            if ($employees && in_array($id, ['cards', 'tablet', 'website', 'signature'], true)) {
                continue;
            }
            $channels[$id] = [
                'group' => $channel['group'],
                'icon' => $channel['icon'],
                'label' => $employees && $id === 'whatsapp' ? 'Messenger-Gruppe' : $channel['label'],
                'recommended' => in_array($id, $recommended, true),
                'tip' => self::tip($id, $employees),
                'texts' => self::texts($id, $vars),
            ] + ($id === 'tablet' ? [
                'link' => $url . (str_contains($url, '?') ? '&' : '?') . 'geraet=tablet',
                'steps' => [
                    'Öffnen Sie den Tablet-Link im Browser des Tablets. Am einfachsten scannen Sie dazu den QR-Code rechts mit der Kamera des Tablets.',
                    'Speichern Sie die Seite als Lesezeichen oder auf dem Home-Bildschirm, dann ist sie nach einem Neustart schnell wieder da.',
                    'Sperren Sie das Tablet auf diese Seite: beim iPad mit „Geführter Zugriff“ (Einstellungen → Bedienungshilfen), bei Android mit „Bildschirmfixierung“ bzw. „App anpinnen“ (Einstellungen → Sicherheit). So kann niemand die Seite verlassen.',
                    'Schließen Sie das Tablet ans Ladekabel an und stellen Sie die automatische Bildschirmsperre auf „Nie“ oder eine lange Zeit.',
                ],
            ] : []);
        }
        // Empfohlene Wege zuerst, in der Reihenfolge des Profils
        $order = array_flip(array_keys(self::CHANNELS));
        uksort($channels, static function (string $a, string $b) use ($recommended, $order): int {
            $ra = array_search($a, $recommended, true);
            $rb = array_search($b, $recommended, true);
            return [($ra === false ? 99 : $ra), $order[$a]] <=> [($rb === false ? 99 : $rb), $order[$b]];
        });
        return ['channels' => $channels, 'headline' => $headline, 'employees' => $employees, 'durationShort' => $vars['durationShort']];
    }

    private static function baseKey(string $catalogKey): string
    {
        $best = '';
        foreach (array_keys(self::PROFILES) as $key) {
            if (str_starts_with($catalogKey, $key) && strlen($key) > strlen($best)) {
                $best = $key;
            }
        }
        return $best;
    }

    private static function tip(string $channel, bool $employees): string
    {
        return match ($channel) {
            'email' => $employees
                ? 'Schicken Sie die Einladung an alle, die mitmachen sollen, und sagen Sie kurz, wer die Ergebnisse sieht. Eine Erinnerung nach etwa einer Woche bringt meist noch einmal viele Antworten.'
                : 'Am besten kurz nach dem Kauf, Termin oder Kontakt, solange die Erinnerung frisch ist. Setzen Sie die Empfänger in BCC. Eine freundliche Erinnerung nach etwa einer Woche bringt meist noch einmal viele Antworten.',
            'whatsapp' => $employees
                ? 'Ideal für Teams ohne eigenen Rechner: einfach in die Gruppe posten. Kurz halten, der Link macht den Rest.'
                : 'Für Kunden, mit denen Sie ohnehin per WhatsApp oder SMS schreiben. Schicken Sie die Nachricht einzeln, nicht in eine große Gruppe.',
            'poster' => $employees
                ? 'Hängen Sie den QR-Code dort auf, wo alle vorbeikommen: Pausenraum, Stempeluhr, Teeküche.'
                : 'Hängen Sie den QR-Code dort auf, wo Kunden kurz warten: an der Kasse, im Wartebereich, an der Tür. Eine konkrete Frage als Überschrift wirkt besser als „Kundenbefragung“.',
            'cards' => 'Kleine Kärtchen mit QR-Code, die Sie mit dem Kassenbon oder in die Tüte geben. Persönlich überreicht mit einem kurzen Satz („Wir freuen uns über Ihre Meinung“) bringt das die meisten Antworten.',
            'tablet' => 'Ein Tablet am Ausgang oder an der Theke, auf dem Kunden direkt antworten. Mit dem Tablet-Link beginnt die Befragung nach jedem „Danke“ von selbst für die nächste Person neu, auch wenn jemand mittendrin aufhört. Stellen Sie das Tablet so auf, dass niemand beim Antworten zuschaut.',
            'website' => 'Als kurzer Beitrag auf Ihrer Website, bei Facebook, Instagram oder im Newsletter. Bei Instagram setzen Sie den Link in die Bio oder in eine Story.',
            'signature' => 'Ein Satz unter jeder E-Mail, die Sie oder Ihr Team verschicken. Kostet nichts und bringt über die Wochen stetig Antworten.',
            default => '',
        };
    }

    /** @return list<array{label:string, subject?:string, body:string}> */
    private static function texts(string $channel, array $v): array
    {
        $employees = $v['employees'];
        $hello = $employees ? 'Liebes Team,' : 'Guten Tag,';
        $from = $employees
            ? ($v['company'] !== null ? 'Ihre Geschäftsleitung, ' . $v['company'] : 'Ihre Geschäftsleitung')
            : ($v['company'] !== null ? 'Ihr Team von ' . $v['company'] : 'Ihr Team');
        $why = $employees
            ? 'Ihre ehrliche Meinung hilft uns, die Arbeit bei uns besser zu machen.'
            : 'Ihre Rückmeldung hilft uns, noch besser zu werden.';
        $anon = $employees ? 'Sie ist anonym, wir sehen nur die Gesamtergebnisse.' : 'Die Teilnahme ist anonym.';
        $until = $v['until'] !== null ? 'Mitmachen können Sie bis zum ' . $v['until'] . '.' : null;
        $subject = ($v['company'] !== null ? $v['company'] . ': ' : '') . ($employees ? 'Ihre Meinung ist gefragt' : str_replace(' heute?', '?', $v['headline']));
        $thanks = 'Vielen Dank!' . ($v['company'] !== null ? ' ' . $v['company'] : '');

        return match ($channel) {
            'email' => [
                [
                    'label' => 'Einladung',
                    'subject' => $subject,
                    'body' => self::lines([
                        $hello, '',
                        'wir möchten gern von Ihnen wissen, ' . $v['topic'] . '. ' . $why, '',
                        'Die Befragung dauert ' . $v['duration'] . '. ' . $anon,
                        $v['url'], '',
                        $until, $until !== null ? '' : null,
                        'Vielen Dank für Ihre Unterstützung!',
                        $from,
                    ]),
                ],
                [
                    'label' => 'Erinnerung',
                    'subject' => 'Erinnerung: ' . $subject,
                    'body' => self::lines([
                        $hello, '',
                        'vor einigen Tagen haben wir Sie um Ihre Meinung gebeten. Falls Sie noch nicht dazu gekommen sind: '
                            . ($v['until'] !== null ? 'Die Befragung ist noch bis zum ' . $v['until'] . ' geöffnet' : 'Die Befragung ist noch geöffnet')
                            . ' und dauert ' . $v['duration'] . '.',
                        $v['url'], '',
                        'Wenn Sie schon mitgemacht haben: herzlichen Dank!', '',
                        $from,
                    ]),
                ],
            ],
            'whatsapp' => [[
                'label' => 'Nachricht',
                'body' => self::lines([
                    ($employees ? 'Hallo zusammen! ' : 'Hallo! ') . 'Wir möchten gern wissen, ' . $v['topic'] . '. '
                        . 'Machen Sie mit? Dauert ' . $v['duration'] . ' und ist anonym:',
                    $v['url'],
                    $thanks,
                ]),
            ]],
            'poster' => [[
                'label' => 'Text für den Aushang',
                'body' => self::lines([
                    $v['headline'],
                    'Sagen Sie es uns in ' . $v['durationShort'] . ': QR-Code scannen und anonym mitmachen.',
                ]),
            ]],
            'cards' => [[
                'label' => 'Text für das Kärtchen',
                'body' => self::lines([
                    $v['headline'],
                    'Ihre Meinung in ' . $v['durationShort'] . ', einfach QR-Code scannen.',
                    $thanks,
                ]),
            ]],
            'tablet' => [[
                'label' => 'Schild neben dem Tablet',
                'body' => self::lines([
                    $v['headline'],
                    'Hier können Sie es uns direkt sagen, in ' . $v['durationShort'] . '. ' . $thanks,
                ]),
            ]],
            'website' => [[
                'label' => 'Beitrag',
                'body' => self::lines([
                    'Ihre Meinung ist gefragt! Wir möchten gern wissen, ' . $v['topic'] . '. '
                        . 'Die Befragung dauert ' . $v['duration'] . ' und ist anonym.'
                        . ($v['until'] !== null ? ' Mitmachen können Sie bis zum ' . $v['until'] . '.' : ''),
                    'Jetzt mitmachen: ' . $v['url'],
                ]),
            ]],
            'signature' => [[
                'label' => 'Zeile für die Signatur',
                'body' => $v['headline'] . ' Sagen Sie es uns in ' . $v['durationShort'] . ': ' . $v['url'],
            ]],
            default => [],
        };
    }

    private static function lines(array $lines): string
    {
        return rtrim(implode("\n", array_filter($lines, static fn (?string $line): bool => $line !== null)));
    }
}
