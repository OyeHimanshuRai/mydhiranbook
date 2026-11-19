<?php
namespace App\Services;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Http\Request;
class PlatformAuditService
{
    public function log(?PlatformAdmin $actor, string $action, string $targetType='platform_admin', int|string|null $targetId=null, ?array $before=null, ?array $after=null, ?string $reason=null, ?Request $request=null): void
    {
        PlatformAuditLog::create(['actor_admin_id'=>$actor?->id,'action'=>$action,'target_type'=>$targetType,'target_id'=>$targetId,'before'=>$before,'after'=>$after,'reason'=>$reason,'ip'=>$request?->ip(),'user_agent'=>substr((string)$request?->userAgent(),0,1000),'created_at'=>now()]);
    }
}
