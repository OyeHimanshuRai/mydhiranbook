<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration {
    private function isEncrypted(string $v): bool { try { Crypt::decryptString($v); return true; } catch (DecryptException) { return false; } }
    private function each(callable $fn): void
    {
        DB::table('dhiran_loans')->select('id','kyc_pan')->orderBy('id')->chunkById(200, function ($rows) use ($fn) {
            foreach ($rows as $r) { $u=[]; foreach (['kyc_pan'] as $c) { if ($r->$c !== null && $r->$c !== '' && ($n=$fn((string)$r->$c)) !== null) { $u[$c]=$n; } } if ($u) { DB::table('dhiran_loans')->where('id',$r->id)->update($u); } }
        });
    }
    public function up(): void
    {
        Schema::table('dhiran_loans', function ($t) { $t->text('kyc_pan')->nullable()->change(); });
        $this->each(fn ($v) => $this->isEncrypted($v) ? null : Crypt::encryptString($v));
    }
    public function down(): void
    {
        $this->each(fn ($v) => $this->isEncrypted($v) ? Crypt::decryptString($v) : null);
        Schema::table('dhiran_loans', function ($t) { $t->string('kyc_pan',10)->nullable()->change(); });
    }
};
