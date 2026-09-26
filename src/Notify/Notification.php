<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

/**
 * Something worth telling a human, in a shape every channel can render.
 *
 * Channels format this; they never decide what is in it. `facts` are short
 * label/value pairs (the numbers), `body` is the prose, and `brief` is the
 * self-contained Markdown you can paste into an assistant to go from
 * "something is wrong" to "here is the code that is wrong".
 */
final readonly class Notification
{
    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public string $title,
        public string $body,
        public Severity $severity = Severity::Warning,
        public array $facts = [],
        public ?string $url = null,
        public ?string $brief = null,
    ) {}

    public function withUrl(?string $url): self
    {
        return new self($this->title, $this->body, $this->severity, $this->facts, $url, $this->brief);
    }

    /** Plain text, for channels with no formatting at all. */
    public function toText(): string
    {
        $lines = [$this->title, '', $this->body];

        if ($this->facts !== []) {
            $lines[] = '';

            foreach ($this->facts as $label => $value) {
                $lines[] = $label.': '.$value;
            }
        }

        if ($this->url !== null) {
            $lines[] = '';
            $lines[] = $this->url;
        }

        return implode("\n", $lines);
    }
}
