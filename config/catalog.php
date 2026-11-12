<?php

declare(strict_types=1);

$scale = static fn (string $text, int $min, int $max, string $low, string $high, bool $stars = false): array => [
    'text' => $text,
    'options' => array_map(
        static fn (int $value): string => (string) $value,
        range($min, $max),
    ),
    'ends' => [$low, $high],
    'stars' => $stars,
];

$choice = static fn (string $text, array $options): array => ['text' => $text, 'options' => $options];

$reasons = [
    'Preis oder Konditionen',
    'Angebot passte nicht mehr zu meinem Bedarf',
    'Qualität des Angebots',
    'Service oder Erreichbarkeit',
    'Ein anderes Angebot passte besser',
    'Ich benötige das Angebot derzeit nicht',
    'Ein anderer Grund',
    'Kann ich nicht sagen',
];

$inviteFormer = 'Laden Sie die betreffende Gruppe zeitnah per persönlichem Link ein. Befragen Sie ehemalige Kunden getrennt von bestehenden Kunden.';

$satisfaction = static function (string $title, string $question, string $distribution, array $aspects) use ($scale, $choice): array {
    return [
        'title' => $title,
        'why' => 'Ihre Kunden bewerten mit fünf Sternen, wie zufrieden sie waren.',
        'questions' => [
            $scale($question, 1, 5, 'Sehr unzufrieden', 'Sehr zufrieden', true) + ['metric' => 'csat'],
            $choice('Was hat Ihre Bewertung am stärksten beeinflusst?', $aspects),
        ],
        'comment' => 'Was möchten Sie uns zu Ihrer Bewertung noch sagen?',
        'distribution' => $distribution,
        'report' => 'Zeigen Sie die Anzahl der Antworten und die Verteilung über alle fünf Bewertungen.',
    ];
};

$nps = static function (string $title, string $distribution) use ($scale, $choice): array {
    return [
        'title' => $title,
        'why' => 'Sie erfahren, wie wahrscheinlich Ihre Kunden Ihr Unternehmen weiterempfehlen würden.',
        'questions' => [
            $scale(
                'Wie wahrscheinlich ist es, dass Sie unser Unternehmen Freunden oder Kollegen weiterempfehlen?',
                0,
                10,
                'Äußerst unwahrscheinlich',
                'Äußerst wahrscheinlich',
            ) + ['metric' => 'nps'],
            $choice('Haben Sie uns in den letzten drei Monaten jemandem empfohlen?', ['Ja', 'Nein', 'Weiß ich nicht mehr']),
        ],
        'comment' => 'Was ist der wichtigste Grund für Ihre Antwort?',
        'distribution' => $distribution,
        'report' => 'Ziehen Sie den Anteil der Antworten von 0 bis 6 vom Anteil der Antworten von 9 bis 10 ab. Antworten mit 7 oder 8 gehen in die Gesamtzahl ein. Zeigen Sie den NPS zusammen mit der Anzahl der Antworten.',
    ];
};

$likelihood = static fn (string $text): array => $scale($text, 1, 5, 'Sehr unwahrscheinlich', 'Sehr wahrscheinlich');

$ideaQuestions = [
    $choice('Wie lösen Sie die beschriebene Aufgabe heute meistens?', [
        'Mit einem gekauften Produkt oder einer Dienstleistung',
        'Mit einer eigenen Lösung',
        'Ich lasse die Aufgabe unerledigt',
        'Die Aufgabe kommt bei mir nicht vor',
        'Anders',
    ]),
    $scale('Wie nützlich wäre die vorgestellte Idee für Sie?', 1, 5, 'Überhaupt nicht nützlich', 'Sehr nützlich', true),
    $scale('Wie wahrscheinlich würden Sie das vorgestellte Angebot ausprobieren?', 1, 5, 'Sehr unwahrscheinlich', 'Sehr wahrscheinlich', true),
    $choice('Was müssten Sie am ehesten noch wissen, bevor Sie sich entscheiden?', [
        'Was es kostet',
        'Wie es funktioniert',
        'Wie viel Zeit oder Aufwand es braucht',
        'Wann und wo es erhältlich ist',
        'Etwas anderes',
        'Ich habe genug Informationen',
    ]),
];

$ideaSurvey = static fn (string $title, string $distribution): array => [
    'title' => $title,
    'why' => 'Sie erfahren, wie die Befragten die Aufgabe heute lösen und ob die Idee für sie nützlich wäre.',
    'questions' => $ideaQuestions,
    'comment' => 'Was würden Sie an der vorgestellten Idee ändern?',
    'distribution' => $distribution . ' Zeigen Sie allen dieselbe kurze Beschreibung der Idee und erklären Sie, um welche Aufgabe es geht.',
    'report' => 'Zeigen Sie getrennt, wie die Befragten die Aufgabe heute lösen, wie nützlich sie die Idee finden und ob sie sie ausprobieren würden.',
];

$employeeSurvey = static fn (string $title, string $why, array $questions, string $audience): array => [
    'title' => $title,
    'why' => $why,
    'questions' => $questions,
    'comment' => 'Was sollten wir aus Ihrer Sicht verbessern? Bitte nennen Sie keine Namen oder persönlichen Angaben.',
    'distribution' => $audience . ' Erklären Sie vorab, wer die Antworten sehen kann und wie Sie die Ergebnisse verwenden.',
    'report' => 'Fassen Sie die Antworten zusammen. Lassen Sie Auswertungen kleiner Gruppen und Kommentare weg, wenn daraus einzelne Personen erkennbar wären.',
];

$agreement = static fn (string $text): array => $scale($text, 1, 5, 'Trifft gar nicht zu', 'Trifft voll und ganz zu');

$recommendationAndEffort = static function (
    string $title,
    string $distribution,
    string $statement,
) use ($nps, $scale): array {
    $survey = $nps($title, $distribution);
    $survey['why'] = 'Sie erfahren, ob Kunden Sie weiterempfehlen würden und wie leicht ihnen der konkrete Vorgang gefallen ist.';
    array_splice($survey['questions'], 1, 0, [
        $scale($statement, 1, 5, 'Stimme überhaupt nicht zu', 'Stimme voll und ganz zu') + ['metric' => 'ces'],
    ]);
    $survey['comment'] = 'Was hat es Ihnen leichter oder schwerer gemacht?';
    return $survey;
};

$catalog = [
    'audiences' => [
        'cancellation' => 'Frühere Kunden, die einen Vertrag oder ein Abo gekündigt haben.',
        'repurchase' => 'Frühere Kunden, deren letzter Kauf länger zurückliegt als bei Ihnen üblich.',
        'renewal' => 'Aktuelle Kunden, bei denen bald eine Vertragsverlängerung ansteht.',
        'return' => 'Kunden nach einem abgeschlossenen Kauf oder Termin.',
        'store-satisfaction' => 'Kunden direkt nach ihrem Einkauf vor Ort.',
        'online-satisfaction' => 'Kunden, die gerade online bei Ihnen bestellt haben.',
        'service-satisfaction' => 'Kunden nach einem Kontakt mit dem Kundenservice.',
        'transaction-nps' => 'Kunden, die vor Kurzem gekauft oder einen Auftrag erteilt haben.',
        'relationship-nps' => 'Eine vergleichbare Auswahl aus Ihrem Kundenstamm in festen Abständen.',
        'purchase-effort' => 'Kunden kurz nach einem Kauf oder einer Buchung.',
        'service-effort' => 'Kunden kurz nach einem Kontakt mit dem Kundenservice.',
        'idea-customers' => 'Kunden, für die Ihre Idee im Alltag oder bei der Arbeit nützlich sein könnte.',
        'idea-prospects' => 'Menschen, für deren Alltag oder Arbeit die neue Idee gedacht ist.',
        'product-use' => 'Menschen, die das konkrete Produkt schon genutzt haben.',
        'product-nonpurchase' => 'Interessenten, die sich das Produkt angesehen und sich dagegen entschieden haben.',
        'employee-team' => 'Mitarbeiter, die regelmäßig im betreffenden Team zusammenarbeiten.',
        'employee-workload' => 'Mitarbeiter, deren Arbeitsalltag Sie besser verstehen möchten.',
        'employee-orientation' => 'Mitarbeiter nach den ersten Wochen in Ihrem Betrieb.',
        'employee-training' => 'Mitarbeiter, die ihre Einarbeitung vor Kurzem abgeschlossen haben.',
    ],
    'steps' => [
        'goal' => [
            'title' => 'Wo drückt der Schuh?',
            'hint' => 'Wählen Sie, was Sie gerade am meisten beschäftigt. Den passenden Fragebogen stellen wir für Sie zusammen.',
            'options' => [
                'loss' => [
                    'label' => 'Mir laufen Kunden davon',
                    'hint' => 'Wir fragen nach, warum Kunden kündigen oder wegbleiben und ob sie wiederkommen würden.',
                    'next' => 'audience',
                ],
                'satisfaction' => [
                    'label' => 'Ich will wissen, wie zufrieden meine Kunden sind',
                    'hint' => 'Zum Beispiel nach einem Einkauf oder Termin, mit Sternen oder mit der Frage, ob sie Sie weiterempfehlen würden.',
                    'next' => 'metric',
                ],
                'product' => [
                    'label' => 'Ich will wissen, wie mein Produkt oder meine Idee ankommt',
                    'hint' => 'Testen Sie eine neue Idee oder erfahren Sie, was an einem vorhandenen Angebot gut ankommt.',
                    'next' => 'product-stage',
                ],
                'employees' => [
                    'label' => 'Ich will wissen, wie es meinem Team geht',
                    'hint' => 'Fragen Sie Ihre Mitarbeiter nach Zusammenarbeit, Arbeitsalltag oder Einarbeitung.',
                    'next' => 'employee-topic',
                ],
            ],
        ],
        'audience' => [
            'title' => 'Wen können Sie fragen?',
            'hint' => 'Wählen Sie die Kunden, die Sie erreichen können.',
            'options' => [
                'former' => ['label' => 'Frühere Kunden', 'next' => 'departure'],
                'current' => ['label' => 'Aktuelle Kunden', 'next' => 'relationship'],
            ],
        ],
        'departure' => [
            'title' => 'Was ist passiert?',
            'hint' => 'Wählen Sie, was bei diesen Kunden passiert ist.',
            'options' => [
                'cancelled' => ['label' => 'Kunden haben einen Vertrag oder ein Abo gekündigt', 'next' => 'cancellation'],
                'lapsed' => ['label' => 'Kunden haben schon länger nichts mehr gekauft', 'next' => 'repurchase'],
            ],
        ],
        'relationship' => [
            'title' => 'Wie kaufen oder buchen diese Kunden bei Ihnen?',
            'hint' => 'Wählen Sie, was zu diesen Kunden passt.',
            'options' => [
                'contract' => ['label' => 'Sie haben einen Vertrag oder ein Abo', 'next' => 'renewal'],
                'purchase' => ['label' => 'Sie kaufen oder buchen bei Bedarf', 'next' => 'return'],
                'store' => ['label' => 'Sie kaufen im Geschäft', 'next' => 'return'],
            ],
        ],
        'metric' => [
            'title' => 'Was möchten Sie von Ihren Kunden wissen?',
            'hint' => 'Wählen Sie eine oder mehrere Fragen für dieselben Kunden.',
            'checkboxes' => [
                'stars' => [
                    'label' => 'Wie zufrieden sie waren',
                    'hint' => 'Eine kurze Bewertung mit fünf Sternen.',
                ],
                'nps' => [
                    'label' => 'Ob sie mein Unternehmen weiterempfehlen würden',
                    'hint' => 'Eine Antwort auf einer Skala von 0 bis 10 (NPS).',
                ],
                'ces' => [
                    'label' => 'Wie leicht es für sie war',
                    'hint' => 'Zum Beispiel beim Bestellen oder beim Kundenservice (CES).',
                ],
            ],
            'options' => [
                'stars' => ['next' => 'touchpoint'],
                'nps' => ['next' => 'occasion'],
                'ces' => ['next' => 'effort-context'],
                'stars-nps' => ['next' => 'touchpoint'],
                'stars-ces' => ['next' => 'touchpoint'],
                'nps-ces' => ['next' => 'effort-context'],
                'stars-nps-ces' => ['next' => 'touchpoint'],
            ],
        ],
        'touchpoint' => [
            'title' => 'Wozu möchten Sie Rückmeldung bekommen?',
            'hint' => 'Wählen Sie den Vorgang, auf den sich die Fragen beziehen sollen.',
            'options' => [
                'store' => ['label' => 'Ein Einkauf vor Ort', 'next' => 'store-satisfaction'],
                'online' => ['label' => 'Eine Online-Bestellung', 'next' => 'online-satisfaction'],
                'service' => ['label' => 'Ein Kontakt mit unserem Service', 'next' => 'service-satisfaction'],
            ],
        ],
        'effort-context' => [
            'title' => 'Wobei sollte es für Ihre Kunden einfach sein?',
            'hint' => 'Fragen Sie nach einem konkreten Vorgang, den Ihre Kunden gerade erlebt haben.',
            'options' => [
                'purchase' => ['label' => 'Beim Kaufen oder Buchen', 'next' => 'purchase-effort'],
                'service' => ['label' => 'Wenn sie Hilfe vom Kundenservice brauchten', 'next' => 'service-effort'],
            ],
        ],
        'occasion' => [
            'title' => 'Wann möchten Sie Ihre Kunden befragen?',
            'hint' => 'Wählen Sie, ob Sie nach einem Kauf oder zu einem anderen Zeitpunkt fragen möchten.',
            'options' => [
                'recent' => ['label' => 'Nach einem Kauf oder Auftrag', 'next' => 'transaction-nps'],
                'ongoing' => ['label' => 'Zu einem festen Zeitpunkt, unabhängig vom letzten Kauf', 'next' => 'relationship-nps'],
            ],
        ],
        'product-stage' => [
            'title' => 'Worum geht es?',
            'hint' => 'Wählen Sie, ob Sie eine Idee vorstellen oder ein vorhandenes Produkt untersuchen möchten.',
            'options' => [
                'idea' => ['label' => 'Eine Idee für ein neues Produkt oder eine neue Leistung', 'next' => 'idea-audience'],
                'existing' => ['label' => 'Ein Produkt oder eine Leistung, die wir bereits anbieten', 'next' => 'product-audience'],
            ],
        ],
        'idea-audience' => [
            'title' => 'Wen möchten Sie zur Idee fragen?',
            'hint' => 'Fragen Sie Menschen, für die das neue Angebot gedacht ist.',
            'options' => [
                'customers' => ['label' => 'Kunden, die schon etwas Ähnliches bei uns kaufen', 'next' => 'idea-customers'],
                'prospects' => ['label' => 'Menschen, die das Angebot erstmals interessieren könnte', 'next' => 'idea-prospects'],
            ],
        ],
        'product-audience' => [
            'title' => 'Wessen Sicht interessiert Sie?',
            'hint' => 'Wer das Produkt schon genutzt hat, kann es anders beurteilen als jemand, der vor der Kaufentscheidung stand.',
            'options' => [
                'users' => ['label' => 'Menschen, die das Produkt schon genutzt haben', 'next' => 'product-use'],
                'nonbuyers' => ['label' => 'Menschen, die sich das Produkt angesehen und sich dagegen entschieden haben', 'next' => 'product-nonpurchase'],
            ],
        ],
        'employee-topic' => [
            'title' => 'Worum soll es bei der Befragung gehen?',
            'hint' => 'Wählen Sie, zu welcher Situation Sie Rückmeldung möchten.',
            'options' => [
                'work' => ['label' => 'Wie die Arbeit im Alltag läuft', 'next' => 'work-topic'],
                'onboarding' => ['label' => 'Wie neue Mitarbeiter bei uns ankommen', 'next' => 'onboarding-topic'],
            ],
        ],
        'work-topic' => [
            'title' => 'Was möchten Sie genauer verstehen?',
            'hint' => 'Wählen Sie, wozu Sie Rückmeldung brauchen. Sie können beide Themen auswählen.',
            'checkboxes' => [
                'team' => ['label' => 'Wie gut wir im Team zusammenarbeiten'],
                'conditions' => ['label' => 'Ob Aufgaben und Arbeitsmenge gut zu schaffen sind'],
            ],
            'options' => [
                'team' => ['next' => 'employee-team'],
                'conditions' => ['next' => 'employee-workload'],
                'team-conditions' => ['next' => 'employee-work-and-team'],
                'both' => ['next' => 'employee-work-and-team', 'selection' => ['team', 'conditions']],
            ],
        ],
        'onboarding-topic' => [
            'title' => 'Was möchten Sie am Einstieg verbessern?',
            'hint' => 'Denken Sie an die ersten Wochen im Betrieb. Sie können beide Themen auswählen.',
            'checkboxes' => [
                'orientation' => ['label' => 'Wie schnell Neue wissen, an wen sie sich wenden können'],
                'training' => ['label' => 'Wie gut wir Neue auf ihre Aufgaben vorbereiten'],
            ],
            'options' => [
                'orientation' => ['next' => 'employee-orientation'],
                'training' => ['next' => 'employee-training'],
                'orientation-training' => ['next' => 'employee-onboarding'],
                'both' => ['next' => 'employee-onboarding', 'selection' => ['orientation', 'training']],
            ],
        ],
    ],
    'surveys' => [
        'purchase-effort' => $recommendationAndEffort(
            'Weiterempfehlung und Aufwand beim Kaufen oder Buchen',
            'Laden Sie Kunden kurz nach dem Kauf oder der Buchung ein. Fragen Sie jeweils in einem vergleichbaren Abstand zum Vorgang.',
            'Es war einfach, bei Ihnen zu kaufen oder zu buchen.',
        ),
        'service-effort' => $recommendationAndEffort(
            'Weiterempfehlung und Aufwand beim Kundenservice',
            'Laden Sie Kunden kurz nach einem Kontakt mit Ihrem Kundenservice ein.',
            'Es war einfach, bei Ihrem Kundenservice Hilfe zu bekommen.',
        ),
        'idea-customers' => $ideaSurvey(
            'Passt die neue Idee zu Ihren Kunden?',
            'Fragen Sie Kunden, die eine ähnliche Aufgabe haben oder bisher ein vergleichbares Angebot nutzen.',
        ),
        'idea-prospects' => $ideaSurvey(
            'Ist die neue Idee für mögliche Käufer interessant?',
            'Laden Sie Menschen ein, für deren Alltag oder Arbeit die Idee gedacht ist.',
        ),
        'product-use' => [
            'title' => 'Wie kommt das Produkt im Alltag an?',
            'why' => 'Menschen, die Ihr Produkt schon genutzt haben, können sagen, was ihnen hilft und wo es hakt.',
            'questions' => [
                $choice('Wie oft haben Sie dieses Produkt bisher genutzt?', [
                    'Einmal', 'Mehrmals', 'Weiß ich nicht mehr',
                ]),
                $scale('Wie gut hat das Produkt die Aufgabe erfüllt, für die Sie es genutzt haben?', 1, 5, 'Überhaupt nicht gut', 'Sehr gut'),
                $scale('Wie einfach war es für Sie, das Produkt zu nutzen?', 1, 5, 'Sehr schwierig', 'Sehr einfach'),
                $likelihood('Wie wahrscheinlich würden Sie das Produkt erneut nutzen?'),
            ],
            'comment' => 'Was sollten wir an diesem Produkt verbessern?',
            'distribution' => 'Benennen Sie das Produkt in der Einladung und befragen Sie Menschen, die es bereits genutzt haben.',
            'report' => 'Zeigen Sie getrennt, wie gut das Produkt seine Aufgabe erfüllt, wie einfach es zu nutzen ist und ob die Befragten es wieder nutzen würden.',
        ],
        'product-nonpurchase' => [
            'title' => 'Warum haben Interessenten das Produkt nicht gekauft?',
            'why' => 'Sie erfahren, was bei der Entscheidung am meisten gegen einen Kauf gesprochen hat.',
            'questions' => [
                $choice('Was war der wichtigste Grund für Ihre Entscheidung gegen das Produkt?', [
                    'Der Preis war mir zu hoch',
                    'Ein anderes Produkt passte besser',
                    'Eine für mich wichtige Eigenschaft hat gefehlt',
                    'Ich hatte zu wenig Informationen',
                    'Das Produkt war nicht rechtzeitig verfügbar',
                    'Ich brauchte kein solches Produkt mehr',
                    'Ein anderer Grund',
                    'Kann ich nicht sagen',
                ]),
                $choice('Wie haben Sie Ihren Bedarf danach gedeckt?', [
                    'Ich habe ein anderes Produkt gekauft',
                    'Ich habe eine andere Lösung gefunden',
                    'Ich habe die Anschaffung verschoben',
                    'Ich brauchte keine Lösung mehr',
                    'Kann ich nicht sagen',
                ]),
            ],
            'comment' => 'Was hätte Ihnen die Entscheidung für unser Produkt erleichtert?',
            'distribution' => 'Laden Sie Menschen ein, die das konkrete Produkt ernsthaft in Betracht gezogen und sich bereits entschieden haben.',
            'report' => 'Zeigen Sie die häufigsten Gründe und getrennt davon, was die Interessenten anschließend getan haben.',
        ],
        'employee-team' => $employeeSurvey(
            'Wie klappt die Zusammenarbeit im Team?',
            'Sie sehen, wo der Austausch im Team gut klappt und wo Mitarbeiter mehr Unterstützung brauchen.',
            [
                $agreement('Ich bekomme die Informationen, die ich für meine Arbeit brauche, rechtzeitig.'),
                $agreement('Wenn ich Hilfe brauche, unterstützen mich meine Kollegen.'),
                $agreement('Im Team kann ich Probleme offen ansprechen.'),
            ],
            'Laden Sie alle Mitarbeiter ein, die regelmäßig im betreffenden Team zusammenarbeiten.',
        ),
        'employee-workload' => $employeeSurvey(
            'Ist die Arbeit im Alltag gut zu schaffen?',
            'Sie sehen, wie Mitarbeiter die Arbeitsmenge einschätzen und ob klar ist, was Vorrang hat.',
            [
                $agreement('Ich kann meine Aufgaben in der vorgesehenen Arbeitszeit erledigen.'),
                $agreement('Wenn viel zu tun ist, weiß ich, welche Aufgaben Vorrang haben.'),
                $agreement('Mir stehen die Arbeitsmittel zur Verfügung, die ich für meine Aufgaben brauche.'),
            ],
            'Laden Sie Mitarbeiter ein, deren Arbeitsalltag Sie besser verstehen möchten.',
        ),
        'employee-orientation' => $employeeSurvey(
            'Finden sich neue Mitarbeiter gut zurecht?',
            'Sie erfahren, ob neue Mitarbeiter ihre Ansprechpartner und die wichtigsten Abläufe kennen.',
            [
                $agreement('In meiner ersten Arbeitswoche wusste ich, an wen ich mich mit Fragen wenden kann.'),
                $agreement('Ich konnte die Informationen finden, die ich für meinen Einstieg brauchte.'),
                $agreement('Mir war klar, was in den ersten Wochen von mir erwartet wurde.'),
            ],
            'Fragen Sie neue Mitarbeiter nach den ersten Wochen, solange sie sich noch gut an den Einstieg erinnern.',
        ),
        'employee-training' => $employeeSurvey(
            'Wie gut bereitet die Einarbeitung auf die Arbeit vor?',
            'Sie erfahren, ob neue Mitarbeiter ausreichend Anleitung, Übung und Rückmeldung bekommen haben.',
            [
                $agreement('Meine Aufgaben wurden mir verständlich erklärt.'),
                $agreement('Ich hatte genug Zeit, die wichtigsten Aufgaben zu üben.'),
                $agreement('Ich bekam Rückmeldung dazu, wie ich meine Aufgaben erledige.'),
            ],
            'Laden Sie Mitarbeiter ein, die ihre Einarbeitung vor Kurzem abgeschlossen haben.',
        ),
        'cancellation' => [
            'title' => 'Warum haben Kunden gekündigt?',
            'why' => 'Ehemalige Kunden können angeben, welcher Grund bei ihrer Kündigung ausschlaggebend war.',
            'questions' => [
                $choice('Was war der wichtigste Grund für Ihre Kündigung?', $reasons),
                $choice('Nutzen Sie inzwischen ein ähnliches Angebot?', [
                    'Ja, bei einem anderen Anbieter', 'Ja, ich habe eine eigene Lösung', 'Nein', 'Möchte ich nicht beantworten',
                ]),
            ],
            'comment' => 'Was hätten wir aus Ihrer Sicht besser machen können?',
            'distribution' => $inviteFormer,
            'report' => 'Zeigen Sie die häufigsten Kündigungsgründe mit Anzahl und Anteil der Antworten.',
        ],
        'repurchase' => [
            'title' => 'Warum haben frühere Kunden nicht wieder gekauft?',
            'why' => 'Diese Befragung zeigt, welche Hürden Ihre früheren Kunden bei einem weiteren Kauf sehen.',
            'questions' => [
                $choice('Was ist der wichtigste Grund, warum Sie bisher nicht erneut bei uns gekauft haben?', [
                    ...array_slice($reasons, 0, -2),
                    'Ich plane, wieder bei Ihnen einzukaufen',
                    ...array_slice($reasons, -2),
                ]),
                $choice('Haben Sie seit Ihrem letzten Einkauf etwas Vergleichbares woanders gekauft?', [
                    'Ja', 'Nein', 'Weiß ich nicht mehr',
                ]),
            ],
            'distribution' => 'Wählen Sie eine Gruppe, deren letzter Kauf deutlich länger als für Ihr Angebot üblich zurückliegt. ' . $inviteFormer,
            'report' => 'Zeigen Sie die Verteilung der Antworten. Weisen Sie die Gruppe, die wieder bei Ihnen kaufen möchte, separat aus.',
        ],
        'renewal' => [
            'title' => 'Möchten Ihre Kunden ihren Vertrag verlängern?',
            'why' => 'Fragen Sie Kunden vor der nächsten Verlängerung, ob sie ihren Vertrag behalten möchten.',
            'questions' => [
                $likelihood('Wie wahrscheinlich ist es, dass Sie Ihren Vertrag oder Ihr Abo verlängern?'),
                $choice('Haben Sie sich bereits ein vergleichbares Angebot angesehen?', ['Ja', 'Nein', 'Möchte ich nicht beantworten']),
            ],
            'distribution' => 'Versenden Sie den Link an Kunden, bei denen die nächste Entscheidung über eine Verlängerung absehbar ist.',
            'report' => 'Zeigen Sie die Antwortverteilung und die Anzahl der Antworten. Die Angaben messen eine Absicht zum Zeitpunkt der Befragung.',
        ],
        'return' => [
            'title' => 'Möchten Ihre Kunden wieder bei Ihnen kaufen oder buchen?',
            'why' => 'Sie erfahren, ob Ihre Kunden beim nächsten Bedarf wieder bei Ihnen kaufen oder buchen möchten.',
            'questions' => [
                $likelihood('Wie wahrscheinlich ist es, dass Sie beim nächsten Bedarf wieder bei uns kaufen oder buchen?'),
                $choice('Haben Sie seit Ihrem letzten Kauf oder Termin etwas Vergleichbares woanders gekauft oder gebucht?', [
                    'Ja', 'Nein', 'Weiß ich nicht mehr',
                ]),
            ],
            'distribution' => 'Laden Sie Kunden nach einem abgeschlossenen Kauf oder Termin per Link ein.',
            'report' => 'Zeigen Sie die Antwortverteilung und die Anzahl der Antworten. Die Angaben messen eine Absicht zum Zeitpunkt der Befragung.',
        ],
        'store-satisfaction' => $satisfaction(
            'Zufriedenheit beim Einkauf vor Ort',
            'Wie zufrieden waren Sie mit Ihrem heutigen Einkauf bei uns?',
            'Bieten Sie die Befragung direkt nach dem Einkauf an, etwa per QR-Code am Ausgang oder auf einer Karte.',
            ['Auswahl', 'Beratung', 'Wartezeit', 'Preis', 'Etwas anderes', 'Kann ich nicht sagen'],
        ),
        'online-satisfaction' => $satisfaction(
            'Zufriedenheit mit der Online-Bestellung',
            'Wie zufrieden waren Sie mit dem Bestellvorgang in unserem Online-Shop?',
            'Zeigen Sie den Befragungslink nach Abschluss der Bestellung an.',
            ['Produktsuche', 'Informationen zum Produkt', 'Bestellablauf', 'Zahlung', 'Etwas anderes', 'Kann ich nicht sagen'],
        ),
        'service-satisfaction' => $satisfaction(
            'Zufriedenheit mit dem Servicekontakt',
            'Wie zufrieden waren Sie mit Ihrem letzten Kontakt mit unserem Kundenservice?',
            'Versenden Sie den Befragungslink nach Abschluss des Servicefalls.',
            ['Erreichbarkeit', 'Wartezeit', 'Umgang mit meinem Anliegen', 'Lösung meines Anliegens', 'Etwas anderes', 'Kann ich nicht sagen'],
        ),
        'transaction-nps' => $nps(
            'Würden Kunden Sie nach dem Kauf weiterempfehlen?',
            'Laden Sie Kunden nach dem Kauf oder Auftrag in einem einheitlichen Abstand ein. Für Vergleiche sollte dieser Abstand gleich bleiben.',
        ),
        'relationship-nps' => $nps(
            'Würden Ihre Kunden Sie weiterempfehlen?',
            'Befragen Sie in festen Abständen eine vergleichbare Auswahl aus Ihrem Kundenstamm.',
        ),
    ],
];

// Neue Themen (12.11.2026): Kunden gewinnen, Angebot und Preise, Veranstaltung, Mitarbeiterzufriedenheit, Austritt
$goalOptions = $catalog['steps']['goal']['options'];
$catalog['steps']['goal']['options'] = [
    'loss' => $goalOptions['loss'],
    'satisfaction' => $goalOptions['satisfaction'],
    'growth' => [
        'label' => 'Ich möchte mehr Kunden gewinnen',
        'hint' => 'Erfahren Sie, wie Kunden Sie finden, warum sie sich für Sie entscheiden und wer sie sind.',
        'next' => 'growth-topic',
    ],
    'offer' => [
        'label' => 'Ich will wissen, ob Angebot und Preise passen',
        'hint' => 'Fragen Sie, wie Ihre Preise ankommen und was sich Kunden zusätzlich wünschen.',
        'next' => 'offer-topic',
    ],
    'product' => $goalOptions['product'],
    'employees' => $goalOptions['employees'],
];
$catalog['steps']['growth-topic'] = [
    'title' => 'Was möchten Sie über Ihre Kunden erfahren?',
    'hint' => 'Wählen Sie, was Ihnen beim Gewinnen neuer Kunden am meisten hilft.',
    'options' => [
        'channels' => ['label' => 'Wie Kunden auf mich aufmerksam werden', 'hint' => 'Sie sehen, welche Wege wirken: Empfehlung, Google, soziale Medien oder Werbung.', 'next' => 'acquisition'],
        'reasons' => ['label' => 'Warum Kunden sich für mich entscheiden', 'hint' => 'Sie erfahren, was Sie von anderen Anbietern unterscheidet.', 'next' => 'choice-reasons'],
        'profile' => ['label' => 'Wer meine Kunden sind', 'hint' => 'Sie erfahren, wie oft Ihre Kunden kommen, woher sie kommen und für wen sie kaufen.', 'next' => 'customer-profile'],
    ],
];
$catalog['steps']['offer-topic'] = [
    'title' => 'Was möchten Sie zu Ihrem Angebot wissen?',
    'hint' => 'Wählen Sie, was Sie gerade am meisten beschäftigt.',
    'options' => [
        'price' => ['label' => 'Wie meine Preise ankommen', 'hint' => 'Preis-Leistungs-Verhältnis, Vergleich mit anderen Anbietern und wofür Kunden mehr zahlen würden.', 'next' => 'price-perception'],
        'wishes' => ['label' => 'Was sich Kunden zusätzlich wünschen', 'hint' => 'Fehlende Produkte, Leistungen, Öffnungszeiten oder Services.', 'next' => 'offer-wishes'],
    ],
];
$catalog['steps']['touchpoint']['options']['event'] = ['label' => 'Eine Veranstaltung oder ein Seminar', 'next' => 'event-satisfaction'];
$catalog['steps']['employee-topic']['options']['general'] = ['label' => 'Wie zufrieden mein Team insgesamt ist', 'next' => 'employee-satisfaction'];
$catalog['steps']['employee-topic']['options']['exit'] = ['label' => 'Warum Mitarbeiter uns verlassen', 'next' => 'employee-exit'];

$employeeRecommendation = $scale(
    'Wie wahrscheinlich ist es, dass Sie uns als Arbeitgeber Freunden oder Bekannten empfehlen?',
    0,
    10,
    'Äußerst unwahrscheinlich',
    'Äußerst wahrscheinlich',
) + ['metric' => 'nps'];

$catalog['surveys'] += [
    'acquisition' => [
        'title' => 'Wie werden Kunden auf Sie aufmerksam?',
        'why' => 'Sie sehen, welche Wege Ihnen tatsächlich Kunden bringen, und können Ihr Werbebudget gezielter einsetzen.',
        'questions' => [
            $choice('Wie sind Sie zum ersten Mal auf uns aufmerksam geworden?', [
                'Empfehlung von Freunden, Bekannten oder Kollegen', 'Internetsuche (z. B. Google)', 'Bewertungsportal oder Karte (z. B. Google Maps)',
                'Soziale Medien', 'Anzeige oder Werbung', 'Zufällig vorbeigekommen', 'Messe, Veranstaltung oder bestehender Kontakt',
                'Etwas anderes', 'Weiß ich nicht mehr',
            ]),
            $choice('Seit wann sind Sie Kunde bei uns?', [
                'Heute zum ersten Mal', 'Seit weniger als einem Jahr', 'Seit 1 bis 3 Jahren', 'Seit mehr als 3 Jahren',
            ]),
            $choice('Wo informieren Sie sich am ehesten über Angebote wie unseres?', [
                'Suchmaschine', 'Soziale Medien', 'Bewertungsportale', 'Lokale Zeitung oder Anzeigenblatt',
                'Newsletter oder E-Mail', 'Im Bekanntenkreis', 'Etwas anderes',
            ]),
        ],
        'comment' => 'Wie könnten wir noch mehr Menschen wie Sie erreichen?',
        'distribution' => 'Fragen Sie möglichst neue Kunden, solange sie sich noch daran erinnern, wie sie auf Sie gekommen sind.',
        'report' => 'Zeigen Sie die Anteile der Wege, getrennt nach neuen und langjährigen Kunden.',
    ],
    'choice-reasons' => [
        'title' => 'Warum entscheiden sich Kunden für Sie?',
        'why' => 'Sie erfahren, was Sie aus Sicht Ihrer Kunden von anderen Anbietern unterscheidet. Genau das gehört in Ihre Werbung.',
        'questions' => [
            $choice('Was ist der wichtigste Grund, warum Sie sich für uns entschieden haben?', [
                'Qualität', 'Preis', 'Beratung und Service', 'Auswahl', 'Lage und Erreichbarkeit',
                'Persönlicher Kontakt', 'Empfehlung', 'Etwas anderes',
            ]),
            $choice('Haben Sie vor Ihrer Entscheidung auch andere Anbieter in Betracht gezogen?', [
                'Ja, mehrere', 'Ja, einen', 'Nein', 'Weiß ich nicht mehr',
            ]),
            $scale('Wie schneiden wir im Vergleich zu anderen Anbietern ab?', 1, 5, 'Deutlich schlechter', 'Deutlich besser'),
        ],
        'comment' => 'Was unterscheidet uns aus Ihrer Sicht von anderen Anbietern?',
        'distribution' => 'Fragen Sie aktuelle Kunden, am besten kurz nach einem Kauf oder Auftrag.',
        'report' => 'Zeigen Sie die wichtigsten Gründe und den Vergleich mit anderen Anbietern.',
    ],
    'customer-profile' => [
        'title' => 'Wer sind Ihre Kunden?',
        'why' => 'Sie erfahren, wie oft Ihre Kunden kommen, woher sie kommen und für wen sie kaufen. So können Sie gezielter Menschen wie sie ansprechen.',
        'questions' => [
            $choice('Wie oft nutzen Sie unser Angebot?', [
                'Heute zum ersten Mal', 'Mehrmals pro Woche', 'Etwa einmal pro Woche', 'Etwa einmal pro Monat', 'Mehrmals im Jahr', 'Seltener',
            ]),
            $choice('Wie lange brauchen Sie ungefähr zu uns?', [
                'Weniger als 5 Minuten', '5 bis 15 Minuten', '15 bis 30 Minuten', 'Mehr als 30 Minuten', 'Ich nutze das Angebot online',
            ]),
            $choice('Für wen kaufen oder buchen Sie bei uns meistens?', [
                'Für mich selbst', 'Für meine Familie oder meinen Haushalt', 'Für meine Firma', 'Als Geschenk', 'Unterschiedlich',
            ]),
            $choice('Zu welcher Altersgruppe gehören Sie?', [
                'Unter 25', '25 bis 39', '40 bis 54', '55 bis 69', '70 oder älter', 'Möchte ich nicht angeben',
            ]),
        ],
        'comment' => 'Was ist Ihnen bei einem Angebot wie unserem besonders wichtig?',
        'distribution' => 'Fragen Sie über einen längeren Zeitraum möglichst unterschiedliche Kunden, nicht nur Stammkunden.',
        'report' => 'Zeigen Sie die Verteilung je Frage. Werten Sie keine sehr kleinen Gruppen aus.',
    ],
    'price-perception' => [
        'title' => 'Wie kommen Ihre Preise an?',
        'why' => 'Sie erfahren, wie Kunden Ihr Preis-Leistungs-Verhältnis einschätzen und wofür sie mehr zahlen würden.',
        'questions' => [
            $scale('Wie bewerten Sie unser Preis-Leistungs-Verhältnis?', 1, 5, 'Sehr schlecht', 'Sehr gut', true),
            $choice('Wie empfinden Sie unsere Preise im Vergleich zu ähnlichen Anbietern?', [
                'Deutlich günstiger', 'Etwas günstiger', 'Etwa gleich', 'Etwas teurer', 'Deutlich teurer', 'Kann ich nicht beurteilen',
            ]),
            $choice('Wofür würden Sie am ehesten etwas mehr bezahlen?', [
                'Bessere Qualität', 'Mehr Beratung oder Service', 'Schnellere Verfügbarkeit', 'Mehr Flexibilität bei Terminen oder Öffnungszeiten',
                'Nichts davon', 'Kann ich nicht sagen',
            ]),
        ],
        'comment' => 'Was sollten wir bei unseren Preisen oder Leistungen ändern?',
        'distribution' => 'Fragen Sie aktuelle Kunden kurz nach einem Kauf oder Auftrag, solange der Preis noch präsent ist.',
        'report' => 'Zeigen Sie die Bewertung des Preis-Leistungs-Verhältnisses und den Vergleich mit anderen Anbietern.',
    ],
    'offer-wishes' => [
        'title' => 'Was wünschen sich Ihre Kunden zusätzlich?',
        'why' => 'Sie erfahren, was Ihren Kunden bei Ihnen fehlt und wofür sie bisher woanders hingehen.',
        'questions' => [
            $scale('Wie gut deckt unser Angebot Ihren Bedarf ab?', 1, 5, 'Überhaupt nicht', 'Vollständig', true),
            $choice('Was würden Sie sich von uns am meisten wünschen?', [
                'Größere Auswahl', 'Neue Produkte oder Leistungen', 'Andere Öffnungszeiten', 'Online bestellen oder buchen',
                'Liefer- oder Abholservice', 'Mehr Beratung', 'Etwas anderes', 'Nichts davon',
            ]),
            $choice('Haben Sie in letzter Zeit etwas woanders gekauft oder gebucht, das Sie gern bei uns bekommen hätten?', [
                'Ja', 'Nein', 'Weiß ich nicht mehr',
            ]),
        ],
        'comment' => 'Was genau fehlt Ihnen bei uns?',
        'distribution' => 'Fragen Sie aktuelle Kunden, gern auch Stammkunden. Sie kennen Ihr Angebot am besten.',
        'report' => 'Zeigen Sie die häufigsten Wünsche. Die Freitexte nennen oft konkrete Produkte oder Leistungen.',
    ],
    'event-satisfaction' => $satisfaction(
        'Zufriedenheit mit der Veranstaltung',
        'Wie zufrieden waren Sie mit der Veranstaltung insgesamt?',
        'Bieten Sie die Befragung direkt am Ende der Veranstaltung an, etwa per QR-Code auf der letzten Folie oder als Link in der Dankes-E-Mail.',
        ['Inhalte', 'Referentin oder Referent', 'Organisation', 'Ort und Räume', 'Verpflegung', 'Etwas anderes', 'Kann ich nicht sagen'],
    ),
    'employee-satisfaction' => $employeeSurvey(
        'Wie zufrieden ist Ihr Team?',
        'Sie erfahren, wie gern Ihre Mitarbeiter bei Ihnen arbeiten und ob sie Sie als Arbeitgeber weiterempfehlen würden.',
        [
            $employeeRecommendation,
            $agreement('Ich arbeite gern in diesem Betrieb.'),
            $agreement('Meine Arbeit wird wertgeschätzt.'),
            $agreement('Ich sehe für mich eine Zukunft in diesem Betrieb.'),
        ],
        'Laden Sie alle Mitarbeiter ein. Bei kleinen Teams werten Sie nur das Gesamtergebnis aus.',
    ),
    'employee-exit' => array_replace($employeeSurvey(
        'Warum verlassen Mitarbeiter Ihren Betrieb?',
        'Ausscheidende Mitarbeiter sagen oft offener, was nicht gepasst hat. So können Sie verhindern, dass weitere gehen.',
        [
            $choice('Was war der wichtigste Grund für Ihre Entscheidung, uns zu verlassen?', [
                'Bezahlung', 'Arbeitszeiten oder Arbeitsbelastung', 'Entwicklungsmöglichkeiten', 'Führung',
                'Zusammenarbeit im Team', 'Persönliche oder familiäre Gründe', 'Ein besseres Angebot', 'Ein anderer Grund', 'Möchte ich nicht sagen',
            ]),
            $agreement('Unter anderen Umständen würde ich wieder hier arbeiten.'),
            $employeeRecommendation,
        ],
        'Bitten Sie Mitarbeiter in ihren letzten Arbeitstagen um ihre Rückmeldung. Bei wenigen Austritten sammeln Sie die Antworten über einen längeren Zeitraum, damit niemand erkennbar ist.',
    ), ['comment' => 'Was hätten wir tun können, damit Sie bleiben? Bitte nennen Sie keine Namen.']),
];
$catalog['audiences'] += [
    'acquisition' => 'Möglichst neue Kunden, die sich noch erinnern, wie sie auf Sie gekommen sind.',
    'choice-reasons' => 'Aktuelle Kunden, kurz nach einem Kauf oder Auftrag.',
    'customer-profile' => 'Eine möglichst breite Auswahl Ihrer Kunden, nicht nur Stammkunden.',
    'price-perception' => 'Aktuelle Kunden, kurz nach einem Kauf oder Auftrag.',
    'offer-wishes' => 'Aktuelle Kunden, gern auch Stammkunden.',
    'event-satisfaction' => 'Teilnehmende direkt nach der Veranstaltung.',
    'employee-satisfaction' => 'Alle Mitarbeiter Ihres Betriebs.',
    'employee-exit' => 'Mitarbeiter, die Ihren Betrieb verlassen.',
];

$catalog['surveys']['event-satisfaction']['why'] = 'Die Teilnehmenden bewerten mit fünf Sternen, wie zufrieden sie mit der Veranstaltung waren.';

foreach ([
    'store-satisfaction' => 'Es war einfach, vor Ort bei Ihnen einzukaufen.',
    'online-satisfaction' => 'Es war einfach, bei Ihnen online zu bestellen.',
    'service-satisfaction' => 'Es war einfach, bei Ihrem Kundenservice Hilfe zu bekommen.',
    'event-satisfaction' => 'Es war einfach, sich für die Veranstaltung anzumelden.',
] as $id => $effortStatement) {
    $base = $catalog['surveys'][$id];
    $withNps = $base;
    $withNps['title'] .= ' und Weiterempfehlung';
    $withNps['why'] = 'Ihre Kunden bewerten ihr Erlebnis und geben an, ob sie Ihr Unternehmen weiterempfehlen würden.';
    $withNps['questions'][] = $catalog['surveys']['transaction-nps']['questions'][0];
    $effort = $scale($effortStatement, 1, 5, 'Stimme überhaupt nicht zu', 'Stimme voll und ganz zu') + ['metric' => 'ces'];

    $withEffort = $base;
    $withEffort['title'] .= ' und Aufwand';
    $withEffort['why'] = 'Ihre Kunden bewerten ihr Erlebnis und wie leicht ihnen der Vorgang gefallen ist.';
    $withEffort['questions'][] = $effort;

    $withBoth = $withNps;
    $withBoth['title'] .= ' sowie Aufwand';
    $withBoth['why'] = 'Ihre Kunden bewerten ihr Erlebnis, die Weiterempfehlung und wie leicht ihnen der Vorgang gefallen ist.';
    $withBoth['questions'][] = $effort;

    foreach (['-nps' => $withNps, '-ces' => $withEffort, '-nps-ces' => $withBoth] as $suffix => $survey) {
        $catalog['surveys'][$id . $suffix] = $survey;
        $catalog['audiences'][$id . $suffix] = $catalog['audiences'][$id];
    }
}

foreach ([
    'purchase-effort' => 'Wie leicht war es, zu kaufen oder zu buchen?',
    'service-effort' => 'Wie leicht war es, Hilfe vom Kundenservice zu bekommen?',
] as $id => $title) {
    $survey = $catalog['surveys'][$id];
    $survey['title'] = $title;
    $survey['why'] = 'Ihre Kunden geben an, wie leicht ihnen der Vorgang gefallen ist.';
    $survey['questions'] = [$survey['questions'][1]];
    $catalog['surveys'][$id . '-ces'] = $survey;
    $catalog['audiences'][$id . '-ces'] = $catalog['audiences'][$id];
}

foreach ([
    'employee-work-and-team' => ['employee-team', 'employee-workload', 'Wie klappt die Zusammenarbeit und wie gut ist die Arbeit zu schaffen?'],
    'employee-onboarding' => ['employee-orientation', 'employee-training', 'Wie klappt der Einstieg und die Einarbeitung?'],
] as $id => [$first, $second, $title]) {
    $combined = $catalog['surveys'][$first];
    $combined['title'] = $title;
    $combined['why'] = 'Die Befragung verbindet beide Themen für dieselben Mitarbeiter.';
    $combined['questions'][] = $catalog['surveys'][$second]['questions'][0];
    $catalog['surveys'][$id] = $combined;
    $catalog['audiences'][$id] = $catalog['audiences'][$first];
}

return $catalog;
