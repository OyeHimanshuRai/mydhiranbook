<?php
return [
    'defaults'=>['guard'=>'web','passwords'=>'platform_admins'],
    'guards'=>['web'=>['driver'=>'session','provider'=>'users'],'platform_admin'=>['driver'=>'session','provider'=>'platform_admins']],
    'providers'=>['users'=>['driver'=>'eloquent','model'=>App\Models\User::class],'platform_admins'=>['driver'=>'eloquent','model'=>App\Models\Platform\PlatformAdmin::class]],
    // reports ask for the user's own password again; one confirmation lasts 1 hour (owner 2026-11-21)
    'password_timeout'=>3600,
    'passwords'=>['users'=>['provider'=>'users','table'=>'password_reset_tokens','expire'=>60,'throttle'=>60],'platform_admins'=>['provider'=>'platform_admins','table'=>'platform_password_reset_tokens','expire'=>30,'throttle'=>60]],
];
