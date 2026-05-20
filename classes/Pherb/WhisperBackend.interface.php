<?php
namespace Pherb;

/**
 * WhisperBackend — Interface for whisper transcription backends.
 *
 * Implementations may use the whisper-server HTTP API (synchronous),
 * the whisper-cli command-line tool (async/process), or any other
 * compatible transcription engine.
 */
interface WhisperBackend
{
    /**
     * Transcribe an audio file.
     *
     * @param string $filePath Absolute path to the audio file
     * @param string $model    Model name (e.g., 'medium.en', 'small.en')
     * @return array           Whisper verbose_json response with segments and word timestamps
     * @throws \RuntimeException If transcription fails
     */
    public function transcribe(string $filePath, string $model = 'medium.en'): array;
}
