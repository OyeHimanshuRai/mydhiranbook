<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration {
    private function isEncrypted(string $v): bool { try { Crypt::decryptString($v); return true; } catch (DecryptException) { return false; } }
    private function each(callable $fn): void
    {
        DB::table('customers')->select('id','pan','id_number')->orderBy('id')->chunkById(200, function ($rows) use ($fn) {
            foreach ($rows as $r) { $u=[]; foreach (['pan','id_number'] as $c) { if ($r->$c !== null && $r->$c !== '' && ($n=$fn((string)$r->$c)) !== null) { $u[$c]=$n; } } if ($u) { DB::table('customers')->where('id',$r->id)->update($u); } }
        });
    }
    public function up(): void
    {
        Schema::table('customers', function ($t) { $t->text('pan')->nullable()->change(); $t->text('id_number')->nullable()->change(); });
        $this->each(fn ($v) => $this->isEncrypted($v) ? null : Crypt::encryptString($v));
    }
    public function down(): void
    {
        $this->each(fn ($v) => $this->isEncrypted($v) ? Crypt::decryptString($v) : null);
        Schema::table('customers', function ($t) { $t->string('pan',10)->nullable()->change(); $t->string('id_number',30)->nullable()->change(); });
    }
};
