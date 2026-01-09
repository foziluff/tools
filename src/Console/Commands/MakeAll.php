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
            Artisan::call("make:model {$name} -m");
            $this->info("Generated Laravel model & extras for {$name}");

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
        } else {
            $this->warn("Model {$name} already exists, skipping...");
        }
        $controllerPath = app_path("Http/Controllers/{$name}Controller.php");
        $camelName = lcfirst($name);

        if (!File::exists($controllerPath)) {
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
        } else {
            $this->warn("Controller {$name}Controller already exists, skipping...");
        }
        $storeRequestOld = app_path("Http/Requests/Store{$name}Request.php");
        $updateRequestOld = app_path("Http/Requests/Update{$name}Request.php");

        $customRequestDir = app_path("Http/Requests/{$name}");
        File::ensureDirectoryExists($customRequestDir);

        $storeRequestNew = "{$customRequestDir}/Store{$name}Request.php";
        $updateRequestNew = "{$customRequestDir}/Update{$name}Request.php";

        function updateNamespace($filePath, $newNamespace): void
        {
            if (File::exists($filePath)) {
                $content = File::get($filePath);
                $content = preg_replace('/namespace App\\\Http\\\Requests;/', "namespace {$newNamespace};", $content);
                File::put($filePath, $content);
            }
        }

        if (File::exists($storeRequestOld) && !File::exists($storeRequestNew)) {
            File::move($storeRequestOld, $storeRequestNew);
            $this->info("Moved Store{$name}Request to {$storeRequestNew}");
            updateNamespace($storeRequestNew, "App\\Http\\Requests\\{$name}");
        }

        if (File::exists($updateRequestOld) && !File::exists($updateRequestNew)) {
            File::move($updateRequestOld, $updateRequestNew);
            $this->info("Moved Update{$name}Request to {$updateRequestNew}");
            updateNamespace($updateRequestNew, "App\\Http\\Requests\\{$name}");
        }

        if (!File::exists($storeRequestNew)) {
            $storeRequestContent = <<<PHP
<?php

namespace App\Http\Requests\\{$name};

use Illuminate\Foundation\Http\FormRequest;

class Store{$name}Request extends FormRequest
{
    protected \$stopOnFirstFailure = true;

    public function rules(): array
    {
        return [];
    }
}
PHP;

            File::put($storeRequestNew, $storeRequestContent);
            $this->info("Created Store{$name}Request file.");
        } else {
            $this->warn("Store{$name}Request already exists, skipping...");
        }

        if (!File::exists($updateRequestNew)) {
            $updateRequestContent = <<<PHP
<?php

namespace App\Http\Requests\\{$name};

use Illuminate\Foundation\Http\FormRequest;

class Update{$name}Request extends FormRequest
{
    protected \$stopOnFirstFailure = true;

    public function rules(): array
    {
        return [];
    }
}
PHP;

            File::put($updateRequestNew, $updateRequestContent);
            $this->info("Created Update{$name}Request file.");
        } else {
            $this->warn("Update{$name}Request already exists, skipping...");
        }

        $repoPath = app_path("Repositories/{$name}Repository.php");
        if (!File::exists($repoPath)) {
            File::ensureDirectoryExists(dirname($repoPath));
            File::put($repoPath, <<<PHP
<?php

namespace App\Repositories;

class {$name}Repository
{
}
PHP
            );
            $this->info("Created repository: {$repoPath}");
        } else {
            $this->warn("Repository {$name}Repository already exists, skipping...");
        }

        $camelName = lcfirst($name);
        $servicePath = app_path("Services/{$name}Service.php");
        if (!File::exists($servicePath)) {
            File::ensureDirectoryExists(dirname($servicePath));
            File::put($servicePath, <<<PHP
<?php

namespace App\Services;

use App\Repositories\\{$name}Repository;

readonly class {$name}Service
{
    public function __construct(
        private {$name}Repository \${$camelName}Repository
    )
    {
    }
}
PHP
            );
            $this->info("Created service: {$servicePath}");
        } else {
            $this->warn("Service {$name}Service already exists, skipping...");
        }
    }
}