<?php

declare(strict_types=1);

use ImageConverter\DTO\Run;
use ImageConverter\RunStatus;

test('a run is hydrated and restored from its own array', function () {
    $data = [
        'id' => 'HG7ML7M8z78YcAPEB',
        'status' => 'SUCCEEDED',
        'defaultDatasetId' => 'ds1',
        'startedAt' => '2026-11-27T10:00:00.000Z',
        'finishedAt' => '2026-11-27T10:01:00.000Z',
        'statusMessage' => 'Done',
        'actId' => 'act1',
    ];
    $run = Run::fromArray($data + ['userId' => 'ignored']);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->toArray())->toBe($data)
        ->and(Run::fromArray(json_decode((string) json_encode($run), true)))->toEqual($run);
});

test('missing or odd optional fields become null', function () {
    $run = Run::fromArray(['id' => 'run1', 'status' => 'READY', 'defaultDatasetId' => 5, 'startedAt' => ['x']]);

    expect($run->defaultDatasetId)->toBeNull()
        ->and($run->startedAt)->toBeNull()
        ->and($run->finishedAt)->toBeNull()
        ->and($run->statusMessage)->toBeNull()
        ->and($run->actId)->toBeNull();
});

test('id and a known status are required', function (array $data) {
    expect(fn () => Run::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'no id' => [['status' => 'READY']],
    'numeric id' => [['id' => 1, 'status' => 'READY']],
    'no status' => [['id' => 'run1']],
    'unknown status' => [['id' => 'run1', 'status' => 'PAUSED']],
]);

test('every status knows whether it is final and whether it is a failure', function (
    string $status,
    bool $finished,
    bool $failure,
) {
    $run = Run::fromArray(['id' => 'run1', 'status' => $status]);

    expect($run->isFinished())->toBe($finished)
        ->and($run->status->isFinished())->toBe($finished)
        ->and($run->status->isFailure())->toBe($failure)
        ->and($run->isSuccessful())->toBe($status === 'SUCCEEDED');
})->with([
    ['READY', false, false],
    ['RUNNING', false, false],
    ['SUCCEEDED', true, false],
    ['FAILED', true, true],
    ['ABORTING', false, false],
    ['ABORTED', true, true],
    ['TIMING-OUT', false, false],
    ['TIMED-OUT', true, true],
]);
