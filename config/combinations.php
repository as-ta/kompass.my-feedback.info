<?php

declare(strict_types=1);

$question = static fn (string $text, string $low, string $high): array => [
    'text' => $text,
    'options' => ['1', '2', '3', '4', '5'],
    'ends' => [$low, $high],
];

$separate = ['label' => 'Dieses Thema separat befragen'];

return [
    'compatible' => [
        'renewal', 'return', 'product-use',
        'store-satisfaction', 'online-satisfaction', 'service-satisfaction',
        'transaction-nps', 'relationship-nps', 'purchase-effort', 'service-effort',
        'acquisition', 'choice-reasons', 'customer-profile', 'price-perception', 'offer-wishes',
    ],
    'metricSuffix' => [
        'ces' => '-ces',
        'stars-ces' => '-ces',
        'stars-nps' => '-nps',
        'stars-nps-ces' => '-nps-ces',
    ],
    'steps' => [
        'loss' => [
            'title' => 'Was möchten Sie dieselben Kunden zum Wiederkommen fragen?',
            'hint' => 'Wählen Sie, was zu den Menschen passt, die Sie für Ihr Hauptanliegen einladen.',
            'options' => [
                'renew' => [
                    'label' => 'Ob sie ihren Vertrag oder ihr Abo verlängern möchten',
                    'question' => $question('Wie wahrscheinlich ist es, dass Sie Ihren Vertrag oder Ihr Abo verlängern?', 'Sehr unwahrscheinlich', 'Sehr wahrscheinlich'),
                ],
                'return' => [
                    'label' => 'Ob sie beim nächsten Bedarf wieder kaufen oder buchen würden',
                    'question' => $question('Wie wahrscheinlich ist es, dass Sie beim nächsten Bedarf wieder bei uns kaufen oder buchen?', 'Sehr unwahrscheinlich', 'Sehr wahrscheinlich'),
                ],
                'separate' => $separate + ['hint' => 'Zum Beispiel, wenn Sie stattdessen frühere Kunden nach ihrer Kündigung fragen möchten.'],
            ],
        ],
        'satisfaction' => [
            'title' => 'Können dieselben Kunden auch ihre Zufriedenheit bewerten?',
            'hint' => 'Die Frage bezieht sich auf dasselbe Angebot, um das es bei Ihrem Hauptanliegen geht.',
            'options' => [
                'include' => [
                    'label' => 'Ja, ich möchte dieselben Kunden nach ihrer Zufriedenheit fragen',
                    'question' => $question('Wie zufrieden sind Sie insgesamt mit diesem Angebot?', 'Sehr unzufrieden', 'Sehr zufrieden') + ['metric' => 'csat'],
                ],
                'separate' => $separate + ['hint' => 'Zum Beispiel, wenn Sie die Zufriedenheit mit einem anderen Produkt oder Erlebnis messen möchten.'],
            ],
        ],
        'product' => [
            'title' => 'Haben dieselben Kunden das Produkt schon genutzt?',
            'hint' => 'Nur dann können sie beurteilen, wie gut es im Alltag funktioniert.',
            'options' => [
                'include' => [
                    'label' => 'Ja, sie können das Produkt oder die Leistung beurteilen',
                    'question' => $question('Wie gut hat das Produkt oder die Leistung die Aufgabe erfüllt, für die Sie es genutzt haben?', 'Überhaupt nicht gut', 'Sehr gut'),
                ],
                'separate' => $separate + ['hint' => 'Zum Beispiel, wenn Sie eine neue Idee testen oder erfahren möchten, warum Menschen nicht gekauft haben.'],
            ],
        ],
    ],
];
