<?php

namespace Foziluff\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeAll extends Command
{
    protected $signature = 'make:all {name}';

    public function handle(): void
    {
        $name = Str::studly($this->argument('name'));
        $modelFilePath = app_path("Models/{$name}.php");

        if (!File::exists($modelFilePath)) {
            Artisan::call("make:model {$name} -a");
            $this->info("Generated Laravel model & extras for {$name}");
        } else {
            exit("Model {$name} already exists");
        }

        $modelFilePath = app_path("Models/{$name}.php");


        $modelContent = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$name} extends Model
{
    protected \$fillable = [];
}
PHP;

        File::put($modelFilePath, $modelContent);
        $this->info("Custom model structure applied to {$modelFilePath}");
        $controllerPath = app_path("Http/Controllers/{$name}Controller.php");
        $camelName = lcfirst($name);

        $controllerContent = <<<PHP
<?php

namespace App\Http\Controllers;

use App\Http\Requests\\{$name}\\Store{$name}Request;
use App\Http\Requests\\{$name}\\Update{$name}Request;
use App\Services\\{$name}Service;
use Illuminate\Http\JsonResponse;

class {$name}Controller extends Controller
{
    public function __construct(
        readonly private {$name}Service \${$camelName}Service,
    )
    {
        //
    }

    public function index(): JsonResponse
    {
        //
    }
    
    public function store(Store{$name}Request \$request): JsonResponse
    {
        //
    }

    public function show(int \$id): JsonResponse
    {
        //
    }

    public function update(Update{$name}Request \$request, int \$id): JsonResponse
    {
        //
    }

    public function destroy(int \$id): JsonResponse
    {
        //
    }
}
PHP;

        File::put($controllerPath, $controllerContent);
        $this->info("Custom controller structure applied to {$controllerPath}");
        $storeRequestOld = app_path("Http/Requests/Store{$name}Request.php");
        $updateRequestOld = app_path("Http/Requests/Update{$name}Request.php");

        $customRequestDir = app_path("Http/Requests/{$name}");
        File::ensureDirectoryExists($customRequestDir);

        $storeRequestNew = "{$customRequestDir}/Store{$name}Request.php";
        $updateRequestNew = "{$customRequestDir}/Update{$name}Request.php";

        if (File::exists($storeRequestOld)) {
            File::move($storeRequestOld, $storeRequestNew);
            $this->info("Moved Store{$name}Request to {$storeRequestNew}");
        }

        if (File::exists($updateRequestOld)) {
            File::move($updateRequestOld, $updateRequestNew);
            $this->info("Moved Update{$name}Request to {$updateRequestNew}");
        }

// Reload namespaces
        function updateNamespace($filePath, $newNamespace): void
        {
            if (File::exists($filePath)) {
                $content = File::get($filePath);
                $content = preg_replace('/namespace App\\\Http\\\Requests;/', "namespace {$newNamespace};", $content);
                File::put($filePath, $content);
            }

        }
        function forceAuthorizeTrue($filePath): void
        {
            if (File::exists($filePath)) {
                $content = File::get($filePath);
                $content = preg_replace(
                    '/public function authorize\(\): bool\s*\{\s*return false;\s*\}/',
                    "public function authorize(): bool\n    {\n        return true;\n    }",
                    $content
                );
                File::put($filePath, $content);
            }
        }

        forceAuthorizeTrue($storeRequestNew);
        forceAuthorizeTrue($updateRequestNew);

        updateNamespace($storeRequestNew, "App\\Http\\Requests\\{$name}");
        updateNamespace($updateRequestNew, "App\\Http\\Requests\\{$name}");


        // 2. Interface
        $interfacePath = app_path("Contracts/Interfaces/Repositories/{$name}RepositoryInterface.php");
        File::ensureDirectoryExists(dirname($interfacePath));
        File::put($interfacePath, <<<PHP
<?php

namespace App\Contracts\Interfaces\Repositories;

interface {$name}RepositoryInterface
{
}
PHP);
        $this->info("Created interface: {$interfacePath}");

        // 3. Implementation
        $repoPath = app_path("Repositories/{$name}Repository.php");
        File::ensureDirectoryExists(dirname($repoPath));
        File::put($repoPath, <<<PHP
<?php

namespace App\Repositories;

use App\Contracts\Interfaces\Repositories\\{$name}RepositoryInterface;

class {$name}Repository implements {$name}RepositoryInterface
{
}
PHP);
        $this->info("Created repository: {$repoPath}");
        $camelName = lcfirst($name);
        var_dump($camelName);

        // 4. Service
        $servicePath = app_path("Services/{$name}Service.php");
        File::ensureDirectoryExists(dirname($servicePath));
        File::put($servicePath, <<<PHP
<?php

namespace App\Services;

use App\Contracts\Interfaces\Repositories\\{$name}RepositoryInterface;

readonly class {$name}Service
{
    public function __construct(
        private {$name}RepositoryInterface \${$camelName}Repository
    )
    {
        //
    }
}
PHP);
        $this->info("Created service: {$servicePath}");

        $providerPath = app_path('Providers/RepositoryServiceProvider.php');

        if (!File::exists($providerPath)) {
            Artisan::call("make:provider RepositoryServiceProvider");
        }
        $providerContent = File::get($providerPath);

        $bindKey = "\\App\\Contracts\\Interfaces\\Repositories\\{$name}RepositoryInterface::class";
        $bindValue = "\\App\\Repositories\\{$name}Repository::class";
        $bindingEntry = "        {$bindKey} => {$bindValue},";

        if (!str_contains($providerContent, $bindKey)) {
            if (!str_contains($providerContent, 'public array $bindings')) {
                $providerContent = preg_replace(
                    '/class RepositoryServiceProvider extends ServiceProvider\s*\{/',
                    "class RepositoryServiceProvider extends ServiceProvider\n{\n    /**\n     * The container bindings that should be registered.\n     */\n    public array \$bindings = [\n{$bindingEntry}\n    ];",
                    $providerContent
                );
            } else {
                $providerContent = preg_replace(
                    '/public array \$bindings = \[\n/',
                    "public array \$bindings = [\n{$bindingEntry}\n",
                    $providerContent
                );
            }

            File::put($providerPath, $providerContent);
            $this->info("Binding added to \$bindings in RepositoryServiceProvider.");
        } else {
            $this->warn("Binding already exists in RepositoryServiceProvider.");
        }
    }
}

