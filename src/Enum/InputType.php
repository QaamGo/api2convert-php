<?php

declare(strict_types=1);

namespace Api2Convert\Enum;

/**
 * The kinds of source an input file can be created from — the values of the API's
 * input `type` field. Provided as a typed reference for building input descriptors
 * by hand, e.g. `addInput($id, ['type' => InputType::Remote->value, 'source' => …])`;
 * the descriptor arrays the SDK sends use these string values.
 */
enum InputType: string
{
    /** A file uploaded directly to the per-job upload server. */
    case Upload = 'upload';
    /** A file fetched by the API from a public URL. */
    case Remote = 'remote';
    /** The output of a previous conversion in the same job. */
    case Output = 'output';
    /** A finished output of another job, by its id (job chaining). */
    case InputId = 'input_id';
    /** A file picked through the Google Drive picker. */
    case GdrivePicker = 'gdrive_picker';
    /** A small file embedded inline as base64. */
    case Base64 = 'base64';
    /** A file imported from cloud storage (S3, GCS, Azure, FTP, …). */
    case Cloud = 'cloud';
}
