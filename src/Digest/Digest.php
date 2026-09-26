<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Digest;

use DateTimeImmutable;

/**
 * A window's findings, ranked.
 *
 * {@see toMarkdown()} is the point of the whole thing: one self-contained
 * block you can paste into an assistant with the codebase open, so the step
 * after "here is what is slow" — finding the loop that made it slow — does
 * not start from scratch.
 */
final readonly class Digest
{
    /**
     * @param  list<Finding>  $findings  ranked, worst first
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $findings,
        public string $scope = 'all services',
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function of(DateTimeImmutable $from, DateTimeImmutable $to, array $findings, string $scope = 'all services'): self
    {
        usort($findings, static fn (Finding $a, Finding $b): int => $b->score() <=> $a->score());

        return new self($from, $to, $findings, $scope);
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    /**
     * @return list<Finding>
     */
    public function ofKind(FindingKind $kind): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->kind === $kind));
    }

    public function headline(): string
    {
        if ($this->isEmpty()) {
            return 'Nothing worth your attention.';
        }

        $top = $this->findings[0];
        $rest = count($this->findings) - 1;

        return $rest === 0
            ? $top->title
            : $top->title.' (and '.$rest.' other '.($rest === 1 ? 'finding' : 'findings').')';
    }

    /**
     * The whole digest as a Markdown brief: everything an assistant needs to
     * take it from here, with no follow-up questions.
     */
    public function toMarkdown(): string
    {
        $lines = [
            '# Telemetry findings',
            '',
            'Window: '.$this->from->format('Y-m-d H:i').' → '.$this->to->format('Y-m-d H:i').' UTC',
            'Scope: '.$this->scope,
            '',
        ];

        if ($this->isEmpty()) {
            $lines[] = 'No findings in this window.';

            return implode("\n", $lines)."\n";
        }

        $lines[] = 'Each finding below is something measured in production, with the';
        $lines[] = 'attributes that identify it. Please look for the cause in the code:';
        $lines[] = 'name the file or query you suspect, say why, and suggest a fix.';
        $lines[] = '';

        $number = 0;

        foreach ($this->findings as $finding) {
            $number++;
            $lines[] = '## '.$number.'. ['.$finding->kind->label().'] '.$finding->title;
            $lines[] = '';
            $lines[] = $finding->detail;

            if ($finding->facts !== []) {
                $lines[] = '';

                foreach ($finding->facts as $label => $value) {
                    $lines[] = '- **'.$label.'**: '.$value;
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
