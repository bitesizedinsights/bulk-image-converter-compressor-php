<?php

declare(strict_types=1);

namespace ImageConverter;

enum OutputFormat: string
{
    case WebP = 'webp';
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Avif = 'avif';
    /** Keep each image's own format and only compress and resize. */
    case Original = 'original';
    case Gif = 'gif';
    case Tiff = 'tiff';
    case Bmp = 'bmp';
}
