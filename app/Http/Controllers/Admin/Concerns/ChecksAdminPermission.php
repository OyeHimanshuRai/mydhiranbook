<?php
namespace App\Http\Controllers\Admin\Concerns;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ChecksAdminPermission
{
    private function permit(Request $r, string $permission): void
    {
        if (!Auth::guard('platform_admin')->user()->hasPermission($permission)) {
            app(PlatformAuditService::class)->log(Auth::guard('platform_admin')->user(),'admin.permission_denied',reason:$permission,request:$r); abort(403);
        }
    }
}
