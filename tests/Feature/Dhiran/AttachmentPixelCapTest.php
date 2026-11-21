<?php
namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranAttachment;
use App\Services\Dhiran\DhiranAttachmentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentPixelCapTest extends TestCase
{
    use RefreshDatabase;

    /** A tiny PNG file that declares a huge bitmap (decoding it would allocate width*height*4 bytes). */
    private function bigPng(int $w, int $h): string
    {
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $idat = ''; $row = str_repeat("\0", $w + 1);
        for ($i = 0; $i < $h; $i += 500) { $idat .= deflate_add($z, str_repeat($row, min(500, $h - $i)), ZLIB_NO_FLUSH); }
        $idat .= deflate_add($z, '', ZLIB_FINISH);
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', $idat) . $chunk('IEND', '');
    }

    /** Same bound as the profile-photo encoder: 20 megapixels, checked before GD decodes. */
    public function test_upload_over_20_megapixels_is_refused(): void
    {
        Storage::fake('local');
        $shop = self::createDhiranShop(['name' => 'Px shop']);
        $file = UploadedFile::fake()->createWithContent('big.png', $this->bigPng(5000, 5000)); // 25 MP
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('20 megapixels');
        TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store($file, $shop->id, DhiranAttachment::OWNER_LOAN, 1, 'item_photo', null));
    }

    public function test_thumbnail_never_decodes_an_oversized_stored_image(): void
    {
        Storage::fake('local');
        $shop = self::createDhiranShop(['name' => 'Px shop 2']);
        Storage::disk('local')->put('dhiran/x/big.png', $this->bigPng(5000, 5000));
        $att = TenantContext::runFor($shop->id, fn () => DhiranAttachment::create(['shop_id' => $shop->id, 'owner_type' => DhiranAttachment::OWNER_LOAN, 'owner_id' => 1, 'document_type' => 'item_photo', 'file_disk' => 'local', 'file_path' => 'dhiran/x/big.png', 'original_name' => 'big.png', 'mime_type' => 'image/png', 'size_bytes' => 1]));
        $this->assertNull(app(DhiranAttachmentService::class)->thumbnail($att));
        Storage::disk('local')->assertMissing('dhiran/x/big.png.thumb.jpg');
    }
}
