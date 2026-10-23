<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

beforeEach(function () {
    Route::get('/_test/upload-url', fn () => [
        'url' => app(GenerateSignedUploadUrl::class)->forLocal(),
    ]);
});

test('signed upload URLs use HTTPS behind the Coolify proxy', function () {
    Storage::fake(FileUploadConfiguration::disk());

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.1.10',
        'SERVER_PORT' => 80,
        'HTTPS' => 'off',
    ])->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
    ])->get('http://staging.example.test/_test/upload-url');

    $response->assertOk();

    expect($response->json('url'))
        ->toStartWith('https://staging.example.test/livewire/upload-file?');

    // The proxy terminates HTTPS; the PHP request itself still arrives over HTTP.
    $internalUrl = str_replace('https://', 'http://', $response->json('url'));

    $this->post($internalUrl, [
        'files' => [UploadedFile::fake()->create('diagnostic.wav', 900, 'audio/wav')],
    ])->assertOk()->assertJsonCount(1, 'paths');
});

test('direct public requests cannot spoof the forwarded upload scheme', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.10',
        'SERVER_PORT' => 80,
        'HTTPS' => 'off',
    ])->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
    ])->get('http://staging.example.test/_test/upload-url');

    $response->assertOk();

    expect($response->json('url'))
        ->toStartWith('http://staging.example.test/livewire/upload-file?');
});
