<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use InvalidArgumentException;
use PDO;

final class InvoiceIssuer
{
    public const FIELDS = [
        'legal_name' => ['label' => 'Firma / vollständiger Name', 'length' => 200],
        'street_address' => ['label' => 'Straße und Hausnummer', 'length' => 200],
        'postal_code' => ['label' => 'Postleitzahl', 'length' => 24],
        'city' => ['label' => 'Ort', 'length' => 120],
        'contact_name' => ['label' => 'Kontaktperson', 'length' => 120],
        'contact_email' => ['label' => 'Kontakt-E-Mail', 'length' => 254, 'type' => 'email'],
        'contact_phone' => ['label' => 'Telefon', 'length' => 60, 'type' => 'tel'],
        'vat_id' => ['label' => 'Umsatzsteuer-ID', 'length' => 20],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function find(): array
    {
        return $this->db->query('SELECT * FROM invoice_issuer WHERE id = 1')->fetch() ?: [];
    }

    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $field => $options) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            $values[$field] = $value;
            if ($value === '' || strlen($value) > $options['length'] || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $errors[$field] = 'Bitte geben Sie ' . $options['label'] . ' gültig an.';
            }
        }
        if (!filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
        }
        if (!preg_match('/\ADE[0-9]{9}\z/', $values['vat_id'])) {
            $errors['vat_id'] = 'Bitte geben Sie DE gefolgt von neun Ziffern an.';
        }
        $rate = $input['vat_rate_basis_points'] ?? '';
        $values['vat_rate_basis_points'] = is_int($rate) || is_string($rate) ? (string) $rate : '';
        if (!in_array($values['vat_rate_basis_points'], ['700', '1900'], true)) {
            $errors['vat_rate_basis_points'] = 'Bitte wählen Sie 19 % oder 7 %.';
        }
        return ['values' => $values, 'errors' => $errors];
    }

    public function save(array $input): void
    {
        $validated = self::validate($input);
        if ($validated['errors'] !== []) {
            throw new InvalidArgumentException('Invalid invoice issuer');
        }
        // Explicitly exclude the automatically allocated invoice-number counter.
        $fields = array_keys($validated['values']);
        $assignments = implode(', ', array_map(static fn (string $field): string => $field . ' = VALUES(' . $field . ')', $fields));
        $write = $this->db->prepare(
            'INSERT INTO invoice_issuer (id, ' . implode(', ', $fields) . ') VALUES (1, '
            . implode(', ', array_fill(0, count($fields), '?')) . ') ON DUPLICATE KEY UPDATE ' . $assignments
        );
        $write->execute(array_values($validated['values']));
    }

    public static function problem(array $seller): ?string
    {
        $errors = self::validate($seller)['errors'];
        if ((int) ($seller['next_test_number'] ?? 0) < 1) {
            $errors['next_test_number'] = 'Die nächste Rechnungsnummer muss mindestens 1 sein.';
        }
        return $errors === [] ? null : 'Bitte prüfen Sie die Angaben unter „Rechnungsaussteller“. ' . implode(' ', $errors);
    }
}
