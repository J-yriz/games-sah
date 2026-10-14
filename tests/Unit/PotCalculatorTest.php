<?php

use App\Support\PotCalculator;

test('PRD example: 1000/600/300 yields main 900, side 600, uncalled 400', function () {
    $calc = PotCalculator::calculate([
        ['user_id' => 1, 'seat' => 0, 'total' => 1000, 'folded' => false],
        ['user_id' => 2, 'seat' => 1, 'total' => 600, 'folded' => false],
        ['user_id' => 3, 'seat' => 2, 'total' => 300, 'folded' => false],
    ], 0);

    expect($calc['pots'])->toHaveCount(2)
        ->and($calc['pots'][0]['amount'])->toBe(900)
        ->and($calc['pots'][1]['amount'])->toBe(600)
        ->and($calc['uncalled'])->toBe(['user_id' => 1, 'amount' => 400]);
});

test('equal contributions form a single pot with all eligible', function () {
    $calc = PotCalculator::calculate([
        ['user_id' => 1, 'seat' => 0, 'total' => 100, 'folded' => false],
        ['user_id' => 2, 'seat' => 1, 'total' => 100, 'folded' => false],
        ['user_id' => 3, 'seat' => 2, 'total' => 100, 'folded' => false],
    ], 0);

    expect($calc['pots'])->toHaveCount(1)
        ->and($calc['pots'][0]['amount'])->toBe(300)
        ->and($calc['uncalled'])->toBeNull();
});

test('folded players fund the pot but are not eligible', function () {
    $calc = PotCalculator::calculate([
        ['user_id' => 1, 'seat' => 0, 'total' => 100, 'folded' => false],
        ['user_id' => 2, 'seat' => 1, 'total' => 100, 'folded' => true],
        ['user_id' => 3, 'seat' => 2, 'total' => 20, 'folded' => true],
    ], 0);

    // Levels 20, 100: 20*3=60 eligible [1]; 80*2=160 eligible [1].
    expect($calc['pots'])->toHaveCount(2)
        ->and($calc['pots'][0]['eligible'])->toBe([1])
        ->and($calc['pots'][1]['eligible'])->toBe([1]);
});

test('split pot with odd chip goes left of dealer', function () {
    // Pot 101 split 2 ways: base 50 each, 1 odd chip to first winner left of dealer.
    $shares = PotCalculator::splitPot(101, [1, 2], [
        ['user_id' => 1, 'seat' => 5, 'total' => 100, 'folded' => false],
        ['user_id' => 2, 'seat' => 1, 'total' => 100, 'folded' => false],
    ], 0);

    // Seat order from dealer 0: seat 1 (user 2) first, then seat 5 (user 1).
    expect($shares[2])->toBe(51)
        ->and($shares[1])->toBe(50)
        ->and(array_sum($shares))->toBe(101);
});

test('empty contributions yield no pots', function () {
    expect(PotCalculator::calculate([], 0))->toBe(['pots' => [], 'uncalled' => null]);
});
