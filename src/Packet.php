<?php

declare(strict_types=1);

namespace Whisp;

use Whisp\Enums\MessageType;

class Packet
{
    public MessageType $type;

    public string $message;

    public int $offset = 0;

    public function __construct(
        public string $payload,
    ) {
        // An SSH package payload starts with the message type (MessageType), followed by the message
        $this->type = MessageType::from(ord($payload[0]));
        $this->message = substr($payload, 1); // remove the message type
    }

    /**
     * Extracts a string from the message payload
     *
     * @return array{0: string, 1: int}
     */
    public function extractString(string $data, int &$offset): array
    {
        $length = unpack('N', substr($data, $offset, 4))[1];
        $string = substr($data, $offset + 4, $length);
        $offset += 4 + $length;

        return [$string, $offset];
    }

    /**
     * Extracts values from the message payload based on a format string
     * Format specifiers:
     * %s - length-prefixed string
     * %u - 32-bit unsigned integer
     * %b - boolean (1 byte)
     *
     * @param  string  $format  Format string like '%s%u%u%u' for string + 3 integers
     * @return array<mixed> Array of extracted values
     */
    public function extractFormat(string $format): array
    {
        $values = [];

        // First validate all format specifiers
        preg_match_all('/%([^sub])/', $format, $invalidMatches);
        if (! empty($invalidMatches[1])) {
            throw new \InvalidArgumentException("Unknown format specifier: {$invalidMatches[1][0]}");
        }

        // Extract format specifiers using regex to match %s, %u, %b
        preg_match_all('/%([sub])/', $format, $matches);
        $specifiers = $matches[1] ?? [];

        foreach ($specifiers as $spec) {
            if ($this->offset >= strlen($this->message)) {
                return $values;
            }

            match ($spec) {
                's' => [$values[], $this->offset] = $this->extractString($this->message, $this->offset),
                'u' => [
                    $values[] = (int) unpack('N', substr($this->message, $this->offset, 4))[1],
                    $this->offset += 4,
                ],
                'b' => [
                    $values[] = (bool) ord(substr($this->message, $this->offset, 1)),
                    $this->offset += 1,
                ],
                default => throw new \InvalidArgumentException("Unknown format specifier: {$spec}"),
            };
        }

        return $values;
    }

    public static function fromData(string $data): self
    {
        $packetLength = unpack('N', substr($data, 0, 4))[1]; // First byte is the packet length
        $payload = substr($data, 4, $packetLength - 1);
        $paddingLength = ord($payload[0]); // Second byte is the padding length, random bytes are added to ensure the packet is a length that divides by 8/16/something (TODO: Add accurate notes)

        // Therefore the actual payload is the part of the data after the lengths, and before the padding
        $payload = substr($payload, 1, $packetLength - $paddingLength - 1);

        return new self($payload);
    }
}
