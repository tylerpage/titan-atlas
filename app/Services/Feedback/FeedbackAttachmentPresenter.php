<?php

namespace App\Services\Feedback;

use App\Models\FeedbackAttachment;
use Illuminate\Support\Facades\Storage;

class FeedbackAttachmentPresenter
{
    public function disk(): string
    {
        return (string) config('titan.feedback.disk', config('filesystems.default', 'local'));
    }

    public function isImage(FeedbackAttachment $attachment): bool
    {
        if (str_starts_with((string) $attachment->mime_type, 'image/')) {
            return true;
        }

        $extension = strtolower(pathinfo($attachment->original_filename, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(FeedbackAttachment $attachment): array
    {
        $isImage = $this->isImage($attachment);
        $previewSrc = $isImage ? $this->inlinePreviewSrc($attachment) : null;

        return [
            'id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $this->imageMimeType($attachment),
            'size_bytes' => $attachment->size_bytes,
            'is_image' => $isImage,
            'preview_src' => $previewSrc,
            'preview_url' => $isImage && $previewSrc === null ? $this->previewUrl($attachment) : null,
            'download_url' => route('admin.feedback.attachments.download', $attachment, absolute: true),
        ];
    }

    public function previewUrl(FeedbackAttachment $attachment): string
    {
        $disk = $this->disk();

        if ($this->diskDriver($disk) === 's3' && Storage::disk($disk)->exists($attachment->storage_path)) {
            return Storage::disk($disk)->temporaryUrl(
                $attachment->storage_path,
                now()->addHour(),
                [
                    'ResponseContentType' => $this->imageMimeType($attachment),
                    'ResponseContentDisposition' => 'inline; filename="'.addslashes($attachment->original_filename).'"',
                ],
            );
        }

        return route('admin.feedback.attachments.show', $attachment, absolute: true);
    }

    public function inlinePreviewSrc(FeedbackAttachment $attachment): ?string
    {
        $maxBytes = max(1, (int) config('titan.feedback.inline_preview_max_bytes', 5_242_880));

        if ($attachment->size_bytes > $maxBytes) {
            return null;
        }

        $disk = $this->disk();

        if (! Storage::disk($disk)->exists($attachment->storage_path)) {
            return null;
        }

        $contents = Storage::disk($disk)->get($attachment->storage_path);

        if ($contents === null || $contents === '') {
            return null;
        }

        return 'data:'.$this->imageMimeType($attachment).';base64,'.base64_encode($contents);
    }

    public function imageMimeType(FeedbackAttachment $attachment): string
    {
        if (str_starts_with((string) $attachment->mime_type, 'image/')) {
            return (string) $attachment->mime_type;
        }

        return match (strtolower(pathinfo($attachment->original_filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            default => 'image/jpeg',
        };
    }

    protected function diskDriver(string $disk): string
    {
        return (string) config("filesystems.disks.{$disk}.driver", 'local');
    }
}
