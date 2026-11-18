<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ActivityOccurred;
use App\Listeners\RecordActivityLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Support\PosContext;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PosContext::class, static fn (): PosContext => new PosContext);
        $this->app->scoped(AuditContext::class, static fn (): AuditContext => new AuditContext);
    }

    public function boot(): void
    {
        $windowsPassthrough = [
            'PHPRC',
            'PHP_INI_SCAN_DIR',
            'SystemRoot',
            'SYSTEMROOT',
            'SystemDrive',
            'SYSTEMDRIVE',
            'windir',
            'WINDIR',
            'Path',
            'PATH',
            'ComSpec',
            'COMSPEC',
            'PATHEXT',
            'TEMP',
            'TMP',
            'USERPROFILE',
            'APPDATA',
            'LOCALAPPDATA',
        ];

        foreach ($windowsPassthrough as $envKey) {
            if (! in_array($envKey, ServeCommand::$passthroughVariables, true)) {
                ServeCommand::$passthroughVariables[] = $envKey;
            }
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Event::listen(ActivityOccurred::class, RecordActivityLog::class);

        Gate::before(static function (User $user, string $ability): ?bool {
            if ($user->isSuperAdmin()) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }
}
