<?php

declare(strict_types=1);

namespace Bellman\Prompt;

/** Mime types accepted by Prompt::asUserWithData() and Prompt::asUserWithUri(). */
final class Mime
{
    public const APPLICATION_PDF = 'application/pdf';
    public const TEXT_PLAIN = 'text/plain';

    public const AUDIO_MPEG = 'audio/mpeg';
    public const AUDIO_MP3 = 'audio/mp3';
    public const AUDIO_WAV = 'audio/wav';

    public const IMAGE_PNG = 'image/png';
    public const IMAGE_JPEG = 'image/jpeg';
    public const IMAGE_WEBP = 'image/webp';

    public const VIDEO_MOV = 'video/mov';
    public const VIDEO_MPEG = 'video/mpeg';
    public const VIDEO_MP4 = 'video/mp4';
    public const VIDEO_MPG = 'video/mpg';
    public const VIDEO_AVI = 'video/avi';
    public const VIDEO_WMV = 'video/wmv';
    public const VIDEO_MPEGPS = 'video/mpegps';
    public const VIDEO_FLV = 'video/flv';
}
