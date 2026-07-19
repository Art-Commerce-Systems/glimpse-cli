<?php

namespace App\Commands;

use GlimpseImg\Client;
use GlimpseImg\UsageSummary;

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
                $this->line((string) json_encode($this->toArray($usage), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->render($usage);

            return self::SUCCESS;
        });
    }

    /**
     * The snake_case summary for --json, mirroring the API response shape
     * (period re-serialized as ISO-8601) so the output contract survives
     * the SDK's typed results.
     *
     * @return array<string, mixed>
     */
    private function toArray(UsageSummary $usage): array
    {
        return [
            'period' => [
                'from' => $usage->period->from->format(DATE_ATOM),
                'to' => $usage->period->to->format(DATE_ATOM),
            ],
            'operations' => $usage->operations,
            'bytes_saved' => $usage->bytesSaved,
            'average_reduction' => $usage->averageReduction,
            'by_operation' => $usage->byOperation,
        ];
    }

    private function render(UsageSummary $usage): void
    {
        $rows = [];

        $rows[] = ['Period', $usage->period->from->format('Y-m-d').' to '.$usage->period->to->format('Y-m-d')];
        $rows[] = ['Operations', number_format($usage->operations)];
        // humanSize expects a non-negative int; a net-growth month is
        // possible (conversions can grow output), so carry the sign.
        $rows[] = ['Data saved', ($usage->bytesSaved < 0 ? '-' : '').$this->humanSize(abs($usage->bytesSaved))];
        $rows[] = ['Avg. reduction', $usage->averageReduction.'%'];

        $this->table(['Metric', 'Value'], $rows);

        if ($usage->byOperation !== []) {
            $this->newLine();
            $this->line('<options=bold>By operation</>');

            foreach ($usage->byOperation as $operation => $count) {
                $this->line("  {$operation}: ".number_format($count));
            }
        }
    }
}
