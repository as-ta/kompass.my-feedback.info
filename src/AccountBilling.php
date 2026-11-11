<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use PDO;

final class AccountBilling
{
    public const FIELDS = [
        'first_name' => ['label' => 'Vorname', 'length' => 100, 'autocomplete' => 'given-name', 'required' => true],
        'last_name' => ['label' => 'Nachname', 'length' => 100, 'autocomplete' => 'family-name', 'required' => true],
        'company' => ['label' => 'Firma (vollständiger Name)', 'length' => 200, 'autocomplete' => 'organization', 'wide' => true],
        'address_line1' => ['label' => 'Straße und Hausnummer', 'length' => 200, 'autocomplete' => 'billing address-line1', 'required' => true, 'wide' => true],
        'address_line2' => ['label' => 'Adresszusatz', 'length' => 200, 'autocomplete' => 'billing address-line2', 'wide' => true],
        'postal_code' => ['label' => 'Postleitzahl', 'length' => 24, 'autocomplete' => 'billing postal-code', 'required' => true],
        'city' => ['label' => 'Ort', 'length' => 120, 'autocomplete' => 'billing address-level2', 'required' => true],
        'country' => ['label' => 'Land', 'length' => 100, 'autocomplete' => 'billing country-name', 'required' => true, 'wide' => true],
        'phone' => ['label' => 'Telefon', 'length' => 60, 'autocomplete' => 'tel', 'type' => 'tel'],
        'vat_id' => ['label' => 'Umsatzsteuer-ID (USt-IdNr.)', 'length' => 40],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function hasDetails(int $user): bool
    {
        $read = $this->db->prepare('SELECT 1 FROM account_billing WHERE account_id = ?');
        $read->execute([$user]);
        return $read->fetchColumn() !== false;
    }

    public function find(int $user): array
    {
        $read = $this->db->prepare(
            'SELECT u.email, b.* FROM account_user u LEFT JOIN account_billing b ON b.account_id = u.id WHERE u.id = ?'
        );
        $read->execute([$user]);
        return $read->fetch() ?: [];
    }

    public static function validate(array $post): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $name => $field) {
            $raw = $post[$name] ?? '';
            $validText = is_string($raw) && mb_check_encoding($raw, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $raw);
            $value = $validText ? preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $raw) : '';
            if (!$validText) {
                $errors[$name] = 'Bitte geben Sie einen gültigen Text ein.';
            } elseif ($value === '' && !empty($field['required'])) {
                $errors[$name] = 'Bitte füllen Sie dieses Feld aus.';
            } elseif (mb_strlen($value, 'UTF-8') > $field['length']) {
                $errors[$name] = 'Bitte verwenden Sie höchstens ' . $field['length'] . ' Zeichen.';
            }
            $values[$name] = $value;
        }
        return [$values, $errors];
    }

    public function save(int $user, array $values): void
    {
        $write = $this->db->prepare(
            'INSERT INTO account_billing
                (account_id, first_name, last_name, company, address_line1, address_line2, postal_code, city, country, phone, vat_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                first_name = VALUES(first_name), last_name = VALUES(last_name), company = VALUES(company),
                address_line1 = VALUES(address_line1), address_line2 = VALUES(address_line2),
                postal_code = VALUES(postal_code), city = VALUES(city), country = VALUES(country),
                phone = VALUES(phone), vat_id = VALUES(vat_id)'
        );
        $parameters = [$user];
        foreach (self::FIELDS as $name => $field) {
            $parameters[] = $values[$name] === '' ? null : $values[$name];
        }
        $write->execute($parameters);
    }
}
