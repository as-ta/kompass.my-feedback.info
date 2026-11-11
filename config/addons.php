<?php

declare(strict_types=1);

$choice = static fn (string $text, array $options): array => [
    'text' => $text, 'options' => $options,
];

return [
    'loss' => [
        'alternatives' => $choice('Wie leicht fällt es Ihnen, ein passendes Angebot zu finden?', [
            'Sehr schwer', 'Eher schwer', 'Weder leicht noch schwer', 'Eher leicht', 'Sehr leicht', 'Kann ich nicht sagen',
        ]),
        'expectations' => $choice('Was ist Ihnen bei einem solchen Angebot am wichtigsten?', [
            'Preis', 'Qualität', 'Gute Beratung', 'Schnelle Verfügbarkeit', 'Einfache Nutzung', 'Etwas anderes',
        ]),
    ],
    'satisfaction' => [
        'first-time' => $choice('Hatten Sie vor Ihrem letzten Einkauf oder Kontakt schon einmal mit uns zu tun?', [
            'Ja', 'Nein', 'Weiß ich nicht mehr', 'Kann ich nicht beantworten',
        ]),
        'improvements' => $choice('Wo wünschen Sie sich am ehesten eine Verbesserung?', [
            'Informationen zum Angebot', 'Wartezeit', 'Erreichbarkeit', 'Verständlichkeit der nächsten Schritte',
            'Etwas anderes', 'Kein Wunsch nach Verbesserung',
        ]),
    ],
    'product' => [
        'frequency' => $choice('Wie oft brauchen Sie ein Produkt oder eine Leistung dieser Art?', [
            'Täglich oder fast täglich', 'Mindestens einmal pro Woche', 'Mindestens einmal pro Monat',
            'Seltener', 'Nie', 'Kann ich nicht sagen',
        ]),
        'priorities' => $choice('Welche Eigenschaft ist Ihnen bei einem solchen Angebot am wichtigsten?', [
            'Einfache Nutzung', 'Zuverlässigkeit', 'Preis', 'Schnelle Verfügbarkeit', 'Unterstützung bei Fragen', 'Etwas anderes',
        ]),
    ],
    'employees' => [
        'follow-up' => $choice('Wünschen Sie sich zu diesem Thema einen regelmäßigen Austausch?', [
            'Ja', 'Nein', 'Kann ich noch nicht sagen',
        ]),
        'priorities' => $choice('In welchem Bereich wünschen Sie sich am ehesten eine Verbesserung?', [
            'Informationsaustausch', 'Arbeitsmittel', 'Planung der Aufgaben', 'Unterstützung bei Fragen',
            'Etwas anderes', 'Kein Wunsch nach Verbesserung',
        ]),
    ],
];
