<?php

declare(strict_types=1);

/*
 * Branchen für den Wizard (zweite Frage nach dem Anliegen).
 * Wirkung: passendere Formulierungen, unpassende Optionen ausblenden, Einladungswege im Teilen-Paket.
 *
 * - phrases: Textbausteine in Fragen an die Befragten ('alt' => 'neu'), gilt auch für Zusatzfragen
 * - owner:   ersetzt „Ihr Unternehmen“ in Texten für den Inhaber
 * - company: ersetzt „unser Unternehmen“ in Fragen an die Befragten (z. B. „unsere Praxis“)
 * - people: ersetzt „Kunden“ in Texten für den Inhaber (nur Wörter mit gleicher Beugung wie „Kunden“)
 * - hide:   Optionen je Wizard-Schritt, die in dieser Branche nicht passen
 * - labels: andere Beschriftung für Optionen ('schritt.option' => 'Text')
 * - visit:  Zufriedenheit „vor Ort“ in der Sprache der Branche (Titel, Frage, Einflussfaktoren, Aufwand, Zielgruppe, Einladung)
 */
$rest = ['Etwas anderes', 'Kann ich nicht sagen'];

return [
    'retail' => [
        'label' => 'Geschäft oder Einzelhandel',
    ],
    'hospitality' => [
        'label' => 'Gastronomie oder Hotel',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'Ihrem letzten Besuch', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'beim nächsten Bedarf wieder zu uns kommen'],
        'company' => 'uns',
        'labels' => ['touchpoint.store' => 'Ein Besuch bei uns (Restaurant, Café oder Hotel)', 'relationship.store' => 'Sie kommen zu uns ins Restaurant, Café oder Hotel'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Besuch',
            'question' => 'Wie zufrieden waren Sie mit Ihrem Besuch bei uns?',
            'aspects' => ['Essen und Getränke', 'Service und Freundlichkeit', 'Wartezeit', 'Atmosphäre', 'Preis-Leistung', ...$rest],
            'effort' => 'Es war unkompliziert, bei Ihnen zu Gast zu sein.',
            'audience' => 'Gäste direkt nach ihrem Besuch.',
            'distribution' => 'Bieten Sie die Befragung direkt beim Gast an, etwa per QR-Code auf dem Tisch, auf der Rechnung oder auf einer Karte.',
            'topic' => 'wie Ihr Besuch bei uns war',
            'headline' => 'Wie war Ihr Besuch?',
            'channels' => ['cards', 'poster', 'tablet'],
        ],
    ],
    'trades' => [
        'label' => 'Handwerk oder Bau',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'Ihrem letzten Auftrag', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'uns beim nächsten Bedarf wieder beauftragen'],
        'hide' => ['relationship' => ['store'], 'touchpoint' => ['online']],
        'labels' => ['touchpoint.store' => 'Ein abgeschlossener Auftrag'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Auftrag',
            'question' => 'Wie zufrieden sind Sie mit der Ausführung Ihres Auftrags?',
            'aspects' => ['Qualität der Arbeit', 'Termintreue', 'Sauberkeit', 'Beratung', 'Preis', ...$rest],
            'effort' => 'Es war einfach, Sie zu beauftragen.',
            'audience' => 'Kunden nach Abschluss ihres Auftrags.',
            'distribution' => 'Geben Sie die Befragung nach Abschluss des Auftrags mit, etwa als Karte mit QR-Code oder als Link mit der Rechnung.',
            'topic' => 'wie zufrieden Sie mit unserer Arbeit sind',
            'headline' => 'Wie zufrieden sind Sie mit unserer Arbeit?',
            'channels' => ['cards', 'email', 'whatsapp'],
        ],
    ],
    'health' => [
        'label' => 'Praxis oder Gesundheit',
        'owner' => 'Ihre Praxis',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'Ihrem heutigen Besuch', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'beim nächsten Bedarf wieder zu uns kommen'],
        'people' => 'Patienten',
        'company' => 'unsere Praxis',
        'hide' => ['touchpoint' => ['online']],
        'labels' => ['touchpoint.store' => 'Ein Termin in der Praxis', 'relationship.store' => 'Sie kommen zu Terminen in die Praxis'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Praxisbesuch',
            'question' => 'Wie zufrieden waren Sie mit Ihrem heutigen Besuch in unserer Praxis?',
            'aspects' => ['Wartezeit', 'Freundlichkeit', 'Zeit für das Gespräch', 'Verständliche Erklärungen', 'Terminvergabe', ...$rest],
            'effort' => 'Es war einfach, einen Termin bei Ihnen zu bekommen.',
            'audience' => 'Patienten direkt nach ihrem Termin in der Praxis.',
            'distribution' => 'Bieten Sie die Befragung direkt nach dem Termin an, etwa per QR-Code am Empfang oder auf einer Karte.',
            'topic' => 'wie Ihr Besuch in unserer Praxis war',
            'headline' => 'Wie war Ihr Besuch heute?',
            'channels' => ['cards', 'poster', 'tablet'],
        ],
    ],
    'beauty' => [
        'label' => 'Friseur, Kosmetik oder Wellness',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'Ihrem heutigen Termin', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'beim nächsten Bedarf wieder zu uns kommen'],
        'company' => 'uns',
        'hide' => ['touchpoint' => ['online']],
        'labels' => ['touchpoint.store' => 'Ein Termin bei uns', 'relationship.store' => 'Sie kommen zu Terminen zu uns'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Termin',
            'question' => 'Wie zufrieden waren Sie mit Ihrem heutigen Termin bei uns?',
            'aspects' => ['Ergebnis', 'Beratung', 'Freundlichkeit', 'Wartezeit', 'Preis', ...$rest],
            'effort' => 'Es war einfach, einen Termin bei Ihnen zu bekommen.',
            'audience' => 'Kunden direkt nach ihrem Termin.',
            'distribution' => 'Bieten Sie die Befragung direkt nach dem Termin an, etwa per QR-Code an der Kasse oder auf einer Karte.',
            'topic' => 'wie Ihr Termin bei uns war',
            'headline' => 'Wie war Ihr Termin heute?',
            'channels' => ['cards', 'poster', 'whatsapp'],
        ],
    ],
    'online' => [
        'label' => 'Onlinehandel',
        'hide' => ['touchpoint' => ['store'], 'relationship' => ['store']],
    ],
    'education' => [
        'label' => 'Kurse, Fitness oder Bildung',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'diesem Kurs', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'wieder einen Kurs bei uns besuchen'],
        'company' => 'uns',
        'labels' => ['touchpoint.store' => 'Ein Kurs, Training oder Seminar', 'relationship.store' => 'Sie besuchen Kurse oder Trainings bei uns'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Kurs',
            'question' => 'Wie zufrieden waren Sie mit dem Kurs?',
            'aspects' => ['Inhalte', 'Kursleitung', 'Organisation', 'Räume und Ausstattung', 'Preis', ...$rest],
            'effort' => 'Es war einfach, sich für den Kurs anzumelden.',
            'audience' => 'Teilnehmende direkt nach dem Kurs oder Training.',
            'distribution' => 'Bieten Sie die Befragung am Ende des Kurses an, etwa per QR-Code im Raum oder als Link in der Abschluss-E-Mail.',
            'topic' => 'wie Ihnen der Kurs gefallen hat',
            'headline' => 'Wie hat Ihnen der Kurs gefallen?',
            'channels' => ['poster', 'email', 'cards'],
        ],
    ],
    'business' => [
        'label' => 'Beratung oder Dienstleistung für Firmen',
        'phrases' => ['Ihrem letzten Einkauf oder Kontakt' => 'diesem Projekt', 'beim nächsten Bedarf wieder bei uns kaufen oder buchen' => 'uns beim nächsten Bedarf wieder beauftragen'],
        'hide' => ['relationship' => ['store'], 'touchpoint' => ['online']],
        'labels' => ['touchpoint.store' => 'Ein abgeschlossenes Projekt oder ein Auftrag'],
        'visit' => [
            'title' => 'Zufriedenheit mit dem Projekt',
            'question' => 'Wie zufrieden waren Sie mit der Zusammenarbeit in diesem Projekt?',
            'aspects' => ['Ergebnis', 'Kommunikation', 'Termintreue', 'Fachliche Kompetenz', 'Preis-Leistung', ...$rest],
            'effort' => 'Die Zusammenarbeit mit Ihnen war unkompliziert.',
            'audience' => 'Kunden nach Abschluss eines Projekts oder Auftrags.',
            'distribution' => 'Senden Sie den Link nach Projektabschluss per E-Mail, zum Beispiel mit der Schlussrechnung.',
            'topic' => 'wie zufrieden Sie mit unserer Zusammenarbeit sind',
            'headline' => 'Wie war die Zusammenarbeit?',
            'channels' => ['email', 'signature'],
        ],
    ],
    'other' => [
        'label' => 'Sonstige Branche',
    ],
];
