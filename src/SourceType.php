<?php

declare(strict_types=1);

namespace ImageConverter;

enum SourceType: string
{
    /** Image downloaded from a public URL. */
    case Url = 'url';
    /** File read from a key-value store. */
    case Upload = 'upload';
}
