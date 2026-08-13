<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'grow4me:doctor';

    protected $description = 'Validate the Grow4Me connector configuration without exposing secrets';

    public function handle(ResourceRegistry $resources): int
    {
        $checks = [
            $this->secretCheck('Connector token', config('grow4me-connector.token')),
            $this->secretCheck('Cursor secret', config('grow4me-connector.cursor_secret'), 32),
            $this->httpsCheck('Webhook URL', config('grow4me-connector.webhook.url')),
            $this->secretCheck('Webhook secret', config('grow4me-connector.webhook.secret'), 32),
        ];

        try {
            $count = count($resources->all());
            $checks[] = [
                'Resources',
                $count > 0 ? 'OK' : 'FAIL',
                $count > 0 ? "{$count} configured" : 'No resource adapters configured',
            ];
        } catch (Throwable $exception) {
            $checks[] = ['Resources', 'FAIL', $exception->getMessage()];
        }

        $outboxTable = (string) config('grow4me-connector.outbox.table');
        $outboxExists = Schema::connection(config('grow4me-connector.outbox.connection'))
            ->hasTable($outboxTable);
        $checks[] = [
            'Outbox table',
            $outboxExists ? 'OK' : 'FAIL',
            $outboxExists ? 'Migration applied' : 'Run php artisan migrate',
        ];

        $this->table(['Check', 'Status', 'Detail'], $checks);

        return collect($checks)->contains(fn (array $check): bool => $check[1] === 'FAIL')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{string, string, string}
     */
    private function secretCheck(string $name, mixed $value, int $minimumLength = 1): array
    {
        $valid = is_string($value) && strlen($value) >= $minimumLength;

        return [
            $name,
            $valid ? 'OK' : 'FAIL',
            $valid ? 'Configured' : "Must contain at least {$minimumLength} characters",
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function httpsCheck(string $name, mixed $value): array
    {
        $valid = is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https';

        return [
            $name,
            $valid ? 'OK' : 'FAIL',
            $valid ? 'HTTPS configured' : 'A valid HTTPS URL is required',
        ];
    }
}
