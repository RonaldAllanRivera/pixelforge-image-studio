<?php

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\OutcomeUnknown;
use InvalidArgumentException;

/**
 * Pixelforge's own count of our usage (GET /v1/usage): per UTC day, and for the whole
 * range. A generation counts on the day it finished, and only if it succeeded.
 */
final readonly class UsageReport
{
    /**
     * @param  array<string, UsageFigures>  $days  Keyed by date (Y-m-d).
     */
    public function __construct(
        public array $days,
        public UsageFigures $total,
    ) {}

    /**
     * @param  array<mixed>  $body
     *
     * @throws OutcomeUnknown when the body isn't the documented shape
     */
    public static function fromArray(array $body): self
    {
        try {
            if (! isset($body['data'], $body['total']) || ! is_array($body['data']) || ! is_array($body['total'])) {
                throw new InvalidArgumentException('Pixelforge usage has no [data] or [total].');
            }

            $days = [];

            foreach ($body['data'] as $day) {
                if (! is_array($day) || ! is_string($day['date'] ?? null)) {
                    throw new InvalidArgumentException('A Pixelforge usage day has no [date].');
                }

                $days[$day['date']] = UsageFigures::fromArray($day);
            }

            return new self($days, UsageFigures::fromArray($body['total']));
        } catch (InvalidArgumentException $e) {
            throw new OutcomeUnknown("Pixelforge's usage answer isn't in the documented shape: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * One day's figures; zero when Pixelforge didn't list the day.
     */
    public function day(string $date): UsageFigures
    {
        return $this->days[$date] ?? UsageFigures::zero();
    }
}
