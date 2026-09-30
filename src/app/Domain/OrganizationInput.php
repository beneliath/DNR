<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class OrganizationInput
{
    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, bool|string>, errors: list<string>, error_fields: list<string|null>}
     */
    public static function normalize(array $input): array
    {
        $fields = [
            'organization_name', 'notes', 'affiliation', 'distinctives', 'website_url',
            'phone', 'fax', 'mailing_address_line_1', 'mailing_address_line_2',
            'mailing_city', 'mailing_state', 'mailing_zipcode', 'mailing_country',
            'physical_address_line_1', 'physical_address_line_2', 'physical_city',
            'physical_state', 'physical_zipcode', 'physical_country',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = InputText::value($input, $field);
        }
        $data['same_address'] = ($input['same_address'] ?? 'no') === 'yes';
        $data['phone_country_code'] = is_scalar($input['phone_country_code'] ?? null)
            ? trim((string) $input['phone_country_code'])
            : \applicationDefaultPhoneCountryCode();
        $data['fax_country_code'] = is_scalar($input['fax_country_code'] ?? null)
            ? trim((string) $input['fax_country_code'])
            : \applicationDefaultPhoneCountryCode();

        $errors = [];
        $error_fields = [];
        $add_error = static function (?string $field, string $message) use (&$errors, &$error_fields): void {
            if (in_array($message, $errors, true)) return;
            $errors[] = $message;
            $error_fields[] = $field;
        };
        if ($data['physical_country'] !== '') {
            $physical_country = \normalizeAddressCountryCode($data['physical_country']);
            if ($physical_country === null) {
                $add_error('physical_country', 'Select a valid physical country.');
            } else {
                $data['physical_country'] = $physical_country;
            }
        }
        if ($data['physical_state'] !== '') {
            $physical_state = \normalizeAddressRegion($data['physical_country'], $data['physical_state']);
            if ($physical_state === null) {
                $add_error('physical_state', $data['physical_country'] === 'CA'
                    ? 'Select a valid physical province.'
                    : 'Select a valid physical state.');
            } else {
                $data['physical_state'] = $physical_state;
            }
        }
        if ($data['same_address']) {
            foreach (['address_line_1', 'address_line_2', 'city', 'state', 'zipcode', 'country'] as $part) {
                $data['mailing_' . $part] = $data['physical_' . $part];
            }
        } elseif ($data['mailing_country'] !== '') {
            $mailing_country = \normalizeAddressCountryCode($data['mailing_country']);
            if ($mailing_country === null) {
                $add_error('mailing_country', 'Select a valid mailing country.');
            } else {
                $data['mailing_country'] = $mailing_country;
            }
        }
        if (!$data['same_address'] && $data['mailing_state'] !== '') {
            $mailing_state = \normalizeAddressRegion($data['mailing_country'], $data['mailing_state']);
            if ($mailing_state === null) {
                $add_error('mailing_state', $data['mailing_country'] === 'CA'
                    ? 'Select a valid mailing province.'
                    : 'Select a valid mailing state.');
            } else {
                $data['mailing_state'] = $mailing_state;
            }
        }

        if ($data['organization_name'] === '') {
            $add_error('organization_name', 'Organization name is required.');
        }
        foreach ([
            'organization_name' => [255, 'Organization Name'],
            'affiliation' => [255, 'Affiliation'],
            'distinctives' => [255, 'Distinctives'],
            'website_url' => [255, 'Website URL'],
            'mailing_address_line_1' => [255, 'Mailing address line 1'],
            'mailing_address_line_2' => [255, 'Mailing address line 2'],
            'mailing_city' => [100, 'Mailing city'],
            'mailing_state' => [100, 'Mailing state'],
            'mailing_zipcode' => [20, 'Mailing postal code'],
            'mailing_country' => [100, 'Mailing country'],
            'physical_address_line_1' => [255, 'Physical address line 1'],
            'physical_address_line_2' => [255, 'Physical address line 2'],
            'physical_city' => [100, 'Physical city'],
            'physical_state' => [100, 'Physical state'],
            'physical_zipcode' => [20, 'Physical postal code'],
            'physical_country' => [100, 'Physical country'],
        ] as $field => [$maximum, $label]) {
            $length_error = InputText::lengthError((string) $data[$field], $maximum, $label);
            if ($length_error !== null) {
                $add_error($field, $length_error);
            }
        }
        $notes_error = InputText::textStorageError((string) $data['notes'], 'Organization notes');
        if ($notes_error !== null) {
            $add_error('notes', $notes_error);
        }
        $normalized_url = \normalizedHttpUrl($data['website_url']);
        if ($normalized_url === null) {
            $add_error('website_url', 'Please provide a valid website URL.');
        } else {
            $data['website_url'] = $normalized_url;
        }
        // Relationship intake may precede a venue or mailing address. Validate supplied parts only.
        foreach ([['phone', 'phone_country_code', 'Organization phone'], ['fax', 'fax_country_code', 'Organization fax']] as [$field, $country, $label]) {
            try {
                $data[$field] = \normalizePhoneNumber($data[$country], $data[$field], $label);
            } catch (\InvalidArgumentException $exception) {
                $add_error($field, $exception->getMessage());
            }
        }

        return ['data' => $data, 'errors' => $errors, 'error_fields' => $error_fields];
    }
}
