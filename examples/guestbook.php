#!/usr/bin/env php
<?php

use Laravel\Prompts\Prompt;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

require __DIR__.'/vendor/autoload.php';

class Guestbook extends Prompt
{
    private array $guestbook = [];

    private string $storageFile;

    public function __construct()
    {
        date_default_timezone_set('UTC');
        $this->storageFile = realpath(__DIR__.'/../').'/guestbook.json';
    }

    private function formatTimestamp(?string $timestamp = null): string
    {
        $time = $timestamp ? strtotime($timestamp) : time();

        return date('D, M j, H:i T', $time);
    }

    private function loadGuestbook(): void
    {
        if (file_exists($this->storageFile)) {
            $contents = file_get_contents($this->storageFile);
            $this->guestbook = json_decode($contents, true) ?? [];
        }
    }

    private function addEntry(array $entry): void
    {
        $fp = fopen($this->storageFile, 'c+');

        if (! $fp) {
            throw new RuntimeException('Could not open guestbook file for writing');
        }

        try {
            // Get an exclusive lock
            if (! flock($fp, LOCK_EX)) {
                throw new RuntimeException('Could not lock guestbook file');
            }

            // Read the current contents
            fseek($fp, 0, SEEK_SET);
            $contents = stream_get_contents($fp);
            $currentEntries = json_decode($contents, true) ?? [];

            // Add the new entry
            $currentEntries[] = $entry;

            // Truncate and write back
            ftruncate($fp, 0);
            fseek($fp, 0, SEEK_SET);
            fwrite($fp, json_encode($currentEntries, JSON_PRETTY_PRINT));

            // Update our local copy
            $this->guestbook = $currentEntries;
        } finally {
            // Always release the lock and close the file
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    public function value(): mixed
    {
        return true;
    }

    /**
     * Center the text in the terminal with a background color, and opposite bold text.
     * We also add an empty line before and after the text to make it look nicer, this is the same bgColor, and the full terminal width
     */
    private function header(string $text): void
    {
        $terminalWidth = $this->terminal()->cols();
        $textLength = mb_strlen($text);

        // Calculate padding needed to center the text
        $padding = (($terminalWidth - $textLength) / 2) - 1;

        // Create the full-width string with centered text
        $fullLine = $padding > 0 ? str_repeat(' ', floor($padding)).$text.str_repeat(' ', ceil($padding)) : $text;

        // Style the entire line
        $styled = $this->bold($fullLine);
        $styled = $this->black($styled);
        $styled = $this->bgMagenta($styled);
        $emptyBgColorLine = $this->bgMagenta(str_repeat(' ', $terminalWidth));

        echo "{$emptyBgColorLine}\n{$styled}\n{$emptyBgColorLine}\n\n";
    }

    private function getVisibleEntries(): array
    {
        // Reload the guestbook to get latest entries
        $this->loadGuestbook();
        if (empty($this->guestbook)) {
            return [];
        }

        // Calculate available height
        $terminalHeight = $this->terminal()->lines() - 2;

        // Reserve space for:
        // - 3 lines for header (1 line + padding)
        // - 2 lines for "Latest Guests:" and table header
        // - 3 lines for input prompt
        // - 2 lines for confirmation message
        // - x for dividers
        $reservedLines = 13;

        // Calculate how many entries we can show
        $availableLines = $terminalHeight - $reservedLines;

        // Return the most recent entries that will fit
        $entries = array_slice(array_reverse($this->guestbook), 0, max(0, $availableLines));

        $availableWidth = $this->terminal()->cols() - 16;
        // We need to truncate the message to fit the available width. We know the length of the name and the timestamp, so we can subtract that from the available width and leave 2 characters for the ellipsis.
        $maxNameLength = array_map(fn ($entry) => mb_strlen($entry['name']), $entries);
        $maxTimestampLength = array_map(fn ($entry) => mb_strlen($this->formatTimestamp($entry['timestamp'])), $entries);

        $maxMessageLength = $availableWidth - max($maxNameLength) - max($maxTimestampLength) - 2;

        // Format entries for display
        return array_map(function ($entry) use ($maxMessageLength) {
            if (! empty($entry['message'])) {
                $entry['message'] = strlen($entry['message']) > $maxMessageLength ? substr($entry['message'], 0, $maxMessageLength).'..' : $entry['message'];
            } else {
                $entry['message'] = '(no message)';
            }

            return [
                'name' => $entry['name'],
                'message' => $entry['message'],
                'signed at' => $this->formatTimestamp($entry['timestamp']),
            ];
        }, $entries);
    }

    private function renderGuestbook(): void
    {
        // Clear the screen
        clear();

        // Show header with bold text and colored background
        $this->header('✨ SIGN MY SSH GUESTBOOK, made with Whisp (SSH server written in PHP) + Laravel Prompts ✨');

        // Show latest guests that fit in the terminal
        echo $this->bold($this->magenta('Latest Guests:')).PHP_EOL;
        table(
            ['Name', 'Message', 'Signed At'],
            $this->getVisibleEntries()
        );
    }

    public function run(): void
    {
        $this->renderGuestbook();

        // Ask for name
        $name = text(
            label: 'What is your name?',
            placeholder: 'Enter your name to sign the guestbook...',
            required: true,
            validate: fn (string $value) => match (true) {
                strlen($value) < 2 => 'The name must be at least 2 characters.',
                strlen($value) > 40 => 'The name must not exceed 40 characters.',
                default => null
            },
            hint: 'This will be public in the guestbook.'
        );

        // Ask for message (optional)
        $message = text(
            label: 'Leave a message (optional)',
            placeholder: 'Your message for the guestbook...',
            required: false,
            hint: 'Your message must be fewer than 50 characters.',
            validate: fn (string $value) => match (true) {
                strlen($value) > 50 => 'Your message must be fewer than 50 characters.',
                default => null
            }
        );

        // Create new entry
        $entry = [
            'name' => $name,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Add entry with safe concurrent access
        $this->addEntry($entry);

        // Re-render the guestbook to show the new entry
        $this->renderGuestbook();

        // Show confirmation and pause briefly
        info("Thank you for signing my guestbook, {$name}! 🎉");
        sleep(1); // Give them time to see their entry
    }
}

// Run the guestbook
(new Guestbook)->run();
