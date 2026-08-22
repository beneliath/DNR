<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class OrganizationInput
{
    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, bool|string>, errors: list<string>}
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
            $data[$field] = is_scalar($input[$field] ?? null)
                ? trim((string) $input[$field])
                : '';
        }
        $data['same_address'] = ($input['same_address'] ?? 'no') === 'yes';
        $data['phone_country_code'] = is_scalar($input['phone_country_code'] ?? null)
            ? trim((string) $input['phone_country_code'])
            : '+1';
        $data['fax_country_code'] = is_scalar($input['fax_country_code'] ?? null)
            ? trim((string) $input['fax_country_code'])
            : '+1';

        if ($data['same_address']) {
            foreach (['address_line_1', 'address_line_2', 'city', 'state', 'zipcode', 'country'] as $part) {
                $data['mailing_' . $part] = $data['physical_' . $part];
            }
        }

        $errors = [];
        if ($data['organization_name'] === '') {
            $errors[] = 'Organization name is required.';
        }
        $normalized_url = \normalizedHttpUrl($data['website_url']);
        if ($normalized_url === null) {
            $errors[] = 'Please provide a valid website URL.';
        } else {
            $data['website_url'] = $normalized_url;
        }
        foreach (['address_line_1', 'city', 'state', 'zipcode', 'country'] as $part) {
            if ($data['physical_' . $part] === '') {
                $errors[] = 'A complete physical address is required.';
                break;
            }
        }
        if (!$data['same_address']) {
            foreach (['address_line_1', 'city', 'state', 'zipcode', 'country'] as $part) {
                if ($data['mailing_' . $part] === '') {
                    $errors[] = 'A complete mailing address is required when it differs from the physical address.';
                    break;
                }
            }
        }
        foreach ([['phone', 'phone_country_code', 'Organization phone'], ['fax', 'fax_country_code', 'Organization fax']] as [$field, $country, $label]) {
            try {
                $data[$field] = \normalizePhoneNumber($data[$country], $data[$field], $label);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return ['data' => $data, 'errors' => array_values(array_unique($errors))];
    }
}
