# myFeedback Kompass

Eigenständige Laminas-MVC-Anwendung für den ersten Teil des Befragungsassistenten. Der Wizard führt je nach Anliegen durch die passenden Rückfragen. Bis zu drei Kundenthemen lassen sich mit einem Hauptanliegen verbinden. Danach folgen freiwillige Zusatzfragen und die Fragebogenvorschau mit einer separaten Anleitung zur Durchführung.

## Lokal starten

Voraussetzungen sind PHP 8.4 und Composer.

```sh
composer install
composer serve
```

Öffnen Sie [den Befragungsassistenten](http://127.0.0.1:8080).

## Auf der Subdomain testen

1. Für `studio.my-feedback.info` PHP 8.4 und HTTPS aktivieren. Die Composer-Abhängigkeiten benötigen die PHP-Erweiterungen `ctype`, `dom`, `filter`, `json`, `mbstring` und `tokenizer`.
2. Das Server-ZIP in ein eigenes Projektverzeichnis entpacken. Der Document Root der Subdomain muss auf dessen Unterordner `public/` zeigen. So bleiben `config/`, `src/`, `view/` und `vendor/` außerhalb des öffentlich erreichbaren Verzeichnisses.
3. Den Wizard unter `https://studio.my-feedback.info/` aufrufen. Für einen ersten Test je einen Pfad für Kundenverlust und Zufriedenheit bis zur Vorschau durchspielen.

Das vollständige Server-ZIP enthält `vendor/`. Beim Upload aus dem Git-Repository wird dieser Ordner vorab mit `composer install --no-dev --optimize-autoloader` erstellt. Für die Vorschau ist keine Datenbankverbindung erforderlich.

## Kontaktformular

Empfänger und Absender sind standardmäßig `info@my-feedback.info`. Sie lassen sich bei Bedarf über `STUDIO_CONTACT_TO` und `STUDIO_CONTACT_FROM` oder in `config/contact.php` ändern. Bei Host Europe kann unter Skripte & Datenbanken → Skript-Einstellungen eine gültige Standard-E-Mail-Adresse für den Skriptversand gesetzt werden. Die Antwortadresse des Absenders der Anfrage wird als Reply-To übergeben.

## Konto und gespeicherte Entwürfe

1. Eine eigene, leere MySQL-Datenbank für den Kompass anlegen und darin `database/001_accounts_and_drafts.sql` importieren.
2. Prüfen, ob für PHP 8.4 die Erweiterung `pdo_mysql` aktiv ist.
3. `config/account.local.php.example` nach `config/account.local.php` kopieren und Datenbankhost, Datenbankname, Benutzer, Passwort und die öffentliche HTTPS-Adresse eintragen. Die Absenderadresse muss beim Hosting für den Skriptversand zugelassen sein. Die Konfiguration liegt außerhalb von `public/`.
4. `database/003_account_billing.sql` in die Kompass-Datenbank importieren. Bei einem Update einer bestehenden Installation diesen Import vor dem Hochladen der neuen PHP-Dateien ausführen.
5. In der Vorschau **Fragebogen übernehmen** wählen und mit einer Test-E-Mail-Adresse anmelden. Den einmaligen Link innerhalb von 20 Minuten im selben Browser öffnen und bestätigen. Danach öffnet sich direkt die gespeicherte Befragung. Dieselbe E-Mail-Adresse führt bei späteren Anmeldungen wieder zum selben Konto.

Rechnungsdaten werden erst abgefragt, wenn der Kunde eine Zahlung startet. Danach geht es direkt weiter zu PayPal. Über **Rechnungsdaten** lassen sich die Angaben später ändern. Sie werden pro Konto in `account_billing` gespeichert. Die Rechnungserstellung folgt separat.

Alternativ können die Werte über `KOMPASS_DB_DSN`, `KOMPASS_DB_USER`, `KOMPASS_DB_PASSWORD`, `KOMPASS_PUBLIC_URL` und `KOMPASS_ACCOUNT_FROM` gesetzt werden. Die lokale Konfigurationsdatei hat Vorrang. Bis zur Konfiguration läuft der Wizard im Vorschaumodus.

Der Entwurf enthält die konkrete Fassung aus der Vorschau. Die Tabellen `poll`, `poll_version`, `question` und `question_option` trennen Befragung, Fassung, Fragen und Antwortoptionen. Der Wizard-Katalog erzeugt neue Entwürfe. Bereits gespeicherte Fassungen lassen sich im Konto erneut ansehen. Die Anwendung bietet dafür keinen Bearbeitungsmodus.

## PayPal-Testzahlung pro Befragung

1. In der bestehenden Kompass-Datenbank `database/002_poll_paypal_sandbox.sql` importieren.
2. Für PHP 8.4 die Erweiterung `curl` aktivieren.
3. Im [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/sandbox) eine Sandbox-App auswählen oder anlegen.
4. `config/paypal.local.php.example` nach `config/paypal.local.php` kopieren. Sandbox Client ID, Sandbox Secret und einen Testbetrag in EUR mit zwei Nachkommastellen eintragen, zum Beispiel `1.00`. Alternativ `KOMPASS_PAYPAL_CLIENT_ID`, `KOMPASS_PAYPAL_SECRET` und `KOMPASS_PAYPAL_AMOUNT` setzen. Die lokale Datei hat Vorrang und liegt außerhalb von `public/`.
5. `database/004_poll_lifecycle.sql` nach 002 und 003 importieren. Bei einem Update den Import vor dem Upload der PHP-Dateien ausführen. Die Migration übernimmt frühere Sandbox-Zahlungen als ersten Zeitraum.
6. Im Konto einen Entwurf öffnen und über die PayPal-Buttons bezahlen. PayPal öffnet sich in einem eigenen Fenster (PayPal JavaScript SDK, geladen von www.paypal.com mit der Sandbox Client ID). Nach der Freigabe im Sandbox-Käuferkonto bucht Kompass die Zahlung ab und lädt die Detailseite neu. Lädt das PayPal-Skript nicht, bleibt der bisherige Button mit Weiterleitung zu PayPal erhalten; die Prüfung startet dann nach der Rückkehr automatisch. Sobald PayPal den Abschluss bestätigt, zeigt Kompass **Bereit zum Start** und stellt bei vollständigen Rechnungsdaten die ZUGFeRD-Testrechnung bereit.

**Befragung für 4 Wochen starten** öffnet den Teilnahmelink für 28 Tage. Nach Ablauf kann ein neuer Zeitraum bezahlt und gestartet werden. Der Teilnahmelink bleibt gleich. Die Laufzeiten und Sandbox-Zahlungen stehen in `poll_period`, die Links in `poll_public` und die eingegangenen Antworten in `poll_response`.

Die Integration verwendet die PayPal-Sandbox. Die Testrechnungen dokumentieren Zahlungen mit Spielgeld.

## ZUGFeRD im Sandbox-Test

1. Falls noch nicht geschehen, `database/005_sandbox_invoice.sql` importieren. Das ZUGFeRD-Update benötigt keine neue Migration. Beim FTP-Upload auch die mitgelieferten Dateien im Ordner `vendor/` übernehmen.
2. In phpMyAdmin die Zeile mit `id = 1` in `invoice_issuer` bearbeiten. Eintragen: vollständiger Firmenname, Straße und Hausnummer, PLZ, Ort, Kontaktname, Kontakt-E-Mail, Telefon, deutsche USt-IdNr. und Steuersatz in Basispunkten (`1900` steht für 19 Prozent). `next_test_number` verwaltet Kompass.
3. Eine neue Sandbox-Zahlung mit einer deutschen Rechnungsanschrift abschließen. Unter **Testrechnungen** erscheint die **ZUGFeRD-Testrechnung (PDF)**. Der Download kombiniert die sichtbare Rechnung mit dem eingebetteten Rechnungs-XML. Auch vorhandene Test-XRechnungen lassen sich so herunterladen.

Kompass speichert die XML-Datei bei der Zahlungsbestätigung in `sandbox_invoice` und verwendet für jede neue Test-XRechnung eine Nummer aus dem eigenen Testnummernkreis. Die gespeicherte XML enthält die Daten zum Zeitpunkt der Ausstellung. Der PDF-Download wird daraus erzeugt. Die Composer-Abhängigkeit TCPDF 6.11.3 liefert den PDF/A-3-Container. Unterstützt wird der Sandbox-Test für inländische, regulär besteuerte Vorauszahlungen. Live-Rechnung, weitere Steuerfälle und etwaige Schlussrechnungen müssen vor Echtbetrieb separat festgelegt werden.

## Entscheidungsbaum und Katalog

In [config/catalog.php](config/catalog.php) stehen die Schritte und Befragungsvorlagen. Jede Antwort verweist entweder auf eine Folgefrage oder auf eine Vorlage. Der Katalog deckt Kundenverlust, Zufriedenheit, Weiterempfehlung, die Kombination aus NPS und CES, Produktanalyse und Mitarbeiterbefragung ab. Die Zusatzfragen stehen in `config/addons.php`. Der Einstieg über das Anliegen führt direkt zu Fragen, deren Antworten die Empfehlung verändern. Die Vorlagen liegen bis zur Anbindung einer eigenen Datenbank im PHP-Katalog.

Antworten werden über den Teilnahmelink während eines aktiven Zeitraums gespeichert. Die Vorschau dient zum Ausprobieren. Live-Zahlung, steuerliche Rechnungen und Ergebnisansicht folgen separat.

## Teilnahme auf mehreren Seiten

1. `database/006_response_progress.sql` in die Kompass-Datenbank importieren, **bevor** die neuen PHP-Dateien hochgeladen werden. Die Migration ergänzt `poll_response` um `started_at`, `updated_at` und `completed_at` und markiert bisherige Antworten als vollständig.
2. Befragte sehen eine Frage pro Seite. Nach jeder Seite wird der Stand gespeichert, mit der letzten Seite gilt die Teilnahme als vollständig. Als abgebrochen gilt eine Teilnahme nach 24 Stunden ohne Aktivität; ihre bereits beantworteten Fragen fließen in die Auswertung ein.
3. Ist der Inhaber im selben Browser angemeldet, öffnet der Teilnahmelink eine Testansicht. Dort wird nichts gespeichert.

## Erscheinungsbild (Logo und Farben)

1. `database/007_account_branding.sql` importieren, bevor die neuen PHP-Dateien hochgeladen werden.
2. Der Ordner `data/logos/` muss existieren und für PHP beschreibbar sein. Er liegt bewusst außerhalb von `public/`; Logos werden über `/?logo=<datei>` ausgeliefert. SVG-Dateien werden beim Hochladen von Skripten und externen Verweisen bereinigt.
3. Im Konto unter **Erscheinungsbild** Logo, Hauptfarbe und optional Akzentfarbe festlegen. Ohne Angaben sehen Befragte das myFeedback-Layout, ohne Logo den Firmennamen aus den Rechnungsdaten.
4. Die Teilnahmeseite hat eine eigene, schlanke Kopfzeile ohne Kompass-Navigation und im Fuß einen Link „Datenschutz“ (Standard: https://www.my-feedback.info/de/datenschutz – bitte prüfen).

## Verlängern

Solange eine Befragung läuft, kann sie auf der Detailseite um 4 Wochen verlängert werden. Der neue Zeitraum schließt nahtlos an das bisherige Ende an (`PollLifecycle::chain()`), Link und QR-Code bleiben gleich, jede Verlängerung erhält eine eigene Rechnung. Nach Ablauf wird wie bisher gebucht und anschließend gestartet. Antworten werden immer dem gerade laufenden Zeitraum zugeordnet. Keine Datenbank-Änderung nötig.


## Abo (PayPal Subscriptions)

Eine laufende oder beendete Befragung kann statt einzelner 4-Wochen-Buchungen dauerhaft laufen: 19,00 € im Monat (`KOMPASS_PAYPAL_SUBSCRIPTION_AMOUNT`, Standard `19.00`).

- Der Kunde gibt das Abo einmal im PayPal-Fenster frei, danach bucht PayPal monatlich ab.
- Läuft die Befragung noch, beginnt das Abo (und die erste Abbuchung) erst am Ende des bezahlten Zeitraums.
- Jede bestätigte Abbuchung wird ein eigener Zeitraum in `poll_period` (`subscription_id` gesetzt) mit Rechnung.
- Kündigung im Kompass-Konto (Detailseite → „Abo kündigen“) oder direkt bei PayPal; bezahlte Zeiträume laufen zu Ende.
- Produkt und Plan legt der Kompass beim ersten Bedarf selbst bei PayPal an (Tabelle `paypal_plan`). Bei einer Preisänderung entsteht ein neuer Plan; bestehende Abos behalten ihren Preis.

Einrichtung:

1. `database/008_poll_subscription.sql` einspielen (vor dem Hochladen der PHP-Dateien).
2. Webhook bei PayPal anlegen: developer.paypal.com → Apps & Credentials → (Sandbox bzw. später Live) → die Kompass-App öffnen → ganz unten „Webhooks“ → „Add Webhook“.
   - URL: `https://kompass.my-feedback.info/?paypal-webhook`
   - Events: `BILLING.SUBSCRIPTION.ACTIVATED`, `BILLING.SUBSCRIPTION.CANCELLED`, `BILLING.SUBSCRIPTION.SUSPENDED`, `BILLING.SUBSCRIPTION.EXPIRED`, `BILLING.SUBSCRIPTION.UPDATED`, `PAYMENT.SALE.COMPLETED`
3. Der Webhook liefert nur den Anlass: Der Kompass fragt den Stand danach immer selbst bei PayPal ab. Fehlt ein Webhook, gleicht er beim Aufruf der Detailseite oder des Teilnahmelinks nach (spätestens alle 10 Minuten).

## Teilen-Paket und Tablet-Modus

Auf der Detailseite (bereit/aktiv) zeigt „So erreichen Sie Ihre Befragten“ je Einladungsweg Tipps und vorbefüllte Texte (src/ShareKit.php, Empfehlungen je Befragungsart).

Tablet-Modus: Teilnahmelink mit `&geraet=tablet`. Nach dem „Danke“ startet die Befragung nach 8 Sekunden neu; nach 90 Sekunden ohne Eingabe fragt sie „Sind Sie noch da?“ und beginnt nach weiteren 20 Sekunden neu (`&neu=1` verwirft die angefangene Teilnahme in der Sitzung, bereits beantwortete Fragen bleiben als abgebrochene Teilnahme erhalten). Im Tablet-Modus gibt es keine Testansicht: Antworten werden auch gespeichert, wenn der Inhaber angemeldet ist.

Druckvorlagen: `?account=print&id=<Befragung>&vorlage=aushang|aufsteller|kaertchen|tablet` (nur für den angemeldeten Inhaber). Eigene Seite ohne Kompass-Rahmen (view/print.phtml, public/print.css, public/print.js) mit Logo, Hauptfarbe und QR-Code; Überschrift und Text sind vor dem Drucken änderbar. Gedruckt bzw. als PDF gespeichert wird über den Druckdialog des Browsers (A4, ohne Ränder).
