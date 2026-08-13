<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeResourceCommand extends Command
{
    protected $signature = 'make:grow4me-resource
        {name : Adapter class name}
        {--model= : Fully qualified Eloquent model class}
        {--key= : Public sync resource key}
        {--namespace=App\\Grow4Me\\Resources : Generated class namespace}
        {--path= : Output directory; defaults to app/Grow4Me/Resources}
        {--force : Overwrite an existing adapter}';

    protected $description = 'Create a Grow4Me Eloquent resource adapter';

    public function handle(Filesystem $files): int
    {
        $model = $this->option('model');
        $key = $this->option('key');

        if (! is_string($model) || $model === '' || ! is_string($key) || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $key) !== 1) {
            $this->error('Both --model and a lowercase --key are required.');

            return self::INVALID;
        }

        $class = Str::studly((string) $this->argument('name'));
        $namespace = trim((string) $this->option('namespace'), '\\');
        $directory = $this->option('path');
        $directory = is_string($directory) && $directory !== ''
            ? $directory
            : app_path('Grow4Me/Resources');
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$class.'.php';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error("Adapter already exists at [{$path}].");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($directory);
        $files->put($path, $this->contents($namespace, $class, trim($model, '\\'), $key));

        $this->info("Grow4Me resource adapter created at [{$path}].");
        $this->line('Add the generated class to grow4me-connector.resources.');

        return self::SUCCESS;
    }

    private function contents(string $namespace, string $class, string $model, string $key): string
    {
        $modelClass = class_basename($model);

        return <<<PHP
<?php

namespace {$namespace};

use {$model};
use Illuminate\\Database\\Eloquent\\Model;
use ParsDesign\\Grow4MeDataConnector\\Resources\\EloquentResourceAdapter;

class {$class} extends EloquentResourceAdapter
{
    public function key(): string
    {
        return '{$key}';
    }

    public function modelClass(): string
    {
        return {$modelClass}::class;
    }

    public function isActive(Model \$model): bool
    {
        return true;
    }

    public function payload(Model \$model): array
    {
        return [
            // Map your stable Grow4Me payload here.
        ];
    }
}
PHP;
    }
}
