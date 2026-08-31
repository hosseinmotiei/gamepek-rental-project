<?php

namespace App\Exceptions;

/**
 * Thrown by ImageUploadService when a file could not be verified as
 * physically present on disk after store(). The message is always the
 * Persian, customer/admin-safe text meant to be shown directly in the UI --
 * never a raw exception, path, or stack trace.
 */
class ImageUploadFailedException extends \RuntimeException
{
    public static function diskWriteFailed(): self
    {
        return new self('ذخیره تصویر روی سرور انجام نشد. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.');
    }
}
