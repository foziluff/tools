<?php

namespace Foziluff;

use Foziluff\Console\Commands\MakeAll;
use Illuminate\Support\ServiceProvider;
use Foziluff\Console\Commands\GenerateSwaggerDocs;

class ToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([GenerateSwaggerDocs::class, MakeAll::class]);
    }
}
