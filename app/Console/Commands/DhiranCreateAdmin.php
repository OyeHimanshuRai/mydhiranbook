<?php
namespace App\Console\Commands;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
class DhiranCreateAdmin extends Command
{
    protected $signature='dhiran:create-admin {email} {--name=}';
    protected $description='Create a super admin with a one-time temporary password (TOTP enrolment required on first login, within 24h)';
    public function handle(): int
    {
        $email=strtolower(trim($this->argument('email')));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email address.'); return self::FAILURE; }
        if (PlatformAdmin::where('email',$email)->exists()) { $this->error('An admin with this email already exists.'); return self::FAILURE; }
        $password=Str::password(20);
        PlatformAdmin::create(['name'=>$this->option('name') ?: 'Platform Admin','email'=>$email,'password'=>$password,'role'=>'super_admin','is_active'=>true,'password_changed_at'=>now()]);
        $this->line("Admin created: {$email}");
        $this->line("Temporary password (shown once): {$password}");
        $this->line('Sign in at /admin/login within 24 hours to set up the authenticator; after that ask another super admin to reset the password.');
        return self::SUCCESS;
    }
}
