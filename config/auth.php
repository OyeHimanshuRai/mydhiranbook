<?php
return [
    'defaults'=>['guard'=>'web','passwords'=>'platform_admins'],
    'guards'=>['web'=>['driver'=>'session','provider'=>'users'],'platform_admin'=>['driver'=>'session','provider'=>'platform_admins']],
    'providers'=>['users'=>['driver'=>'eloquent','model'=>App\Models\User::class],'platform_admins'=>['driver'=>'eloquent','model'=>App\Models\Platform\PlatformAdmin::class]],
    'passwords'=>['users'=>['provider'=>'users','table'=>'password_reset_tokens','expire'=>60,'throttle'=>60],'platform_admins'=>['provider'=>'platform_admins','table'=>'platform_password_reset_tokens','expire'=>30,'throttle'=>60]],
];
