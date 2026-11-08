<?php

declare(strict_types=1);

namespace ImageConverter;

enum ConversionStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
