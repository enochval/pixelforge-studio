<?php

declare(strict_types=1);

use App\Domain\GenerationStatus;

it('allows a transition only when it moves the generation forward', function (GenerationStatus $from, GenerationStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with(function () {
    $cases = [
        // Ordinary progress.
        ['creating', 'queued', true],
        ['queued', 'processing', true],
        ['processing', 'succeeded', true],
        ['processing', 'failed', true],

        // A missing or late intermediate event must not block an outcome.
        ['creating', 'processing', true],
        ['creating', 'succeeded', true],
        ['creating', 'failed', true],
        ['queued', 'succeeded', true],
        ['queued', 'failed', true],

        // Out-of-order delivery: an earlier stage arriving after a later one.
        ['processing', 'queued', false],
        ['succeeded', 'processing', false],
        ['failed', 'queued', false],

        // An outcome is never revised.
        ['succeeded', 'failed', false],
        ['failed', 'succeeded', false],

        // A redelivery of the event that produced the current status.
        ['queued', 'queued', false],
        ['processing', 'processing', false],
        ['succeeded', 'succeeded', false],
        ['failed', 'failed', false],
    ];

    return array_map(
        fn (array $case) => [
            GenerationStatus::from($case[0]),
            GenerationStatus::from($case[1]),
            $case[2],
        ],
        $cases,
    );
});

it('covers every pair of statuses', function () {
    // Guards the matrix above against a status being added without the
    // transitions involving it being considered.
    $statuses = GenerationStatus::cases();

    expect(count($statuses) ** 2)->toBe(25);
});

it('treats succeeded and failed as alternative endings at the same rank', function () {
    expect(GenerationStatus::Succeeded->rank())->toBe(GenerationStatus::Failed->rank())
        ->and(GenerationStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Failed->isTerminal())->toBeTrue();
});

it('orders the stages a generation passes through', function () {
    expect(GenerationStatus::Creating->rank())->toBeLessThan(GenerationStatus::Queued->rank())
        ->and(GenerationStatus::Queued->rank())->toBeLessThan(GenerationStatus::Processing->rank())
        ->and(GenerationStatus::Processing->rank())->toBeLessThan(GenerationStatus::Succeeded->rank());
});

it('lists the statuses still awaiting an outcome', function () {
    expect(GenerationStatus::nonTerminal())
        ->toBe([GenerationStatus::Creating, GenerationStatus::Queued, GenerationStatus::Processing]);

    foreach (GenerationStatus::nonTerminal() as $status) {
        expect($status->isTerminal())->toBeFalse();
    }
});
