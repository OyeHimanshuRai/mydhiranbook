<?php

namespace App\Services\Dhiran;

use App\Models\Dhiran\DhiranAttachment;
use App\Services\CustomerProfilePhotoEncoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stores Dhiran evidence files on the PRIVATE disk and records a shop-scoped
 * dhiran_attachments row (Phase E2/E3).
 *
 * Safety:
 *  - Private disk only ('local' = storage/app/private); never 'public'/web-served.
 *  - Allow-list of extensions + MIME; size cap; dangerous types rejected.
 *  - Images are oriented, shrunk to 2400 px and re-encoded via GD (JPEG q85) so EXIF/geolocation metadata is stripped.
 *  - Path is non-guessable (random filename) under a shop-scoped directory.
 *  - shop_id comes from the tenant context (BelongsToShop), never the request.
 */
class DhiranAttachmentService
{
    /** Private disk (storage/app/private). */
    public const DISK = 'local';

    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    /** ext => mime allow-list. */
    private const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    /**
     * Validate + store an uploaded file as a Dhiran attachment.
     *
     * @param  int     $shopId       authenticated shop (never from request)
     * @param  string  $ownerType    DhiranAttachment::OWNER_*
     * @param  int     $ownerId
     * @param  string  $documentType one of DhiranAttachment::DOCUMENT_TYPES
     */
    public function store(
        UploadedFile $file,
        int $shopId,
        string $ownerType,
        int $ownerId,
        string $documentType,
        ?int $uploadedBy = null,
        ?int $loanId = null, // the loan an upload made from the New Loan form belongs to (a borrower's document has no loan of its own)
    ): DhiranAttachment {
        if (! $file->isValid()) {
            throw new LogicException('The uploaded file is not valid.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new LogicException('File is too large (max 8 MB).');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! array_key_exists($ext, self::ALLOWED)) {
            throw new LogicException('Unsupported file type. Allowed: JPG, PNG, PDF.');
        }
        // Verify the real MIME matches the claimed extension (block disguised files).
        $realMime = $file->getMimeType();
        if ($realMime !== self::ALLOWED[$ext]) {
            throw new LogicException('File contents do not match its type.');
        }
        // Refuse absurd pixel counts before GD allocates the bitmap (a tiny PNG can decode to gigabytes).
        if ($realMime !== 'application/pdf') { // check dimensions from the header, before GD ever decodes the bitmap
            $size = @getimagesize($file->getRealPath());
            if (! $size || $size[0] < 1 || $size[1] < 1) {
                throw new LogicException('Image could not be read. Upload a JPG or PNG photo.');
            }
            if ($size[0] * $size[1] > self::MAX_PIXELS) {
                throw new LogicException('Image is too large (max 20 megapixels).');
            }
            // Orienting copies the bitmap, so GD needs about 2x its size; running out of memory is a fatal error, not an exception.
            $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
            if ($memoryLimit > 0 && memory_get_usage(true) + $size[0] * $size[1] * 8 + 16 * 1024 * 1024 > $memoryLimit) {
                throw new LogicException('This image needs more processing memory. Upload a smaller photo.');
            }
        }
        if (! in_array($documentType, DhiranAttachment::DOCUMENT_TYPES, true)) {
            throw new LogicException('Unknown document type.');
        }

        $bytes = null; // PDFs are stored as uploaded; images as the compressed bytes
        if ($realMime !== 'application/pdf') {
            [$bytes, $realMime] = $this->compressImage($file, $size[2], $realMime);
            $ext = $realMime === 'image/png' ? 'png' : 'jpg';
        }

        $dir      = "dhiran/{$shopId}/" . Str::random(2);
        $filename = Str::random(40) . '.' . $ext;
        $path     = "{$dir}/{$filename}";
        $disk     = Storage::disk(self::DISK);

        if ($bytes !== null) {
            $disk->put($path, $bytes);
        } else {
            $disk->putFileAs($dir, $file, $filename);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
        $attachment = DhiranAttachment::create([
            'shop_id'       => $shopId,
            'owner_type'    => $ownerType,
            'owner_id'      => $ownerId,
            'document_type' => $documentType,
            'file_disk'     => self::DISK,
            'file_path'     => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type'     => $realMime,
            'size_bytes'    => $bytes === null ? $file->getSize() : strlen($bytes),
            'uploaded_by'   => $uploadedBy,
        ]);

        // The upload is a line in the loan's activity log (the loan it hangs on: its own, its item's, or the one named by the caller).
        $logLoan = match ($ownerType) {
            DhiranAttachment::OWNER_LOAN => $ownerId,
            DhiranAttachment::OWNER_ITEM => (int) \App\Models\Dhiran\DhiranLoanItem::withoutGlobalScopes()->whereKey($ownerId)->value('dhiran_loan_id'),
            default => $loanId,
        };
        if ($logLoan) {
            $type = ucfirst(str_replace('_', ' ', $documentType));
            \App\Services\Dhiran\LoanLog::record($shopId, (int) $logLoan, 'document.uploaded', 'document',
                "Uploaded {$type}: " . mb_substr((string) $file->getClientOriginalName(), 0, 80) . ($ownerType === DhiranAttachment::OWNER_CUSTOMER ? " (kept on the borrower's profile)" : ''),
                ['attachment_id' => $attachment->id, 'document_type' => $documentType, 'file' => $attachment->original_name, 'size_kb' => (int) round($file->getSize() / 1024),
                 'kept_on' => $ownerType === DhiranAttachment::OWNER_ITEM ? 'item' : ($ownerType === DhiranAttachment::OWNER_CUSTOMER ? 'borrower' : 'loan')]);
        }
        \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack(); // no upload without its log line, and no orphan file
            $disk->delete($path);
            throw $e;
        }

        return $attachment;
    }

    public const MAX_PIXELS = 20_000_000; // same bound as the profile-photo encoder

    /** Longest side of a gallery thumbnail, in pixels. */
    public const THUMB_MAX = 320;

    /**
     * Path of a small JPEG copy of an image attachment, made on first request and kept next to the original
     * (attachments are never edited in place, so it cannot go stale). Null for non-images or when GD cannot
     * decode the file: the caller then serves the original. The original already passed GD at upload.
     */
    public function thumbnail(DhiranAttachment $attachment): ?string
    {
        if (! $attachment->isImage()) {
            return null;
        }
        $disk = Storage::disk($attachment->file_disk);
        $path = $attachment->file_path . '.thumb.jpg';
        if ($disk->exists($path)) {
            return $path;
        }

        $bytes = (string) $disk->get($attachment->file_path);
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null; // never decode an unreadable or oversized stored image just to thumbnail it
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, self::THUMB_MAX / max($w, $h));
        [$tw, $th] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $thumb = imagecreatetruecolor($tw, $th);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255)); // transparent PNG areas become white
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagejpeg($thumb, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($img);
        imagedestroy($thumb);

        if ($bytes === false || $bytes === '') {
            return null;
        }
        $disk->put($path, $bytes);

        return $path;
    }

    /** Longest side of a stored image, in pixels (photos are never enlarged). */
    public const IMAGE_MAX_SIDE = 2400;

    public const JPEG_QUALITY = 85;

    /**
     * Upright, metadata-free, at most 2400 px, JPEG q85 (PNG only when it really has transparency).
         * An image GD cannot read is refused, never stored raw.
     *
     * @return array{0:string,1:string} bytes, mime
     */
    private function compressImage(UploadedFile $file, int $type, string $mime): array
    {
        $encoder = app(CustomerProfilePhotoEncoder::class);
        $original = (string) file_get_contents($file->getRealPath());
        $img = null;
        try {
            if ($type === IMAGETYPE_JPEG && ! $encoder->jpegIsComplete($original)) {
                throw new LogicException('This photo looks incomplete or damaged. Upload it again or take a new one.');
            }
            $img = $encoder->normalize($file, $type, self::IMAGE_MAX_SIDE);
            // Only a PNG with an alpha channel or tRNS chunk can be transparent: skip the pixel scan for everything else.
            $png = $type === IMAGETYPE_PNG && (in_array(ord(substr($original, 25, 1)), [4, 6], true) || str_contains($original, 'tRNS')) && $this->hasTransparency($img);
            ob_start();
            try {
                $ok = $png ? imagepng($img, null, 9) : imagejpeg($img, null, self::JPEG_QUALITY);
                $bytes = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $ok || $bytes === '') {
                throw new \RuntimeException('The image could not be compressed.');
            }
        } catch (LogicException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new LogicException('This image could not be processed. Upload another JPG or PNG photo.');
        } finally {
            if ($img instanceof \GdImage) {
                imagedestroy($img);
            }
        }

        // Always the re-encoded bytes: keeping the original could keep appended code or metadata (a PNG with PHP after IEND).
        return [$bytes, $png ? 'image/png' : 'image/jpeg'];
    }

    /** True when any pixel is not fully opaque (a PNG with an unused alpha channel is just a photo). */
    private function hasTransparency(\GdImage $img): bool
    {
        for ($y = imagesy($img) - 1; $y >= 0; $y--) {
            for ($x = imagesx($img) - 1; $x >= 0; $x--) {
                if (imagecolorat($img, $x, $y) >> 24 & 0x7F) {
                    return true;
                }
            }
        }

        return false;
    }
}
