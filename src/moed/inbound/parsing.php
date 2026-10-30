<?php

declare(strict_types=1);

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/** @return list<string> */
function inboundEmailAcceptedMarkerPrefixes(): array
{
    return array_map(
        static fn(mixed $prefix): string => (string) $prefix,
        deploymentConfig()->list('inbound_email.accepted_marker_prefixes')
    );
}

function inboundEmailMarkerPrefixPattern(): string
{
    return implode('|', array_map(
        static fn(string $prefix): string => preg_quote($prefix, '/'),
        inboundEmailAcceptedMarkerPrefixes()
    ));
}

function inboundEmailGatewayAddress(): string
{
    $configured = getenv('DNR_INBOUND_ADDRESS');
    $address = strtolower(trim(is_string($configured) ? $configured : ''));
    if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('DNR_INBOUND_ADDRESS must be a valid email address.');
    }
    return $address;
}

function inboundEmailMaximumRawBytes(): int
{
    $configured = (int) (getenv('DNR_INBOUND_MAX_BYTES') ?: INBOUND_EMAIL_MAX_RAW_BYTES);
    return max(65536, min(16777215, $configured));
}

function normalizeInboundEmailAddress(mixed $address): string
{
    if (!is_scalar($address)) {
        return '';
    }
    $address = strtolower(trim((string) $address));
    return strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL)
        ? $address
        : '';
}

/** @return list<array{address: string, name: string}> */
function inboundEmailHeaderAddresses(IMessage $message, string $headerName): array
{
    $header = $message->getHeaderAs($headerName, AddressHeader::class);
    if (!$header instanceof AddressHeader) {
        return [];
    }

    $addresses = [];
    foreach ($header->getAddresses() as $addressPart) {
        $address = normalizeInboundEmailAddress($addressPart->getEmail());
        if ($address === '') {
            continue;
        }
        if (count($addresses) >= INBOUND_EMAIL_MAX_PARTICIPANTS && !isset($addresses[$address])) {
            throw new InvalidArgumentException('The inbound message has too many participants.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $addressPart->getName()) ?? '');
        $addresses[$address] = [
            'address' => $address,
            'name' => mb_substr($name, 0, 255, 'UTF-8'),
        ];
    }
    return array_values($addresses);
}

/** Convert email HTML to inert text while retaining absolute web link destinations. */
function inboundEmailHtmlToPlainText(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    $document = new DOMDocument();
    if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return '';
    }
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//head | //script | //style | //template | //iframe | //object') ?: [] as $node) {
        $node->parentNode?->removeChild($node);
    }
    foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }
        $url = trim($anchor->getAttribute('href'));
        if (!preg_match('~\Ahttps?://[^\s<>"\x00-\x20\x7F]+\z~i', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $label = trim($anchor->textContent);
        // Separate destinations from neighboring inline text so autolinking
        // cannot join adjacent anchors into a different URL.
        if ($label === '' || $label === $url) {
            $anchor->textContent = ' ' . $url . ' ';
        } else {
            $anchor->appendChild($document->createTextNode(' (' . $url . ') '));
        }
    }
    $html = $document->saveHTML() ?: '';
    $html = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $html) ?? $html;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function inboundEmailPlainText(IMessage $message): string
{
    // The plain-text alternative can contain only a link's label (e.g. HERE).
    // Prefer inert HTML-derived text so the destination survives MIME import.
    $html = $message->getHtmlContent();
    $text = is_string($html) ? inboundEmailHtmlToPlainText($html) : '';
    if ($text === '') {
        $text = $message->getTextContent();
    }
    $text = is_string($text) ? $text : '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
    return trim($text);
}

function inboundEmailUtcDate(mixed $value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * @return array{
 *   rfc_message_id: string, sender_name: string, sender_address: string,
 *   to_addresses: list<string>, cc_addresses: list<string>, subject: string,
 *   sent_at: ?string, received_at: string, body_text: string,
 *   attachment_names: list<string>, raw_headers: string, deduplication_hash: string,
 *   _inbox_attachments: list<array{filename: string, data_base64: string}>
 * }
 */
function parseInboundEmail(string $rawMessage, ?string $receivedAt = null): array
{
    $rawLength = strlen($rawMessage);
    if ($rawLength < 1) {
        throw new InvalidArgumentException('The inbound message is empty.');
    }
    if ($rawLength > inboundEmailMaximumRawBytes()) {
        throw new InvalidArgumentException('The inbound message exceeds the configured size limit.');
    }

    $headerEnd = preg_match('/\r?\n\r?\n/', $rawMessage, $headerMatch, PREG_OFFSET_CAPTURE)
        ? $headerMatch[0][1] : $rawLength;
    if ($headerEnd > INBOUND_EMAIL_MAX_HEADER_BYTES) {
        throw new InvalidArgumentException('The inbound message headers exceed the size limit.');
    }
    $rawHeaders = substr($rawMessage, 0, $headerEnd);
    if (strlen(mb_decode_mimeheader($rawHeaders)) > INBOUND_EMAIL_MAX_HEADER_BYTES) {
        throw new InvalidArgumentException('The decoded inbound headers exceed the size limit.');
    }
    $message = Message::from($rawMessage, false);
    $from = inboundEmailHeaderAddresses($message, HeaderConsts::FROM);
    if (count($from) !== 1) {
        throw new InvalidArgumentException('The inbound message must contain exactly one valid From address.');
    }
    $to = array_column(inboundEmailHeaderAddresses($message, HeaderConsts::TO), 'address');
    $cc = array_column(inboundEmailHeaderAddresses($message, HeaderConsts::CC), 'address');
    if (count(array_unique(array_merge([$from[0]['address']], $to, $cc))) > INBOUND_EMAIL_MAX_PARTICIPANTS) {
        throw new InvalidArgumentException('The inbound message has too many participants.');
    }
    $subject = trim((string) ($message->getSubject() ?? ''));
    $subject = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject) ?? $subject;
    $messageId = trim((string) ($message->getHeaderValue(HeaderConsts::MESSAGE_ID) ?? ''));
    $messageId = trim($messageId, "<> \t\r\n");
    $messageId = mb_substr($messageId, 0, 998, 'UTF-8');
    $dateHeader = $message->getHeaderValue(HeaderConsts::DATE);

    $attachmentNames = [];
    $inboxParts = []; $inboxBytes = 0;
    $inboxMessage = preg_match('/\+mm\./i', implode(' ', array_merge($to, $cc)))
        || preg_match('/\[MOED@[^\]]{1,100}#M/', $subject);
    foreach ($message->getAllAttachmentParts() as $attachment) {
        $filename = trim((string) ($attachment->getFilename() ?? 'unnamed attachment'));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $filename) ?? $filename;
        $filename = mb_substr($filename, 0, 255, 'UTF-8');
        if ($filename !== '') {
            $attachmentNames[$filename] = true;
        }
        if ($inboxMessage && count($inboxParts) < 10 && preg_match('/\.(pdf|jpe?g|png|gif)\z/i', $filename)) {
            $stream = $attachment->getContentStream();
            $bytes = $stream ? $stream->read(5 * 1024 * 1024 + 1) : '';
            if ($bytes !== '' && strlen($bytes) <= 5 * 1024 * 1024 && $inboxBytes + strlen($bytes) <= 6 * 1024 * 1024) {
                $inboxParts[] = ['filename'=>mb_substr($filename,0,180),'data_base64'=>base64_encode($bytes)];
                $inboxBytes += strlen($bytes);
            }
        }
        if (count($attachmentNames) >= 100) {
            break;
        }
    }

    $receivedAt = inboundEmailUtcDate($receivedAt ?? gmdate('c')) ?? gmdate('Y-m-d H:i:s');
    $deduplicationSource = $messageId !== ''
        ? 'message-id:' . strtolower($messageId)
        : 'raw-message:' . $rawMessage;

    return [
        'rfc_message_id' => $messageId,
        'sender_name' => (string) $from[0]['name'],
        'sender_address' => (string) $from[0]['address'],
        'to_addresses' => array_values(array_unique($to)),
        'cc_addresses' => array_values(array_unique($cc)),
        'subject' => mb_substr($subject, 0, 998, 'UTF-8'),
        'sent_at' => inboundEmailUtcDate($dateHeader),
        'received_at' => $receivedAt,
        'body_text' => inboundEmailPlainText($message),
        'attachment_names' => array_keys($attachmentNames),
        '_inbox_attachments' => $inboxParts,
        'raw_headers' => $rawHeaders,
        'deduplication_hash' => hash('sha256', $deduplicationSource, true),
    ];
}

/** @return list<string> */
function inboundEmailDecodeAddressList(mixed $json): array
{
    $decoded = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $addresses = [];
    foreach ($decoded as $address) {
        $address = normalizeInboundEmailAddress($address);
        if ($address !== '') {
            $addresses[$address] = true;
        }
    }
    return array_keys($addresses);
}

/** @return list<string> */
function inboundEmailDecodeStringList(mixed $json): array
{
    $decoded = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $strings = [];
    foreach ($decoded as $value) {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $strings[] = trim((string) $value);
        }
    }
    return array_values(array_unique($strings));
}
