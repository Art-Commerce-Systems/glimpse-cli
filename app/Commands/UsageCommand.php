<?php

namespace App\Commands;

use GlimpseImg\Client;

class UsageCommand extends GlimpseCommand
{
    protected $signature = 'usage
        {--json : Print the raw usage summary as JSON}';

    protected $description = "Show your team's month-to-date API usage";

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $usage = $client->usage();

            if ($this->option('json')) {
                $this->line((string) json_encode($usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->render($usage);

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function render(array $usage): void
    {
        $operations = data_get($usage, 'operations');
        $bytesSaved = data_get($usage, 'bytes_saved');
        $averageReduction = data_get($usage, 'average_reduction');

        $rows = [];

        $rows[] = ['Period', $this->period($usage)];
        $rows[] = ['Operations', is_numeric($operations) ? number_format((int) $operations) : '0'];
        // humanSize expects a non-negative int; a net-growth month is
        // possible (conversions can grow output), so carry the sign.
        $rows[] = ['Data saved', is_int($bytesSaved)
            ? ($bytesSaved < 0 ? '-' : '').$this->humanSize(abs($bytesSaved))
            : '0 B'];
        $rows[] = ['Avg. reduction', is_numeric($averageReduction) ? $averageReduction.'%' : '0%'];

        $this->table(['Metric', 'Value'], $rows);

        $byOperation = data_get($usage, 'by_operation');

        if (is_array($byOperation) && $byOperation !== []) {
            $this->newLine();
            $this->line('<options=bold>By operation</>');

            foreach ($byOperation as $operation => $count) {
                $count = is_numeric($count) ? number_format((int) $count) : '0';
                $this->line("  {$operation}: {$count}");
            }
        }
    }

    /**
     * Format the calendar-month window as plain dates.
     *
     * @param  array<string, mixed>  $usage
     */
    private function period(array $usage): string
    {
        $from = data_get($usage, 'period.from');
        $to = data_get($usage, 'period.to');

        if (! is_string($from) || ! is_string($to)) {
            return 'current month';
        }

        return substr($from, 0, 10).' to '.substr($to, 0, 10);
    }
}
