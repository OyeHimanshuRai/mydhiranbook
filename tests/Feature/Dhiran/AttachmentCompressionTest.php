<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranAttachment;
use App\Services\Dhiran\DhiranAttachmentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/** Every uploaded image is stored upright, at most 2400 px, without metadata; unreadable images are refused, PDFs are left alone. */
class AttachmentCompressionTest extends TestCase
{
    use RefreshDatabase;

    private $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->shop = self::createDhiranShop(['name' => 'Compress shop']);
    }

    /** A noisy (hard to compress) JPEG, optionally tagged with an EXIF orientation. */
    private function noisyJpeg(int $w, int $h, int $quality = 90, ?int $orientation = null): string
    {
        mt_srand(7);
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += 10) {
            for ($x = 0; $x < $w; $x += 10) {
                imagefilledrectangle($img, $x, $y, $x + 9, $y + 9, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagejpeg($img, null, $quality);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);
        if ($orientation === null) {
            return $jpeg;
        }
        // APP1 segment straight after SOI: "Exif\0\0", big-endian TIFF with one IFD0 entry (Orientation).
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    private function save(UploadedFile $file): DhiranAttachment
    {
        return TenantContext::runFor($this->shop->id, fn () => app(DhiranAttachmentService::class)->store(
            $file, $this->shop->id, DhiranAttachment::OWNER_CUSTOMER, 1, 'id_proof_front', null));
    }

    public function test_a_big_sideways_phone_photo_is_stored_upright_small_and_without_exif(): void
    {
        $original = $this->noisyJpeg(4000, 3000, 60, 6);
        $this->assertNotFalse(strpos($original, "Exif\x00\x00"), 'fixture carries EXIF');

        $att = $this->save(UploadedFile::fake()->createWithContent('phone.jpg', $original));
        $stored = Storage::disk('local')->get($att->file_path);
        [$w, $h] = getimagesizefromstring($stored);

        $this->assertLessThanOrEqual(2400, max($w, $h));
        $this->assertGreaterThan($w, $h, 'orientation 6 is turned upright, so the 4000x3000 landscape becomes portrait');
        $this->assertFalse(strpos($stored, "Exif\x00\x00"), 'no EXIF/GPS block is stored');
        $this->assertLessThan(strlen($original), strlen($stored));
        $this->assertSame('image/jpeg', $att->mime_type);
        $this->assertSame(strlen($stored), $att->size_bytes);
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $original = $this->noisyJpeg(300, 200, 60);

        $att = $this->save(UploadedFile::fake()->createWithContent('small.jpg', $original));
        $stored = Storage::disk('local')->get($att->file_path);

        // pixel size only: the re-encode (q85) of a tiny q60 original may well be a larger file
        $this->assertSame([300, 200], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_an_unreadable_image_is_refused_and_nothing_is_stored(): void
    {
        $good = $this->noisyJpeg(400, 300);
        $cases = [
            'not an image at all' => 'this is not an image',
            'cut off halfway' => substr($good, 0, intdiv(strlen($good), 2)),
            'header then garbage' => "\xFF\xD8\xFF\xE0" . str_repeat("\x13", 200),
        ];
        foreach ($cases as $label => $content) {
            try {
                $this->save(UploadedFile::fake()->createWithContent('broken.jpg', $content));
                $this->fail("{$label}: a broken .jpg must be refused");
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_pdf_is_stored_byte_for_byte(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $att = $this->save(UploadedFile::fake()->createWithContent('agreement.pdf', $pdf));

        $this->assertSame($pdf, Storage::disk('local')->get($att->file_path));
        $this->assertSame('application/pdf', $att->mime_type);
    }
}
