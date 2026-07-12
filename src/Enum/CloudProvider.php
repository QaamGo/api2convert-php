<?php

declare(strict_types=1);

namespace Api2Convert\Enum;

/**
 * The cloud storage providers the API can import inputs from and deliver outputs to
 * — the values of a cloud descriptor's `source` (input) / `type` (output) field.
 *
 * This is **build-side vocabulary only**: it types the input builder and output-target
 * serialization. Read models keep `source`/`type`/`status` as raw strings, so an unknown
 * provider string returned by the server round-trips untyped and never throws — hydrate
 * tolerantly with {@see tryFrom()}, never {@see from()}.
 *
 * Import support (a `CloudInput` factory) exists for {@see AmazonS3}, {@see Azure},
 * {@see Ftp} and {@see GoogleCloud}. {@see Gdrive} and {@see Youtube} are **output-only**
 * (they validate as an output `type` but have no downloader); Google Drive *input* uses the
 * separate `gdrive_picker` input type.
 */
enum CloudProvider: string
{
    case AmazonS3 = 'amazons3';
    case Azure = 'azure';
    case Ftp = 'ftp';
    case Gdrive = 'gdrive';
    case GoogleCloud = 'googlecloud';
    case Youtube = 'youtube';
}
