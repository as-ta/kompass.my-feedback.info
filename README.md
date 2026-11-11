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
5. In der Vorschau **Fragebogen übernehmen** wählen und mit einer Test-E-Mail-Adresse anmelden. Den einmaligen Link innerhalb von 20 Minuten im selben Browser öffnen und bestätigen. Beim ersten Mal die Rechnungsdaten ergänzen. Danach öffnet sich **Meine Befragungen** und markiert den gerade gespeicherten Entwurf. Dieselbe E-Mail-Adresse führt bei späteren Anmeldungen wieder zum selben Konto.

Bestehende Konten ohne Rechnungsdaten ergänzen die Angaben beim nächsten Aufruf des Kontobereichs. Über **Rechnungsdaten** lassen sich die Angaben später ändern. Sie werden pro Konto in `account_billing` gespeichert. Die Rechnungserstellung folgt separat.

Alternativ können die Werte über `KOMPASS_DB_DSN`, `KOMPASS_DB_USER`, `KOMPASS_DB_PASSWORD`, `KOMPASS_PUBLIC_URL` und `KOMPASS_ACCOUNT_FROM` gesetzt werden. Die lokale Konfigurationsdatei hat Vorrang. Bis zur Konfiguration läuft der Wizard im Vorschaumodus.

Der Entwurf enthält die konkrete Fassung aus der Vorschau. Die Tabellen `poll`, `poll_version`, `question` und `question_option` trennen Befragung, Fassung, Fragen und Antwortoptionen. Der Wizard-Katalog erzeugt neue Entwürfe. Bereits gespeicherte Fassungen lassen sich im Konto erneut ansehen. Die Anwendung bietet dafür keinen Bearbeitungsmodus.

## PayPal-Testzahlung pro Befragung

1. In der bestehenden Kompass-Datenbank `database/002_poll_paypal_sandbox.sql` importieren.
2. Für PHP 8.4 die Erweiterung `curl` aktivieren.
3. Im [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/sandbox) eine Sandbox-App auswählen oder anlegen.
4. `config/paypal.local.php.example` nach `config/paypal.local.php` kopieren. Sandbox Client ID, Sandbox Secret und einen Testbetrag in EUR mit zwei Nachkommastellen eintragen, zum Beispiel `1.00`. Alternativ `KOMPASS_PAYPAL_CLIENT_ID`, `KOMPASS_PAYPAL_SECRET` und `KOMPASS_PAYPAL_AMOUNT` setzen. Die lokale Datei hat Vorrang und liegt außerhalb von `public/`.
5. `database/004_poll_lifecycle.sql` nach 002 und 003 importieren. Bei einem Update den Import vor dem Upload der PHP-Dateien ausführen. Die Migration übernimmt frühere Sandbox-Zahlungen als ersten Zeitraum.
6. Im Konto einen Entwurf öffnen, **Mit PayPal testen** wählen und im Sandbox-Käuferkonto bestätigen. Die Prüfung startet nach der Rückkehr automatisch. Sobald PayPal den Abschluss bestätigt, zeigt Kompass **Bereit zum Start** und stellt bei vollständigen Rechnungsdaten die ZUGFeRD-Testrechnung bereit.

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
